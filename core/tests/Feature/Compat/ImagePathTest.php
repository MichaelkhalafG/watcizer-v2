<?php

use App\Compat\LegacyJson;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * ── Compat keeps an image's stored folder unless it is the one legacy used (2026-09-26) ──────
 *
 * The dashboard stores a cover in `Product_image/` (a cover is now one of the gallery images).
 * Compat stripped every image path to a bare filename and the readers put `Product/` back, so
 * every cover uploaded after the cutover 404'd on the listing, the product page and the image
 * sitemap. The rule now lives in LegacyJson::legacyImage(); this file holds both halves of it:
 * a moved image keeps its folder everywhere, and a legacy-folder image is byte-identical to
 * what the legacy host emitted.
 */

const IMG_API_KEY = 'image-path-test-api-code';

beforeEach(function () {
    config(['compat.api_key' => IMG_API_KEY]);
});

/** @return TestResponse<Response> */
function imgCompat(string $path): TestResponse
{
    return withHeaders(['Api-Code' => IMG_API_KEY, 'Accept' => 'application/json'])->get('/api/'.$path);
}

/**
 * Two products on the listing whose covers sit in legacy's `Product/` folder.
 *
 * @return array{0: int, 1: int}
 */
function twoLegacyCoverProducts(): array
{
    $listed = [];
    foreach (catalogueReferenceRows() as $row) {
        if (isset($row['id'])) {
            $listed[] = T::int($row['id']);
        }
    }
    $ids = DB::table('catalog_product_images')->where('is_cover', 1)->where('path', 'like', 'Product/%')
        ->whereIn('product_id', array_slice($listed, 0, 400))->orderBy('product_id')->limit(2)->pluck('product_id')->all();
    expect($ids)->toHaveCount(2);

    return [T::int($ids[0]), T::int($ids[1])];
}

/** @param  TestResponse<Response>  $response */
function withSlashes(TestResponse $response): string
{
    return (string) json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

it('drops the folder ONLY when it is the one legacy used for that field', function () {
    expect(LegacyJson::legacyImage('Product/a.webp', 'Product'))->toBe('a.webp')
        ->and(LegacyJson::legacyImage('Product_image/a.webp', 'Product'))->toBe('Product_image/a.webp')
        ->and(LegacyJson::legacyImage('Product_image/a.webp', 'Product_image'))->toBe('a.webp')
        ->and(LegacyJson::legacyImage('Category_type/s.webp', 'Sub_type'))->toBe('Category_type/s.webp')
        ->and(LegacyJson::legacyImage('/Brand/b.png', 'Brand'))->toBe('b.png')
        ->and(LegacyJson::legacyImage('bare.webp', 'Product'))->toBe('bare.webp')
        ->and(LegacyJson::legacyImage('https://cdn.example/x.webp', 'Product'))->toBe('https://cdn.example/x.webp')
        ->and(LegacyJson::legacyImage(null, 'Product'))->toBeNull()
        ->and(LegacyJson::legacyImage('', 'Product'))->toBeNull();
});

it('sends a cover stored in Product_image/ with its folder on the listing, the product page and the sitemap', function () {
    [$moved, $legacy] = twoLegacyCoverProducts();
    $legacyFile = basename(T::str(DB::table('catalog_product_images')->where('product_id', $legacy)->where('is_cover', 1)->value('path')));

    DB::table('catalog_product_images')->where('product_id', $moved)->where('is_cover', 1)
        ->update(['path' => 'Product_image/zz-moved-cover.webp']);
    app(StorefrontCache::class)->flush(1);
    $base = rtrim(config()->string('compat.asset_base'), '/');

    // the listing: what the storefront's cards read
    $images = [];
    foreach (catalogueReferenceRows() as $row) {
        if (isset($row['id'])) {
            $images[T::int($row['id'])] = $row['image'] ?? null;
        }
    }
    expect($images[$moved] ?? null)->toBe('Product_image/zz-moved-cover.webp')
        ->and($images[$legacy] ?? null)->toBe($legacyFile);                  // legacy row: unchanged

    // the product page
    expect(imgCompat("products/{$moved}")->assertOk()->json('product.image'))->toBe($base.'/Uploads_Images/Product_image/zz-moved-cover.webp')
        ->and(imgCompat("products/{$legacy}")->assertOk()->json('product.image'))->toBe($base.'/Uploads_Images/Product/'.$legacyFile);

    // the image sitemap Google crawls
    $sitemap = (string) get('/en/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain('/Uploads_Images/Product_image/zz-moved-cover.webp')
        ->and($sitemap)->not->toContain('/Uploads_Images/Product/zz-moved-cover.webp');
});

it('keeps a category image written to Category_type/ in its own folder', function () {
    $sub = DB::table('storefront_categories')->where('storefront_id', 1)->where('depth', 2)->whereNotNull('image_path')->orderBy('id')->value('id');
    expect($sub)->not->toBeNull();
    DB::table('storefront_categories')->where('id', $sub)->update(['image_path' => 'Category_type/zz-sub.webp']);
    app(StorefrontCache::class)->flush(1);

    $meta = withSlashes(imgCompat('catalog/meta')->assertOk());

    expect($meta)->toContain('"image":"Category_type/zz-sub.webp"')
        ->and($meta)->toContain('/Uploads_Images/Category_type/zz-sub.webp')
        ->and($meta)->not->toContain('/Uploads_Images/Sub_type/Category_type/');
});
