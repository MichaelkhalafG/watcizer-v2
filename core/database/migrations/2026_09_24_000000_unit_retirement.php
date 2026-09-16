<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1n — a unit can be RETIRED instead of deleted (wave 4D, task C3).
 *
 * ── The mess this exists to clean up ─────────────────────────────────────────────────────────
 *
 * The legacy `size_types` table mixed two unrelated things: physical units of measurement (mm, cm,
 * inch, ATM, Bar) and garment/shoe SIZES (XS…XXXXXL, 26…47). The transform carries it across
 * faithfully into `catalog_units` — as decided on 2026-09-06, "units quirk transforms as-is,
 * wave-4 backlog: units cleanup UI" — so today the unit picker on the watch form offers 37 options
 * of which 31 are clothing sizes.
 *
 * Measured on this database (2026-09-14): `mm` is used 676 times, `ATM` 29, `cm` and `Bar` once
 * each… and **`M` is used as a unit 231 times** and `33` once. Those last two are not units at all;
 * they are what happens when a picker offers thirty-one wrong answers.
 *
 * ── Why RETIRE and not DELETE ────────────────────────────────────────────────────────────────
 *
 * Every one of the eight `*_unit_id` columns on `catalog_product_watch_specs` is a FK with
 * `restrictOnDelete()`, so a used unit cannot be deleted at all — correctly: deleting the row a
 * spec points at would either fail or strip the meaning out of 676 measurements. And an UNUSED one
 * could be deleted, but that would make the id reusable, and ids of `size_types` are preserved by
 * the transform (§2.9.6 rule 7) precisely so that nothing downstream has to re-learn them.
 *
 * So retirement is a curtain, not a delete: a retired unit keeps its row, its id and its history,
 * and simply stops being offered. `UnitCleanup::merge()` is what empties one first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_units') && ! Schema::hasColumn('catalog_units', 'retired_at')) {
            Schema::table('catalog_units', function (Blueprint $t): void {
                // NULLABLE, so the column carries no implicit `0000-00-00` default — the M1
                // lesson about a second `timestamp NOT NULL` in one table (error 1067).
                $t->timestamp('retired_at')->nullable()->after('code');
                $t->index('retired_at', 'catalog_units_retired_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_units') && Schema::hasColumn('catalog_units', 'retired_at')) {
            Schema::table('catalog_units', function (Blueprint $t): void {
                $t->dropIndex('catalog_units_retired_idx');
                $t->dropColumn('retired_at');
            });
        }
    }
};
