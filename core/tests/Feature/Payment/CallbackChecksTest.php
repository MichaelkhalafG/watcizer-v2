<?php

use App\Domain\Payment\Landing;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontUrls;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;

/*
 * The FOUR ORDERED CHECKS of study §3.9.2, each proven to refuse, and each proven to write nothing
 * when it refuses.
 *
 * This is the only unauthenticated route on the core host and it cannot be otherwise — a payment
 * provider cannot send an `Api-Code` header this application invented, so the SIGNATURE is the
 * authentication. Every test below therefore asserts two things: the status, and that the database
 * did not move.
 *
 * The payloads are signed the way Paymob signs them (twenty fields, sha512, documented order) by
 * `PaymentFixture`, which keeps its own copy of the field list so these tests cannot pass by
 * agreeing with the implementation's bug.
 *
 * Every request here is a GET — the shopper's BROWSER returning — so a refusal is a redirect to the
 * storefront's failed landing (Landing, 2026-10-12), not a 403: the status that matters is still
 * asserted, as "the database did not move". The provider's POST gets the JSON refusal; that arm is
 * CallbackLandingTest's.
 */

beforeEach(fn () => config(['compat.payment_return_url' => 'https://watchizereg.test/']));

/** Where a Watchizer shopper's browser is sent: the confirmation page, with what it is told. */
function landing(string $status, ?string $orderNumber = null): string
{
    return 'https://watchizereg.test/order-confirmation?'.($orderNumber === null ? '' : 'order='.$orderNumber.'&').'status='.$status;
}

/**
 * Everything a callback could change, as one comparable snapshot.
 *
 * @return array<string, mixed>
 */
function paymentState(int $orderId): array
{
    return [
        'order' => (array) T::row(DB::table('orders')->where('id', $orderId)
            ->first(['status', 'paid_via_provider', 'paid_via_method'])),
        'attempts' => T::int(DB::table('payment_statuses')->where('order_id', $orderId)->count()),
        // `orders`, plural — the string `Reference::order()` actually writes. With the singular
        // this count was always 0, so the "nothing moved" half of every snapshot below was
        // comparing zero to zero and would not have noticed a release.
        'movements' => T::int(DB::table('inventory_movements')->where('reference_type', 'orders')
            ->where('reference_id', $orderId)->count()),
    ];
}

it('CHECK 1 — 404s a storefront it does not know, and a provider the storefront does not hold', function () {
    $orderId = PaymentFixture::order();
    $before = paymentState($orderId);

    // Unknown storefront code: a plain 404 even for a browser — there is no shop to send it to
    // without resolving the host through the default-storefront fallback (W6/W7 removes it).
    get('/api/pay/nosuchshop/paymob/callback?id=1')->assertNotFound();

    // Known storefront, but no contract for that provider: refused, and the browser is sent to
    // THAT storefront's failed landing.
    get('/api/pay/watchizer/fawry/callback?id=1')->assertRedirect(landing(Landing::FAILED));

    expect(paymentState($orderId))->toBe($before);
});

it('CHECK 1 — 404s a DISABLED contract and one holding no credentials', function () {
    $orderId = PaymentFixture::order();
    $before = paymentState($orderId);

    PaymentFixture::paymob(enabled: false);
    get('/api/pay/watchizer/paymob/callback?id=1')->assertRedirect(landing(Landing::FAILED));

    // Enabled but credential-less: verifying with an empty secret is how a forged callback is
    // accepted, so the contract is treated as unusable rather than as "verify anyway".
    PaymentFixture::paymobWithoutCredentials();
    get('/api/pay/watchizer/paymob/callback?id=1')->assertRedirect(landing(Landing::FAILED));

    expect(paymentState($orderId))->toBe($before);
});

it('CHECK 2 — 403s a payload whose signature does not verify against THIS contract', function () {
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 100.0);
    $before = paymentState($orderId);

    // Signed with a different secret: the shape is perfect, the signature is not this contract's.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'some-other-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect(landing(Landing::FAILED));

    expect(paymentState($orderId))->toBe($before, 'a bad signature must write nothing at all');
});

it('CHECK 2 — two storefronts with two Paymob accounts cannot validate each other', function () {
    /*
     * The difference between this route and wave 3's global one, stated as data: storefront 1 and
     * storefront 2 each hold a Paymob contract with its OWN secret, and a callback signed for one
     * is refused by the other.
     */
    PaymentFixture::paymob(storefrontId: 1, hmacSecret: 'secret-of-storefront-one');
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'secret-of-storefront-two');

    $orderId = PaymentFixture::order(total: 50.0, storefrontId: Storefront::BRAND_FASHION_ID);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 5000, secret: 'secret-of-storefront-two');

    // Presented to storefront 1's route, it does not verify — and the browser is sent to
    // storefront 1's failed landing, the shop whose route it arrived on.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect(landing(Landing::FAILED));

    // Presented to its own, it does — and the landing is its own shop's.
    get('/api/pay/brandfashion/paymob/callback?'.http_build_query($payload))
        ->assertRedirect(StorefrontUrls::paymentLanding(Storefront::BRAND_FASHION_ID, Landing::PAID, PaymentFixture::orderNumber($orderId)));

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing');
});

it('CHECK 3 — 403s an order that belongs to another storefront, even with a valid signature', function () {
    /*
     * The heart of it. Paymob's HMAC covers twenty fields and `merchant_order_id` is NOT one of
     * them, so the field that SELECTS the order is unsigned: a replay can change it. The storefront
     * match is what makes an unsigned selector safe — the order id can be swapped, but the order
     * cannot be moved into another storefront.
     */
    PaymentFixture::paymob(storefrontId: 1, hmacSecret: 'shared-test-secret');
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'shared-test-secret');

    $foreignOrder = PaymentFixture::order(total: 100.0, storefrontId: Storefront::BRAND_FASHION_ID);
    $before = paymentState($foreignOrder);

    // A perfectly signed callback for storefront 1's contract, naming storefront 2's order. The
    // landing carries NO order number: it is another shop's order.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($foreignOrder), 10000, secret: 'shared-test-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect(landing(Landing::FAILED));

    expect(paymentState($foreignOrder))->toBe($before, 'the foreign order must be untouched');
});

it('CHECK 4 — records the attempt but leaves the ORDER untouched when the amount disagrees', function () {
    PaymentFixture::paymob(hmacSecret: 'amount-secret');
    $orderId = PaymentFixture::order(total: 100.0);

    // The provider reports 1.00 for a 100.00 order. A finding, so the shopper is told the payment
    // is being CONFIRMED — not that it failed, and never that it succeeded.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 100, secret: 'amount-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))
        ->assertRedirect(landing(Landing::PENDING, PaymentFixture::orderNumber($orderId)));

    $order = T::row(DB::table('orders')->where('id', $orderId)->first(['status', 'paid_via_provider']));
    $attempt = T::row(DB::table('payment_statuses')->where('order_id', $orderId)->first(['success', 'amount_cents', 'provider']));

    expect($order->status)->toBe('pending', 'a mismatched amount leaves the order neither paid nor cancelled')
        ->and($order->paid_via_provider)->toBeNull()
        // The attempt IS on record, as a failure, with what the provider claimed.
        ->and($attempt->success)->toBe('false')
        ->and(T::int($attempt->amount_cents))->toBe(100)
        ->and(T::str($attempt->provider))->toBe('paymob')
        // Stock stays reserved: nothing was released, because nothing was decided.
        ->and(T::int(DB::table('inventory_movements')->where('reference_type', 'order')
            ->where('reference_id', $orderId)->count()))->toBe(0);
});

it('marks an order PAID once, recording who took the money', function () {
    PaymentFixture::paymob(hmacSecret: 'ok-secret');
    $method = PaymentFixture::method(PaymentFixture::paymob(hmacSecret: 'ok-secret'), 'card', '4001');
    $orderId = PaymentFixture::order(total: 250.0);

    $payload = PaymentFixture::callback(
        PaymentFixture::orderNumber($orderId), 25000, secret: 'ok-secret', integrationId: '4001'
    );

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))
        ->assertRedirect(landing(Landing::PAID, PaymentFixture::orderNumber($orderId)));

    $order = T::row(DB::table('orders')->where('id', $orderId)
        ->first(['status', 'paid_via_provider', 'paid_via_method']));
    $attempt = T::row(DB::table('payment_statuses')->where('order_id', $orderId)
        ->first(['success', 'provider', 'method', 'storefront_payment_method_id']));

    expect($order->status)->toBe('processing')
        ->and(T::str($order->paid_via_provider))->toBe('paymob')
        ->and($attempt->success)->toBe('true')
        ->and(T::str($attempt->provider))->toBe('paymob')
        // The method came from the provider's signed `integration_id`, mapped back to the row.
        ->and(T::int($attempt->storefront_payment_method_id))->toBe(T::int($method->getAttribute('id')))
        ->and(T::str($attempt->method))->toBe('card');
});

it('is IDEMPOTENT: a replayed callback records one attempt, and sends the browser to the PAID landing', function () {
    /*
     * This assertion used to be `assertOk()` — the replay answered `Already processed` as JSON to
     * every caller, on the written premise that a browser arriving on a replay is rare. It is not:
     * the processed POST wins the race to the server almost every time, so the browser normally
     * arrives on exactly this branch, and a shopper who had just paid was shown a code fragment on
     * a blank page (measured on the first successful live payment, 2026-10-12). A GET replay now
     * lands where a first delivery would have, with what the RECORD says; the provider's POST still
     * gets its JSON (CallbackLandingTest).
     */
    PaymentFixture::paymob(hmacSecret: 'replay-secret');
    $orderId = PaymentFixture::order(total: 75.0);
    $number = PaymentFixture::orderNumber($orderId);
    $payload = PaymentFixture::callback($number, 7500, secret: 'replay-secret', transactionId: 4242);
    $query = http_build_query($payload);

    get('/api/pay/watchizer/paymob/callback?'.$query)->assertRedirect(landing(Landing::PAID, $number));
    get('/api/pay/watchizer/paymob/callback?'.$query)->assertRedirect(landing(Landing::PAID, $number));   // the replay

    expect(T::int(DB::table('payment_statuses')->where('order_id', $orderId)->count()))->toBe(1);
});

it('lets TWO providers carry the SAME transaction id — the index correction', function () {
    /*
     * Wave 3 shipped `UNIQUE (pay_transaction_id)`. Transaction ids are unique only WITHIN a
     * provider, so with Paymob and Fawry both live a Fawry id colliding with a Paymob one would be
     * rejected as a replay and a real payment silently lost. The index is now
     * `UNIQUE (provider, pay_transaction_id)` — proven here as data, on the table itself.
     */
    $orderId = PaymentFixture::order(total: 10.0);
    $shared = 777777;

    DB::table('payment_statuses')->insert([
        'order_id' => $orderId, 'provider' => 'paymob', 'pay_transaction_id' => $shared,
        'amount_cents' => 1000, 'success' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('payment_statuses')->insert([
        'order_id' => $orderId, 'provider' => 'fawry', 'pay_transaction_id' => $shared,
        'amount_cents' => 1000, 'success' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(T::int(DB::table('payment_statuses')->where('pay_transaction_id', $shared)->count()))->toBe(2);

    // …and the same pair twice is still refused.
    expect(fn () => DB::table('payment_statuses')->insert([
        'order_id' => $orderId, 'provider' => 'fawry', 'pay_transaction_id' => $shared,
        'amount_cents' => 1000, 'success' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('runs the SAME checks through the legacy Watchizer alias', function () {
    // The alias is a routing convenience, never a second code path with second rules.
    PaymentFixture::paymob(storefrontId: 1, hmacSecret: 'alias-secret');
    $orderId = PaymentFixture::order(total: 30.0);

    // A bad signature is refused there too — and, the alias being Watchizer's, lands on Watchizer's page.
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 3000, secret: 'wrong')
    ))->assertRedirect(landing(Landing::FAILED));

    // And a foreign order is refused there too.
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'alias-secret');
    $foreign = PaymentFixture::order(total: 30.0, storefrontId: Storefront::BRAND_FASHION_ID);
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($foreign), 3000, secret: 'alias-secret')
    ))->assertRedirect(landing(Landing::FAILED));

    // A valid one for storefront 1 goes through.
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 3000, secret: 'alias-secret')
    ))->assertRedirect(landing(Landing::PAID, PaymentFixture::orderNumber($orderId)));

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing');
});

it('accepts a legacy order with NO storefront_id as the primary storefront, and only there', function () {
    /*
     * Orders written before core recorded a storefront carry NULL. Every one of them belongs to
     * Watchizer, the only storefront the legacy app ever served — so NULL is accepted for the
     * PRIMARY storefront and refused for any other. A deviation with a sunset: when every order
     * carries the column this branch is dead.
     */
    PaymentFixture::paymob(storefrontId: 1, hmacSecret: 'null-secret');
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'null-secret');

    $legacyOrder = PaymentFixture::order(total: 20.0, storefrontId: null);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($legacyOrder), 2000, secret: 'null-secret');

    // Refused on the secondary storefront, whose own failed landing the browser is sent to…
    get('/api/pay/brandfashion/paymob/callback?'.http_build_query($payload))
        ->assertRedirect(StorefrontUrls::paymentLanding(Storefront::BRAND_FASHION_ID, Landing::FAILED, null));
    expect(T::str(DB::table('orders')->where('id', $legacyOrder)->value('status')))->toBe('pending');

    // …accepted on the primary.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))
        ->assertRedirect(landing(Landing::PAID, PaymentFixture::orderNumber($legacyOrder)));
    expect(T::str(DB::table('orders')->where('id', $legacyOrder)->value('status')))->toBe('processing');
});
