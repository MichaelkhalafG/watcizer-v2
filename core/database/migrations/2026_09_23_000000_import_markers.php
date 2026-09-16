<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1m — the two marks an IMPORTED row carries that a hand-written one does not (wave 4D, importers).
 *
 * ── Why a column at all, when the list already derives its states ────────────────────────────
 *
 * Everything else the team needs to see at a glance is DERIVED — no Arabic, no image, no
 * category, no stock are all answerable from the row itself, and a derived state cannot go stale
 * while somebody fixes the data. The importer adds one fact that is NOT derivable: **where this
 * Arabic came from.** A machine-written title and a human-written one look identical in the
 * column; the difference is history, so it has to be stored.
 *
 * The developer's requirement (2026-09-14) is precise about the lifecycle, and it is why this is
 * one boolean per TRANSLATION rather than a flag on the product:
 *
 *   • it must be visible as a badge,
 *   • it must be filterable, so the team can work through the backlog in order,
 *   • **and it must disappear the moment a human edits that translation** — which means it has to
 *     live on the row a human edits, and be cleared by the one door that edits it
 *     (`ProductWriter::writeTranslations()`), not by a screen remembering to.
 *
 * `catalog_product_translations` is transform output, so this column is dropped and recreated by
 * every rebuild and every legacy-sourced translation gets the default. That is correct: a row the
 * transform wrote came from the legacy database, where a human typed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_product_translations')
            && ! Schema::hasColumn('catalog_product_translations', 'is_machine')) {
            Schema::table('catalog_product_translations', function (Blueprint $t): void {
                // Default 0: every existing row, and everything the transform writes, is human.
                $t->boolean('is_machine')->default(false)->after('locale');
                // The list filters on it, and the whole point is to find the backlog quickly.
                $t->index(['locale', 'is_machine'], 'cpt_locale_machine_idx');
            });
        }

        /*
         * The import's own audit trail, on the product.
         *
         * NOT a "missing data" column — that is derived, deliberately (see above). This records
         * WHERE the row came from, which nothing else in the schema can answer once `wa_code` has
         * been chosen freely: `woo:15826`, `joyroom:JR-PBM01`. It is what makes a second run of the
         * importer an UPDATE rather than a duplicate, and what lets the team filter the rehearsal's
         * output out of the catalogue in one query.
         */
        if (Schema::hasTable('catalog_products') && ! Schema::hasColumn('catalog_products', 'import_ref')) {
            Schema::table('catalog_products', function (Blueprint $t): void {
                $t->string('import_ref', 64)->nullable()->after('hs_code');
                // UNIQUE, so a re-run cannot create a second copy of the same source row. Nullable
                // columns do not collide on NULL in MariaDB, so every hand-made product is exempt.
                $t->unique('import_ref', 'catalog_products_import_ref_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_product_translations') && Schema::hasColumn('catalog_product_translations', 'is_machine')) {
            Schema::table('catalog_product_translations', function (Blueprint $t): void {
                $t->dropIndex('cpt_locale_machine_idx');
                $t->dropColumn('is_machine');
            });
        }
        if (Schema::hasTable('catalog_products') && Schema::hasColumn('catalog_products', 'import_ref')) {
            Schema::table('catalog_products', function (Blueprint $t): void {
                $t->dropUnique('catalog_products_import_ref_unique');
                $t->dropColumn('import_ref');
            });
        }
    }
};
