<?php

use App\Domain\Analytics\DashboardMetrics;
use App\Domain\Inventory\InventoryService;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The home screen's numbers come from real queries, so these tests compare them with the same
 * queries computed independently — the reconciliation habit, applied to a dashboard.
 *
 * The page was rebuilt on 2026-10-21 and the figures it leads with changed: the catalogue and
 * lifetime counters are gone (nobody acted on them) and the money the shop took is in their place.
 * The habit did not change, and neither did what these tests are for — every headline figure on the
 * first screen somebody reads each morning is checked against a query written separately here.
 * `DashboardHomeTest` owns the RULES (what counts as sold, who may see whose money, the empty shop);
 * this file owns "does the number on the screen equal the number in the table".
 */

/**
 * A money figure off the page.
 *
 * JSON has ONE number type, so a total of `12740.0` comes back as `12740` and a strict comparison
 * against a PHP float fails on a value that is perfectly correct. Compare the amounts, not the types.
 *
 * @param  array<string, mixed>  $props
 */
function homeMoney(array $props, string $block, string $key): float
{
    $sales = T::arr($props['sales'] ?? []);

    return (float) T::str(T::arr($sales[$block] ?? [])[$key] ?? '0');
}

/**
 * A count off the page.
 *
 * @param  array<string, mixed>  $props
 */
function homeCount(array $props, string $block, string $key): int
{
    $sales = T::arr($props['sales'] ?? []);

    return T::int(T::arr($sales[$block] ?? [])[$key] ?? null);
}

it('counts today\'s orders and today\'s money from the live tables', function () {
    $today = now()->startOfDay();
    $orders = DB::table('orders')->where('created_at', '>=', $today)->count();
    $sold = (float) DB::table('orders')
        ->where('created_at', '>=', $today)
        ->where('status', '<>', 'cancelled')
        ->sum('total_price_for_order');

    // An admin's default scope is every storefront, which is what the two queries above count.
    $props = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());

    expect(homeCount($props, 'today', 'orders'))->toBe($orders)
        ->and(homeMoney($props, 'today', 'ordered'))->toBe(round($sold, 2));
});

it('counts the window\'s orders and money the way the customers screen already does', function () {
    $from = now()->startOfDay()->subDays(DashboardMetrics::WINDOW_DAYS - 1);
    $base = fn () => DB::table('orders')->where('created_at', '>=', $from);

    $orders = $base()->count();
    // The SAME two sums App\Domain\Customers\Customers uses — one definition of money in this
    // dashboard, not two. A home screen that disagreed with the customers screen would be worse
    // than a home screen with no money on it, which is what this used to be.
    $sold = (float) $base()->where('status', '<>', 'cancelled')->sum('total_price_for_order');
    $delivered = (float) $base()->whereIn('status', ['delivered', 'completed'])->sum('total_price_for_order');

    $props = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());

    expect(homeCount($props, 'window', 'orders'))->toBe($orders)
        ->and(homeMoney($props, 'window', 'ordered'))->toBe(round($sold, 2))
        ->and(homeMoney($props, 'window', 'collected'))->toBe(round($delivered, 2));
});

it('counts the open orders the queue promises, over every date', function () {
    // Deliberately NOT windowed, here as on the screen: an old pending order is the one worth
    // seeing, and a test that windowed it would stop guarding what the index was added for.
    $open = DB::table('orders')->whereIn('status', DashboardMetrics::OPEN_STATUSES)->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('queue.total', $open));
});

it('reads low stock from each product own threshold column', function () {
    /*
     * The same predicate the screen uses, from the same helper — a reconciliation, not a copy.
     *
     * The two `where()` clauses that used to sit here are gone because they are INSIDE the rule
     * now (B5, 2026-09-20). That is the point of the change: a caller adding its own idea of what
     * "low" means beside the helper is exactly how three screens came to give two answers, and
     * this test was quietly one of the callers doing it.
     */
    $expected = DB::table('catalog_products')->whereNull('deleted_at')
        ->whereRaw(Sql::lowStock())
        ->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('inventory.low_stock', $expected));
});

it('counts the products that are live and empty, which is the number somebody acts on', function () {
    $expected = InventoryService::outOfStockProducts()->count();

    actingAs(Staff::admin())->get('/manage')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('inventory.out_of_stock', $expected));
});

it('is reachable by data-entry, whose job starts here', function () {
    actingAs(Staff::dataEntry())->get('/manage')->assertOk();
});
