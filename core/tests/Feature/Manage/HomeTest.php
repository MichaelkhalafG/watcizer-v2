<?php

use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;

/*
 * The home screen's numbers come from real queries, so these tests compare them with the same
 * queries computed independently — the reconciliation habit, applied to a dashboard.
 */

it('counts products and orders from the live tables', function () {
    $products = DB::table('catalog_products')->whereNull('deleted_at')->count();
    $orders = DB::table('orders')->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stats.0.key', 'products')
        ->where('stats.0.value', $products)
        ->where('stats.3.key', 'orders_total')
        ->where('stats.3.value', $orders));
});

it('counts today\'s orders by the shared table, so a legacy-placed order shows up too', function () {
    $today = DB::table('orders')->where('created_at', '>=', now()->startOfDay())->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stats.2.key', 'orders_today')
        ->where('stats.2.value', $today));
});

it('reads low stock from each product own threshold column', function () {
    // The same predicate the screen uses, from the same helper — a reconciliation, not a copy.
    $expected = DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1)->where('in_stock', 1)
        ->whereRaw(Sql::belowLowStockThreshold())
        ->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('inventory.low_stock', $expected));
});

it('reports the variant count, which is zero until Brand Fashion arrives', function () {
    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('inventory.variants', DB::table('catalog_product_variants')->count()));
});

it('is reachable by data-entry, whose job starts here', function () {
    actingAs(Staff::dataEntry())->get('/manage')->assertOk();
});
