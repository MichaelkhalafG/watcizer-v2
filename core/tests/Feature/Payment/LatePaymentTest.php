<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Reference;
use App\Domain\Inventory\StockTarget;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\LatePayment;
use App\Domain\Orders\UnpaidOrders;
use App\Domain\Payment\CallbackPolicy;
use App\Domain\Payment\Landing;
use App\Mail\PaymentAlertAdmin;
use App\Storefront\StorefrontUrls;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * ── A payment that succeeds AFTER the order stopped waiting for it (2026-11-05, order 000092) ────
 *
 * Production, read-only: order 000092 (Watchizer, valU, 4,445 EGP) was declined, cancelled in the
 * same second with its stock returned, and then CHARGED two minutes later by a retry on the same
 * payment page (transaction 566147894). The callback recorded a finding and left the order
 * cancelled; nobody was told. Five of seven valU orders since 2026-10-25 were cancelled within
 * minutes of a decline.
 *
 * Two changes, and every path between them is a test here:
 *
 *   1. a decline does not cancel — the order waits for the retry, the expiry ends the wait;
 *   2. a success on a cancelled order brings it back when its stock is still there (reserved again
 *      through the ledger, all or nothing), and raises a finding + an e-mail to the shop when not.
 *
 * Every fixture order holds a REAL reservation (`commitOrder`), so "stock stays correct through the
 * ledger" is asserted on the column, on the movements and on their sum — not on a status alone.
 */

const LP_SECRET = 'late-payment-secret';

beforeEach(function () {
    config([
        'compat.unpaid.expire_after_minutes' => 60,
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,       // the suite never commits; see config
        'notifications.send.park' => false,
    ]);
    StorefrontMailboxFixture::recipients(1, ['ops@example.test']);
    DB::table('storefront_payment_providers')->delete();
    $provider = PaymentFixture::paymob(hmacSecret: LP_SECRET);
    PaymentFixture::method($provider, 'card', '4001');
    PaymentFixture::method($provider, 'valu', '4002', 1, true, 'فاليو', 'valU');
    Mail::fake();
});

/**
 * A pending online order of a known shopper with ONE unit of a real product reserved.
 *
 * @return array{order: int, number: string, product: int, before: int}
 */
function lpOrder(float $total = 4445.0, string $email = 'late@example.test', int $skip = 0): array
{
    $row = T::one(
        DB::table('catalog_products')
            ->whereNull('deleted_at')->where('is_active', 1)->where('stock_express', '>=', 2)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')->offset($skip)
    );
    $productId = Row::int(Row::cast($row), 'id');
    $before = Row::int(Row::cast($row), 'stock_express');

    $orderId = PaymentFixture::order(total: $total);
    DB::table('orders')->where('id', $orderId)->update([
        'payment_method' => 'paymob', 'guest_email' => $email, 'guest_phone' => '010'.random_int(10000000, 99999999),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 1, 'piece_price' => number_format($total, 2, '.', ''), 'total_price' => number_format($total, 2, '.', ''),
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId, null, 1);

    return ['order' => $orderId, 'number' => PaymentFixture::orderNumber($orderId), 'product' => $productId, 'before' => $before];
}

/**
 * The provider's callback as the shopper's browser delivers it; returns where the browser is sent.
 *
 * @param  array{order: int, number: string, product: int, before: int}  $f
 */
function lpCallback(array $f, bool $success, int $transactionId, string $integrationId = '4001', ?int $amountMinor = null): string
{
    $total = T::float(DB::table('orders')->where('id', $f['order'])->value('total_price_for_order'));
    $payload = PaymentFixture::callback(
        $f['number'], $amountMinor ?? (int) round($total * 100), success: $success, secret: LP_SECRET,
        transactionId: $transactionId, integrationId: $integrationId,
    );
    $response = get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload));
    $response->assertRedirect();

    return T::str($response->headers->get('location'));
}

/** @param  array{order: int, number: string, product: int, before: int}  $f */
function lpStatus(array $f): string
{
    return T::str(DB::table('orders')->where('id', $f['order'])->value('status'));
}

function lpStock(int $productId): int
{
    return T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));
}

/**
 * @param  array{order: int, number: string, product: int, before: int}  $f
 * @return list<string> the reasons of this order's stock movements, in the order they were written
 */
function lpReasons(array $f): array
{
    return array_values(array_map(
        fn (mixed $r): string => T::str($r),
        DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $f['order'])->orderBy('id')->pluck('reason')->all(),
    ));
}

/**
 * @param  array{order: int, number: string, product: int, before: int}  $f
 * @return list<array{kind: string, outcome: string|null, resolved: bool, detail: array<mixed>}>
 */
function lpFindings(array $f): array
{
    $out = [];
    foreach (DB::table('payment_reconciliation_findings')->where('order_id', $f['order'])->orderBy('id')->get() as $raw) {
        $row = Row::cast($raw);
        $detail = json_decode(Row::nstr($row, 'detail') ?? '[]', true);
        $out[] = [
            'kind' => Row::str($row, 'kind'),
            'outcome' => Row::nstr($row, 'outcome'),
            'resolved' => Row::nstr($row, 'resolved_at') !== null,
            'detail' => is_array($detail) ? $detail : [],
        ];
    }

    return $out;
}

/**
 * @param  array{order: int, number: string, product: int, before: int}  $f
 * @return list<string>
 */
function lpMailKinds(array $f): array
{
    return array_map(fn (array $row): string => T::str($row['kind']), OrderMailer::forOrder($f['order']));
}

/**
 * What `orders:expire-unpaid` does for one order when the window ends.
 *
 * @param  array{order: int, number: string, product: int, before: int}  $f
 */
function lpExpire(array $f): bool
{
    return app(UnpaidOrders::class)->cancel($f['order'], UnpaidOrders::EXPIRED);
}

/** The ledger and the column agree for this product — `inventory:verify`'s invariant 2, for one row. */
function lpLedgerMatches(int $productId): bool
{
    return app(InventoryService::class)->ledgerQuantity(StockTarget::product($productId), InventoryService::BUCKET_EXPRESS) === lpStock($productId)
        || DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->where('bucket', 'express')->doesntExist();
}

// ── 1. fail, then success ────────────────────────────────────────────────────────────────────────

it('FAIL THEN SUCCESS: the decline leaves the order waiting, the retry pays it — card and valU', function (string $method, string $integration) {
    $f = lpOrder();
    expect(lpStock($f['product']))->toBe($f['before'] - 1);

    $landing = lpCallback($f, success: false, transactionId: 810001, integrationId: $integration);
    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::FAILED, $f['number']))
        ->and(lpStatus($f))->toBe('pending')
        ->and(lpStock($f['product']))->toBe($f['before'] - 1)          // still reserved
        ->and(lpReasons($f))->toBe(['order'])
        ->and(lpMailKinds($f))->toBe([]);

    $landing = lpCallback($f, success: true, transactionId: 810002, integrationId: $integration);
    $order = T::one(DB::table('orders')->where('id', $f['order']));

    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::PAID, $f['number']))
        ->and(lpStatus($f))->toBe('processing')
        ->and(Row::nstr($order, 'paid_via_provider'))->toBe('paymob')
        ->and(Row::nstr($order, 'paid_via_method'))->toBe($method)
        ->and(lpReasons($f))->toBe(['order'])                           // one reservation, never released
        ->and(lpStock($f['product']))->toBe($f['before'] - 1)
        ->and(lpFindings($f))->toBe([])
        ->and(lpMailKinds($f))->toEqualCanonicalizing([OrderMailer::KIND_CUSTOMER, OrderMailer::KIND_ADMIN]);

    // The Paymob amount equals the stored total, on the attempt recorded as the payment.
    $paid = T::one(DB::table('payment_statuses')->where('order_id', $f['order'])->where('success', 'true'));
    expect(Row::int($paid, 'amount_cents'))->toBe(444500)
        ->and(Row::int($paid, 'amount_cents'))->toBe((int) round(T::float($order->total_price_for_order) * 100))
        ->and(Row::nstr($paid, 'method'))->toBe($method);
})->with([
    'card' => ['card', '4001'],
    'valU' => ['valu', '4002'],
]);

// ── 2. fail, then fail ───────────────────────────────────────────────────────────────────────────

it('FAIL THEN FAIL: two attempts on record, the order still waiting; the expiry cancels it and returns the stock ONCE', function () {
    $f = lpOrder();

    lpCallback($f, success: false, transactionId: 820001, integrationId: '4002');
    lpCallback($f, success: false, transactionId: 820002, integrationId: '4002');

    expect(lpStatus($f))->toBe('pending')
        ->and(DB::table('payment_statuses')->where('order_id', $f['order'])->count())->toBe(2)
        ->and(lpReasons($f))->toBe(['order'])
        ->and(lpFindings($f))->toBe([])
        ->and(lpMailKinds($f))->toBe([]);

    // It IS what the sweep looks for: still unpaid, a reservation, no successful attempt.
    DB::table('orders')->where('id', $f['order'])->update(['created_at' => now()->subMinutes(61)]);
    expect(app(UnpaidOrders::class)->expiredIds(60))->toContain($f['order']);

    expect(lpExpire($f))->toBeTrue()
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed'])
        ->and(lpStock($f['product']))->toBe($f['before'])
        ->and(lpExpire($f))->toBeFalse()                                // a second sweep moves nothing
        ->and(lpReasons($f))->toBe(['order', 'payment_failed'])
        ->and(lpLedgerMatches($f['product']))->toBeTrue();

    // The shopper is told once, at the end of the window, and the shop is told it was a DECLINE.
    expect(lpMailKinds($f))->toEqualCanonicalizing([OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]);
    foreach (DB::table('integration_outbox')->where('aggregate_id', $f['order'])->where('channel', 'mail')->pluck('payload')->all() as $payload) {
        expect(T::str($payload))->toContain('"reason":"declined"');
    }

    // One more decline on the cancelled order: an attempt, and NO finding — no money moved.
    lpCallback($f, success: false, transactionId: 820003, integrationId: '4002');
    expect(lpStatus($f))->toBe('cancelled')
        ->and(DB::table('payment_statuses')->where('order_id', $f['order'])->count())->toBe(3)
        ->and(lpFindings($f))->toBe([]);
});

// ── 3. success after the window ──────────────────────────────────────────────────────────────────

it('SUCCESS AFTER THE WINDOW: the cancelled order comes back, its stock reserved again through the ledger — card and valU', function (string $method, string $integration) {
    $f = lpOrder();
    lpCallback($f, success: false, transactionId: 830001, integrationId: $integration);
    expect(lpExpire($f))->toBeTrue()
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpStock($f['product']))->toBe($f['before']);

    $landing = lpCallback($f, success: true, transactionId: 830002, integrationId: $integration);
    $order = T::one(DB::table('orders')->where('id', $f['order']));

    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::PAID, $f['number']))
        ->and(lpStatus($f))->toBe('processing')
        ->and(Row::nstr($order, 'paid_via_provider'))->toBe('paymob')
        ->and(Row::nstr($order, 'paid_via_method'))->toBe($method)
        // The ledger tells the whole story, one movement per event, and the column follows it.
        ->and(lpReasons($f))->toBe(['order', 'payment_failed', 'order_reinstate'])
        ->and(lpStock($f['product']))->toBe($f['before'] - 1)
        ->and(lpLedgerMatches($f['product']))->toBeTrue()
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeFalse()
        ->and(app(InventoryService::class)->isReinstated($f['order']))->toBeTrue()
        // The attempt that landed on the cancelled order IS the payment.
        ->and(T::str(DB::table('payment_statuses')->where('pay_transaction_id', 830002)->value('success')))->toBe('true')
        ->and(T::int(DB::table('payment_statuses')->where('pay_transaction_id', 830002)->value('amount_cents')))->toBe(444500)
        // Nothing for a person to clear, and the customer has their confirmation.
        ->and(lpFindings($f))->toBe([])
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_CUSTOMER);
    expect(lpMailKinds($f))->toContain(OrderMailer::KIND_ADMIN);

    // The reinstatement is on the activity record, with the transaction that caused it.
    $log = T::one(DB::table('core_activity_log')->where('subject_type', 'orders')->where('subject_id', $f['order'])->orderByDesc('id'));
    expect(Row::str($log, 'changes'))->toContain('late payment after cancellation')->toContain('830002');

    // A reinstated order that a person cancels afterwards gives its unit back — under its own reason,
    // because `payment_failed` is already spent on this line — and exactly once.
    $movements = app(InventoryService::class)->releaseOrder($f['order'], 'order_cancel', null, 1, 'customer changed their mind');
    expect($movements)->toHaveCount(1)
        ->and(lpReasons($f))->toBe(['order', 'payment_failed', 'order_reinstate', 'reinstate_cancel'])
        ->and(lpStock($f['product']))->toBe($f['before'])
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeTrue()
        ->and(app(InventoryService::class)->releaseOrder($f['order'], 'order_cancel', null, 1))->toBe([])
        ->and(lpLedgerMatches($f['product']))->toBeTrue();
    expect(T::str(DB::table('inventory_movements')->where('reference_id', $f['order'])->where('reason', 'reinstate_cancel')->value('note')))
        ->toBe('order_cancel — customer changed their mind');
})->with([
    'card' => ['card', '4001'],
    'valU' => ['valu', '4002'],
]);

// ── 4. the stock was sold to someone else in between ─────────────────────────────────────────────

it('STOCK SOLD IN BETWEEN: nothing is reserved, the order stays cancelled, a finding is raised and the shop is e-mailed', function () {
    $f = lpOrder();
    expect(lpExpire($f))->toBeTrue();

    // Somebody else bought the last units while the order was cancelled.
    app(InventoryService::class)->set(StockTarget::product($f['product']), InventoryService::BUCKET_EXPRESS, 0, 'manual');
    expect(lpStock($f['product']))->toBe(0);
    $movements = DB::table('inventory_movements')->count();

    $landing = lpCallback($f, success: true, transactionId: 840001, integrationId: '4002');

    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::PENDING, $f['number']))   // "being confirmed", never "paid"
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpStock($f['product']))->toBe(0)                                                   // no oversell to make it fit
        ->and(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(lpReasons($f))->toBe(['order', 'payment_failed'])
        // The money is on record, NOT as this order's payment…
        ->and(T::str(DB::table('payment_statuses')->where('pay_transaction_id', 840001)->value('success')))->toBe('false')
        ->and(T::str(DB::table('payment_statuses')->where('pay_transaction_id', 840001)->value('outcome')))->toBe('success');

    // …with a finding that says why, open…
    $findings = lpFindings($f);
    expect($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe(CallbackPolicy::KIND_TERMINAL)
        ->and($findings[0]['outcome'])->toBe(CallbackPolicy::OUTCOME_SUCCESS)
        ->and($findings[0]['resolved'])->toBeFalse()
        ->and($findings[0]['detail']['reinstated'] ?? null)->toBeFalse()
        ->and($findings[0]['detail']['refused'] ?? null)->toBe(LatePayment::NO_STOCK)
        ->and($findings[0]['detail']['transaction'] ?? null)->toBe('840001');

    // …and the shop told NOW, in words it can act on, with the customer's phone one tap away.
    expect(lpMailKinds($f))->toContain(OrderMailer::KIND_PAYMENT_ALERT);
    Mail::assertSent(PaymentAlertAdmin::class, fn (PaymentAlertAdmin $m) => $m->hasTo('ops@example.test'));
    $html = '';
    Mail::assertSent(PaymentAlertAdmin::class, function (PaymentAlertAdmin $m) use (&$html): bool {
        $html = $m->render();

        return true;
    });
    expect($html)->toContain('the stock is no longer available')
        ->and($html)->toContain('والمخزون لم يعد متاحًا')
        ->and($html)->toContain('transaction 840001')
        ->and($html)->toContain('href="tel:+')
        ->and($html)->toContain('#'.$f['number']);
    // The customer is NOT told "confirmed" for an order that is not.
    expect(lpMailKinds($f))->not->toContain(OrderMailer::KIND_CUSTOMER);
});

it('is ALL OR NOTHING across lines: one line out of stock means not one unit is reserved again', function () {
    $f = lpOrder();
    // A second line on another product, reserved like the first.
    $other = T::one(
        DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1)->where('stock_express', '>=', 2)
            ->where('id', '!=', $f['product'])
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderByDesc('id')
    );
    $otherId = Row::int(Row::cast($other), 'id');
    $otherBefore = lpStock($otherId);
    $lineId = (int) DB::table('order_items')->insertGetId([
        'order_id' => $f['order'], 'product_id' => $otherId, 'offer_id' => null, 'quantity' => 1,
        'piece_price' => '0.00', 'total_price' => '0.00', 'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Reserved through the service, exactly as `commitOrder()` would have for a two-line order.
    $service = app(InventoryService::class);
    $service->adjust(StockTarget::product($otherId), InventoryService::BUCKET_EXPRESS, -1, 'order', Reference::orderLine($f['order'], $lineId), null, 1);
    $firstBefore = $f['before'];
    expect(lpStock($otherId))->toBe($otherBefore - 1);

    expect(lpExpire($f))->toBeTrue()
        ->and(lpStock($f['product']))->toBe($firstBefore)
        ->and(lpStock($otherId))->toBe($otherBefore);

    // The SECOND line's product sells out; the first is still on the shelf.
    $service->set(StockTarget::product($otherId), InventoryService::BUCKET_EXPRESS, 0, 'manual');

    lpCallback($f, success: true, transactionId: 840010);

    expect(lpStatus($f))->toBe('cancelled')
        ->and(lpStock($f['product']))->toBe($firstBefore, 'the line that WAS in stock must not stay reserved when the other could not be')
        ->and(lpStock($otherId))->toBe(0)
        ->and(DB::table('inventory_movements')->where('reference_id', $f['order'])->where('reason', InventoryService::REINSTATE_REASON)->count())->toBe(0)
        ->and(lpFindings($f)[0]['detail']['refused'] ?? null)->toBe(LatePayment::NO_STOCK);
});

// ── 5. the amount ────────────────────────────────────────────────────────────────────────────────

it('never brings an order back on an amount that is not its total', function () {
    $f = lpOrder(4445.0);
    expect(lpExpire($f))->toBeTrue();

    // 44.45 EGP instead of 4,445.00: a success by name, not this order's payment.
    $landing = lpCallback($f, success: true, transactionId: 850001, amountMinor: 4445);

    $findings = lpFindings($f);
    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::PENDING, $f['number']))
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed'])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe(CallbackPolicy::KIND_AMOUNT)
        ->and(T::str(DB::table('payment_statuses')->where('pay_transaction_id', 850001)->value('success')))->toBe('false')
        // Money moved and the order does not show it: the shop is told here too.
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_PAYMENT_ALERT);
});

// ── 6. who cancelled it ──────────────────────────────────────────────────────────────────────────

it('does not bring back an order a PERSON cancelled: finding, e-mail, nothing reserved', function () {
    $f = lpOrder();
    // What the dashboard's cancel does to the stock and the status.
    app(InventoryService::class)->releaseOrder($f['order'], 'order_cancel', null, 1, 'customer phoned');
    DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);

    lpCallback($f, success: true, transactionId: 860001);

    $findings = lpFindings($f);
    expect(lpStatus($f))->toBe('cancelled')
        ->and(lpReasons($f))->toBe(['order', 'order_cancel'])
        ->and(lpStock($f['product']))->toBe($f['before'])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]['detail']['refused'] ?? null)->toBe(LatePayment::BY_OPERATOR)
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_PAYMENT_ALERT);
});

it('brings a SUPERSEDED order back and flags the pair: the shopper has a newer live order', function () {
    $f = lpOrder(email: 'twice@example.test');
    expect(app(UnpaidOrders::class)->cancel($f['order'], UnpaidOrders::SUPERSEDED))->toBeTrue();

    // The newer order of the same shopper (same e-mail), alive.
    $newer = lpOrder(total: 4445.0, email: 'twice@example.test', skip: 1);
    DB::table('orders')->where('id', $newer['order'])->update(['payment_method' => 'cash', 'status' => 'processing']);

    $landing = lpCallback($f, success: true, transactionId: 870001);

    $findings = lpFindings($f);
    expect($landing)->toBe(StorefrontUrls::paymentLanding(1, Landing::PAID, $f['number']))
        ->and(lpStatus($f))->toBe('processing')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed', 'order_reinstate'])
        ->and($findings)->toHaveCount(1)
        ->and($findings[0]['kind'])->toBe(CallbackPolicy::KIND_PAID_TWICE)
        ->and($findings[0]['resolved'])->toBeFalse()
        ->and($findings[0]['detail']['newer_orders'] ?? null)->toBe([$newer['number']])
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_PAYMENT_ALERT)
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_CUSTOMER);
});

// ── 7. the dashboard ─────────────────────────────────────────────────────────────────────────────

it('heads the orders screen with every charged-but-not-shown order, and drops it when the finding is cleared', function () {
    $f = lpOrder();
    expect(lpExpire($f))->toBeTrue();
    app(InventoryService::class)->set(StockTarget::product($f['product']), InventoryService::BUCKET_EXPRESS, 0, 'manual');
    lpCallback($f, success: true, transactionId: 880001);

    // A decline on another cancelled order is NOT money: it must not appear.
    $quiet = lpOrder(email: 'quiet@example.test', skip: 1);
    expect(lpExpire($quiet))->toBeTrue();
    lpCallback($quiet, success: false, transactionId: 880002);

    actingAs(Staff::admin());
    $alerts = T::arr(Props::of(get('/manage/orders')->assertOk())['money_alerts'] ?? null);
    $rows = T::arr($alerts['rows'] ?? null);
    $mine = array_values(array_filter($rows, fn (mixed $row): bool => T::arr($row)['order_id'] === $f['order']));

    expect(T::int($alerts['count']))->toBeGreaterThanOrEqual(1)
        ->and($mine)->toHaveCount(1)
        ->and(T::arr($mine[0])['refused'])->toBe(LatePayment::NO_STOCK)
        ->and(T::arr($mine[0])['amount'])->toBe('4445.00')
        ->and(T::arr($mine[0])['url'])->toBe('/manage/orders/'.$f['order'])
        ->and(array_filter($rows, fn (mixed $row): bool => T::arr($row)['order_id'] === $quiet['order']))->toBe([]);

    // The order's own page carries the reason and the transaction for the person who acts on it.
    $findings = T::arr(Props::of(get('/manage/orders/'.$f['order'])->assertOk())['findings'] ?? null);
    $open = T::arr($findings[0] ?? null);
    expect($open['refused'])->toBe(LatePayment::NO_STOCK)
        ->and($open['transaction'])->toBe('880001')
        ->and($open['outcome'])->toBe('success');

    // Cleared with a reason: gone from the notice (the count goes down by exactly this one).
    $before = T::int($alerts['count']);
    post('/manage/orders/'.$f['order'].'/findings/'.T::int($open['id']).'/resolve', ['note' => 'refunded through the provider portal'])
        ->assertSessionHasNoErrors();
    $after = T::arr(Props::of(get('/manage/orders')->assertOk())['money_alerts'] ?? null);
    expect(T::int($after['count']))->toBe($before - 1)
        ->and(array_filter(T::arr($after['rows']), fn (mixed $row): bool => T::arr($row)['order_id'] === $f['order']))->toBe([]);
});

// ── 8. the command (order 000092's way back) ─────────────────────────────────────────────────────

it('orders:reinstate-paid — dry run writes nothing; --apply reserves, records the payment and resolves the finding; then refuses a second time', function () {
    // The stranded state: charged after the cancellation while the unit was gone…
    $f = lpOrder();
    expect(lpExpire($f))->toBeTrue();
    $service = app(InventoryService::class);
    $service->set(StockTarget::product($f['product']), InventoryService::BUCKET_EXPRESS, 0, 'manual');
    lpCallback($f, success: true, transactionId: 890001, integrationId: '4002');
    expect(lpStatus($f))->toBe('cancelled')->and(lpFindings($f)[0]['resolved'])->toBeFalse();

    // …and the command REFUSES while it still is, saying which line and how many.
    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number']]))->toBe(1);
    $out = Artisan::output();
    expect($out)->toContain('REFUSED — nothing written')
        ->and($out)->toContain('NOT ENOUGH')
        ->and($out)->toContain('needs 1, express bucket holds 0')
        ->and(Artisan::call('orders:reinstate-paid', ['order' => $f['number'], '--apply' => true]))->toBe(1)
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed']);

    // The watch is found and put back on the shelf.
    $service->set(StockTarget::product($f['product']), InventoryService::BUCKET_EXPRESS, 1, 'restock');
    $movements = DB::table('inventory_movements')->count();
    $mail = DB::table('integration_outbox')->count();

    // DRY RUN: reports, exits 0, writes nothing.
    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number']]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('dry run: nothing is written')
        ->and($out)->toContain('Would do:')
        ->and($out)->toContain('transaction 890001')
        ->and($out)->toContain('paid via paymob/valu')
        ->and($out)->toContain('in stock')
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(DB::table('integration_outbox')->count())->toBe($mail)
        ->and(lpFindings($f)[0]['resolved'])->toBeFalse();

    // APPLY.
    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number'], '--apply' => true]))->toBe(0);
    $order = T::one(DB::table('orders')->where('id', $f['order']));
    $finding = T::one(DB::table('payment_reconciliation_findings')->where('order_id', $f['order']));

    expect(lpStatus($f))->toBe('processing')
        ->and(Row::nstr($order, 'paid_via_provider'))->toBe('paymob')
        ->and(Row::nstr($order, 'paid_via_method'))->toBe('valu')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed', 'order_reinstate'])
        ->and(lpStock($f['product']))->toBe(0)                                              // the one unit, reserved
        ->and(lpLedgerMatches($f['product']))->toBeTrue()
        ->and(T::str(DB::table('payment_statuses')->where('pay_transaction_id', 890001)->value('success')))->toBe('true')
        ->and(Row::nstr($finding, 'resolved_at'))->not->toBeNull()
        ->and(Row::nstr($finding, 'note'))->toContain('orders:reinstate-paid')
        ->and(lpMailKinds($f))->toContain(OrderMailer::KIND_CUSTOMER);

    // A second run has nothing to bring back.
    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('not cancelled')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed', 'order_reinstate']);
});

it('orders:reinstate-paid refuses an order with no successful payment, and one whose payment is not its total', function () {
    $f = lpOrder();
    lpCallback($f, success: false, transactionId: 895001);
    expect(lpExpire($f))->toBeTrue();

    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('no successful payment is on record')
        ->and(lpStatus($f))->toBe('cancelled');

    lpCallback($f, success: true, transactionId: 895002, amountMinor: 100);
    expect(Artisan::call('orders:reinstate-paid', ['order' => $f['number'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('never recorded as its payment')
        ->and(lpStatus($f))->toBe('cancelled')
        ->and(lpReasons($f))->toBe(['order', 'payment_failed']);
});
