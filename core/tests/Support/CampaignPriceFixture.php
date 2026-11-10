<?php

namespace Tests\Support;

use App\Domain\Campaigns\CampaignPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign prices OFF at the start of every feature test (2026-11-10), inside the test's transaction.
 *
 * A campaign price overrides a product's price everywhere — correctly. The suite's tests borrow real products of the
 * copy they run on and set the prices they assert; a live priced campaign on that copy (the team's, once production is
 * copied, or a browser pass's) prices them instead — measured: 13 tests on the scratch copy with two live priced
 * campaigns. Cleared here and rolled back with the test; a test about campaign prices sets its own. The in-process
 * memo of the rules is forgotten too: it outlives a test.
 */
final class CampaignPriceFixture
{
    private static ?bool $hasColumns = null;

    public static function neutral(): void
    {
        // a copy behind the migration has no prices to clear
        self::$hasColumns ??= Schema::hasColumn('storefront_campaign_products', 'price_mode');
        if (self::$hasColumns) {
            DB::table('storefront_campaign_products')->whereNotNull('price_mode')->update(['price_mode' => null, 'price_value' => null]);
        }
        foreach (DB::table('storefronts')->pluck('id') as $id) {
            CampaignPrices::forget(T::int($id));
        }
    }
}
