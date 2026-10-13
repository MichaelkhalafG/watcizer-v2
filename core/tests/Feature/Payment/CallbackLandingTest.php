<?php

use App\Domain\Payment\CallbackPolicy;
use App\Domain\Payment\Landing;
use App\Http\Controllers\Payment\PaymentCallbackController;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * The METHOD RULE, stated in full (Landing, 2026-10-12): a browser gets a redirect to the storefront
 * whatever the outcome; JSON is for the server-to-server caller only.
 *
 * Measured on the first successful live payment: the processed POST settled the order, and the
 * shopper's browser — arriving a moment later on the same transaction — was answered with
 * `{"message":"Already processed"}` on a blank page. Every early exit did the same. So this file
 * walks each exit of the scoped handler twice, once as the provider (POST) and once as the shopper
 * (GET), and asserts the answer each is owed.
 *
 * Option A (developer decision): the landing is `/order-confirmation?order=…&status=…` on the
 * storefront the callback was scoped to. It is a confirmation MESSAGE, not a receipt: the page
 * renders what the query says, verifies nothing and fires no Purchase event.
 */

beforeEach(fn () => config(['compat.payment_return_url' => 'https://watchizereg.test/']));

const WZ_LANDING = 'https://watchizereg.test/order-confirmation?';

function landedAt(string $status, ?string $orderNumber = null): string
{
    return WZ_LANDING.($orderNumber === null ? '' : 'order='.$orderNumber.'&').'status='.$status;
}

it('decides the landing from the POLICY, never from what the provider claimed', function () {
    $paid = ['action' => CallbackPolicy::ACT_PAY, 'finding' => null, 'paid' => true];
    $declined = ['action' => CallbackPolicy::ACT_CANCEL, 'finding' => null, 'paid' => false];
    $amount = ['action' => CallbackPolicy::ACT_RECORD_ONLY, 'finding' => CallbackPolicy::KIND_AMOUNT, 'paid' => false];
    $terminal = ['action' => CallbackPolicy::ACT_RECORD_ONLY, 'finding' => CallbackPolicy::KIND_TERMINAL, 'paid' => false];
    $pending = ['action' => CallbackPolicy::ACT_RECORD_ONLY, 'finding' => null, 'paid' => false];

    expect(Landing::of($paid, CallbackPolicy::OUTCOME_SUCCESS))->toBe(Landing::PAID)
        ->and(Landing::of($declined, CallbackPolicy::OUTCOME_FAILED))->toBe(Landing::FAILED)
        // A "success" the policy refused is a finding: the shopper is told it is being confirmed.
        ->and(Landing::of($amount, CallbackPolicy::OUTCOME_SUCCESS))->toBe(Landing::PENDING)
        ->and(Landing::of($terminal, CallbackPolicy::OUTCOME_SUCCESS))->toBe(Landing::PENDING)
        ->and(Landing::of($pending, CallbackPolicy::OUTCOME_PENDING))->toBe(Landing::PENDING)
        // A replay is told what the RECORD says.
        ->and(Landing::ofRecorded('true', CallbackPolicy::OUTCOME_SUCCESS))->toBe(Landing::PAID)
        ->and(Landing::ofRecorded('false', CallbackPolicy::OUTCOME_FAILED))->toBe(Landing::FAILED)
        ->and(Landing::ofRecorded('false', CallbackPolicy::OUTCOME_PENDING))->toBe(Landing::PENDING)
        ->and(Landing::ofRecorded(null, null))->toBe(Landing::FAILED);
});

it('builds the landing on the storefront\'s return base, number first, nothing else', function () {
    expect(StorefrontUrls::paymentLanding(1, Landing::PAID, '000027'))->toBe(landedAt('paid', '000027'))
        ->and(StorefrontUrls::paymentLanding(1, Landing::FAILED, null))->toBe(landedAt('failed'))
        ->and(StorefrontUrls::paymentLanding(1, Landing::PENDING, '  '))->toBe(landedAt('pending'))
        // The other shop's landing is the other shop's: derived from its own domain.
        ->and(StorefrontUrls::paymentLanding(Storefront::BRAND_FASHION_ID, Landing::PAID, '000001'))
        ->toBe(Shops::site('brandfashion').'/order-confirmation?order=000001&status=paid');
});

it('lands on the storefront the callback was SCOPED to, not the one the request\'s host resolves to', function () {
    // A Brand Fashion callback arriving on Watchizer's API host: the landing is still Brand Fashion's.
    $response = PaymentCallbackController::land(
        Request::create('https://api.watchizereg.com/api/pay/brandfashion/paymob/callback'),
        Storefront::BRAND_FASHION_ID, Landing::PAID, '000001',
    );

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response instanceof RedirectResponse ? $response->getTargetUrl() : null)
        ->toBe(Shops::site('brandfashion').'/order-confirmation?order=000001&status=paid');
});

it('answers the PROVIDER with JSON on every exit: refusal, success, decline, replay', function () {
    PaymentFixture::paymob(hmacSecret: 'real');
    $orderId = PaymentFixture::order(total: 100.0);
    $number = PaymentFixture::orderNumber($orderId);

    // Refused at the signature: the JSON refusal and its status, no redirect.
    $forged = PaymentFixture::processedCallback($number, 10000, secret: 'forged', transactionId: 910001);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$forged['hmac'], $forged['body'])
        ->assertForbidden()->assertExactJson(['message' => 'Forbidden']);

    // Settled.
    $paid = PaymentFixture::processedCallback($number, 10000, secret: 'real', transactionId: 910002);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$paid['hmac'], $paid['body'])
        ->assertOk()->assertExactJson(['message' => 'ok']);

    // Replayed: "stop retrying, this is on record".
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$paid['hmac'], $paid['body'])
        ->assertOk()->assertExactJson(['message' => 'Already processed']);

    // Declined, on another order.
    $other = PaymentFixture::order(total: 50.0);
    $declined = PaymentFixture::processedCallback(PaymentFixture::orderNumber($other), 5000, success: false, secret: 'real', transactionId: 910003);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$declined['hmac'], $declined['body'])
        ->assertOk()->assertExactJson(['message' => 'failed']);

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing')
        ->and(T::str(DB::table('orders')->where('id', $other)->value('status')))->toBe('cancelled');
});

it('sends the SHOPPER to the confirmation page on every exit, with the order number where it is proven', function () {
    PaymentFixture::paymob(hmacSecret: 'real');

    // Paid on a first delivery.
    $paidOrder = PaymentFixture::order(total: 100.0);
    $paidNumber = PaymentFixture::orderNumber($paidOrder);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($paidNumber, 10000, secret: 'real', transactionId: 920001)
    ))->assertRedirect(landedAt('paid', $paidNumber));

    // Declined.
    $declinedOrder = PaymentFixture::order(total: 50.0);
    $declinedNumber = PaymentFixture::orderNumber($declinedOrder);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($declinedNumber, 5000, success: false, secret: 'real', transactionId: 920002)
    ))->assertRedirect(landedAt('failed', $declinedNumber));

    // The amount disagrees: a finding, so "being confirmed", never "paid".
    $mismatchOrder = PaymentFixture::order(total: 80.0);
    $mismatchNumber = PaymentFixture::orderNumber($mismatchOrder);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($mismatchNumber, 100, secret: 'real', transactionId: 920003)
    ))->assertRedirect(landedAt('pending', $mismatchNumber));

    // Refused at the signature: failed, and NO number — before ownership is proven it is a claim.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($paidNumber, 10000, secret: 'forged', transactionId: 920004)
    ))->assertRedirect(landedAt('failed'));

    expect(T::str(DB::table('orders')->where('id', $paidOrder)->value('status')))->toBe('processing')
        ->and(T::str(DB::table('orders')->where('id', $declinedOrder)->value('status')))->toBe('cancelled')
        ->and(T::str(DB::table('orders')->where('id', $mismatchOrder)->value('status')))->toBe('pending');
});

it('the NORMAL arrival: the POST settles, the browser follows and is told what the record says', function () {
    PaymentFixture::paymob(hmacSecret: 'real');

    // Paid: the POST wins the race, the GET lands on the replay branch — and on the paid page.
    $paidOrder = PaymentFixture::order(total: 100.0);
    $paidNumber = PaymentFixture::orderNumber($paidOrder);
    $processed = PaymentFixture::processedCallback($paidNumber, 10000, secret: 'real', transactionId: 930001);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$processed['hmac'], $processed['body'])->assertOk();
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($paidNumber, 10000, secret: 'real', transactionId: 930001)
    ))->assertRedirect(landedAt('paid', $paidNumber));

    // Declined: the same race, and the record says failed — so does the page.
    $declinedOrder = PaymentFixture::order(total: 50.0);
    $declinedNumber = PaymentFixture::orderNumber($declinedOrder);
    $processed = PaymentFixture::processedCallback($declinedNumber, 5000, success: false, secret: 'real', transactionId: 930002);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$processed['hmac'], $processed['body'])->assertOk();
    get('/api/pay/watchizer/paymob/callback?'.http_build_query(
        PaymentFixture::callback($declinedNumber, 5000, success: false, secret: 'real', transactionId: 930002)
    ))->assertRedirect(landedAt('failed', $declinedNumber));

    // One attempt each: the replay recorded nothing.
    expect(T::int(DB::table('payment_statuses')->where('order_id', $paidOrder)->count()))->toBe(1)
        ->and(T::int(DB::table('payment_statuses')->where('order_id', $declinedOrder)->count()))->toBe(1);
});

it('keeps the unknown-storefront refusal a plain 404 for a browser too', function () {
    /*
     * The one exit that does not redirect, with its reason: there is no shop to send the browser
     * to without resolving the request's HOST, and `StorefrontUrls::storefrontOf()` falls back to
     * Watchizer when the host matches nothing — the very fallback W6/W7 removes. Nobody legitimately
     * lands on a storefront code that does not exist, so there is nobody to redirect.
     */
    get('/api/pay/nosuchshop/paymob/callback?id=1')->assertNotFound()->assertExactJson(['message' => 'Not found']);
});

it('carries nothing from the payload into the landing URL', function () {
    PaymentFixture::paymob(hmacSecret: 'real');
    $orderId = PaymentFixture::order(total: 100.0);
    $number = PaymentFixture::orderNumber($orderId);
    $payload = PaymentFixture::callback($number, 10000, secret: 'real', transactionId: 940001);

    $location = T::str(get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->headers->get('location'));

    expect($location)->toBe(landedAt('paid', $number))
        ->and($location)->not->toContain('hmac')
        ->and($location)->not->toContain('940001')
        ->and($location)->not->toContain('10000')
        ->and($location)->not->toContain('amount');
});
