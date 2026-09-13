<?php

use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * 🟠-3 — a SCOPED grant must not read another storefront's orders, and
 * 🟠-2 — the provider/method filters must work on a database with no payment history.
 *
 * ── 🟠-3, and why it was not caught by the existing tests ───────────────────────────────────
 *
 * AGENTS §2.18 promises a scoped grant cannot reach another storefront's data. Every catalogue
 * screen keeps it through the `{storefront}` path segment and `EnsureStorefrontScope`. The order
 * queue has no such segment — deliberately, the team works one queue — so nothing narrowed it, and
 * the promise was aspirational for exactly the screen that shows customer names, phone numbers and
 * money. The wave-4C authorisation tests all used an UNSCOPED admin, so the gap was invisible.
 *
 * The UI stays one queue; a scoped operator simply has a smaller one.
 *
 * ── 🟠-2, and why the earlier fix was not enough ────────────────────────────────────────────
 *
 * The provider/method allow-list was first built from `orders.paid_via_*`, then widened to include
 * `payment_statuses`. Both are HISTORY — and a freshly migrated production database has none, which
 * is the state this filter is first used in. `resolvedFilters()` drops a value outside its
 * allow-list, so with no history the filters were silently disabled. The list now also includes
 * what is CONFIGURED, and the test below runs with `payment_statuses` emptied on purpose.
 */

/** An order on one storefront, with a number that is easy to assert on. */
function scopedOrder(int $storefrontId): int
{
    return PaymentFixture::order(total: 42.0, storefrontId: $storefrontId);
}

/** @return list<int> */
function visibleOrderIds(User $actor): array
{
    $props = Props::of(actingAs($actor)->get('/manage/orders?per_page=100')->assertOk());
    $out = [];
    foreach (T::arr(T::arr($props['table'] ?? [])['data'] ?? []) as $row) {
        $out[] = T::int(T::arr($row)['id'] ?? 0);
    }

    return $out;
}

it('shows a SCOPED grant only its own storefront’s orders', function () {
    $mine = scopedOrder(1);
    $theirs = scopedOrder(Storefront::BRAND_FASHION_ID);

    $scoped = Staff::adminFor(1);
    $visible = visibleOrderIds($scoped);

    expect($visible)->toContain($mine);
    expect($visible)->not->toContain($theirs);

    // Every row on the page belongs to the scope, not just the two this test made.
    $props = Props::of(actingAs($scoped)->get('/manage/orders?per_page=100')->assertOk());
    foreach (T::arr(T::arr($props['table'] ?? [])['data'] ?? []) as $row) {
        expect(T::arr($row)['storefront_id'] ?? null)->toBe(1);
    }
});

it('does not offer a scoped grant a storefront it cannot see', function () {
    // Cosmetic, but it leaked a fact: the option named the other storefront, and choosing it
    // returned an empty list — a control that looks broken rather than absent.
    $scopedProps = Props::of(actingAs(Staff::adminFor(1))->get('/manage/orders')->assertOk());
    $values = [];
    foreach (T::arr(T::arr($scopedProps['filters'] ?? [])['storefronts'] ?? []) as $option) {
        $values[] = T::str(T::arr($option)['value'] ?? '');
    }

    expect($values)->toBe(['1']);

    // …and an unscoped admin is still offered both.
    $adminProps = Props::of(actingAs(Staff::admin())->get('/manage/orders')->assertOk());
    $all = [];
    foreach (T::arr(T::arr($adminProps['filters'] ?? [])['storefronts'] ?? []) as $option) {
        $all[] = T::str(T::arr($option)['value'] ?? '');
    }

    expect($all)->toContain('1')->toContain((string) Storefront::BRAND_FASHION_ID);
});

it('leaves an UNSCOPED admin seeing everything, exactly as before', function () {
    $mine = scopedOrder(1);
    $theirs = scopedOrder(Storefront::BRAND_FASHION_ID);

    $visible = visibleOrderIds(Staff::admin());

    expect($visible)->toContain($mine)->toContain($theirs);
});

it('404s an out-of-scope order DETAIL, never 403', function () {
    $theirs = scopedOrder(Storefront::BRAND_FASHION_ID);
    $mine = scopedOrder(1);
    $scoped = Staff::adminFor(1);

    // 404 and not 403, for the reason §3.11.14 gives: an order number is guessable, and a 403
    // would confirm the guess.
    actingAs($scoped)->get("/manage/orders/{$theirs}")->assertNotFound();
    actingAs($scoped)->get("/manage/orders/{$mine}")->assertOk();

    // …and an unscoped admin still reaches both.
    actingAs(Staff::admin())->get("/manage/orders/{$theirs}")->assertOk();
});

it('does not let a scoped grant reach another storefront’s order through the WRITE verbs either', function () {
    $theirs = scopedOrder(Storefront::BRAND_FASHION_ID);
    $scoped = Staff::adminFor(1);

    // `advance` and `cancel` resolve the order through the same domain, which refuses an order it
    // cannot see — asserted here because a read-only fix would be a half fix.
    actingAs($scoped)->put("/manage/orders/{$theirs}/status", ['status' => 'processing'])->assertNotFound();
    actingAs($scoped)->post("/manage/orders/{$theirs}/cancel")->assertNotFound();

    expect(T::str(DB::table('orders')->where('id', $theirs)->value('status')))->toBe('pending');
});

it('offers the CONFIGURED providers as filters even with NO payment history at all', function () {
    // The state a freshly migrated production database is actually in.
    DB::table('payment_statuses')->delete();
    DB::table('orders')->update(['paid_via_provider' => null, 'paid_via_method' => null]);

    // …with one contract configured, which is what the runbook's cutover step creates.
    $provider = PaymentFixture::paymob();
    PaymentFixture::method($provider, 'card');

    $props = Props::of(actingAs(Staff::admin())->get('/manage/orders')->assertOk());

    $providers = [];
    foreach (T::arr(T::arr($props['filters'] ?? [])['providers'] ?? []) as $option) {
        $providers[] = T::str(T::arr($option)['value'] ?? '');
    }
    $methods = [];
    foreach (T::arr(T::arr($props['filters'] ?? [])['methods'] ?? []) as $option) {
        $methods[] = T::str(T::arr($option)['value'] ?? '');
    }

    expect($providers)->toContain('paymob')
        ->and($methods)->toContain('card');
});

it('and the filter BITES with no payment history: an unmatched provider returns nothing', function () {
    DB::table('payment_statuses')->delete();
    DB::table('orders')->update(['paid_via_provider' => null, 'paid_via_method' => null]);

    $provider = PaymentFixture::paymob();
    PaymentFixture::method($provider, 'card');

    $admin = Staff::admin();

    $total = static function (string $url) use ($admin): int {
        $props = Props::of(actingAs($admin)->get($url)->assertOk());

        return T::int(T::arr(T::arr($props['table'] ?? [])['meta'] ?? [])['total'] ?? -1);
    };

    $all = $total('/manage/orders');
    expect($all)->toBeGreaterThan(0);

    // The value is ACCEPTED (it is configured) and it MATCHES NOTHING (no order was paid through
    // it yet). Before the fix the value was dropped and this returned the whole list — the exact
    // shape of a dead filter.
    expect($total('/manage/orders?filters[provider]=paymob'))->toBe(0);
});

it('reports a payment finding on the order it belongs to, and only to a payments grant', function () {
    // The 🔴-1 surface: the finding reaches the screen, and clearing it is payment work.
    $orderId = scopedOrder(1);
    DB::table('payment_reconciliation_findings')->insert([
        'order_id' => $orderId, 'provider' => 'paymob', 'kind' => 'refund_or_void',
        'outcome' => 'refunded', 'order_status' => 'processing', 'amount_cents' => 4200,
        'detail' => '{}', 'created_at' => now(),
    ]);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/orders/{$orderId}")->assertOk());
    $findings = T::arr($props['findings'] ?? []);

    expect($findings)->toHaveCount(1)
        ->and(T::str(T::arr($findings[0] ?? [])['kind'] ?? ''))->toBe('refund_or_void')
        ->and(T::arr($props['abilities'] ?? [])['resolve_findings'] ?? null)->toBeTrue();

    // Data-entry sees the finding (it explains the order) but may not clear it.
    $entryProps = Props::of(actingAs(Staff::dataEntry())->get("/manage/orders/{$orderId}")->assertOk());
    expect(T::arr($entryProps['abilities'] ?? [])['resolve_findings'] ?? null)->toBeFalse();

    $findingId = T::int(DB::table('payment_reconciliation_findings')->where('order_id', $orderId)->value('id'));
    actingAs(Staff::dataEntry())
        ->post("/manage/orders/{$orderId}/findings/{$findingId}/resolve", ['note' => 'not mine to clear'])
        ->assertForbidden();

    expect(DB::table('payment_reconciliation_findings')->where('id', $findingId)->value('resolved_at'))->toBeNull();
});

it('clears a finding WITH a reason, and never by deleting the row', function () {
    $orderId = scopedOrder(1);
    DB::table('payment_reconciliation_findings')->insert([
        'order_id' => $orderId, 'provider' => 'paymob', 'kind' => 'amount_mismatch',
        'outcome' => 'success', 'order_status' => 'pending', 'amount_cents' => 1,
        'detail' => '{}', 'created_at' => now(),
    ]);
    $findingId = T::int(DB::table('payment_reconciliation_findings')->where('order_id', $orderId)->value('id'));

    // A reason is required: "resolved" with no note is indistinguishable from ignoring it.
    actingAs(Staff::admin())->post("/manage/orders/{$orderId}/findings/{$findingId}/resolve", ['note' => ''])
        ->assertSessionHasErrors('note');

    actingAs(Staff::admin())
        ->post("/manage/orders/{$orderId}/findings/{$findingId}/resolve", ['note' => 'Paymob portal shows 1.00 test charge; ignored'])
        ->assertRedirect();

    $row = Row::cast(T::one(DB::table('payment_reconciliation_findings')->where('id', $findingId)));

    expect(Row::nstr($row, 'resolved_at'))->not->toBeNull()
        ->and(Row::nint($row, 'resolved_by'))->not->toBeNull()
        ->and(Row::nstr($row, 'note'))->toContain('test charge')
        // The row STAYS: it is the evidence that someone looked.
        ->and(DB::table('payment_reconciliation_findings')->where('id', $findingId)->exists())->toBeTrue();

    // …and it cannot be cleared twice.
    actingAs(Staff::admin())
        ->post("/manage/orders/{$orderId}/findings/{$findingId}/resolve", ['note' => 'again'])
        ->assertSessionHasErrors('note');
});
