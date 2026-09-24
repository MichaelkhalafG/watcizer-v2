<?php

use App\Models\Storefront\StorefrontPaymentProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;

/*
 * `GET /api/callback_payment` — Watchizer's LIVE Paymob URL, and the most dangerous single line in
 * wave 4C.
 *
 * The URL cannot move: it lives in Paymob's merchant dashboard against an integration id, and
 * repointing it has no atomic cutover — a transaction in flight at that moment calls back to the
 * old address, leaving money taken and an order nobody confirms. So wave 4C keeps the URL and
 * changes what answers it.
 *
 * The first cut of that route simply took the URL over. Wave 4C also writes the `.env`-to-table
 * credential migration as a RUNBOOK STEP and deliberately does not perform it — so there was no
 * contract row, and the new handler answered "no enabled contract" (404) to every real callback.
 * Fourteen wave-3 tests said so. These tests hold the fix in place:
 *
 *   no contract, or no credentials  →  the proven wave-3 handler, byte-identical to today
 *   contract with credentials       →  the wave-4C four checks
 *
 * which makes inserting the credentials the cutover itself: no URL change, no deploy, and
 * reversible by disabling the contract.
 */

it('falls through to the wave-3 handler when no contract exists at all', function () {
    // The state of production right now, and of every rehearsal: the payments tables are empty.
    DB::table('storefront_payment_providers')->delete();

    // Wave-3 behaviour for a bad signature is 403. The 4C handler would answer 404 ("no contract"),
    // so the status code alone tells us which path served the request.
    $orderId = PaymentFixture::order(total: 100.0);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'not-the-configured-secret');

    get('/api/callback_payment?'.http_build_query($payload))->assertForbidden();
});

it('falls through when the contract exists but holds no credentials', function () {
    // A half-finished setup: someone added the contract in the dashboard and has not pasted the
    // keys yet. The live URL must keep working throughout that window.
    PaymentFixture::paymobWithoutCredentials();

    $orderId = PaymentFixture::order(total: 100.0);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'not-the-configured-secret');

    get('/api/callback_payment?'.http_build_query($payload))->assertForbidden();
});

it('falls through on a HALF-entered contract, instead of 403ing every callback', function () {
    /*
     * ── The gate asks COMPLETE, not merely SET (2026-09-23) ──────────────────────────────────
     *
     * An operator pastes `secret_key` and `public_key` and has not yet pasted `hmac_secret`. The
     * old gate — `credentialsSet()`, "is the array non-empty" — called that contract LIVE, handed
     * the URL to the 4C checks, and those cannot verify a signature without the HMAC secret: every
     * real callback answered 403, with the money taken and the order pending.
     *
     * Signed with the wave-3 GLOBAL secret, so success here can only mean the fall-through.
     */
    config(['services.paymob.hmac_secret' => 'the-global-wave3-secret', 'compat.payment_return_url' => 'https://watchizereg.test/']);
    StorefrontPaymentProvider::query()->updateOrCreate(
        ['storefront_id' => 1, 'provider' => 'paymob'],
        ['is_enabled' => true, 'settings' => null,
            'credentials' => ['secret_key' => 'sk-present', 'public_key' => 'pk-present']],
    );

    $orderId = PaymentFixture::order(total: 100.0);
    // The wave-3 handler reads `merchant_order_id` as the order ID, where the 4C path takes the
    // order NUMBER — so a success here is only possible through the fall-through.
    $payload = PaymentFixture::callback((string) $orderId, 10000, secret: 'the-global-wave3-secret');

    get('/api/callback_payment?'.http_build_query($payload))->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing');
});

it('reports exactly which required keys a contract is missing, by NAME', function () {
    $contract = StorefrontPaymentProvider::query()->updateOrCreate(
        ['storefront_id' => 1, 'provider' => 'paymob'],
        ['is_enabled' => true, 'settings' => null,
            'credentials' => ['secret_key' => 'sk', 'public_key' => '   ', 'hmac_secret' => '']],
    );
    $required = ['secret_key', 'public_key', 'hmac_secret'];

    // Blank and whitespace-only values are NOT present: a pasted space is not a key.
    expect($contract->credentialsSet())->toBeTrue()
        ->and($contract->credentialsComplete($required))->toBeFalse()
        ->and($contract->missingCredentials($required))->toBe(['public_key', 'hmac_secret']);
});

it('hands the URL to the wave-4C checks once the contract holds credentials', function () {
    $provider = PaymentFixture::paymob(hmacSecret: 'the-cutover-secret');
    PaymentFixture::method($provider);
    $orderId = PaymentFixture::order(total: 100.0);

    // Signed with the CONTRACT's secret, which the wave-3 path does not know: only the 4C handler
    // can verify this, so a success here proves the cutover happened.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'the-cutover-secret');

    get('/api/callback_payment?'.http_build_query($payload))->assertRedirect();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing')
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('paid_via_provider')))->toBe('paymob')
        // …and the attempt is recorded against the provider, which is the row the settlement
        // export reads.
        ->and(T::int(DB::table('payment_statuses')->where('order_id', $orderId)->where('provider', 'paymob')->count()))->toBe(1);
});

it('is reversible: disabling the contract puts the URL back on the wave-3 handler', function () {
    $provider = PaymentFixture::paymob(hmacSecret: 'the-cutover-secret');
    PaymentFixture::method($provider);

    // The rollback an operator actually has on a bad night — one switch in the dashboard, no
    // deploy and no merchant-portal edit.
    $provider->update(['is_enabled' => false]);

    $orderId = PaymentFixture::order(total: 100.0);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'the-cutover-secret');

    // Back to wave-3 rules: this payload is signed with a secret the legacy path does not hold, so
    // it is refused with wave-3's 403 rather than accepted by the 4C path.
    get('/api/callback_payment?'.http_build_query($payload))->assertForbidden();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending');
});

it('leaves the SCOPED route unconditional, because a new storefront has nothing to fall back to', function () {
    DB::table('storefront_payment_providers')->delete();

    $orderId = PaymentFixture::order(total: 100.0);
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'anything');

    // No contract, no fall-through: the scoped URL is 4C's own and answers 404, saying nothing
    // about whether the storefront or the provider is the part that does not exist.
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertNotFound();

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending')
        ->and(T::int(DB::table('payment_statuses')->where('order_id', $orderId)->count()))->toBe(0);
});

it('keeps the cutover condition in DATA, not in a config flag', function () {
    // Stated as a test because it is a design decision that a future change could quietly undo: a
    // flag is a second thing to get right on switch night and can disagree with the table it
    // describes. The credentials being present is the only condition under which the new path can
    // verify a signature at all, so it IS the condition.
    $provider = PaymentFixture::paymob(hmacSecret: 'secret-a');

    expect($provider->credentialsSet())->toBeTrue();

    $provider->update(['credentials' => null]);
    $fresh = StorefrontPaymentProvider::query()->whereKey($provider->getKey())->firstOrFail();

    expect($fresh->credentialsSet())->toBeFalse();
});
