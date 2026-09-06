<?php

use App\Transform\Steps\Step18StorefrontProduct;

/*
 * 🟠-3 re-verification (2026-09-07): the identity guard is the only door for a twin redirect row.
 * Every attempt to sneak a source == target row through — plain, case-shifted, padded — is refused.
 */

it('refuses an identity redirect in every disguise', function (string $from, string $to) {
    expect(Step18StorefrontProduct::redirectRow(1, $from, $to, null, null))->toBeNull();
})->with([
    ['/product/hugo-boss-watch-for-men-1514217', '/product/hugo-boss-watch-for-men-1514217'],
    ['/product/hugo-boss-watch-for-men-1514217', '/product/HUGO-BOSS-WATCH-FOR-MEN-1514217'],
    ['/product/hugo-boss-watch-for-men-1514217', ' /product/hugo-boss-watch-for-men-1514217 '],
    ['/PRODUCT/x', '/product/x'],
]);

it('writes a real redirect when the kept product moved to a different canonical slug', function () {
    $row = Step18StorefrontProduct::redirectRow(1, '/product/hugo-boss-watch-for-men-1514217', '/product/hugo-boss-watch-for-men-1514217-71', '2026-01-01 00:00:00', null);

    expect($row)->not->toBeNull()
        ->and($row['to_path'] ?? null)->toBe('/product/hugo-boss-watch-for-men-1514217-71')
        ->and($row['source'] ?? null)->toBe('legacy_twin')
        ->and($row['status'] ?? null)->toBe(301);
});
