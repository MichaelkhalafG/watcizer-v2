<?php

use App\Compat\LegacyJson;

/*
 * `LegacyJson::imageUrl()` — the product page's gallery, cover and brand-logo URLs (CompatProductDetail),
 * and the Meta feed's (2026-10-10). Pinned to exact strings for every path shape: the two shapes the
 * catalogue actually holds (`Product/<file>` 7,687 rows, `Product_image/<file>` 3,709 rows, on
 * wz_prod_20261008 — no empty, null, slashed, absolute or spaced path among them) and the edge cases.
 *
 * The 2026-10-10 change added an optional `$base` (the feed's image host). With it absent or null the
 * output must be byte-identical to before; measured against HEAD's copy on 274,044 (path, folder, base)
 * pairs, 0 differed — these cases keep it that way.
 */

const IMG_BASE = 'https://api.watchizereg.com';

dataset('image url shapes', [
    // [path, folder, expected]
    'cover, bare file (legacyImage strips Product/)' => ['1784465057_2026-07-19_6a5cc6a197bec.webp', 'Product', IMG_BASE.'/Uploads_Images/Product/1784465057_2026-07-19_6a5cc6a197bec.webp'],
    'cover kept under Product_image/' => ['Product_image/1789839590_2026-09-19_6aaec8e6313d8.webp', 'Product', IMG_BASE.'/Uploads_Images/Product_image/1789839590_2026-09-19_6aaec8e6313d8.webp'],
    'gallery, bare file' => ['1784472826_g0_6a5ce4fac298c.webp', 'Product_image', IMG_BASE.'/Uploads_Images/Product_image/1784472826_g0_6a5ce4fac298c.webp'],
    'brand logo' => ['1784479110_2026-07-19_6a5cfd8652bf6.webp', 'Brand', IMG_BASE.'/Uploads_Images/Brand/1784479110_2026-07-19_6a5cfd8652bf6.webp'],
    'raw path with its folder' => ['Product/x.webp', 'Product', IMG_BASE.'/Uploads_Images/Product/x.webp'],
    'leading slash' => ['/x.webp', 'Product', IMG_BASE.'/Uploads_Images/Product/x.webp'],
    'leading slash with folder' => ['/Product/x.webp', 'Product', IMG_BASE.'/Uploads_Images/Product/x.webp'],
    'double leading slash' => ['//x.webp', 'Product', IMG_BASE.'/Uploads_Images/Product/x.webp'],
    'absolute URL passes through' => ['https://cdn.example/x.webp', 'Product', 'https://cdn.example/x.webp'],
    'absolute URL, upper-case scheme' => ['HTTP://cdn.example/x.webp', 'Product', 'HTTP://cdn.example/x.webp'],
    'empty' => ['', 'Product', null],
    'null' => [null, 'Product', null],
    'the string 0 is a file name, not empty' => ['0', 'Product', IMG_BASE.'/Uploads_Images/Product/0'],
]);

it('builds exactly the URL it always built, for every path shape', function (?string $path, string $folder, ?string $expected) {
    config()->set('compat.asset_base', IMG_BASE);

    expect(LegacyJson::imageUrl($path, $folder))->toBe($expected)
        ->and(LegacyJson::imageUrl($path, $folder, null))->toBe($expected);   // the new argument, absent = null
})->with('image url shapes');

it('never doubles the slash when the configured base ends in one', function (?string $path, string $folder, ?string $expected) {
    config()->set('compat.asset_base', IMG_BASE.'/');

    expect(LegacyJson::imageUrl($path, $folder))->toBe($expected);
})->with('image url shapes');

it('puts the image on the base it is given, and only there', function () {
    config()->set('compat.asset_base', IMG_BASE);

    expect(LegacyJson::imageUrl('x.webp', 'Product', 'https://images.example.test/'))->toBe('https://images.example.test/Uploads_Images/Product/x.webp')
        ->and(LegacyJson::imageUrl('https://cdn.example/x.webp', 'Product', 'https://images.example.test'))->toBe('https://cdn.example/x.webp')
        ->and(LegacyJson::imageUrl(null, 'Product', 'https://images.example.test'))->toBeNull();
});
