<?php

use App\Compat\LegacyLocaleNegotiator;

/*
 * The legacy host negotiates the app locale from Accept-Language (mcamara/laravel-localization,
 * supported en/ar, default en) for EVERY request — verified 2026-09-07 on the running legacy app.
 */

it('negotiates like the legacy host', function (?string $header, string $expected) {
    expect(LegacyLocaleNegotiator::negotiate($header, ['en' => 'en_GB', 'ar' => 'ar_AE'], 'en'))->toBe($expected);
})->with([
    [null, 'en'],
    ['', 'en'],
    ['ar', 'ar'],
    ['ar-EG,ar;q=0.9,en-US;q=0.8,en;q=0.7', 'ar'],
    ['en-US,en;q=0.9,ar;q=0.8', 'en'],
    ['fr-FR,fr;q=0.9', 'en'],
    ['fr;q=0.9,ar;q=0.8', 'ar'],
    ['*', 'en'],
    ['ar_AE', 'ar'],
    ['de, en;q=0.5, ar;q=0.6', 'ar'],
]);
