<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campaign prices (Michael, 2026-11-10): while picking a product for a Hot Deals or Flash Sale campaign, the team may
 * set an EXTRA discount that holds only from the campaign's start to its end.
 *
 *   storefront_campaign_products.price_mode    'price'   — a campaign price (e.g. 1,500), or
 *                                              'percent' — an extra percentage off the product's current price (its
 *                                                          valid sale, else its price), rounded to the pound;
 *                                              NULL      — no campaign price: the product keeps its normal price
 *   storefront_campaign_products.price_value   the price, or the percentage (0 < % ≤ 90)
 *
 * Nothing in the product's own price fields changes: the price is decided at every read by App\Domain\Campaigns\
 * CampaignPrices from the LIVE campaign (starts_at ≤ now < ends_at, active) — so it starts and ends at the exact
 * second, and goes away by itself when the campaign ends, is switched off, or the product is removed from it.
 *
 * `storefront_campaign_products` is the second deploy's table (2026_11_05_000000_campaigns, live on production), so
 * this is a guarded ALTER of its own — the third deploy's SECOND migration.
 *
 * Rollback, by hand (the ledger is `core_migrations`; run only after the code that reads the columns is gone):
 *
 *   ALTER TABLE storefront_campaign_products DROP COLUMN price_mode, DROP COLUMN price_value;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_10_000000_campaign_prices';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storefront_campaign_products') && ! Schema::hasColumn('storefront_campaign_products', 'price_mode')) {
            Schema::table('storefront_campaign_products', function (Blueprint $table): void {
                $table->string('price_mode', 8)->nullable()->after('position');
                $table->decimal('price_value', 12, 2)->nullable()->after('price_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('storefront_campaign_products') && Schema::hasColumn('storefront_campaign_products', 'price_mode')) {
            Schema::table('storefront_campaign_products', function (Blueprint $table): void {
                $table->dropColumn(['price_mode', 'price_value']);
            });
        }
    }
};
