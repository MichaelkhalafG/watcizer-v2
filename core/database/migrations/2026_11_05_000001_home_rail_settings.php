<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a home slider looks like, per slider (the twelve updates, Part 3.2 and 3.3, 2026-11-05).
 *
 * `storefront_home_rails.settings` — one nullable JSON column for the things a rail may carry beyond its
 * kind, target and picks:
 *
 *   background  "#RRGGBB" | absent   the colour of that slider's section (3.2)
 *   blend       true | absent        blend the product photos' white studio background into it (3.2)
 *   colors      ["#…", "#…", "#…"]   the three colours that fill a brand banner (3.3)
 *   description {"ar": "…", "en": "…"}  a brand banner's sentence under its title (3.3)
 *
 * NULL — every rail that existed before this migration — renders exactly as it did: no colour, no blend.
 * One JSON column rather than four typed ones because every key is optional, belongs to one kind or
 * another, and is only ever read whole for the page; `HomeRails` owns the shape and validates it.
 *
 * `storefront_home_rails` is a preserved (dashboard) table, so the column is `hasColumn`-guarded: a
 * `migrate` after a rebuild that kept the table must be a no-op (`RebuildSurvivesTest`).
 *
 * Rollback, by hand (the ledger is `core_migrations`):
 *
 *   ALTER TABLE storefront_home_rails DROP COLUMN settings;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000001_home_rail_settings';
 *
 * After the old code is back. A `brand_banner` rail left behind is ignored by the old code's kinds
 * list only if it is deleted first: `DELETE FROM storefront_home_rails WHERE kind = 'brand_banner';`
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_home_rails') && ! Schema::hasColumn('storefront_home_rails', 'settings')) {
            Schema::table('storefront_home_rails', function (Blueprint $t): void {
                $t->longText('settings')->nullable()->after('card_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('storefront_home_rails') && Schema::hasColumn('storefront_home_rails', 'settings')) {
            Schema::table('storefront_home_rails', function (Blueprint $t): void {
                $t->dropColumn('settings');
            });
        }
    }
};
