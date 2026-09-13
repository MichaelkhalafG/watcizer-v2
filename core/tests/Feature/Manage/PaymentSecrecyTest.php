<?php

use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The 4C non-negotiable: credentials are encrypted at rest, NEVER rendered, NEVER logged.
 *
 * These tests do not read the code and agree with it; they take a contract with a known secret,
 * open every screen that touches payments, and search the ENTIRE response body for that secret.
 * A prop, a hidden input, an error message that echoes the old value, a stray `dd()` — all of them
 * fail here, which is the point: "we are careful" is not a control.
 *
 * The one thing the screen may say about a secret is whether it is SET and which key names are
 * present. That is asserted too, because a screen that says nothing at all makes a key rotation
 * unverifiable.
 */

/** A distinctive value: if it appears anywhere in a response, it came from the store. */
const SECRET = 'sk-LIVE-7f3c9d2b-DO-NOT-RENDER';

it('never renders a stored credential on the payments screen', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET, 'public_key' => 'pk-x', 'hmac_secret' => 'h-x']]);
    PaymentFixture::method($provider);

    $response = actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk();

    // The whole rendered document, not just the props: the Inertia page is serialised into the
    // HTML, so this catches a leak through any path.
    expect($response->getContent())->not->toContain(SECRET)
        ->and($response->getContent())->not->toContain('pk-x')
        ->and($response->getContent())->not->toContain('h-x');
});

it('says whether each key is SET without saying what it is, so a rotation is checkable', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET, 'public_key' => 'pk-x']]);

    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/payments')->assertOk());
    $rows = T::arr($props['providers'] ?? []);
    expect($rows)->not->toBeEmpty();

    $row = T::arr($rows[0] ?? []);

    expect($row['credentials_set'] ?? null)->toBeTrue()
        // KEY NAMES only…
        ->and(T::arr($row['credential_keys_present'] ?? []))->toContain('secret_key')
        ->and(T::arr($row['credential_keys_present'] ?? []))->toContain('public_key')
        // …and the honest verdict that the third one is missing, which is exactly what an admin
        // needs to see after a half-finished rotation.
        ->and($row['credentials_complete'] ?? null)->toBeFalse()
        // …and no values, under any key.
        ->and(json_encode($row))->not->toContain(SECRET);
});

it('keeps the secret out of the model’s own array form, which IS the Inertia prop', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET]]);

    $fresh = StorefrontPaymentProvider::query()->whereKey($provider->getKey())->firstOrFail();

    // `$hidden` is the belt: an Inertia prop is `toArray()`, so a model handed to a screen by a
    // future controller cannot carry the secret even if nobody remembers to strip it.
    expect(json_encode($fresh->toArray()))->not->toContain(SECRET)
        // …and the braces: the value is still THERE and still readable by the domain.
        ->and($fresh->getAttribute('credentials'))->toBe(['secret_key' => SECRET]);
});

it('stores the credential encrypted, so a database read cannot lift it', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET]]);

    $raw = T::str(DB::table('storefront_payment_providers')->where('id', $provider->getKey())->value('credentials'));

    // What a `SELECT` returns — a dump, a replica, a stolen backup — must not contain the secret.
    expect($raw)->not->toContain(SECRET)
        ->and($raw)->not->toBe('')
        // Laravel's encrypter emits base64 JSON carrying an iv/value/mac envelope.
        ->and(base64_decode($raw, true))->toContain('"iv"');
});

it('never logs a credential when a callback is verified', function () {
    $provider = PaymentFixture::paymob(hmacSecret: SECRET);
    PaymentFixture::method($provider);
    $orderId = PaymentFixture::order(total: 100.0);

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    // A payload signed with the WRONG secret: the failure path is the one most likely to log the
    // expected value "for debugging", and it is the one that must not.
    $payload = PaymentFixture::callback(PaymentFixture::orderNumber($orderId), 10000, secret: 'wrong-secret');
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($payload))->assertForbidden();

    expect($lines)->not->toBeEmpty('the signature failure must be logged — silently dropping it would hide an attack');

    foreach ($lines as $line) {
        expect($line)->not->toContain(SECRET, 'a log line carried the hmac secret');
    }
});

it('keeps the secret out of a validation error when the form is rejected', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET]]);

    // A second contract with the same provider is refused. The refusal must not echo the stored
    // credentials back through the error bag or the flashed input.
    $response = actingAs(Staff::admin())
        ->post('/manage/storefronts/1/payments/providers', ['provider' => 'paymob', 'is_enabled' => true])
        ->assertSessionHasErrors('provider');

    expect(json_encode(session()->all()))->not->toContain(SECRET)
        ->and($response->getContent())->not->toContain(SECRET);
});

it('keeps a BLANK credential field from clearing the stored value', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET, 'public_key' => 'pk-x', 'hmac_secret' => 'h-x']]);

    // Saving the enable switch, with the credential inputs untouched (blank), as the screen posts
    // them. A blank that cleared the key would break payments on a save nobody thought was risky.
    $providerId = Coerce::int($provider->getKey());

    actingAs(Staff::admin())->put("/manage/storefronts/1/payments/providers/{$providerId}", [
        'is_enabled' => false,
        'credentials' => ['secret_key' => '', 'public_key' => '', 'hmac_secret' => ''],
    ])->assertRedirect();

    $fresh = StorefrontPaymentProvider::query()->whereKey($provider->getKey())->firstOrFail();

    expect($fresh->getAttribute('is_enabled'))->toBeFalsy()
        ->and($fresh->getAttribute('credentials'))->toBe([
            'secret_key' => SECRET, 'public_key' => 'pk-x', 'hmac_secret' => 'h-x',
        ]);
});

it('replaces only the field that was filled in, and drops an undeclared key', function () {
    $provider = PaymentFixture::paymob();
    $provider->update(['credentials' => ['secret_key' => SECRET, 'public_key' => 'pk-x', 'hmac_secret' => 'h-x']]);

    $providerId = Coerce::int($provider->getKey());

    actingAs(Staff::admin())->put("/manage/storefronts/1/payments/providers/{$providerId}", [
        'is_enabled' => true,
        'credentials' => [
            'secret_key' => 'sk-ROTATED',
            'public_key' => '',
            // A key the provider does not declare is dropped rather than stored: an attacker who
            // can post a form must not be able to plant arbitrary data in an encrypted blob the
            // domain later reads by name.
            'planted' => 'nonsense',
        ],
    ])->assertRedirect();

    $fresh = StorefrontPaymentProvider::query()->whereKey($provider->getKey())->firstOrFail();
    $credentials = $fresh->getAttribute('credentials');

    expect($credentials)->toBe([
        'secret_key' => 'sk-ROTATED', 'public_key' => 'pk-x', 'hmac_secret' => 'h-x',
    ])->and($credentials)->not->toHaveKey('planted');
});
