<?php

use function Pest\Laravel\withHeaders;

/*
 * ── Field errors in the shopper's language on the three writes that show them (2026-09-23) ────
 *
 * The legacy host sets the locale from Accept-Language on EVERY request — default `en` — and
 * ships an Arabic `validation.php`, so an Arabic phone gets Arabic field errors under the checkout
 * inputs and everything else English (`Checkout.jsx` renders `errors[field][0]`).
 * `add_address`, `remove_from_cart` and `add_order` sat outside core's negotiating group and
 * answered in `APP_LOCALE`, which is `ar` on the server since 2026-09-20: after the flip every
 * shopper, English browser included, would have got Arabic errors.
 *
 * The first draft of this file got that direction backwards (it assumed core answered English)
 * and its Arabic test passed with the fix REMOVED — because core was already Arabic for everyone.
 * The test that actually fails without the fix is the ENGLISH one below.
 *
 * Phrasing is NOT byte-identical to legacy (the two Arabic files differ on a few rules): that is
 * sanctioned as D-25, and the harness's new `:ar` cases exercise it.
 */

const LOCALE_API_KEY = 'locale-test-api-code';

beforeEach(function () {
    config(['compat.api_key' => LOCALE_API_KEY]);
});

/** @return array<string, string> */
function localeHeaders(?string $acceptLanguage): array
{
    $headers = ['Api-Code' => LOCALE_API_KEY, 'Accept' => 'application/json'];
    if ($acceptLanguage !== null) {
        $headers['Accept-Language'] = $acceptLanguage;
    }

    return $headers;
}

/**
 * The first error message in a 422 body, whatever the field, or '' when there is none.
 *
 * Takes `mixed` because that is what a decoded response body is; every step is narrowed rather
 * than cast, so a body of an unexpected shape reads as "no message" instead of a PHP warning.
 */
function firstFieldError(mixed $body): string
{
    $errors = is_array($body) ? ($body['errors'] ?? null) : null;
    if (! is_array($errors)) {
        return '';
    }
    foreach ($errors as $messages) {
        $first = is_array($messages) ? ($messages[0] ?? null) : null;

        return is_string($first) ? $first : '';
    }

    return '';
}

it('answers add_address field errors in ARABIC for an Arabic browser', function () {
    $body = withHeaders(localeHeaders('ar-EG,ar;q=0.9'))->postJson('/api/add_address', [])
        ->assertStatus(422)->json();

    // Arabic script, not merely "not English": the assertion that would have failed before.
    expect(firstFieldError($body))->toMatch('/\p{Arabic}/u');
});

it('answers remove_from_cart and add_order field errors in Arabic too', function (string $path, array $payload) {
    $body = withHeaders(localeHeaders('ar-EG,ar;q=0.9'))->postJson($path, $payload)->json();

    expect(firstFieldError($body))->toMatch('/\p{Arabic}/u');
})->with([
    'remove_from_cart, wrong type' => ['/api/remove_from_cart', ['product_id' => 'abc']],
    'add_order, unknown method' => ['/api/add_order', ['payment_method' => 'bitcoin']],
]);

it('answers ENGLISH to a browser that does not ask for Arabic, as legacy does — not APP_LOCALE', function () {
    /*
     * THE assertion this change exists for. Without the negotiation these routes answer in
     * `APP_LOCALE` (`ar` on the server and in this suite), so a shopper browsing in English got
     * Arabic field errors, and the harness's header-less write cases would have failed the next
     * re-diff against legacy's default `en`. Verified to fail with the middleware removed.
     */
    $requests = [
        ['/api/add_address', []],
        ['/api/remove_from_cart', ['product_id' => 'abc']],
        ['/api/add_order', ['payment_method' => 'bitcoin']],
    ];

    // Collected rather than asserted inline, so one run names every route that answered Arabic.
    $arabic = [];
    foreach ($requests as [$path, $payload]) {
        foreach ([null, 'en-US,en;q=0.9'] as $acceptLanguage) {
            $message = firstFieldError(withHeaders(localeHeaders($acceptLanguage))->postJson($path, $payload)->json());
            if ($message === '' || preg_match('/\p{Arabic}/u', $message) === 1) {
                $arabic[] = $path.' ['.($acceptLanguage ?? 'no header').']: '.$message;
            }
        }
    }

    expect($arabic)->toBe([]);
});

it('does NOT negotiate on add_to_cart, where legacy never shows field text', function () {
    /*
     * Legacy catches `\Exception` around AddToCart, so a validation failure there is a 500 with a
     * ref and no field text reaches the client at all. Negotiating would change nothing a shopper
     * can see; leaving it out keeps the change to exactly the routes it is for.
     */
    $middleware = collect(app('router')->getRoutes()->getRoutes())
        ->mapWithKeys(fn ($route) => [$route->uri() => $route->gatherMiddleware()]);

    expect($middleware['api/add_to_cart'])->not->toContain('legacy.locale')
        ->and($middleware['api/add_address'])->toContain('legacy.locale')
        ->and($middleware['api/remove_from_cart'])->toContain('legacy.locale')
        ->and($middleware['api/add_order'])->toContain('legacy.locale');
});
