<?php

namespace App\Console\Commands;

use App\Domain\Content\BlogWriter;
use App\Domain\Content\DataBlocks;
use App\Domain\Content\QualityGate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LIVING ARTICLES, THE NIGHTLY PASS (SEO Phase 3 item 3, 2026-11-14) — `blog:refresh-blocks`, scheduled at 04:40.
 *
 * For every published article: its data blocks are resolved again and compared with what was last ANNOUNCED
 * (`data_snapshot`). A REAL change (DataBlocks::realChanges: LIVE products added or GONE, the price range moved more
 * than 5 %, the LIVE count changed more than 10 %, a compared model GONE) sets the article's `modified_at` (its
 * dateModified and the sitemap's lastmod) and queues it for IndexNow — at most `--max` articles a night per shop (the
 * rest wait for the next night, their snapshot untouched). A stock flip alone is not a change: the blocks are re-cached
 * (badges and buttons right), the dates stay. No real change: nothing is written. The first pass over an article only
 * records its snapshot.
 *
 * THE PROSE: an article whose TEXT names a model the shop no longer has LIVE, a price outside its LIVE prices, or whose
 * brand has no LIVE product left is flagged needs-update with the reasons (`needs_update_reasons`; the dashboard lists
 * them). Out of stock is never a reason. The flag clears itself when the text matches the catalogue again.
 */
final class BlogRefreshBlocksCommand extends Command
{
    protected $signature = 'blog:refresh-blocks {--max=20 : articles re-announced per shop per night} {--dry-run}';

    protected $description = "Re-render every article's live data blocks; date and announce real changes, flag stale prose";

    public function handle(DataBlocks $blocks, QualityGate $gate): int
    {
        $max = max(0, Coerce::int($this->option('max')));
        $dry = (bool) $this->option('dry-run');
        $announced = [];
        $rows = DB::table('core_blogs')->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderBy('modified_at')->orderBy('published_at')->get(['id', 'storefront_id', 'slug', 'brand_id', 'family', 'data_snapshot', 'needs_update_reasons']);
        $totals = ['checked' => 0, 'changed' => 0, 'deferred' => 0, 'flagged' => 0];
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'id');
            $sf = Row::int($r, 'storefront_id');
            try {
                $tr = [];
                foreach (DB::table('core_blog_translations')->where('blog_id', $id)->get(['locale', 'title', 'meta_title', 'meta_description', 'body']) as $t) {
                    $t = Row::cast($t);
                    $tr[Row::str($t, 'locale')] = ['title' => Row::nstr($t, 'title') ?? '', 'meta_title' => Row::nstr($t, 'meta_title') ?? '',
                        'meta_description' => Row::nstr($t, 'meta_description') ?? '', 'body' => Row::nstr($t, 'body') ?? ''];
                }
                $totals['checked']++;
                $parsed = DataBlocks::parse(($tr['en']['body'] ?? '')."\n".($tr['ar']['body'] ?? ''));
                $update = [];
                if ($parsed !== []) {
                    $now = DataBlocks::snapshot(DataBlocks::resolveAll($sf, $parsed));
                    $before = json_decode(Row::nstr($r, 'data_snapshot') ?? '', true);
                    if (! is_array($before)) {
                        $update['data_snapshot'] = json_encode($now);                 // the first pass: the baseline
                    } elseif (($changes = DataBlocks::realChanges($before, $now)) !== []) {
                        if (($announced[$sf] ?? 0) < $max) {
                            $announced[$sf] = ($announced[$sf] ?? 0) + 1;
                            $update['data_snapshot'] = json_encode($now);
                            $update['modified_at'] = now();
                            $totals['changed']++;
                            $this->line(sprintf('  %s: %s', Row::str($r, 'slug'), implode('; ', $changes)));
                        } else {
                            $totals['deferred']++;                                         // throttled: next night
                        }
                    }
                    if (! $dry) {
                        $blocks->warm($sf, $tr['en']['body'] ?? '', $tr['ar']['body'] ?? '');   // badges right after a stock flip
                    }
                }
                // the prose against the catalogue: GONE models, prices that no longer match, a brand with nothing LIVE
                $scope = ['brand_id' => Row::nint($r, 'brand_id'), 'family' => Row::nstr($r, 'family')];
                $report = $gate->checkArticle($sf, Row::str($r, 'slug'), $tr['en'] ?? null, $tr['ar'] ?? null, $scope);
                $stale = [];
                foreach (['en', 'ar'] as $l) {
                    foreach ($report[$l]['reasons'] as $reason) {
                        if (str_starts_with($reason, 'a model number the shop does not have LIVE') || str_starts_with($reason, 'a price outside')) {
                            $stale[] = strtoupper($l).': '.$reason;
                        }
                    }
                }
                if ($scope['brand_id'] !== null && ! DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
                    ->where('sp.storefront_id', $sf)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.brand_id', $scope['brand_id'])->exists()) {
                    $stale[] = 'the brand has no LIVE product left';
                }
                $stale = array_values(array_unique($stale));
                $was = json_decode(Row::nstr($r, 'needs_update_reasons') ?? '[]', true);
                if ($stale !== (is_array($was) ? $was : [])) {
                    $update['needs_update_reasons'] = $stale === [] ? null : json_encode($stale, JSON_UNESCAPED_UNICODE);
                }
                if ($stale !== []) {
                    $totals['flagged']++;
                }
                if ($update !== [] && ! $dry) {
                    DB::table('core_blogs')->where('id', $id)->update($update);
                    if (isset($update['modified_at'])) {
                        BlogWriter::articleChanged($id);                                   // lastmod + IndexNow
                    }
                }
            } catch (Throwable $e) {
                Log::warning('blog:refresh-blocks failed for an article', ['article' => $id, 'error' => $e->getMessage()]);
                $this->warn("  article {$id}: ".$e->getMessage());
            }
        }
        $this->info(sprintf('blog: %d checked, %d really changed (dated + announced), %d waiting for tomorrow (throttle), %d need an update%s',
            $totals['checked'], $totals['changed'], $totals['deferred'], $totals['flagged'], $dry ? ' — DRY RUN' : ''));

        return self::SUCCESS;
    }
}
