<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Timed campaigns: Hot Deals and Flash Sale, ONE mechanism with two presentations (the twelve
 * updates, Part 2, 2026-11-05).
 *
 * `storefront_campaigns` — one row per campaign of one storefront: the team's name for it, the
 * presentation (`hot_deals` | `flash_sale`), a start and an end (wall-clock, the application's time
 * zone — Cairo on production, exactly as `promotion_rules` stores its window), the active toggle, and
 * `activated_at`: the moment the start quantities below were recorded.
 *
 * `storefront_campaign_products` — the selection, in the team's order, and per product the STARTING
 * QUANTITY of each stock bucket, recorded once when the campaign goes live (`CampaignWriter::activate`),
 * with the ledger's position at that moment (`start_movement_id`). That snapshot is the only thing the
 * progress bar needs that the database did not already hold: "sold" is read from the stock ledger (order
 * lines sold after that position and before the end, minus their releases) and "left" is the live stock. A product with no snapshot shows no bar — never a derived or invented one (the
 * developer's decision D1, 2026-11-04).
 *
 * Both tables are AUTHORED IN THE DASHBOARD: they are in `CoreChecksumCommand::DASHBOARD_TABLES` and each
 * creation is `hasTable`-guarded, so a `migrate` after a rebuild that preserved them is a no-op
 * (AGENTS §2.20; `RebuildSurvivesTest`).
 *
 * Rollback, by hand (the ledger is `core_migrations`, never `migrations`):
 *
 *   DROP TABLE storefront_campaign_products;
 *   DROP TABLE storefront_campaigns;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000000_campaigns';
 *
 * Run it only after the code that reads the tables is gone; nothing else references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_campaigns') or Schema::create('storefront_campaigns', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('name', 120);
            $t->string('presentation', 16);                 // hot_deals | flash_sale
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->boolean('is_active')->default(false);
            $t->dateTime('activated_at')->nullable();        // when the start quantities were recorded
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();

            $t->index(['storefront_id', 'presentation', 'is_active', 'starts_at', 'ends_at'], 'sc_live_idx');
            // with its storefront, as every storefront-owned table goes (storefront_banners, storefront_home_rails, …)
            $t->foreign('storefront_id', 'sc_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
        });

        Schema::hasTable('storefront_campaign_products') or Schema::create('storefront_campaign_products', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedInteger('position')->default(0);
            $t->unsignedInteger('start_express')->nullable();   // NULL until the campaign goes live
            $t->unsignedInteger('start_market')->nullable();
            $t->dateTime('snapshot_at')->nullable();
            // The last stock movement that existed when the start was recorded: "sold" counts the order lines
            // sold AFTER this position in the ledger. A position, not a time — movements are stamped to the
            // second, and a sale in the same second as the snapshot must fall on exactly one side of it.
            $t->unsignedBigInteger('start_movement_id')->nullable();
            $t->timestamps();

            $t->unique(['campaign_id', 'product_id'], 'scp_once_unique');
            $t->index('product_id', 'scp_product_idx');
            $t->foreign('campaign_id', 'scp_campaign_fk')->references('id')->on('storefront_campaigns')->cascadeOnDelete();
            // No foreign key to `catalog_products`, on purpose: that is a transform-output table and this one
            // is preserved — the home rails' picks and the banners point at products the same way. The writer
            // validates the ids; a product deleted since is simply not shown.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_campaign_products');
        Schema::dropIfExists('storefront_campaigns');
    }
};
