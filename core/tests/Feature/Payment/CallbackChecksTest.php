<?php

use App\Models\Storefront\Storefront;
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
 */

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

    // Unknown storefront code.
    get('/api/pay/nosuchshop/paymob/callback?id=1')->assertNotFound();

    // Known storefront, but no contract for that provider.
    get('/api/pay/watchizer/fawry/callback?id=1')->assertNotFound();

    expect(paymentState($orderId))->toBe($before);
});

it('CHECK 1 — 404s a DISABLED contract and one holding no credentials', function () {
    $orderId = PaymentFixture::order();
    $before = paymentState($orderId);

    PaymentFixture::paymob(enabled: false);
    get('/api/pay/watchizer/paymob/callback?id=1')->assertNotFound();

    // Enabled but credential-less: verifying with an empty secret is how a forged callback is
    // accepted, so the contract is treated as unusable rather than as "verify anyway".
    PaymentFixture::paymobWithoutCredentials();
    get('/api/pay/watchizer/paymob/callback?id=1')->assertNotFound();

    expect(paymentState($orderId))->toBe($before);
});

it('CHECK 2 — 403s a payload whose signature does not verify against THIS contract', function () {
    PaymentFixture::paymob(hmacSecret: 'the-real-secret');
    $orderId = PaymentFixture::order(total: 100.0);
    $before = paymentState($orderId);

    // Signed with a different secret: the shape is perfect, the signature is not this contract's.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'some-other-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertForbidden();

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

    // Presented to storefront 1's route, it does not verify.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertForbidden();

    // Presented to its own, it does.
    get('/api/pay/brandfashion/paymob/callback?'.http_build_query($payload))->assertRedirect();

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

    // A perfectly signed callback for storefront 1's contract, naming storefront 2's order.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($foreignOrder), 10000, secret: 'shared-test-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertForbidden();

    expect(paymentState($foreignOrder))->toBe($before, 'the foreign order must be untouched');
});

it('CHECK 4 — records the attempt but leaves the ORDER untouched when the amount disagrees', function () {
    PaymentFixture::paymob(hmacSecret: 'amount-secret');
    $orderId = PaymentFixture::order(total: 100.0);

    // The provider reports 1.00 for a 100.00 order.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 100, secret: 'amount-secret');

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect();

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

    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect();

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

it('is IDEMPOTENT: a replayed callback records one attempt and answers 200', function () {
    PaymentFixture::paymob(hmacSecret: 'replay-secret');
    $orderId = PaymentFixture::order(total: 75.0);
    $payload = PaymentFixture::callback(
        PaymentFixture::orderNumber($orderId), 7500, secret: 'replay-secret', transactionId: 4242
    );
    $query = http_build_query($payload);

    get('/api/pay/watchizer/paymob/callback?'.$query)->assertRedirect();
    get('/api/pay/watchizer/paymob/callback?'.$query)->assertOk();   // "already processed"

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

    // A bad signature is refused there too.
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 3000, secret: 'wrong')
    ))->assertForbidden();

    // And a foreign order is refused there too.
    PaymentFixture::paymob(storefrontId: Storefront::BRAND_FASHION_ID, hmacSecret: 'alias-secret');
    $foreign = PaymentFixture::order(total: 30.0, storefrontId: Storefront::BRAND_FASHION_ID);
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($foreign), 3000, secret: 'alias-secret')
    ))->assertForbidden();

    // A valid one for storefront 1 goes through.
    get('/api/callback_payment?'.http_build_query(
        PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 3000, secret: 'alias-secret')
    ))->assertRedirect();

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

    // Refused on the secondary storefront…
    get('/api/pay/brandfashion/paymob/callback?'.http_build_query($payload))->assertForbidden();
    expect(T::str(DB::table('orders')->where('id', $legacyOrder)->value('status')))->toBe('pending');

    // …accepted on the primary.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertRedirect();
    expect(T::str(DB::table('orders')->where('id', $legacyOrder)->value('status')))->toBe('processing');
});
