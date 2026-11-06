<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every storefront gets its Banners block row (the design pass, 2026-11-05).
 *
 * The Banners block — the large banner with its two side slots — can now be on TOGETHER with a hero (the
 * campaign, or the 3D watch). Then it is a home section whose place is set among the sliders, so it is a row
 * of `storefront_home_rails`, kind `banners`: one per storefront, no target, no products.
 *
 * Why a row for every storefront, switched ON, FIRST in the order: until today a shop with a hero drew its
 * active banners straight under that hero. With the row first and on, a shop that shows banners under its hero
 * keeps showing them (they now come after the category tiles — the first place the ordering has), and a shop
 * with no banner shows nothing, as before. Nobody has to know the row exists for the page to stay as it is.
 * A storefront whose hero setting is `banner` is not affected at all: there the block leads the page and the
 * row is passed over.
 *
 * `storefront_home_rails` is a preserved (dashboard) table: this is a data change, idempotent by its own
 * condition (a storefront that has the row is left alone), and it does nothing when the table is absent.
 *
 * Rollback, by hand (the ledger is `core_migrations`), BEFORE the old code is restored — the old dashboard
 * does not know the kind:
 *
 *   DELETE FROM storefront_home_rails WHERE kind = 'banners';
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000005_banners_block_row';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_home_rails') || ! Schema::hasTable('storefronts')) {
            return;
        }

        foreach (DB::table('storefronts')->orderBy('id')->pluck('id') as $id) {
            $storefrontId = is_numeric($id) ? (int) $id : 0;
            if ($storefrontId < 1 || DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('kind', 'banners')->exists()) {
                continue;
            }

            DB::transaction(function () use ($storefrontId): void {
                // The sliders keep their order and move down one place (20, 30, 40…); `position` is unsigned, so
                // "before the first" is made by renumbering, not by a smaller number.
                $place = 20;
                foreach (DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->orderBy('position')->orderBy('id')->pluck('id') as $railId) {
                    DB::table('storefront_home_rails')->where('id', $railId)->update(['position' => $place]);
                    $place += 10;
                }

                DB::table('storefront_home_rails')->insert([
                    'storefront_id' => $storefrontId,
                    'kind' => 'banners',
                    'target_id' => null,
                    'title_en' => null,
                    'title_ar' => null,
                    'position' => 10,
                    'is_active' => true,
                    'card_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('storefront_home_rails')) {
            DB::table('storefront_home_rails')->where('kind', 'banners')->delete();
        }
    }
};
