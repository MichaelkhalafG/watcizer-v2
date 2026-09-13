<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Payment\CallbackPolicy;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\get;

/*
 * 🔴-1 — a callback may never transition an order OUT of a terminal state.
 *
 * Both handlers moved the order unconditionally, and the idempotency guard only catches a replay of
 * the SAME transaction id. So a *different* transaction went straight through:
 *
 *   decline → cancelled + stock released → retry succeeds → `processing` with NO stock reserved.
 *
 * An order marked paid whose units are back on the shelf, possibly already sold to someone else.
 * Void and refund arrive as `success=true` with a flag set, so money coming BACK re-marked the
 * order paid; and a decline after a success cancelled a paid order and released the stock of goods
 * being packed.
 *
 * The fix records the attempt, leaves the order alone, and raises a finding a human clears — never
 * a silent re-reservation, because the units may be gone. These four tests are the four cases the
 * developer named, plus the ordinary single success that must keep working.
 */

/**
 * An order with stock reserved, ready to be paid for.
 *
 * @return array{order: int, product: int}
 */
function terminalFixture(float $total = 100.0, string $status = 'pending'): array
{
    $row = T::one(
        DB::table('catalog_products')
            ->whereNull('deleted_at')->where('stock_express', '>=', 2)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')
    );
    $productId = Row::int(Row::cast($row), 'id');

    $orderId = PaymentFixture::order(total: $total, status: $status);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 1, 'piece_price' => number_format($total, 2, '.', ''), 'total_price' => number_format($total, 2, '.', ''),
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $productId];
}

/** @param  array<string, mixed>  $overrides */
function callbackFor(int $orderId, bool $success, int $transactionId, array $overrides = []): string
{
    $payload = PaymentFixture::callback(
        PaymentFixture::orderNumber($orderId),
        10000,
        success: $success,
        transactionId: $transactionId,
        overrides: $overrides,
    );

    return '/api/pay/watchizer/paymob/callback?'.http_build_query($payload);
}

function statusOfOrder(int $orderId): string
{
    return T::str(DB::table('orders')->where('id', $orderId)->value('status'));
}

/** @return list<array<string, string|null>> */
function findingsFor(int $orderId): array
{
    $out = [];
    foreach (DB::table('payment_reconciliation_findings')->where('order_id', $orderId)->orderBy('id')->get() as $raw) {
        $row = Row::cast($raw);
        $out[] = ['kind' => Row::str($row, 'kind'), 'outcome' => Row::nstr($row, 'outcome'), 'order_status' => Row::nstr($row, 'order_status')];
    }

    return $out;
}

beforeEach(function (): void {
    PaymentFixture::method(PaymentFixture::paymob());
});

it('DECLINE THEN SUCCEED: the retry does not resurrect a cancelled order, and stock is not re-reserved', function () {
    $f = terminalFixture();
    $service = app(InventoryService::class);

    // The decline: the order cancels and its units go back. This is wave 3's behaviour and stays.
    get(callbackFor($f['order'], false, 500001))->assertRedirect();

    expect(statusOfOrder($f['order']))->toBe('cancelled')
        ->and($service->isReleased($f['order']))->toBeTrue();

    $movements = DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $f['order'])->count();

    // The retry SUCCEEDS — a different transaction id, so the idempotency guard does not see it.
    get(callbackFor($f['order'], true, 500002))->assertRedirect();

    expect(statusOfOrder($f['order']))->toBe('cancelled', 'a callback must not resurrect a cancelled order')
        // …no stock movement was invented to back the payment…
        ->and(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $f['order'])->count())
        ->toBe($movements, 'stock must never be silently re-reserved')
        // …the money IS on record…
        ->and(DB::table('payment_statuses')->where('order_id', $f['order'])->count())->toBe(2)
        // …and a human has something to clear.
        ->and(findingsFor($f['order']))->toBe([[
            'kind' => CallbackPolicy::KIND_TERMINAL,
            'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
            'order_status' => 'cancelled',
        ]]);
});

it('VOID AFTER SUCCESS: the order stays processing and the void is not read as a sale', function () {
    $f = terminalFixture();

    get(callbackFor($f['order'], true, 500010))->assertRedirect();
    expect(statusOfOrder($f['order']))->toBe('processing');

    // Paymob sends a void as success=true WITH is_voided=true.
    get(callbackFor($f['order'], true, 500011, ['is_voided' => 'true']))->assertRedirect();

    $attempts = DB::table('payment_statuses')->where('order_id', $f['order'])->orderBy('id')->get();
    $second = Row::cast(T::row($attempts[1] ?? null));

    expect(statusOfOrder($f['order']))->toBe('processing', 'a void does not move the order by itself')
        // The attempt is recorded as a VOID, not as a second sale…
        ->and(Row::nstr($second, 'outcome'))->toBe(CallbackPolicy::OUTCOME_VOIDED)
        ->and(Row::nstr($second, 'success'))->toBe('false')
        // …so it cannot be counted as revenue…
        ->and(CallbackPolicy::isRevenue(CallbackPolicy::OUTCOME_VOIDED))->toBeFalse()
        // …and it raises a finding.
        ->and(findingsFor($f['order']))->toBe([[
            'kind' => CallbackPolicy::KIND_REVERSAL,
            'outcome' => CallbackPolicy::OUTCOME_VOIDED,
            'order_status' => 'processing',
        ]]);
});

it('REFUND AFTER SUCCESS: the refund is its own outcome and never counts as revenue', function () {
    $f = terminalFixture();

    get(callbackFor($f['order'], true, 500020))->assertRedirect();
    get(callbackFor($f['order'], true, 500021, ['is_refunded' => 'true']))->assertRedirect();

    $attempts = DB::table('payment_statuses')->where('order_id', $f['order'])->orderBy('id')->get();

    expect(Row::nstr(Row::cast(T::row($attempts[0] ?? null)), 'outcome'))->toBe(CallbackPolicy::OUTCOME_SUCCESS)
        ->and(Row::nstr(Row::cast(T::row($attempts[1] ?? null)), 'outcome'))->toBe(CallbackPolicy::OUTCOME_REFUNDED)
        ->and(statusOfOrder($f['order']))->toBe('processing')
        ->and(findingsFor($f['order']))->toBe([[
            'kind' => CallbackPolicy::KIND_REVERSAL,
            'outcome' => CallbackPolicy::OUTCOME_REFUNDED,
            'order_status' => 'processing',
        ]]);

    // The reason this matters at all: the settlement export must not add a refund to the day's
    // takings. A finance report that counts one is a reconciliation error found months later.
    $csv = Staff::admin();
    $export = Pest\Laravel\actingAs($csv)->get('/manage/orders/export/settlement')->assertOk();
    $lines = [];
    foreach (preg_split('/\r?\n/', $export->streamedContent()) ?: [] as $line) {
        if (str_contains($line, PaymentFixture::orderNumber($f['order']))) {
            $lines[] = $line;
        }
    }

    expect(count($lines))->toBe(2, 'both attempts appear — a refund is not hidden, it is labelled');
    expect(implode("\n", $lines))->toContain(',refunded')->toContain(',success');
});

it('DECLINE AFTER SUCCESS: a failed retry does not cancel a paid order or release its stock', function () {
    $f = terminalFixture();
    $service = app(InventoryService::class);

    get(callbackFor($f['order'], true, 500030))->assertRedirect();
    expect(statusOfOrder($f['order']))->toBe('processing');

    get(callbackFor($f['order'], false, 500031))->assertRedirect();

    expect(statusOfOrder($f['order']))->toBe('processing', 'a decline must not cancel an order that was already paid')
        ->and($service->isReleased($f['order']))->toBeFalse('the stock of a paid order stays reserved')
        ->and(findingsFor($f['order']))->toBe([[
            'kind' => CallbackPolicy::KIND_FAILED_AFTER_PAID,
            'outcome' => CallbackPolicy::OUTCOME_FAILED,
            'order_status' => 'processing',
        ]]);
});

it('still does the ordinary thing: ONE success pays the order, with no finding at all', function () {
    $f = terminalFixture();

    get(callbackFor($f['order'], true, 500040))->assertRedirect();

    $attempt = Row::cast(T::one(DB::table('payment_statuses')->where('order_id', $f['order'])));

    expect(statusOfOrder($f['order']))->toBe('processing')
        ->and(T::str(DB::table('orders')->where('id', $f['order'])->value('paid_via_provider')))->toBe('paymob')
        ->and(Row::nstr($attempt, 'success'))->toBe('true')
        ->and(Row::nstr($attempt, 'outcome'))->toBe(CallbackPolicy::OUTCOME_SUCCESS)
        ->and(findingsFor($f['order']))->toBe([], 'a clean payment raises nothing for a human');
});

it('refuses a callback for every state beyond processing, not only for cancelled', function () {
    // `shipped`, `delivered` and `completed` are terminal for the same reason: the goods are with a
    // courier or a customer, and a payment event cannot undo a physical fact.
    // Distinct transaction ids per case: `strlen('delivered') === strlen('completed')`, so the
    // first version collided and the second callback answered "Already processed" (200) instead of
    // being judged — the idempotency guard doing its job on my own test's mistake.
    foreach ([['shipped', 500051], ['delivered', 500052], ['completed', 500053]] as [$status, $transaction]) {
        $f = terminalFixture(status: $status);

        get(callbackFor($f['order'], true, $transaction))->assertRedirect();

        expect(statusOfOrder($f['order']))->toBe($status, "a callback must not move a {$status} order")
            ->and(findingsFor($f['order']))->toBe([[
                'kind' => CallbackPolicy::KIND_TERMINAL,
                'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
                'order_status' => $status,
            ]]);
    }
});

it('records a PENDING callback without a finding, because it is not a decision', function () {
    $f = terminalFixture();

    get(callbackFor($f['order'], false, 500060, ['pending' => 'true']))->assertRedirect();

    expect(statusOfOrder($f['order']))->toBe('pending')
        ->and(Row::nstr(Row::cast(T::one(DB::table('payment_statuses')->where('order_id', $f['order']))), 'outcome'))
        ->toBe(CallbackPolicy::OUTCOME_PENDING)
        ->and(findingsFor($f['order']))->toBe([], '3-D Secure traffic is not a reconciliation problem');
});

it('raises a finding for an amount mismatch too, which used to be a log line only', function () {
    $f = terminalFixture(total: 100.0);

    // The payload claims a different amount. Wave 3 logged it and left the order alone; now there
    // is also a row a human must clear.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($f['order']), 1, success: true, transactionId: 500070);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload));

    expect(statusOfOrder($f['order']))->toBe('pending')
        ->and(findingsFor($f['order']))->toBe([[
            'kind' => CallbackPolicy::KIND_AMOUNT,
            'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
            'order_status' => 'pending',
        ]]);
});
