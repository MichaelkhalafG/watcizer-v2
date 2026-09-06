<?php

use App\Support\LegacySlug;

it('mirrors the storefront toSlug() exactly', function (string $name, string $expected) {
    expect(LegacySlug::make($name))->toBe($expected);
})->with([
    ['Hugo Boss Watch For Men 1514217', 'hugo-boss-watch-for-men-1514217'],
    ['Tommy Hilfiger Men’s Black Pebbled Reporter Crossbody Bag', 'tommy-hilfiger-mens-black-pebbled-reporter-crossbody-bag'],
    ['Belts & Wallets', 'belts-wallets'],
    ['  Watches  ', 'watches'],
    ['Fashion', 'fashion'],
    ['ساعات', ''],
    ['Rosé -- Gold!!', 'ros-gold'],
]);

it('falls back to the id when the name slugifies to nothing, as the legacy sitemap does', function () {
    expect(LegacySlug::orId('ساعات', 42))->toBe('42')
        ->and(LegacySlug::orId('Diver', 1))->toBe('diver');
});
