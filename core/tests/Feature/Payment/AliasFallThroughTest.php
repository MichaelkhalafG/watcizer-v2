<?php

use App\Domain\Payment\PaymobProvider;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * ── The ALIAS FALL-THROUGH, with a nested POST (review 🔴-2, second reader) ───────────────────
 *
 * `PaymentCallbackController::alias()` hands the request to the wave-3
 * `CheckoutCompatController::callbackPayment()` whenever the Watchizer Paymob contract is not live
 * — no row, or a row with no credentials. That is the DEFAULT state of a fresh deployment, not an
 * exotic one, which is why it is the state this file drives.
 *
 * `ProcessedCallbackTest` seeds a live contract before every case, so every one of its eleven tests
 * exercises the SCOPED handler through the alias URL. The fall-through had no coverage at all, and
 * what lived in it was the same defect `CallbackPolicy::outcomeFromPaymob()` had been fixed for:
 * four identifiers read with top-level `$request->input()`, on a handler that POST had just been
 * routed to. Measured before the fix, on a genuine signed success:
 *
 *   • the order stayed `pending` — money taken, never confirmed, stock still reserved;
 *   • a `payment_statuses` row was written naming NO order, NO transaction and NO amount;
 *   • the answer was a 302, which is not the 200 a provider stops retrying on, and the idempotency
 *     lookup keys on that null transaction id — so each retry wrote another orphan row.
 *
 * Every case below therefore asserts the ROW as well as the status code: a handler that answers
 * 200 and records nothing usable is the failure this file exists to catch.
 */

/**
 * Everything a callback could move, for one order.
 *
 * @return array{status: string, attempts: int}
 */
function fallThroughState(int $orderId): array
{
    return [
        'status' => T::str(DB::table('orders')->where('id', $orderId)->value('status')),
        'attempts' => T::int(DB::table('payment_statuses')->where('order_id', $orderId)->count()),
    ];
}

/**
 * The wave-3 merchant reference: `<order id>-<timestamp>`, exactly what
 * `CompatCheckout::createPaymobIntention()` sends. The integer PREFIX is the order id, which is how
 * this handler resolves an order — so a fixture that sent an order NUMBER would be testing a shape
 * this path never receives.
 */
function waveThreeReference(int $orderId): string
{
    return $orderId.'-'.now()->getTimestamp();
}

beforeEach(function () {
    // No contract at all: the condition `aliasIsLive()` reads. The wave-3 handler verifies against
    // the global config secret, which is what it has always used.
    StorefrontPaymentProvider::query()->delete();
    config(['services.paymob.hmac_secret' => 'fall-through-secret']);
});

it('the fall-through is the state under test — no contract, so aliasIsLive() is false', function () {
    // The premise, asserted rather than assumed: every case below is worthless if a contract exists,
    // because the scoped handler would serve the request and the reader being tested never runs.
    expect(StorefrontPaymentProvider::query()->where('storefront_id', Storefront::WATCHIZER_ID)
        ->where('provider', PaymobProvider::KEY)->exists())->toBeFalse();
});

it('reads a NESTED POST and confirms the order', function () {
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 10000, secret: 'fall-through-secret', transactionId: 880001,
    );

    $response = postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body']);

    // 200 and JSON, not a 302: a redirect sent to a machine that will not follow it leaves Paymob
    // retrying a callback it has already delivered.
    expect($response->status())->toBe(200)
        ->and($response->headers->get('location'))->toBeNull();

    expect(fallThroughState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);

    // The row the reader is FOR. Every one of these was NULL before the fix, on this exact payload.
    $row = DB::table('payment_statuses')->where('order_id', $orderId)->first();
    expect((int) T::int($row?->pay_transaction_id))->toBe(880001)
        ->and((int) T::int($row?->amount_cents))->toBe(10000)
        ->and(T::str($row?->success))->toBe('true')
        ->and(T::str($row?->outcome))->toBe('success');
});

it('reads a NESTED POST failure and cancels the order', function () {
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 10000, success: false,
        secret: 'fall-through-secret', transactionId: 880002,
    );

    postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body'])->assertOk();

    expect(fallThroughState($orderId))->toBe(['status' => 'cancelled', 'attempts' => 1]);
    expect(T::str(DB::table('payment_statuses')->where('order_id', $orderId)->value('outcome')))->toBe('failed');
});

it('is IDEMPOTENT across retries of one nested POST', function () {
    /*
     * The failure this replaces: the transaction id read NULL, so the `pay_transaction_id` lookup
     * matched nothing, `UNIQUE (provider, pay_transaction_id)` does not collide on NULL, and the
     * 302 kept Paymob coming back. Three deliveries, three orphan rows, on a live payment.
     */
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 10000, secret: 'fall-through-secret', transactionId: 880003,
    );

    $first = postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body']);
    $second = postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body']);
    $third = postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body']);

    expect([$first->status(), $second->status(), $third->status()])->toBe([200, 200, 200])
        ->and($second->json('message'))->toBe('Already processed')
        ->and($third->json('message'))->toBe('Already processed');

    expect(fallThroughState($orderId))->toBe(['status' => 'processing', 'attempts' => 1])
        ->and(T::int(DB::table('payment_statuses')->whereNull('pay_transaction_id')->count()))->toBe(0)
        ->and(T::int(DB::table('payment_statuses')->whereNull('order_id')->count()))->toBe(0);
});

it('the two DELIVERIES of one payment collapse to one attempt', function () {
    // The processed POST arrives; the shopper's browser follows on the GET a moment later.
    $orderId = PaymentFixture::order(total: 100.0);
    $reference = waveThreeReference($orderId);

    $processed = PaymentFixture::processedCallback($reference, 10000, secret: 'fall-through-secret', transactionId: 880004);
    postJson('/api/callback_payment?hmac='.$processed['hmac'], $processed['body'])->assertOk();

    $redirect = PaymentFixture::callback($reference, 10000, secret: 'fall-through-secret', transactionId: 880004);
    $browser = get('/api/callback_payment?'.http_build_query($redirect));

    /*
     * A REPLAY answers `Already processed` as JSON with 200, on both methods and on both handlers —
     * unchanged, and deliberately not routed through `PaymentCallbackController::done()`. It is an
     * answer to the PROVIDER ("stop retrying, this is on record"), and a browser arriving on a
     * replay has always seen that body. The method-aware answer is for the TERMINAL branches, which
     * is where a POST was getting a 302 nobody follows.
     */
    expect($browser->status())->toBe(200)
        ->and($browser->json('message'))->toBe('Already processed')
        ->and($browser->headers->get('location'))->toBeNull();

    expect(fallThroughState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);

    /*
     * The TOTAL, not just this order's. With the identifiers read top-level the POST wrote an
     * ORPHAN row — `order_id` null — and the GET then wrote the real one, so "one attempt for this
     * order" was true while two rows existed. Counting only the scoped rows is how this case
     * passed against the bug it exists to catch.
     */
    expect(T::int(DB::table('payment_statuses')->count()))->toBe(1);
});

it('refuses a nested payload signed with the wrong secret, and writes nothing', function () {
    $orderId = PaymentFixture::order(total: 100.0);
    $before = fallThroughState($orderId);

    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 10000, secret: 'not-the-secret', transactionId: 880005,
    );

    postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body'])->assertForbidden();

    expect(fallThroughState($orderId))->toBe($before)
        ->and(T::int(DB::table('payment_statuses')->count()))->toBe(0);
});

it('fails an amount that disagrees with the order CLOSED, on the nested shape too', function () {
    // The order costs 100.00; the callback claims 1.00. The attempt is recorded, the order is not
    // moved, and the stock stays reserved (deviation D-23).
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 100, secret: 'fall-through-secret', transactionId: 880006,
    );

    postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body'])->assertOk();

    expect(fallThroughState($orderId))->toBe(['status' => 'pending', 'attempts' => 1]);
    $row = DB::table('payment_statuses')->where('order_id', $orderId)->first();
    expect(T::str($row?->success))->toBe('false')
        ->and((int) T::int($row?->amount_cents))->toBe(100);
});

it('the FLAT GET still behaves exactly as it always has', function () {
    /*
     * The regression guard for the change itself. The identifiers moved from `$request->input()`
     * to `paymobField()`, which reads both shapes — so the shape that ALREADY worked must not have
     * moved. This is the case the compat harness covers over real HTTP; it is pinned here too
     * because the harness is not run on every change.
     */
    $orderId = PaymentFixture::order(total: 100.0);
    $flat = PaymentFixture::callback(
        waveThreeReference($orderId), 10000, secret: 'fall-through-secret', transactionId: 880007,
    );

    $response = get('/api/callback_payment?'.http_build_query($flat));

    expect($response->status())->toBe(302)
        ->and($response->headers->get('location'))->toBe(config()->string('compat.payment_return_url'));

    expect(fallThroughState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);
    $row = DB::table('payment_statuses')->where('order_id', $orderId)->first();
    expect((int) T::int($row?->pay_transaction_id))->toBe(880007)
        ->and((int) T::int($row?->amount_cents))->toBe(10000);
});

it('records the Paymob ORDER id, which lives one level deeper in the nested shape', function () {
    // `obj.order.id` against a flat `order`. Two different places for one column.
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        waveThreeReference($orderId), 10000, secret: 'fall-through-secret', transactionId: 880008,
    );

    postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body'])->assertOk();

    expect((int) T::int(DB::table('payment_statuses')->where('order_id', $orderId)->value('pay_order_id')))
        ->toBe(55555);
});
