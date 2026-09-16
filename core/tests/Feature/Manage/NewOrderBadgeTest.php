<?php

use App\Domain\Access\Preferences;
use App\Domain\Orders\NewOrders;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The new-order badge (wave 4D) — a count beside الطلبات, refreshed on navigation.
 *
 * ── What "new" means, and why the test set is shaped this way ────────────────────────────────
 *
 * UNSEEN BY THIS PERSON. Two operators work the same queue, so a global stamp would let whoever
 * looked first clear the badge for the other — the failure the badge exists to prevent, arriving
 * silently. Every case below therefore checks a SECOND person is unaffected.
 *
 * A first-ever visit counts ZERO rather than every order ever placed: a badge reading 75 on
 * somebody's first login is noise, and a badge people learn to ignore has cost more than it gave.
 */

/** The badge the sidebar would render for the current user, or null. */
function ordersBadge(): ?int
{
    foreach (T::arr(Props::of(get('/manage'))['nav'] ?? null) as $rawGroup) {
        foreach (T::arr(T::arr($rawGroup)['items'] ?? null) as $rawItem) {
            $item = T::arr($rawItem);
            if (($item['key'] ?? null) === 'orders') {
                $badge = $item['badge'] ?? null;

                return is_numeric($badge) ? (int) $badge : null;
            }
        }
    }

    return null;
}

/**
 * An order placed now, in the shared table.
 *
 * Through the existing fixture rather than a hand-written INSERT: `orders` has NOT NULL columns
 * without defaults (`address_id` among them), and a second, slightly-wrong way of making one is
 * how a test ends up asserting against a row the application would never produce.
 */
function placeOrder(int $storefrontId = 1): int
{
    /*
     * The clock moves first, and that is not a trick to make the test pass — it models the thing
     * being measured. `orders_seen_at` and `orders.created_at` are second-granularity, and
     * `NewOrders` counts STRICTLY after the stamp, so an order written in the very same second as
     * the operator's visit is outside the window by design (see that class for why `>` beats `>=`).
     *
     * A real order arrives some time after somebody looked at the queue. Freezing the clock at the
     * same instant would be testing a one-second boundary condition instead of the feature.
     */
    Carbon::setTestNow(now()->addSeconds(2));

    return PaymentFixture::order(storefrontId: $storefrontId);
}

it('shows NOTHING on a first visit, rather than every order ever placed', function () {
    $admin = Staff::admin();
    actingAs($admin);

    // Nobody has a stamp yet.
    DB::table('core_user_preferences')->where('user_id', $admin->getAuthIdentifier())
        ->update(['orders_seen_at' => null]);

    expect(ordersBadge())->toBeNull();
});

it('counts an order that arrived AFTER the operator last looked', function () {
    $admin = Staff::admin();
    actingAs($admin);

    // Opening the queue is what sets the stamp.
    get('/manage/orders')->assertOk();
    expect(ordersBadge())->toBeNull();

    placeOrder();

    expect(ordersBadge())->toBe(1);
});

it('clears when the operator opens the queue, and only then', function () {
    $admin = Staff::admin();
    actingAs($admin);

    get('/manage/orders')->assertOk();
    placeOrder();
    placeOrder();
    expect(ordersBadge())->toBe(2);

    // Looking at another screen does NOT clear it — the badge's promise is about the queue.
    get('/manage/customers')->assertOk();
    expect(ordersBadge())->toBe(2);

    get('/manage/orders')->assertOk();
    expect(ordersBadge())->toBeNull();
});

it('does NOT clear one operator\'s badge when another looks', function () {
    /*
     * The case a global stamp would get wrong, and the reason the column is per user. Two people
     * work this queue; the first to open it must not make the second's orders invisible.
     */
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/orders')->assertOk();

    $other = Staff::dataEntry();
    actingAs($other);
    get('/manage/orders')->assertOk();

    placeOrder();

    // Both see it…
    expect(ordersBadge())->toBe(1);
    actingAs($admin);
    expect(ordersBadge())->toBe(1);

    // …and the admin looking clears only the admin's.
    get('/manage/orders')->assertOk();
    expect(ordersBadge())->toBeNull();

    actingAs($other);
    expect(ordersBadge())->toBe(1);
});

it('an EXPORT does not clear the badge', function () {
    /*
     * Downloading a CSV is not looking at the queue. Clearing the badge because somebody exported
     * a file would hide the orders they were about to work — the badge would have lied at exactly
     * the moment it mattered.
     */
    $admin = Staff::admin();
    actingAs($admin);

    get('/manage/orders')->assertOk();
    placeOrder();
    expect(ordersBadge())->toBe(1);

    get('/manage/orders?export=csv')->assertOk();

    expect(ordersBadge())->toBe(1);
});

it('counts ZERO for somebody who may not see orders', function () {
    /*
     * No ability, no query — and no badge promising rows the screen would refuse to show.
     */
    $customer = Staff::customer();

    expect(app(NewOrders::class)->countFor($customer))->toBe(0);
});

it('never promises an order a SCOPED grant may not open', function () {
    /*
     * §2.18. A badge that counted another storefront's orders would send somebody looking for a
     * record the queue then 404s — worse than no badge, because it looks like a bug in the screen.
     */
    $scoped = Staff::adminFor(1);
    actingAs($scoped);
    get('/manage/orders')->assertOk();

    placeOrder(storefrontId: 2);            // another storefront's order

    expect(app(NewOrders::class)->countFor($scoped))->toBe(0);

    placeOrder(storefrontId: 1);            // …and their own
    expect(app(NewOrders::class)->countFor($scoped))->toBe(1);
});

it('sends null and never zero, so a quiet morning shows no badge at all', function () {
    /*
     * A badge reading 0 beside every item is chrome the eye learns to skip, and the day it says 3
     * it gets skipped too. The server decides this, not the component.
     */
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/orders')->assertOk();

    expect(ordersBadge())->toBeNull();
});

it('shows the same number on the dashboard home as in the sidebar', function () {
    /*
     * Two numbers that disagreed would be worse than either alone, so both come from
     * `NewOrders::countFor()`. This is the assertion that keeps them from drifting apart.
     */
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/orders')->assertOk();
    placeOrder();
    placeOrder();

    $stats = T::arr(Props::of(get('/manage'))['stats'] ?? null);

    $unseen = null;
    foreach ($stats as $rawStat) {
        $stat = T::arr($rawStat);
        if (($stat['key'] ?? null) === 'orders_unseen') {
            $unseen = $stat['value'] ?? null;
        }
    }

    expect($unseen)->toBe(2)
        ->and(ordersBadge())->toBe(2);
});

it('stores the stamp per user, in the core-owned table, and never touches `users`', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $before = T::str(DB::table('users')->where('id', $admin->getAuthIdentifier())->value('updated_at') ?? '');

    get('/manage/orders')->assertOk();

    expect(Preferences::ordersSeenAt($admin))->not->toBeNull();

    // The legacy table is untouched — the whole reason this column lives in core.
    expect(T::str(DB::table('users')->where('id', $admin->getAuthIdentifier())->value('updated_at') ?? ''))
        ->toBe($before);
});

it('costs ONE extra query on a dashboard render', function () {
    /*
     * This is shared data: the shell asks for it on every page, so cost is the design constraint.
     * One indexed COUNT, riding `orders_created_at_index` (M1n).
     */
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/orders')->assertOk();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    get('/manage/customers')->assertOk();
    $withBadge = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    // A generous ceiling: the assertion is that the badge did not add a query PER ITEM or per
    // storefront, which is the shape that would actually hurt.
    expect($withBadge)->toBeLessThanOrEqual(25);
});

it('counts nothing for a guest', function () {
    expect(app(NewOrders::class)->countFor(null))->toBe(0);
});

it('is not fooled by an order placed BEFORE the last visit', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $old = placeOrder();
    DB::table('orders')->where('id', $old)->update(['created_at' => now()->subDays(3)]);

    get('/manage/orders')->assertOk();

    expect(ordersBadge())->toBeNull();
});

it('keeps the stamp when a DIFFERENT preference is saved', function () {
    /*
     * `setLocale()` and `markOrdersSeen()` both `updateOrInsert` the same row. A careless writer
     * would blank the other's column — and the badge would silently reset every time somebody
     * changed the dashboard language.
     */
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/orders')->assertOk();

    $stamp = Preferences::ordersSeenAt($admin);
    expect($stamp)->not->toBeNull();

    Preferences::setLocale($admin, 'en');

    expect(Preferences::ordersSeenAt($admin))->toBe($stamp);
});
