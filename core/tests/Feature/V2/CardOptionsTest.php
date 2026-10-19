<?php

use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\getJson;

/*
 * The two card fields of 2026-10-17 (spec v2.11 §8.2 / §8.6, built for the Brand Fashion card):
 *
 *  - `variants` — the product's ACTIVE variant rows reduced to a picker's needs (id, colour, size,
 *    in_stock). SELECTABLE options from `catalog_product_variants`, kept apart by NAME from the
 *    DESCRIPTIVE `colors` (catalog_product_color) the same card carries: the review of 2026-10-17 found a
 *    two-tone dial drawn as two purchasable colours because the card rendered one as if it were the other.
 *  - `hover_image` — the product's second photo, for the card's hover swap; null when there is only one.
 *
 * Every state here is CONSTRUCTED inside the transaction (AGENTS §4): the variants through VariantFixture
 * (the same door the system uses), the images by inserting rows on a product this test picks.
 */

beforeEach(fn () => H::flush());

/**
 * The card of a product id, found through its brand's listing (paged) so the search stays short.
 *
 * @return array<string, mixed>
 */
function cardOf(int $productId): array
{
    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('slug'));
    $res = getJson(H::base('products/'.$slug))->assertOk();
    $brand = H::arr($res->json('product.brand'));
    $url = H::base('products?brand='.T::str($brand['slug']).'&per_page=96&sort=newest');
    for ($i = 0; $i < 10; $i++) {
        $body = H::arr(getJson($url)->assertOk()->json());
        foreach (H::rows($body['data']) as $card) {
            if (T::int($card['id']) === $productId) {
                return $card;
            }
        }
        $next = H::arr($body['links'])['next'] ?? null;
        if (! is_string($next)) {
            break;
        }
        $url = $next;
    }
    throw new RuntimeException("product {$productId} not found in its brand listing");
}

it('carries the variant rows a picker needs — and keeps them apart from the descriptive colours', function () {
    $f = VariantFixture::converted(2, 4);
    $productId = $f['product'];
    $colorId = T::int(DB::table('catalog_colors')->orderBy('id')->value('id'));
    $sizeId = T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));
    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->where('id', $f['variants'][0])->update(['color_id' => $colorId, 'size_id' => $sizeId]));
    // the second row is sold out: the picker must be told, not left to guess from the product aggregate
    app(App\Domain\Inventory\InventoryService::class)->set(App\Domain\Inventory\StockTarget::variant($productId, $f['variants'][1]), 'express', 0, 'manual');
    H::flush();

    $card = cardOf($productId);
    $variants = H::rows($card['variants']);
    expect($variants)->toHaveCount(2);
    expect($variants[0])->toHaveKeys(['id', 'color', 'size', 'in_stock'])
        ->and(T::int($variants[0]['id']))->toBe($f['variants'][0])
        ->and(H::arr($variants[0]['color']))->toHaveKeys(['id', 'hex', 'name'])
        ->and(T::int(H::arr($variants[0]['color'])['id']))->toBe($colorId)
        ->and(T::int(H::arr($variants[0]['size'])['id']))->toBe($sizeId)
        ->and($variants[0]['in_stock'])->toBeTrue()
        ->and($variants[1]['in_stock'])->toBeFalse()
        ->and($variants[1]['color'])->toBeNull();
    // a variant row never carries the attribute colours' `role`, and the descriptive list is untouched by the picker rows
    expect($variants[0])->not->toHaveKey('role');
    foreach (H::rows($card['colors']) as $c) {
        expect($c)->toHaveKeys(['id', 'hex', 'name', 'role']);
    }

    // the detail still answers with its FULL variant rows (price and both stock buckets) — array_replace, not `+`
    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('slug'));
    $detail = H::rows(getJson(H::base('products/'.$slug))->assertOk()->json('product.variants'));
    expect($detail)->toHaveCount(2)
        ->and($detail[0])->toHaveKeys(['id', 'sku', 'label', 'size', 'color', 'price', 'stock']);
});

it('answers an empty variants list for an ordinary product, so no picker is drawn (§11.5)', function () {
    $slug = H::visibleSlug();
    $productId = T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('slug', $slug)->value('product_id'));
    $card = cardOf($productId);
    expect($card['variants'])->toBe([]);
});

it('carries the second photo as hover_image, and null when there is only one', function () {
    $slug = H::visibleSlug();
    $productId = T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('slug', $slug)->value('product_id'));
    // construct the state: exactly one image, then a second one
    DB::table('catalog_product_images')->where('product_id', $productId)->delete();
    DB::table('catalog_product_images')->insert(['product_id' => $productId, 'path' => 'Product/test-cover.webp', 'is_cover' => 1, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    H::flush();
    $card = cardOf($productId);
    expect(H::arr($card['image'])['src'])->toContain('test-cover.webp')
        ->and($card['hover_image'])->toBeNull();

    DB::table('catalog_product_images')->insert(['product_id' => $productId, 'path' => 'Product/test-second.webp', 'is_cover' => 0, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
    H::flush();
    $card = cardOf($productId);
    expect(H::arr($card['image'])['src'])->toContain('test-cover.webp')
        ->and(H::arr($card['hover_image'])['src'])->toContain('test-second.webp');
});
