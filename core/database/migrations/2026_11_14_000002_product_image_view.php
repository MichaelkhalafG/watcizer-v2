<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHAT A PRODUCT PHOTO SHOWS (SEO Phase 1 item 9, 2026-11-14): front, side, back, the dial close up, the clasp, on the
 * wrist, worn, the box and papers, inside, a detail — chosen per image in the dashboard's gallery
 * (config catalog.image_views). The storefronts write each photo's alt text from it ("Hugo Boss 1513477 — on the
 * wrist") unless the team typed the alt text itself. NULL = not chosen.
 *
 * Additive and nullable, so the code before this change runs unchanged beside it. Undo (core records migrations in
 * core_migrations): ALTER TABLE catalog_product_images DROP COLUMN `view`; DELETE FROM core_migrations WHERE
 * migration = '2026_11_14_000002_product_image_view';
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product_images', function (Blueprint $t): void {
            $t->string('view', 16)->nullable()->after('alt_ar');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product_images', function (Blueprint $t): void {
            $t->dropColumn('view');
        });
    }
};
