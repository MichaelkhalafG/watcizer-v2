<?php

/*
|--------------------------------------------------------------------------
| M1c — core_transform_id_map + sp_list_sort_idx (wave 2 review, 2026-09-08)
|--------------------------------------------------------------------------
| 1. The transform id map lives in the shared production database beside the
|    legacy tables; every core-owned table that is not part of the catalog /
|    storefront / inventory / integration families carries the `core_` prefix
|    (like `core_migrations`). Wave-1 review 🟠-1, applied here (🟡-12).
| 2. `sp_list_sort_idx` backs the v2 listing's `position` order inside a large
|    category with an index-ordered scan instead of a temp table + filesort
|    (review 🟠-5): (storefront_id, is_visible, sort_order, product_id).
| Explicit index names ≤ 63 chars (AGENTS §2.14). Never touches a legacy table.
*/

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transform_id_map') && ! Schema::hasTable('core_transform_id_map')) {
            Schema::rename('transform_id_map', 'core_transform_id_map');
        }

        Schema::table('storefront_product', function (Blueprint $t) {
            $t->index(['storefront_id', 'is_visible', 'sort_order', 'product_id'], 'sp_list_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_product', function (Blueprint $t) {
            $t->dropIndex('sp_list_sort_idx');
        });
        if (Schema::hasTable('core_transform_id_map') && ! Schema::hasTable('transform_id_map')) {
            Schema::rename('core_transform_id_map', 'transform_id_map');
        }
    }
};
