<?php

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\MetaText;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Strip the marketplace junk from product meta titles (S-AR stage 2, 2026-10-01).
 *
 * About half the English `meta_title`s end in " | Select…" or a tail cut off mid-word (" | Ch...",
 * " | ..."), copied from a marketplace listing; search results would show it. The rule is
 * `MetaText::title()`: the last " | " segment goes when it ends in an ellipsis. A tail that ends in
 * a word stays. Every locale, every product. One activity-log entry per product changed, and that
 * product's cached detail is forgotten on every storefront.
 *
 *   php artisan catalog:clean-meta-titles            # dry run: what would change, nothing written
 *   php artisan catalog:clean-meta-titles --apply    # write it
 */
final class CatalogCleanMetaTitlesCommand extends Command
{
    protected $signature = 'catalog:clean-meta-titles
        {--apply : write the cleaned titles; without it nothing is written}';

    protected $description = 'Strip the " | Select…" / truncated-tail junk from product meta titles';

    public function handle(StorefrontCache $cache): int
    {
        $apply = (bool) $this->option('apply');

        $changes = [];
        foreach (DB::table('catalog_product_translations')->whereNotNull('meta_title')->orderBy('product_id')->orderBy('locale')
            ->get(['id', 'product_id', 'locale', 'meta_title']) as $raw) {
            $row = Row::cast($raw);
            $before = Row::str($row, 'meta_title');
            $after = MetaText::title($before);
            if ($after !== $before) {
                $changes[] = ['id' => Row::int($row, 'id'), 'product_id' => Row::int($row, 'product_id'), 'locale' => Row::str($row, 'locale'), 'before' => $before, 'after' => $after];
            }
        }

        $products = array_values(array_unique(array_column($changes, 'product_id')));
        $this->info(($apply ? 'Cleaning' : 'Would clean').' '.count($changes).' meta title(s) on '.count($products).' product(s).');
        foreach (array_slice($changes, 0, 15) as $c) {
            $this->line(sprintf('  #%d %s: "%s" → "%s"', $c['product_id'], $c['locale'], $c['before'], $c['after'] ?? ''));
        }
        if (count($changes) > 15) {
            $this->line('  … and '.(count($changes) - 15).' more.');
        }
        if (! $apply || $changes === []) {
            if (! $apply && $changes !== []) {
                $this->line('Nothing written. Run again with --apply to write them.');
            }

            return self::SUCCESS;
        }

        $storefronts = DB::table('storefronts')->pluck('id')->map(fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0))->all();
        $byProduct = [];
        foreach ($changes as $c) {
            $byProduct[$c['product_id']][] = $c;
        }
        foreach ($byProduct as $productId => $rows) {
            DB::transaction(function () use ($productId, $rows): void {
                $before = [];
                $after = [];
                foreach ($rows as $c) {
                    DB::table('catalog_product_translations')->where('id', $c['id'])->update(['meta_title' => $c['after']]);
                    $before['meta_title_'.$c['locale']] = $c['before'];
                    $after['meta_title_'.$c['locale']] = $c['after'];
                }
                ActivityLog::record('catalog_products', $productId, ActivityLog::UPDATED, $before, $after, 'meta title: junk tail removed');
            });
            foreach ($storefronts as $storefrontId) {
                $cache->forgetProduct($storefrontId, $productId);
            }
        }

        $this->info('Done: '.count($changes).' title(s) on '.count($byProduct).' product(s).');

        return self::SUCCESS;
    }
}
