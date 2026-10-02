<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M2e — the custom home rail (2026-10-02, developer: "build it").
 *
 *  - `storefront_home_rails.product_ids` — JSON list, in order: the products the team picked by hand
 *    for a rail of kind `custom` (HomeRails::CUSTOM). Null for every other kind, which keeps choosing
 *    its own cards. Deliberately NO foreign key (the same reason `target_id` has none): products are
 *    transform output, and a key into them would stop `core:drop-clean` on switch night. A picked
 *    product that is hidden or gone is simply not shown.
 *
 * Additive and nullable; `hasColumn`-guarded (a dashboard table — it survives the rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('storefront_home_rails', 'product_ids')) {
            Schema::table('storefront_home_rails', function (Blueprint $t): void {
                $t->text('product_ids')->nullable()->after('target_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('storefront_home_rails', 'product_ids')) {
            Schema::table('storefront_home_rails', fn (Blueprint $t) => $t->dropColumn('product_ids'));
        }
    }
};
