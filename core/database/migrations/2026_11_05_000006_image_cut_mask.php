<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The background mask of a product photo (the developer's review, 2026-11-05, item 32).
 *
 * A home slider with its own colour may "blend" its product photos into it. The blend used to multiply
 * the whole photo by the colour, which tinted the product. It now only replaces the photo's white studio
 * background: `CutoutMask` reads a photo once and produces a small transparency mask of that background,
 * and the storefront lays it over the UNCHANGED photo with CSS. This is where the mask is kept:
 *
 *   catalog_product_images.cut_mask     the mask, a PNG of ~1.3 KB, base64 (TEXT, so a row that is ever
 *                                       serialised to JSON stays valid); NULL = no mask
 *   catalog_product_images.cut_mask_at  when the photo was last examined; NULL = never. A photo examined
 *                                       and found to have no studio background has a date and no mask,
 *                                       and is not examined again.
 *
 * Derived data: it can be thrown away and made again (`php artisan media:cut-masks`), and the storefront
 * makes a missing one itself the first time a blended slider shows that photo. Nothing reads it but
 * `App\Storefront\CutMasks`.
 *
 * `catalog_product_images` is transform OUTPUT (`core:drop-clean` drops it and `2026_09_10` creates it
 * again), so this is a guarded `Schema::table`, exactly like `2026_10_01_000000_image_rendition_failures`.
 *
 * Rollback, by hand (the ledger is `core_migrations`). The old code never names the columns, so the order
 * does not matter:
 *
 *   ALTER TABLE catalog_product_images DROP COLUMN cut_mask, DROP COLUMN cut_mask_at;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000006_image_cut_mask';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_product_images') && ! Schema::hasColumn('catalog_product_images', 'cut_mask')) {
            Schema::table('catalog_product_images', function (Blueprint $table): void {
                $table->text('cut_mask')->nullable();
                $table->timestamp('cut_mask_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_product_images') && Schema::hasColumn('catalog_product_images', 'cut_mask')) {
            Schema::table('catalog_product_images', function (Blueprint $table): void {
                $table->dropColumn(['cut_mask', 'cut_mask_at']);
            });
        }
    }
};
