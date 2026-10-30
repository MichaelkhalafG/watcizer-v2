<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1v — the record of a BULK PRICE CHANGE (2026-10-30, Michael's item 2): which products, the price each had and the
 * price each was given, who did it and when — so one batch can be UNDONE, product by product, only where nobody has
 * changed the price since.
 *
 *  • `core_price_batches` — one row per applied change: the screen's storefront, the operator, what was asked (mode,
 *    value, target, rounding), how many products moved, and whether/when/by whom it was undone.
 *  • `core_price_batch_items` — one row per product in the batch: the catalogue price and sale price before and after.
 *
 * The prices themselves live on `catalog_products` (shared by both storefronts, D3) and move through
 * `ProductPatcher`; the activity log carries one row per product as well, so a product's own history shows the change.
 * Both tables are dashboard-authored and join `CoreChecksumCommand::DASHBOARD_TABLES`. Guarded (AGENTS §2.20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_price_batches') or Schema::create('core_price_batches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('mode', 24);             // increase_amount | decrease_amount | increase_percent | decrease_percent | set
            $t->string('target', 16);           // selling | sale | clear_sale
            $t->decimal('value', 12, 2)->nullable();
            $t->unsignedSmallInteger('rounding')->default(0);   // 0 = none, else the step in EGP
            $t->unsignedInteger('product_count');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->unsignedBigInteger('undone_by')->nullable();
            $t->index('created_at', 'price_batch_created_idx');
        });

        Schema::hasTable('core_price_batch_items') or Schema::create('core_price_batch_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('old_selling', 12, 2);
            $t->decimal('old_sale', 12, 2)->nullable();
            $t->decimal('new_selling', 12, 2);
            $t->decimal('new_sale', 12, 2)->nullable();
            $t->index('batch_id', 'price_item_batch_idx');
            $t->index('product_id', 'price_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_price_batch_items');
        Schema::dropIfExists('core_price_batches');
    }
};
