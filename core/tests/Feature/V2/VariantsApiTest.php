<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\getJson;

/*
 * Wave 3.5 — what the v2 API says about variants.
 *
 * The storefront contract: `variants` is an empty list for a product that has none (every watch
 * and every bag today), and the card's product-level `stock` is what the shopper buys against.
 * When the list is not empty the card's `stock` is the maintained AGGREGATE and the shopper must
 * pick a variant — each carries its own price and its own availability.
 *
 * The listing's `in_stock` filter and the storefront's idea of "orderable" both read
 * `catalog_products.in_stock`, which for a variant product means "some ACTIVE variant has stock".
 * That is the one thing no quantity check would catch, so it is asserted here at the API level as
 * well as at the service level.
 */

beforeEach(fn () => H::flush());

/**
 * Every product id the in-stock listing returns, paged to the end.
 *
 * Paged rather than "the first 96": the listing's default sort is `newest`, and a low-id product
 * sits on the last page — a single-page check would "pass" by never looking.
 *
 * @return list<int>
 */
function inStockListingIds(): array
{
    $ids = [];
    $url = H::base('products?in_stock=1&per_page=96');
    for ($page = 0; $page < 12; $page++) {
        $body = H::arr(getJson($url)->assertOk()->json());
        foreach (H::rows($body['data']) as $card) {
            $ids[] = T::int($card['id']);
        }
        $next = H::arr($body['links'])['next'] ?? null;
        if (! is_string($next)) {
            break;
        }
        $url = $next;
    }

    return $ids;
}

/** The product id behind a visible slug. */
function productIdForSlug(string $slug): int
{
    return T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('slug', $slug)->value('product_id'));
}

it('emits an empty variants list for an ordinary watch, and keeps its product-level stock', function () {
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    $express = T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));

    $product = H::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product'));

    expect($product['variants'])->toBe([])
        ->and(H::arr($product['stock'])['express'])->toBe($express);
});

it('exposes each variant with its own label, price and availability', function () {
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    $f = VariantFixture::converted(2, 4, $productId);
    // A surcharge on the second size, and a different quantity, so nothing can pass by symmetry.
    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->where('id', $f['variants'][1])->update(['price_delta' => '150.00', 'label' => 'Large']));
    app(InventoryService::class)->set(StockTarget::variant($productId, $f['variants'][1]), 'express', 7, 'manual');
    H::flush();

    $product = H::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product'));
    $variants = H::rows($product['variants']);
    $base = T::float(H::arr($product['price'])['amount']);

    expect($variants)->toHaveCount(2)
        ->and($variants[0]['id'])->toBe($f['variants'][0])
        ->and($variants[1]['label'])->toBe('Large')
        ->and($variants[0])->toHaveKeys(['id', 'sku', 'label', 'size', 'color', 'price', 'stock'])
        ->and(H::arr($variants[0]['stock'])['express'])->toBe(4)
        ->and(H::arr($variants[1]['stock'])['express'])->toBe(7)
        ->and(H::arr($variants[1]['price'])['amount'])->toEqual(round($base + 150, 2))
        ->and(H::arr($variants[0]['price'])['amount'])->toEqual($base);

    // The card's stock is the aggregate of the two, and the product is orderable.
    expect(H::arr($product['stock'])['express'])->toBe(11)
        ->and(H::arr($product['stock'])['in_stock'])->toBeTrue();
});

it('omits an INACTIVE variant from the list while its units still count in the quantity', function () {
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    $f = VariantFixture::converted(2, 5, $productId);
    StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->where('id', $f['variants'][1])->update(['is_active' => 0]));
    app(InventoryService::class)->recomputeInStock($productId);
    H::flush();

    $product = H::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product'));

    expect(H::rows($product['variants']))->toHaveCount(1)                       // only the buyable one
        ->and(H::arr($product['stock'])['express'])->toBe(10)                   // both still in the warehouse
        ->and(H::arr($product['stock'])['in_stock'])->toBeTrue();
});

it('reports a product whose only ACTIVE variant is out of stock as NOT in stock', function () {
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    $f = VariantFixture::converted(1, 0, $productId);            // one variant, zero units
    H::flush();

    $product = H::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product'));
    $variants = H::rows($product['variants']);

    expect($variants)->toHaveCount(1)
        ->and(H::arr($variants[0]['stock'])['in_stock'])->toBeFalse()
        ->and(H::arr($product['stock'])['in_stock'])->toBeFalse()
        ->and(H::arr($product['stock'])['express'])->toBe(0)
        ->and($f['variants'])->toHaveCount(1);
});

it('keeps the in_stock listing filter honest for a variant product', function () {
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    VariantFixture::converted(1, 0, $productId);                 // sold out at the only size
    H::flush();

    expect(inStockListingIds())->not->toContain($productId);

    // Restock that size: the product comes back into the filtered listing, aggregate and all.
    $variantId = T::int(DB::table('catalog_product_variants')->where('product_id', $productId)->value('id'));
    app(InventoryService::class)->set(StockTarget::variant($productId, $variantId), 'express', 3, 'restock');
    H::flush();

    expect(inStockListingIds())->toContain($productId);
});

it('never recommends a variant product that has nothing buyable left', function () {
    // `related` filters on `p.in_stock`, which is the aggregate flag — so the same rule that keeps
    // a sold-out watch out of the carousel keeps a sold-out size run out of it too.
    $slug = H::visibleSlug();
    $productId = productIdForSlug($slug);
    VariantFixture::converted(1, 0, $productId);
    H::flush();

    $other = T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', '!=', $productId)->orderBy('product_id')->value('slug'));
    $related = H::rows(getJson(H::base('products/'.$other))->assertOk()->json('related'));

    expect(array_column($related, 'id'))->not->toContain($productId);
});
