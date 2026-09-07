<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\getJson;

/*
 * GET /api/v2/{storefront}/products and /products/{slug} — happy, empty, 404, locale, sorting, search.
 */

beforeEach(fn () => H::flush());

it('paginates the storefront catalog server-side with sane defaults and a hard cap', function () {
    $res = getJson(H::base('products'))->assertOk();
    $res->assertJsonPath('meta.page', 1)->assertJsonPath('meta.per_page', 24)->assertJsonPath('meta.sort', 'newest')->assertJsonPath('meta.locale', 'ar');
    expect($res->json('data'))->toHaveCount(24)
        ->and($res->json('meta.total'))->toBeGreaterThan(24)
        ->and($res->json('links.next'))->toContain('page=2');

    $card = H::arr($res->json('data.0'));
    expect($card)->toHaveKeys(['id', 'slug', 'family', 'title', 'short_description', 'brand', 'grade', 'primary_category', 'image', 'price', 'stock', 'rating', 'is_featured', 'sort_order', 'created_at'])
        ->and(H::arr($card['price']))->toHaveKeys(['amount', 'sale_amount', 'currency', 'discount_pct', 'has_sale'])
        ->and($card)->not->toHaveKeys(['purchase_price', 'wa_code', 'hs_code', 'sku', 'created_by', 'updated_by', 'low_stock_threshold']);

    getJson(H::base('products?per_page=500'))->assertStatus(422);
    getJson(H::base('products?per_page=96'))->assertOk()->assertJsonPath('meta.per_page', 96);
});

it('lists a category subtree in position order and 404s an unknown category', function () {
    $node = H::smallSubtree();
    $res = getJson(H::base('products?category='.$node['path']))->assertOk();
    $res->assertJsonPath('meta.sort', 'position')->assertJsonPath('meta.category.path', $node['path']);
    $ids = array_column(H::rows($res->json('data')), 'id');
    sort($ids);
    $expected = $node['products'];
    sort($expected);
    expect($ids)->toBe($expected);

    getJson(H::base('products?category=no/such/node'))->assertNotFound();
});

it('returns an empty page for a filter nothing matches', function () {
    $res = getJson(H::base('products?q=zzqqxxwwvv'))->assertOk();
    expect($res->json('data'))->toBe([])->and($res->json('meta.total'))->toBe(0)->and($res->json('links.next'))->toBeNull();
    getJson(H::base('products?brand=no-such-brand'))->assertOk()->assertJsonPath('meta.total', 0);
});

it('sorts by price and filters by brand slug and in-stock', function () {
    $prices = array_map(fn (array $c) => H::arr($c['price'])['amount'], H::rows(getJson(H::base('products?sort=price_asc&per_page=50'))->assertOk()->json('data')));
    $sorted = $prices;
    sort($sorted);
    expect($prices)->toBe($sorted);

    $brand = H::str(getJson(H::base('meta'))->json('brands.0.slug'));
    $res = getJson(H::base("products?brand={$brand}&in_stock=1"))->assertOk();
    foreach (H::rows($res->json('data')) as $card) {
        expect(H::arr($card['brand'])['slug'])->toBe($brand)->and(H::arr($card['stock'])['in_stock'])->toBeTrue();
    }
});

it('searches the request locale with FULLTEXT and falls back to LIKE below three characters', function () {
    $title = H::str(DB::table('catalog_product_translations')->where('locale', 'en')->orderBy('product_id')->value('title'));
    $word = '';
    foreach (preg_split('/\s+/', $title) ?: [] as $w) {
        if (mb_strlen($w) >= 4 && ctype_alpha($w)) {
            $word = $w;
            break;
        }
    }
    expect($word)->not->toBe('');

    $hits = getJson(H::base('products?locale=en&q='.rawurlencode($word)))->assertOk()->json('meta.total');
    expect($hits)->toBeGreaterThan(0);
    $short = getJson(H::base('products?locale=en&q='.rawurlencode(mb_substr($word, 0, 2))))->assertOk()->json('meta.total');
    expect($short)->toBeGreaterThan(0);
});

it('serves a product detail by storefront slug with specs, attributes, breadcrumb and related cards', function () {
    $slug = H::visibleSlug();
    $res = getJson(H::base('products/'.$slug))->assertOk();
    $res->assertJsonPath('product.slug', $slug)->assertJsonPath('locale', 'ar');
    $p = H::arr($res->json('product'));
    expect($p)->toHaveKeys(['title', 'long_description', 'images', 'specs', 'attributes', 'categories', 'breadcrumb', 'meta', 'price', 'stock'])
        ->and(H::arr($p['attributes']))->toHaveKeys(['features', 'genders', 'colors'])
        ->and(H::rows($p['breadcrumb']))->not->toBeEmpty()
        ->and(H::rows($p['images'])[0]['is_cover'])->toBeTrue()
        ->and($p)->not->toHaveKeys(['purchase_price', 'wa_code', 'hs_code', 'sku', 'created_by', 'updated_by', 'low_stock_threshold']);
    foreach (H::rows($res->json('related')) as $card) {
        expect($card['id'])->not->toBe($p['id'])->and(H::arr($card['stock'])['in_stock'])->toBeTrue();
    }
});

it('404s an unknown slug', function () {
    getJson(H::base('products/no-such-product'))->assertNotFound()->assertJsonPath('message', 'Product not found');
});

it('omits a locale key when the translation row is missing (fallback OFF)', function () {
    $slug = H::visibleSlug();
    $id = DB::table('storefront_product')->where('storefront_id', 1)->where('slug', $slug)->value('product_id');
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->delete();
    H::flush();

    $title = getJson(H::base('products/'.$slug))->assertOk()->json('product.title');
    expect($title)->toHaveKey('ar')->and($title)->not->toHaveKey('en');
});
