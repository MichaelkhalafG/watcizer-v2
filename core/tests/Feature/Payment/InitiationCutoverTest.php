<?php

use App\Domain\Payment\InitiationResult;
use App\Domain\Payment\PaymentInitiator;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

/*
 * Starting a payment against the STOREFRONT'S OWN contract (wave 4D, task C2).
 *
 * Wave 4C moved callback VERIFICATION onto `storefront_payment_providers` and left INITIATION on
 * `config('services.paymob.*')`. That split is the failure this file exists to prevent: a customer
 * sent to ONE Paymob account to pay, with the callback verified against ANOTHER account's HMAC
 * secret. The signature does not verify, the money is taken, the order sits pending — and the only
 * symptom is "the callback does not verify", which reads like a provider outage.
 *
 * So the rule under test is one sentence: **initiation and verification are live together, or
 * neither is.** `PaymentInitiator` asks the same question `PaymentCallbackController::aliasIsLive()`
 * asks, and answers null when it is not yet true so the caller keeps the proven wave-3 path.
 */

beforeEach(function () {
    // Every test here decides for itself what contracts exist.
    DB::table('storefront_payment_providers')->delete();
});

/**
 * The request a payment is started from.
 *
 * It is a real input now, not ambient state: `PaymentInitiator` builds the provider's callback
 * destination from this host (review 🔴-2), and refuses to start a payment it cannot build one
 * from. So a test that wants a successful initiation has to say where the shop is being served —
 * which is the right thing to have to say.
 */
function apiHost(): HttpRequest
{
    return HttpRequest::create('https://api.watchizereg.com/api/add_order', 'POST');
}

it('does not take over while there is no contract at all', function () {
    // The state of production right now: the payments tables are empty, and the checkout must keep
    // using the wave-3 path rather than failing.
    $result = app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 1, 'WZ-1', 100.0, []);

    expect($result)->toBeNull();
});

it('does not take over while the contract holds no credentials', function () {
    // A half-finished setup: the contract was added in the dashboard and the keys have not been
    // pasted yet. This is the window the callback's own cutover rule already protects.
    $provider = PaymentFixture::paymobWithoutCredentials();
    PaymentFixture::method($provider);

    expect(app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 1, 'WZ-1', 100.0, []))->toBeNull();
});

it('does not take over while the contract is DISABLED, even with credentials', function () {
    // Disabling is the reverse cutover, and it has to move both halves back.
    $provider = PaymentFixture::paymob(enabled: false);
    PaymentFixture::method($provider);

    expect(app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 1, 'WZ-1', 100.0, []))->toBeNull();
});

it('initiates through the CONTRACT once it holds credentials, with that method\'s integration id', function () {
    $provider = PaymentFixture::paymob();
    PaymentFixture::method($provider, integrationId: '4001');

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_9'], 200)]);

    $result = app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 77, 'WZ-77', 1234.35, [
        'first_name' => 'A', 'last_name' => 'B', 'phone' => '01000000000',
    ]);

    expect($result)->toBeInstanceOf(InitiationResult::class);
    expect($result?->ok)->toBeTrue()
        ->and($result?->checkoutUrl)->toContain('unifiedcheckout')
        ->and($result?->providerReference)->toBe('intent_9');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        // MINOR UNITS, rounded — `(int) (1234.35 * 100)` is 123434 in binary floating point, and a
        // piastre missing from an intention is a callback whose amount check fails.
        expect($body['amount'] ?? null)->toBe(123435)
            // The METHOD's integration id is what routes the customer to card / valU / Tamara
            // inside one Paymob account.
            ->and($body['payment_methods'] ?? null)->toBe([4001])
            // The order number is what the signed callback echoes back to find the order.
            ->and($body['special_reference'] ?? null)->toBe('WZ-77')
            /*
             * BOTH callback urls, on every intention (review 🔴-2). Sending neither left the
             * destination to Paymob's merchant portal — a URL in somebody else's web interface,
             * naming the legacy host, where §4 closes /api. A callback there 404s with the money
             * already taken.
             */
            ->and($body['redirection_url'] ?? null)
            ->toBe('https://api.watchizereg.com/api/pay/watchizer/paymob/callback')
            ->and($body['notification_url'] ?? null)
            ->toBe('https://api.watchizereg.com/api/pay/watchizer/paymob/callback');

        return true;
    });
});

it('REFUSES to start a payment from a host no provider could call back to', function () {
    /*
     * Fail closed, loudly. The alternative — sending the intention without the urls — silently
     * hands the destination back to the merchant portal, which is the defect. A shopper told the
     * payment could not be started has lost nothing; a shopper who paid into a 404 has lost money
     * nobody can see.
     */
    $provider = PaymentFixture::paymob();
    PaymentFixture::method($provider, integrationId: '4001');

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_9'], 200)]);

    $result = app(PaymentInitiator::class)->initiate(
        HttpRequest::create('http://127.0.0.1:8000/api/add_order', 'POST'),
        1, 'ar', 'card', 1, 'WZ-1', 100.0, [],
    );

    // A FAILURE, not a null: null means "not cut over, use the wave-3 path", which would send the
    // customer to Paymob through the very path this refusal exists to prevent.
    expect($result)->toBeInstanceOf(InitiationResult::class)
        ->and($result?->ok)->toBeFalse();

    Http::assertNothingSent();
});

it('refuses a method with no integration id instead of sending a broken intention', function () {
    $provider = PaymentFixture::paymob();
    PaymentFixture::method($provider, integrationId: null);

    $result = app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 1, 'WZ-1', 100.0, []);

    // A contract IS live, so this is a real answer rather than a fall-through — and the answer is a
    // refusal with a reason, which the checkout turns into the legacy 422.
    expect($result)->toBeInstanceOf(InitiationResult::class);
    expect($result?->ok)->toBeFalse()
        ->and($result?->failureReason)->toContain('integration id');
});

it('settles a legacy string and a v2 method id on the SAME contract', function () {
    $provider = PaymentFixture::paymob();
    $method = PaymentFixture::method($provider, integrationId: '4001');

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs', 'id' => 'i1'], 200)]);

    $byString = app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'paymob', 1, 'WZ-1', 100.0, []);
    $byId = app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', T::int($method->getAttribute('id')), 2, 'WZ-2', 100.0, []);

    // A legacy client and a v2 client must not settle into two different merchant accounts.
    expect($byString?->ok)->toBeTrue()->and($byId?->ok)->toBeTrue();
});

it('never uses ANOTHER storefront\'s contract', function () {
    // Brand Fashion has the credentials; Watchizer has none. Watchizer must fall through rather
    // than send its customer to Brand Fashion's merchant account.
    $provider = PaymentFixture::paymob(storefrontId: 2);
    PaymentFixture::method($provider);

    expect(app(PaymentInitiator::class)->initiate(apiHost(), 1, 'ar', 'card', 1, 'WZ-1', 100.0, []))->toBeNull();
});
