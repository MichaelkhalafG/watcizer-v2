<?php

use App\Transform\Row;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two products may carry one supplier code.
 *
 * ── The contradiction this settles ──────────────────────────────────────────────────────────
 *
 * The developer's definition, stated when the two code columns were merged (item 4, 2026-09-19):
 *
 *     الكود الداخلي   — our own code. We generate it, it never repeats.
 *     رقم الموديل (SKU) — the manufacturer's or supplier's code. It comes from them, it can be
 *                        empty, and **two products can share one**.
 *
 * `catalog_products.sku` carried a UNIQUE index, which forbids exactly the last clause. That is
 * not a theoretical disagreement: the merge migration hit it on a real row and had to skip one
 * product, because 413 and 414 both carry `Ap0012` / `AP0012` — the same supplier code in two
 * casings, which MariaDB's default case-insensitive collation reads as one value.
 *
 * A constraint the business does not have is worse than no constraint, because it fails at the
 * moment somebody is doing the right thing. The index goes.
 *
 * ── What replaces it ────────────────────────────────────────────────────────────────────────
 *
 * A PLAIN index on the same column. It is not a formality:
 *
 *   • the product form and the importer both look products up by supplier code, and that read was
 *     served by the unique index;
 *   • the duplicate MARKER this pass adds (`flag=shared_sku`, and the badge beside the code on the
 *     list) asks "is any other live product carrying this code?" once per page. Without an index
 *     that is a table scan of 7,713 rows per page; with it, a probe.
 *
 * Duplicates are now something the team can SEE and decide about, rather than something the
 * database refuses and nobody ever learns of. That is the trade this makes: a constraint moved out
 * of the schema and into the screen, where a human can judge whether the two rows are one supplier
 * code used twice (correct) or a typo (not).
 *
 * ── Why `catalog_product_variants.sku` keeps ITS unique index ───────────────────────────────
 *
 * Different column, different meaning, and deliberately left alone. A variant's SKU is generated
 * by us from the product's own code — it is the internal identity of one buyable row, and the
 * inventory ledger resolves it. Two variants sharing one is a bug, not a supplier's habit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = self::duplicateCount();

        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->dropUnique('catalog_products_sku_unique');
            $table->index('sku', 'catalog_products_sku_index');
        });

        $merged = $this->finishTheMerge();

        echo '  dropped catalog_products_sku_unique, added a plain index; '
            ."merged {$merged} product(s) the unique index had blocked; "
            .'shared supplier codes before: '.$before.', now: '.self::duplicateCount()."\n";
    }

    /**
     * Reversible — but restoring the unique index will FAIL while any code is shared, and that is
     * the honest behaviour rather than a `down()` that silently deletes data to make room.
     */
    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->dropIndex('catalog_products_sku_index');
            $table->unique('sku', 'catalog_products_sku_unique');
        });
    }

    /**
     * The rows the merge migration had to skip: a `model_number` that could not move into `sku`
     * because another product already held that code.
     *
     * Same rule as that migration — only where `sku` is empty, never overwriting one — so running
     * this on a tree where the merge already completed moves nothing.
     */
    private function finishTheMerge(): int
    {
        $moved = 0;

        $candidates = DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('sku')->orWhere('sku', '');
            })
            ->whereNotNull('model_number')
            ->where('model_number', '<>', '')
            ->orderBy('id')
            ->get(['id', 'model_number']);

        foreach ($candidates as $raw) {
            $row = Row::cast($raw);

            /*
             * The supplier's own casing is kept — `AP0012`, not `Ap0012`.
             *
             * The two rows agree case-insensitively, which is why the index rejected them, and it
             * is tempting to "normalise" them to one spelling. That would be this migration
             * inventing a fact: the code came from the supplier in that form, the importer matches
             * on it case-insensitively anyway, and the team can see both on screen and decide.
             */
            DB::table('catalog_products')->where('id', Row::int($row, 'id'))->update([
                'sku' => Row::str($row, 'model_number'),
                'updated_at' => now(),
            ]);
            $moved++;
        }

        return $moved;
    }

    /** How many live products carry a supplier code that another live product also carries. */
    private static function duplicateCount(): int
    {
        $rows = DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->get([DB::raw('COUNT(*) as shared')]);

        $total = 0;
        foreach ($rows as $raw) {
            $total += Row::int(Row::cast($raw), 'shared');
        }

        return $total;
    }
};
