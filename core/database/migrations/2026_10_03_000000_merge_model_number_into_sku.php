<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `model_number` and `sku` were the same thing. Now there is one (second browser pass, item 4).
 *
 * ── The measurement that decided which column survives ──────────────────────────────────────
 *
 * Counted on the live catalogue before anything was written, over the 7,713 undeleted products:
 *
 *     sku filled                 6,799
 *     model_number filled          295
 *     both, and IDENTICAL          220   ← nothing to do
 *     both, and DIFFERENT           15   ← cannot be merged; left alone and reported
 *     model_number only             60   ← the rows this migration moves
 *     neither                      854
 *
 * `sku` survives on the numbers alone — 6,799 against 295 — and on what already depends on it:
 * the importer matches incoming rows against it, both CSV exports carry it, the products and stock
 * lists search it, and the client-facing report counts it. `model_number` was on the product form
 * and nowhere else the team touches.
 *
 * A sample confirms they are the same KIND of value, which is the premise of the merge:
 * `FS5468`, `MK4506`, `W07994G4`, `R8871621013` — manufacturer codes, in the column called `sku`.
 *
 * ── What this does NOT do ───────────────────────────────────────────────────────────────────
 *
 * It does not overwrite a `sku` that already has a value. Fifteen products carry two different
 * codes, and in fourteen of them the `model_number` looks like the truer manufacturer code while
 * the `sku` looks internal (`MK4872-W-SV` against `MK4872`). Choosing between them is a judgement
 * about this business, not a rule a migration can apply, so they are listed in
 * `docs/wave4d/sku-model-conflicts.tsv` for a human pass and left exactly as they are.
 *
 * It also does not DROP the column. Those fifteen values are the reason: dropping it destroys the
 * only remaining copy of data nobody has reconciled yet. The dashboard stops writing and reading
 * it, the storefront now reads `sku`, and the column can go in one line whenever the conflicts are
 * settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ── `catalog_products.sku` is UNIQUE, and that is a finding in itself ────────────────
         *
         * A single `UPDATE … SET sku = model_number` fails with
         * `Duplicate entry 'Ap0012' for key 'catalog_products_sku_unique'`, and the reason is
         * worth stating: products 413 and 414 (`Ap0012` and `Ap0013`) BOTH carry the model number
         * `Ap0012`. One of them is a copy-paste at data entry.
         *
         * It also puts the schema at odds with the definition this merge was asked to implement —
         * *"the manufacturer's code … can be empty, and two products can share one."* They cannot,
         * today: the column has a unique index. Dropping it is a data-integrity decision and not
         * one a data merge should make on the way past, so the index stands and the collision is
         * reported.
         *
         * So the move is done row by row, checking each value is still free. 60 rows: the cost of
         * doing it carefully is nothing, and the alternative is a migration that dies half way
         * with no record of where it stopped.
         */
        $moved = 0;
        /** @var list<string> $skipped */
        $skipped = [];

        $candidates = DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('sku')->orWhere('sku', '');
            })
            ->whereNotNull('model_number')
            ->where('model_number', '<>', '')
            ->orderBy('id')
            ->get(['id', 'wa_code', 'model_number']);

        foreach ($candidates as $row) {
            $code = is_string($row->model_number) ? trim($row->model_number) : '';
            if ($code === '') {
                continue;
            }

            $taken = DB::table('catalog_products')
                ->where('sku', $code)
                ->where('id', '<>', $row->id)
                ->exists();

            if ($taken) {
                // Cast at the point of collection: `$row->id` is `mixed` off a query builder,
                // and the list is only ever printed.
                $skipped[] = (string) (is_scalar($row->id) ? $row->id : '?');

                continue;
            }

            DB::table('catalog_products')->where('id', $row->id)->update(['sku' => $code]);
            $moved++;
        }

        // Written to the migration's own output rather than only to a report file: whoever runs
        // this needs to know that something did not move, at the moment it does not move.
        echo "  merged model_number into sku on {$moved} products".
            ($skipped === [] ? '' : '; skipped '.count($skipped).' whose code is already taken: '.implode(', ', $skipped))
            ."\n";
    }

    /**
     * Not reversible, and saying so is more honest than a `down()` that pretends.
     *
     * The rows this filled had an EMPTY `sku`, so an exact reversal is knowable — but only while
     * nothing else has written to those 60 rows since. The moment somebody edits one of them, a
     * blanket `sku = ''` would destroy a value a person typed. A data merge is a decision, not a
     * schema change, and undoing it is a decision too.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }
};
