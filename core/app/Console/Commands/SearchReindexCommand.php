<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ArabicSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan search:reindex` — fold `catalog_product_search.body` into the normalised spelling.
 *
 * ── Why this exists, when the transform already rebuilds the table ───────────────────────────
 *
 * A-UX-1 changed how the index is written: `ProductIndexer` and `Step21SearchIndex` both pass the
 * body through {@see ArabicSearch::normalise()} now. Every row written from this point on is folded.
 * The 15,426 rows already in the table are not, so until they are, an operator typing `ساعه` still
 * finds nothing — the fix is live and invisible.
 *
 * `core:transform` would rewrite them all, and on switch night it will. But a rebuild destroys the
 * imported Brand Fashion catalogue, which is real data the team is working through, so it is not
 * available today. This command is the other half: it brings the existing rows to where new ones
 * already are, without touching anything else.
 *
 * ── Why it rewrites the BODY rather than re-deriving it ──────────────────────────────────────
 *
 * The alternative is `ProductIndexer::reindex()` per product — 7,713 products, each re-reading its
 * translations, brand and category names: several queries apiece, minutes of work, and a great deal
 * of it spent reproducing a string the table already holds.
 *
 * Normalising in place is correct because `normalise()` is IDEMPOTENT and the composition is
 * unchanged: for any untouched product, `normalise(compose(parts))` and `normalise(stored body)` are
 * the same string, because `stored body` IS `compose(parts)`. That property is asserted in
 * `ArabicSearchTest`, and it is the same property `ProductIndexer`'s docblock already rests on.
 *
 * So this is a pure string fold over a table, and it is safe to run twice: the second run finds
 * every row already folded and writes nothing.
 *
 * ── What it does NOT do ─────────────────────────────────────────────────────────────────────
 *
 * It does not touch a title, a product or anything a person reads. `catalog_product_search` is a
 * derived shadow of the catalogue, rebuilt from scratch by the transform; nothing else reads `body`
 * and nothing displays it.
 */
final class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex
        {--dry-run : count the rows that would change and write nothing}
        {--chunk=1000 : rows per pass}';

    protected $description = 'Normalise catalog_product_search.body so Arabic search matches how people type (A-UX-1)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));

        $total = DB::table('catalog_product_search')->count();
        $this->info('search:reindex');
        $this->line('  rows      '.number_format($total));
        $this->line('  mode      '.($dryRun ? 'DRY RUN (nothing is written)' : 'WRITING'));
        $this->line('');

        if ($total === 0) {
            $this->warn('  the index is empty — run core:transform, or import something.');

            return self::SUCCESS;
        }

        $changed = 0;
        $seen = 0;
        $lastProduct = 0;

        /*
         * Keyset pagination on `product_id`, NOT `OFFSET`, and not on an `id` column — this table
         * has none. Its primary key is the composite `(product_id, locale)`, so a row is addressed
         * by both, and the walk advances one PRODUCT at a time with both of its locale rows in the
         * same chunk.
         *
         * Keyset rather than offset because the rows are being UPDATED as we walk them, and an
         * offset over a table you are writing to skips rows. The fold is idempotent, so a skipped
         * row would not be corrupted — it would simply stay un-normalised and unsearchable, which is
         * exactly the bug this command exists to end, reintroduced silently.
         */
        while (true) {
            /*
             * The chunk is a set of PRODUCTS, then every row belonging to them — not a set of rows.
             *
             * A row-limited chunk can end between a product's `ar` and `en` rows, and the next pass
             * (`product_id > last`) would then step straight over the `en` one. It would be left
             * un-normalised and unsearchable, which is the bug being fixed, reintroduced by the fix.
             */
            $ids = DB::table('catalog_product_search')
                ->where('product_id', '>', $lastProduct)
                ->orderBy('product_id')
                ->distinct()
                ->limit($chunk)
                ->pluck('product_id');

            if ($ids->isEmpty()) {
                break;
            }

            $rows = DB::table('catalog_product_search')
                ->whereIn('product_id', $ids->all())
                ->orderBy('product_id')
                ->orderBy('locale')
                ->get(['product_id', 'locale', 'body']);

            foreach ($rows as $row) {
                $lastProduct = is_numeric($row->product_id) ? (int) $row->product_id : $lastProduct;
                $seen++;

                $body = is_string($row->body) ? $row->body : '';
                $folded = ArabicSearch::normalise($body);

                if ($folded === $body) {
                    continue;
                }

                $changed++;
                if (! $dryRun) {
                    DB::table('catalog_product_search')
                        ->where('product_id', $lastProduct)
                        ->where('locale', is_string($row->locale) ? $row->locale : '')
                        ->update(['body' => $folded]);
                }
            }

            $this->line(sprintf('  … %s of %s rows, %s folded',
                number_format($seen), number_format($total), number_format($changed)));
        }

        $this->line('');
        $this->info(sprintf('  %s row(s) %s of %s.',
            number_format($changed), $dryRun ? 'WOULD be folded' : 'folded', number_format($seen)));

        if (! $dryRun && $changed > 0) {
            $this->line('');
            $this->line('  The dashboard and the storefront both fold the typed term the same way,');
            $this->line('  so Arabic search now matches how people actually type.');
        }

        return self::SUCCESS;
    }
}
