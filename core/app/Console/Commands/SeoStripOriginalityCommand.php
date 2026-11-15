<?php

namespace App\Console\Commands;

use App\Domain\Catalog\SearchExposure;
use App\Domain\Content\OriginalityClaims;
use App\Domain\Content\SeoTexts;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * The originality claims of the shops' page texts (Michael, 2026-11-14; OriginalityClaims has the rules):
 *
 *  - the BRAND pages of brands whose products are all kept from search engines or all a matched Rolex
 *    (SearchExposure::brands()): the claims removed;
 *  - every GENERAL WATCH CATEGORY page (Watches and its sub-categories), which lists the matched products too: the
 *    claim reworded to what is true of every watch on it — it lives on the Original watches page only.
 *
 * DRY RUN by default: it prints, per text, what it would change. `--apply` backs every row it changes up into
 * `bk_seo_originality_20261114` first (once per row), then writes through `SeoTexts::save()` — the dashboard's own
 * writer, so the activity log records each change — and flushes the shop's cache. Undo: restore from the backup table
 * (the deploy script's undo).
 */
final class SeoStripOriginalityCommand extends Command
{
    protected $signature = 'seo:strip-originality
        {--apply : write the changes (default: print them only)}
        {--storefront=* : limit to these storefront ids}';

    protected $description = 'Remove or reword the originality claims of brand and watch-category texts (2026-11-14)';

    private const BACKUP = 'bk_seo_originality_20261114';

    private int $changed = 0;

    public function handle(StorefrontCache $cache): int
    {
        // per run: Artisan reuses one command object within a process (a second call counted the first's changes)
        $this->changed = 0;
        $apply = (bool) $this->option('apply');
        $only = array_map('intval', (array) $this->option('storefront'));
        $storefronts = DB::table('storefronts')->where('is_active', 1)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => Coerce::int($id))->all();
        if ($only !== []) {
            $storefronts = array_values(array_intersect($storefronts, $only));
        }
        if ($apply && ! Schema::hasTable(self::BACKUP)) {
            DB::statement('CREATE TABLE '.self::BACKUP.' AS SELECT id, description, meta_description FROM core_seo_texts WHERE 1 = 0');
        }

        foreach ($storefronts as $sf) {
            $flush = false;

            // the brand pages of the brands kept from search
            $brands = SearchExposure::brands($sf);
            $ids = array_merge($brands['hidden_brand_ids'], $brands['restricted_brand_ids']);
            $slugs = $ids === [] ? [] : DB::table('catalog_brands')->whereIn('id', $ids)->pluck('slug')->all();
            $rows = $slugs === [] ? [] : DB::table('core_seo_texts')->where('storefront_id', $sf)->where('subject_type', 'brand')
                ->whereIn('subject_key', $slugs)->orderBy('subject_key')->orderBy('locale')->get()->all();
            foreach ($rows as $row) {
                $flush = $this->change($sf, $row, $apply, fn (?string $d): ?string => OriginalityClaims::stripDescription($d)) || $flush;
            }

            // every category page: a general WATCH category's claim reworded; every category's free-shipping threshold out
            $rows = DB::table('core_seo_texts')->where('storefront_id', $sf)->where('subject_type', 'category')
                ->orderBy('subject_key')->orderBy('locale')->get()->all();
            foreach ($rows as $row) {
                $key = Row::str($row, 'subject_key');
                $watch = $key === 'watches' || str_starts_with($key, 'watches/');
                $flush = $this->change($sf, $row, $apply, fn (?string $d): ?string => OriginalityClaims::withoutFreeShippingThreshold(
                    $watch ? OriginalityClaims::rewordWatchCategory($d) : $d), $watch) || $flush;
            }

            if ($flush) {
                $cache->flush($sf);
            }
        }
        $this->line(($apply ? 'changed ' : 'would change ').$this->changed.' text(s)'.($apply ? ' — backup in '.self::BACKUP : ' (dry run; --apply to write)'));

        return self::SUCCESS;
    }

    /** @param  Closure(?string): ?string  $description */
    private function change(int $sf, stdClass $row, bool $apply, Closure $description, bool $meta = true): bool
    {
        $before = ['d' => Row::nstr($row, 'description'), 'm' => Row::nstr($row, 'meta_description')];
        // the meta's "Original" goes on the pages that may not claim it; another category's meta is left as it is
        $after = ['d' => $description($before['d']), 'm' => $meta ? OriginalityClaims::stripMeta($before['m']) : $before['m']];
        if ($after === $before) {
            return false;
        }
        $type = Row::str($row, 'subject_type');
        $key = Row::str($row, 'subject_key');
        $locale = Row::str($row, 'locale');
        $this->line(sprintf('storefront %d %s %s %s: description %d → %d characters; meta "%s" → "%s"', $sf, $type, $key, $locale,
            mb_strlen($before['d'] ?? ''), mb_strlen($after['d'] ?? ''), mb_substr($before['m'] ?? '', 0, 50), mb_substr($after['m'] ?? '', 0, 50)));
        $this->changed++;
        if (! $apply) {
            return false;
        }
        $id = Row::int($row, 'id');
        if (! DB::table(self::BACKUP)->where('id', $id)->exists()) {
            DB::table(self::BACKUP)->insert(['id' => $id, 'description' => $before['d'], 'meta_description' => $before['m']]);
        }
        SeoTexts::save($sf, $type, $key, [$locale => ['description' => $after['d'], 'meta_description' => $after['m']]], null,
            $type.': '.$key.' (originality claims, 2026-11-14)');

        return true;
    }
}
