<?php

use App\Domain\Analytics\DashboardMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The rebuilt dashboard home (2026-10-21).
 *
 * The page answers five questions and every number on it has an action attached. These tests pin the three
 * things that would be silently wrong if nobody looked: WHAT counts as money, WHO may see whose money, and
 * whether a shop with almost no history reads correctly — which is Brand Fashion's normal state, not an edge
 * case, since it took its first order on 2026-10-19.
 */

/**
 * An order, with everything the row needs. `address_id` is NOT NULL, so a real address is borrowed.
 *
 * @param  array<string, mixed>  $attributes
 */
function dashOrder(array $attributes = []): int
{
    static $n = 0;
    $n = is_int($n) ? $n + 1 : 1;

    return (int) DB::table('orders')->insertGetId($attributes + [
        'storefront_id' => 1,
        'address_id' => DB::table('addresses')->min('id'),
        'total_price_for_order' => 100.00,
        'status' => 'pending',
        'payment_method' => 'cash',
        'order_number' => 'DASHTEST'.$n,
        'guest_name' => 'Dashboard test',
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
}

/**
 * The window block of the metrics, as the page would build it.
 *
 * @param  list<int>|null  $scope
 * @return array{orders: int, ordered: float, collected: float, cancelled: float, cancelled_orders: int, aov: float}
 */
function dashWindow(?array $scope = null): array
{
    return app(DashboardMetrics::class)->sales($scope)['window'];
}

/**
 * The daily series.
 *
 * @param  list<int>|null  $scope
 * @return list<array{date: string, orders: int, ordered: float}>
 */
function dashSeries(?array $scope = null): array
{
    return app(DashboardMetrics::class)->sales($scope)['series'];
}

/**
 * The same-weekday baseline.
 *
 * @param  list<int>|null  $scope
 * @return array{orders: float, ordered: float, weeks: int}
 */
function dashBaseline(?array $scope = null): array
{
    return app(DashboardMetrics::class)->sales($scope)['baseline'];
}

beforeEach(function () {
    // Only this file's rows. The suite runs in a transaction, but the window queries read EVERY order in the
    // database, so the assertions below are written against deltas rather than absolutes where that matters.
    DB::table('orders')->where('order_number', 'like', 'DASHTEST%')->delete();
});

it('counts as sold everything that is not cancelled, and as delivered only what arrived', function () {
    $before = dashWindow();

    dashOrder(['status' => 'pending', 'total_price_for_order' => 1000]);
    dashOrder(['status' => 'delivered', 'total_price_for_order' => 200]);
    dashOrder(['status' => 'completed', 'total_price_for_order' => 50]);
    dashOrder(['status' => 'cancelled', 'total_price_for_order' => 9999]);

    $after = dashWindow();

    // sold = everything except the cancelled one; delivered = the two that reached the customer. These are the
    // SAME two sums the customers screen uses (App\Domain\Customers\Customers), deliberately: one definition.
    expect(round($after['ordered'] - $before['ordered'], 2))->toBe(1250.00)
        ->and(round($after['collected'] - $before['collected'], 2))->toBe(250.00)
        ->and(round($after['cancelled'] - $before['cancelled'], 2))->toBe(9999.00)
        ->and($after['cancelled_orders'] - $before['cancelled_orders'])->toBe(1)
        ->and($after['orders'] - $before['orders'])->toBe(4);
});

it('draws a bar for every day of the window, including the days nothing sold', function () {
    $series = dashSeries();

    expect($series)->toHaveCount(DashboardMetrics::WINDOW_DAYS);

    $dates = array_column($series, 'date');
    expect($dates)->toBe(array_unique($dates), 'a day must appear once')
        ->and($dates[count($dates) - 1])->toBe(Carbon::now()->startOfDay()->toDateString(), 'the last bar is today')
        ->and($dates[0])->toBe(Carbon::now()->startOfDay()->subDays(DashboardMetrics::WINDOW_DAYS - 1)->toDateString());

    // a gap must be a zero-height bar, never a missing one, or a quiet week looks like a short month
    foreach ($series as $point) {
        expect($point)->toHaveKeys(['date', 'orders', 'ordered']);
    }
});

it('compares today with the SAME WEEKDAY, and says so when there is no history to compare with', function () {
    $today = Carbon::now()->startOfDay();

    // two earlier same-weekdays, and one order yesterday that must NOT reach the baseline
    dashOrder(['status' => 'pending', 'total_price_for_order' => 300, 'created_at' => $today->copy()->subWeek()->addHours(10)]);
    dashOrder(['status' => 'pending', 'total_price_for_order' => 500, 'created_at' => $today->copy()->subWeeks(2)->addHours(10)]);
    dashOrder(['status' => 'pending', 'total_price_for_order' => 9999, 'created_at' => $today->copy()->subDay()->addHours(10)]);

    $baseline = dashBaseline();

    // the baseline averages the same weekday over the weeks that HAVE data, and yesterday is not one of them
    expect($baseline['weeks'])->toBeGreaterThanOrEqual(2)
        ->and($baseline['ordered'])->toBeGreaterThan(0.0)
        ->and($baseline['ordered'])->toBeLessThan(9999.0);
});

it('never divides by zero on a shop with no orders at all — Brand Fashion\'s normal state', function () {
    // storefront 2 took its first order on 2026-10-19; a shop with nothing must read as "nothing", not crash
    // and not print a percentage it cannot compute
    $empty = app(DashboardMetrics::class);
    $sales = $empty->sales([99999]);          // a storefront id that owns no orders

    expect($sales['window']['orders'])->toBe(0)
        ->and($sales['window']['ordered'])->toBe(0.0)
        ->and($sales['window']['aov'])->toBe(0.0)
        ->and($sales['baseline']['weeks'])->toBe(0)
        ->and($sales['series'])->toHaveCount(DashboardMetrics::WINDOW_DAYS)
        ->and($empty->queue([99999])['total'])->toBe(0)
        ->and($empty->queue([99999])['oldest'])->toBeNull()
        ->and($empty->payments([99999]))->toBe([])
        ->and($empty->sold([99999]))->toBe(['top' => [], 'restock' => []]);
});

it('shows the work queue over ALL time, because an old pending order is the one worth seeing', function () {
    $old = Carbon::now()->subDays(120);
    dashOrder(['status' => 'pending', 'created_at' => $old]);
    dashOrder(['status' => 'delivered', 'created_at' => $old]);

    $queue = app(DashboardMetrics::class)->queue([1]);
    $statuses = array_column($queue['by_status'], 'status');

    // the 120-day-old PENDING order is counted (no date window), the delivered one is not
    expect($statuses)->toBe(DashboardMetrics::OPEN_STATUSES, 'the queue lists its statuses in its own order')
        ->and($queue['oldest'])->not->toBeNull()
        ->and($queue['oldest_days'])->toBeGreaterThanOrEqual(120);
});

it('keeps a scoped operator inside their own storefront, and refuses to widen on a URL', function () {
    $scoped = Staff::adminFor(2);

    $page = actingAs($scoped)->get('/manage')->assertOk();
    $scope = T::arr(Props::of($page)['scope'] ?? []);
    $options = T::arr($scope['options'] ?? []);

    expect($options)->toHaveCount(1)
        ->and(T::int(T::arr($options[0] ?? [])['id'] ?? null))->toBe(2);

    // asking for the OTHER shop in the query string must not show it: an unknown option reads as "all of
    // mine", which for this operator is storefront 2 and nothing else
    $other = T::arr(Props::of(actingAs($scoped)->get('/manage?storefront=1')->assertOk())['scope'] ?? []);
    expect(array_key_exists('selected', $other))->toBeTrue()
        ->and($other['selected'])->toBeNull();
});

it('gives an unscoped operator every shop and starts on all of them', function () {
    $page = actingAs(Staff::admin())->get('/manage')->assertOk();
    $scope = T::arr(Props::of($page)['scope'] ?? []);

    expect(array_key_exists('selected', $scope))->toBeTrue()
        ->and($scope['selected'])->toBeNull()
        ->and(count(T::arr($scope['options'] ?? [])))->toBeGreaterThanOrEqual(2)
        ->and(T::int($scope['window_days'] ?? null))->toBe(DashboardMetrics::WINDOW_DAYS);

    // and the switch actually narrows
    $one = actingAs(Staff::admin())->get('/manage?storefront=1')->assertOk();
    expect(T::int(T::arr(Props::of($one)['scope'] ?? [])['selected'] ?? null))->toBe(1);
});

it('hands the screen every block it draws, and nothing it does not', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());

    expect(array_keys($props))->toContain('scope', 'sales', 'queue', 'payments', 'top_products', 'restock', 'unseen', 'inventory', 'storefronts')
        // the four tiles the rebuild dropped must not come back by accident: each was a number nobody acted on
        ->and($props)->not->toHaveKey('stats');
});

it('stays inside its query budget, which is what keeps a live shop\'s dashboard quick', function () {
    /*
     * The budget is on THIS PAGE'S OWN data, not on the whole request: every dashboard screen pays for the
     * same shell first (the session, the account, its grants, the navigation, the unseen-orders badge), and
     * folding that in would make the budget drift whenever the shell changes. So the shell is measured
     * against a cheap manage page and subtracted.
     */
    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAs(Staff::admin())->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $shell = $count('/manage/profile');
    $home = $count('/manage');
    $own = $home - $shell;

    // Budget 20 of its own. Measured at 18 against a scratch copy carrying 250,000 synthetic orders
    // (RUN_20261021.md), where the OLD page ran 20 for far less content. A rise means a block started
    // asking per row.
    expect($own)->toBeLessThanOrEqual(20, "the dashboard home ran {$own} queries of its own ({$home} total, {$shell} of them the shell)")
        ->and($own)->toBeGreaterThan(0, 'the measurement itself is broken if the page adds nothing');
});
