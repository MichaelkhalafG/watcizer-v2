<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Show no picture for this category" (the twelve updates, Part 5, 2026-11-05).
 *
 * A category or sub-type with no image of its own now shows the photo of its most recently added
 * product that a shopper can still open (`CategoryImages`). The team may not want one at all, and
 * deleting the picture must then mean "show nothing", not "fall back again" — which an empty
 * `image_path` cannot say, because empty is exactly the state that falls back. So one flag:
 *
 *   storefront_categories.image_hidden   0 (default) = its own image, else the fallback
 *                                        1           = no picture, whatever its products are
 *
 * Every existing row gets 0, so nothing changes for a category that has its own image, and a category
 * that had none gains the fallback — the change the brief asks for.
 *
 * Rollback, by hand (the ledger is `core_migrations`):
 *
 *   ALTER TABLE storefront_categories DROP COLUMN image_hidden;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000002_category_image_hidden';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_categories') && ! Schema::hasColumn('storefront_categories', 'image_hidden')) {
            Schema::table('storefront_categories', function (Blueprint $t): void {
                $t->boolean('image_hidden')->default(false)->after('image_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('storefront_categories') && Schema::hasColumn('storefront_categories', 'image_hidden')) {
            Schema::table('storefront_categories', function (Blueprint $t): void {
                $t->dropColumn('image_hidden');
            });
        }
    }
};
