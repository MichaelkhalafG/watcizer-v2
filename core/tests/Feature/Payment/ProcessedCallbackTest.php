<?php

use App\Domain\Payment\CallbackDestination;
use App\Models\Storefront\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * ── The PROCESSED callback: Paymob's server-to-server POST (review 🔴-2 / G2b) ────────────────
 *
 * Paymob delivers one payment event twice, by two methods and in two payload shapes:
 *
 *   - `redirection_url` — the shopper's browser, a GET, twenty fields FLATTENED into the query
 *     string, booleans as the strings "true"/"false";
 *   - `notification_url` — a server-to-server POST, the same fields NESTED under `obj.*` as real
 *     JSON with real booleans, the merchant reference at `obj.order.merchant_order_id`, and the
 *     `hmac` on the query string rather than in the body.
 *
 * Both routes were registered GET-only, so the processed callback got a 405 and the order depended
 * entirely on the shopper returning to the site. A shopper who pays and closes the tab is the
 * exact case the processed callback exists for, and the symptom is a paid order stuck pending with
 * its stock still reserved.
 */

/**
 * Everything a callback could move.
 *
 * @return array<string, mixed>
 */
function processedState(int $orderId): array
{
    return [
        'status' => T::str(DB::table('orders')->where('id', $orderId)->value('status')),
        'attempts' => T::int(DB::table('payment_statuses')->where('order_id', $orderId)->count()),
    ];
}

it('accepts the NESTED obj.* payload over POST and confirms the order', function () {
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 100.0);
    $callback = PaymentFixture::processedCallback(
        PaymentFixture::orderNumber($orderId), 10000, secret: 'the-real-secret',
    );

    /*
     * The assertion that matters: the nested shape verifies. If `paymobField()` ever stopped
     * reading `obj.*`, the twenty-field concatenation would come out empty and this would be a
     * 403 — which is what a signature bug looks like from the outside, and why it is worth a test
     * rather than a reading of the extractor.
     */
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$callback['hmac'], $callback['body'])
        ->assertOk()
        ->assertJsonPath('message', 'ok');

    expect(processedState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);
});

it('answers the POST with JSON, never a redirect to the storefront', function () {
    /*
     * A 302 sent to a machine that will not follow it says nothing about whether the callback was
     * accepted. The decision is made on the METHOD — a fact about the protocol — and not on an
     * Accept header, which Paymob's POST sends as any-type and which therefore read as a browser.
     */
    PaymentFixture::paymob();
    $orderId = PaymentFixture::order(total: 42.0);
    $callback = PaymentFixture::processedCallback(PaymentFixture::orderNumber($orderId), 4200);

    $response = postJson('/api/pay/watchizer/paymob/callback?hmac='.$callback['hmac'], $callback['body']);

    expect($response->status())->toBe(200)
        ->and($response->headers->get('location'))->toBeNull();
});

it('REDIRECTS a GET even when it asks for JSON — the legacy handler does, byte for byte', function () {
    /*
     * Developer decision, 2026-09-23. A second arm used to answer a GET carrying
     * `Accept: application/json` with JSON. Legacy redirects every GET unconditionally, the
     * harness could see the difference, and no real caller sends that header on the shopper's
     * browser return. The METHOD alone decides: POST → JSON, GET → the storefront.
     */
    config(['compat.payment_return_url' => 'https://watchizereg.test/']);
    PaymentFixture::paymob();
    $orderId = PaymentFixture::order(total: 42.0);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 4200);

    $response = get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload), ['Accept' => 'application/json']);

    expect($response->status())->toBe(302)
        ->and((string) $response->headers->get('location'))->toStartWith('https://watchizereg.test/');
});

it('refuses a nested payload signed with another secret, and writes nothing', function () {
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 100.0);
    $before = processedState($orderId);

    $callback = PaymentFixture::processedCallback(
        PaymentFixture::orderNumber($orderId), 10000, secret: 'some-other-secret',
    );

    postJson('/api/pay/watchizer/paymob/callback?hmac='.$callback['hmac'], $callback['body'])
        ->assertForbidden();

    expect(processedState($orderId))->toBe($before);
});

it('is idempotent across the two deliveries of ONE payment', function () {
    /*
     * The real sequence: Paymob POSTs the processed callback, and the shopper's browser arrives on
     * the GET a moment later. Both carry the same transaction id, so the second must not release
     * stock or record a second attempt — the UNIQUE (provider, pay_transaction_id) index doing the
     * job it was installed for, now that a second delivery actually reaches the controller.
     */
    PaymentFixture::paymob();
    $orderId = PaymentFixture::order(total: 100.0);
    $reference = PaymentFixture::orderNumber($orderId);

    $processed = PaymentFixture::processedCallback($reference, 10000, transactionId: 555001);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$processed['hmac'], $processed['body'])->assertOk();

    $redirect = PaymentFixture::callback($reference, 10000, transactionId: 555001);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($redirect))
        ->assertOk()
        ->assertJsonPath('message', 'Already processed');

    expect(processedState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);
});

it('the alias URL takes the POST too', function () {
    // The URL that lives in the merchant portal. Registering it GET-only meant the processed
    // callback 405'd on the one route Paymob has actually been configured with for years.
    PaymentFixture::paymob();
    $orderId = PaymentFixture::order(total: 77.0);
    $callback = PaymentFixture::processedCallback(PaymentFixture::orderNumber($orderId), 7700);

    postJson('/api/callback_payment?hmac='.$callback['hmac'], $callback['body'])->assertOk();

    expect(processedState($orderId))->toBe(['status' => 'processing', 'attempts' => 1]);
});

it('BOTH methods are registered on both callback routes', function () {
    $methods = function (string $name): array {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                // HEAD is registered alongside GET by the router itself and is not a method
                // anybody declared, so it is not part of what this asserts.
                $methods = [];
                foreach ($route->methods() as $method) {
                    if ($method !== 'HEAD') {
                        $methods[] = $method;
                    }
                }

                return $methods;
            }
        }

        return [];
    };

    expect($methods('pay.callback'))->toEqualCanonicalizing(['GET', 'POST'])
        ->and($methods('pay.callback.alias'))->toEqualCanonicalizing(['GET', 'POST']);
});

it('the callback URL sent to the provider matches the route that is registered', function () {
    /*
     * `CallbackDestination::path()` builds the path literally rather than through `route()`,
     * because `route()` takes its host from APP_URL — a value this deploy has already been bitten
     * by. This is the guard that stops the literal drifting away from the route it names.
     */
    $built = CallbackDestination::path('watchizer', 'paymob');
    $routed = parse_url(route('pay.callback', ['storefront' => 'watchizer', 'provider' => 'paymob']), PHP_URL_PATH);

    expect($built)->toBe($routed);
});

it('sends BOTH callback urls on the intention, built from the request host', function () {
    $contract = PaymentFixture::paymob();
    $storefront = Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->firstOrFail();

    $urls = CallbackDestination::for(
        Request::create('https://api.watchizereg.com/api/add_order', 'POST'),
        $storefront,
        $contract,
    );

    expect($urls)->toBe([
        'notification_url' => 'https://api.watchizereg.com/api/pay/watchizer/paymob/callback',
        'redirection_url' => 'https://api.watchizereg.com/api/pay/watchizer/paymob/callback',
    ]);
});

it('prefers the contract settings over the request host when one is configured', function () {
    $contract = PaymentFixture::paymob();
    $contract->forceFill(['settings' => ['callback_base' => 'https://payments.example-shop.com/']])->save();
    $storefront = Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->firstOrFail();

    $urls = CallbackDestination::for(
        Request::create('https://api.watchizereg.com/api/add_order', 'POST'),
        $storefront,
        $contract->fresh() ?? $contract,
    );

    // `for()` is nullable by contract, so the shape is asserted before a key is read — a null here
    // would otherwise fail as "offset on null" and read as a test bug rather than a refusal.
    expect($urls)->toBeArray()
        ->and($urls['notification_url'] ?? null)
        ->toBe('https://payments.example-shop.com/api/pay/watchizer/paymob/callback');
});

it('REFUSES to build a destination a payment provider could not reach', function () {
    /*
     * The whole point of failing closed. Omitting the urls would hand the destination back to the
     * merchant portal — whose URL names the legacy host, where §4 closes /api — so a callback
     * would 404 with the money already taken. A payment that never starts costs nobody anything.
     */
    $contract = PaymentFixture::paymob();
    $storefront = Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->firstOrFail();

    $refused = [];
    foreach ([
        'http://api.watchizereg.com',          // plaintext
        'https://localhost',                   // no dot
        'https://127.0.0.1',                   // an IP literal
        'https://core.test',                   // a workstation suffix
    ] as $base) {
        $refused[$base] = CallbackDestination::for(
            Request::create($base.'/api/add_order', 'POST'), $storefront, $contract,
        );
    }

    expect($refused)->each->toBeNull();
});

it('an unusable contract setting falls back to the request rather than being trusted', function () {
    $contract = PaymentFixture::paymob();
    $contract->forceFill(['settings' => ['callback_base' => 'http://not-https.example-shop.com']])->save();
    $storefront = Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->firstOrFail();

    $urls = CallbackDestination::for(
        Request::create('https://api.watchizereg.com/api/add_order', 'POST'),
        $storefront,
        $contract->fresh() ?? $contract,
    );

    expect($urls)->toBeArray()
        ->and($urls['notification_url'] ?? null)->toStartWith('https://api.watchizereg.com/');
});
