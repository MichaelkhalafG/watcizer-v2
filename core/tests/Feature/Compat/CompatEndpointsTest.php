<?php

use App\Http\Controllers\Compat\CatalogCompatController;
use App\Http\Controllers\Compat\ProxyController;
use App\Storefront\StorefrontCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/*
 * The legacy-compat surface (study §3.3): legacy paths, legacy shapes, legacy quirks. The
 * byte-level proof against the running legacy app is `compat:diff`; these tests pin the
 * contract pieces a reviewer can check without the legacy host: the Api-Code gate, the
 * legacy 404 messages, the Accept-Language quirk, the 410 list, the proxy, the sitemap paths.
 */

const API_KEY = 'test-api-code';

beforeEach(function () {
    config(['compat.api_key' => API_KEY, 'compat.legacy_base' => 'http://legacy.test']);
    app(StorefrontCache::class)->flush(1);
});

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function compat(string $path, array $headers = []): TestResponse
{
    return withHeaders(['Api-Code' => API_KEY, 'Accept' => 'application/json'] + $headers)->get('/api/'.ltrim($path, '/'));
}

/** @return array<string, mixed> */
function arr(mixed $v): array
{
    expect($v)->toBeArray();
    $out = [];
    foreach (is_array($v) ? $v : [] as $k => $item) {
        $out[(string) $k] = $item;
    }

    return $out;
}

/**
 * The translation row of a locale inside a legacy `translations[]` list.
 *
 * @return array<string, mixed>
 */
function translation(mixed $rows, string $locale): array
{
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row['locale'] ?? null) === $locale) {
            return arr($row);
        }
    }

    return [];
}

it('rejects a missing or wrong Api-Code with the legacy body', function () {
    getJson('/api/all_product')->assertStatus(401)->assertExactJson(['error' => 'Unauthorized']);
    withHeaders(['Api-Code' => 'wrong'])->getJson('/api/catalog/meta')->assertStatus(401);
    config(['compat.api_key' => '']);
    withHeaders(['Api-Code' => ''])->getJson('/api/catalog/meta')->assertStatus(401);   // an empty key never opens the door
});

it('serves catalog/meta in the legacy shape; meta follows Accept-Language like legacy, all_product is pinned to EN (D-13 / F-18)', function () {
    $en = compat('catalog/meta')->assertOk()->assertHeader('Cache-Control', 'max-age=1800, public');
    $en->assertJsonStructure(['tables' => ['categoryTypes', 'brands', 'grades', 'subTypes', 'colors', 'materials', 'shapes', 'sizeTypes', 'displayTypes', 'closureTypes', 'movementTypes'], 'brands', 'categories', 'sub_types', 'genders', 'grades', 'dial_colors', 'band_colors', 'features', 'banners', 'shipping_cities']);
    $brand = arr($en->json('tables.brands.0'));
    expect(array_keys($brand))->toBe(['id', 'image', 'created_at', 'updated_at', 'brand_name', 'translations'])
        ->and($brand['brand_name'])->toBe(translation($brand['translations'], 'en')['brand_name']);

    // meta: the legacy cache holds models → serialised per request locale (F-18); compat negotiates the same way.
    $ar = compat('catalog/meta', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.8'])->assertOk();
    expect($ar->json('tables.brands.0.brand_name'))->toBe(translation($brand['translations'], 'ar')['brand_name']);
    expect(compat('catalog/meta', ['Accept-Language' => 'fr'])->json('tables.brands.0.brand_name'))->toBe($brand['brand_name']);
    // all_product: the legacy cache holds arrays → locale-blind; compat pins EN (D-13).
    expect(compat('all_product', ['Accept-Language' => 'ar'])->json('0.product_title'))->toBe(compat('all_product')->json('0.product_title'));

    // Only visible sub types (study §3.3): every listed id has at least one visible product.
    foreach (arr($en->json('tables.subTypes')) as $sub) {
        $legacyId = arr($sub)['id'];
        $n = DB::table('storefront_categories as c')->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'c.id')
            ->where('c.legacy_source', 'sub_type')->where('c.legacy_id', $legacyId)->count();
        expect($n)->toBeGreaterThan(0, 'sub type listed without products');
    }
});

it('serves all_product with the 42 legacy columns (purchase_price hidden), the 6 appended attributes and the 5 relations, ordered by id', function () {
    $rows = arr(compat('all_product')->assertOk()->assertHeader('Cache-Control', 'max-age=600, public')->json());
    expect(count($rows))->toBe(DB::table('storefront_product')->where('storefront_id', 1)->where('is_visible', 1)->count());
    $ids = array_map(fn (mixed $r) => arr($r)['id'], array_values($rows));
    $sorted = $ids;
    sort($sorted);
    expect($ids)->toBe($sorted);
    $first = arr(array_values($rows)[0] ?? null);
    $keys = array_keys($first);
    expect($keys)->toHaveCount(53)
        ->and(array_slice($keys, 0, 5))->toBe(['id', 'category_type_id', 'brand_id', 'grade_id', 'sub_type_id'])
        ->and(array_slice($keys, -11))->toBe(['product_title', 'model_name', 'country', 'stone', 'long_description', 'short_description', 'feature', 'gender', 'dial_color', 'band_color', 'translations'])
        ->and($keys)->not->toContain('purchase_price')
        ->and($first['selling_price'])->toBeString();
    $genders = arr($first['gender']);
    if ($genders !== []) {
        expect(arr(array_values($genders)[0] ?? null))->toHaveKey('pivot');
    }
});

it('serves the gallery rows, ratings and shipping cities', function () {
    $image = arr(compat('all_product_image')->assertOk()->json('0'));
    expect(array_keys($image))->toBe(['id', 'product_id', 'image', 'is_cover', 'sort', 'alt_ar', 'alt_en', 'created_at', 'updated_at'])
        ->and($image['is_cover'])->toBeFalse();
    compat('all_product_rating')->assertOk();
    expect(arr(compat('show_shipping_city')->assertOk()->json('0')))->toHaveKeys(['id', 'shipping_cost', 'city_name', 'translations']);
});

it('serves products/{id} and by-name with the legacy ProductResource keys and the legacy 404 messages', function () {
    $id = DB::table('storefront_product')->where('storefront_id', 1)->orderBy('product_id')->value('product_id');
    $id = is_numeric($id) ? (int) $id : 0;
    $res = compat("products/{$id}")->assertOk();
    $product = arr($res->json('product'));
    $keys = array_keys($product);
    expect($keys)->toHaveCount(99)->and($keys[0])->toBe('id')->and(end($keys))->toBe('extra_attributes')
        ->and($product)->not->toHaveKeys(['purchase_price', 'wa_code', 'hs_code', 'sku_unique']);
    foreach (arr($res->json('related')) as $r) {
        expect(array_keys(arr($r)))->toHaveCount(31)->and(arr($r))->not->toHaveKey('purchase_price');
    }

    $title = DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('title');
    compat('products/by-name/'.rawurlencode(is_string($title) ? $title : ''))->assertOk()->assertJsonPath('product.id', $id);

    // Generic, leak-free 404s (review 🟡-11): no model class, no path, no id echoed, small body.
    config(['app.debug' => false]);
    foreach (['products/999999', 'products/by-name/nope', 'products/abc', 'products/4.5'] as $miss) {
        $res = compat($miss)->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $body = (string) $res->getContent();
        expect(strlen($body))->toBeLessThan(200);
        expect($body)->not->toContain('App'.chr(92));
        expect($body)->not->toContain('D:'.chr(92));
        expect($body)->not->toContain('exception');
    }
});

it('coerces the id segment like the legacy findOrFail (MySQL loose string→number)', function () {
    $id = DB::table('storefront_product')->where('storefront_id', 1)->orderBy('product_id')->value('product_id');
    $id = is_numeric($id) ? (int) $id : 0;
    foreach (["{$id}.0", '0'.$id, "{$id}abc", " {$id}", "{$id}e0"] as $variant) {
        compat('products/'.rawurlencode($variant))->assertOk()->assertJsonPath('product.id', $id);
    }
    foreach (["{$id}.5", 'abc', '0', "-{$id}", '99999999999999999999'] as $miss) {
        compat('products/'.rawurlencode($miss))->assertNotFound();
    }
    expect(CatalogCompatController::legacyId('4.0'))->toBe(4)
        ->and(CatalogCompatController::legacyId('4abc'))->toBe(4)
        ->and(CatalogCompatController::legacyId('4.5'))->toBeNull()
        ->and(CatalogCompatController::legacyId('abc'))->toBeNull();
});

it('enforces the curated CORS origin list on every /api path, moved and proxied alike', function () {
    Http::fake(['legacy.test/*' => Http::response('[]', 200, ['Content-Type' => 'application/json'])]);
    $storefront = 'https://watchizereg.com';
    $evil = 'https://evil.example.com';

    $ok = withHeaders(['Api-Code' => API_KEY, 'Origin' => $storefront])->getJson('/api/catalog/meta')->assertOk();
    expect($ok->headers->get('Access-Control-Allow-Origin'))->toBe($storefront);
    $no = withHeaders(['Api-Code' => API_KEY, 'Origin' => $evil])->getJson('/api/catalog/meta')->assertOk();
    expect($no->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    $proxied = withHeaders(['Api-Code' => API_KEY, 'Origin' => $evil])->getJson('/api/all_offer')->assertOk();
    expect($proxied->headers->get('Access-Control-Allow-Origin'))->toBeNull();

    $preflight = call('OPTIONS', '/api/all_product', [], [], [], ['HTTP_ORIGIN' => $evil, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'api-code']);
    expect($preflight->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    $preflightOk = call('OPTIONS', '/api/all_product', [], [], [], ['HTTP_ORIGIN' => $storefront, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'api-code']);
    expect($preflightOk->headers->get('Access-Control-Allow-Origin'))->toBe($storefront);
    expect(config('cors.allowed_origins'))->not->toContain('*');
});

it('throttles /api at the legacy rate of 60 per minute', function () {
    $limiter = RateLimiter::limiter('api');
    expect($limiter)->not->toBeNull();
    $limits = $limiter === null ? null : $limiter(Request::create('/api/catalog/meta'));
    expect($limits)->toBeInstanceOf(Limit::class);
    if ($limits instanceof Limit) {
        expect($limits->maxAttempts)->toBe(60);
    }
});

it('retires the never-called legacy paths with 410', function () {
    foreach (['all_brand', 'all_sub_type', 'products', 'new_colors', 'products/1/variants'] as $path) {
        compat($path)->assertStatus(410);
    }
    get('/api/categories/main')->assertStatus(410);
});

it('proxies every other /api path to the legacy host with the client address forwarded', function () {
    Http::fake(['legacy.test/*' => Http::response('[{"id":1}]', 200, ['Content-Type' => 'application/json', 'ETag' => '"abc"', 'X-Powered-By' => 'PHP'])]);

    $res = withHeaders(['Api-Code' => API_KEY])->get('/api/all_offer?x=1');
    $res->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('ETag', '"abc"')->assertHeader('X-Proxied-By', 'core');
    expect($res->headers->get('X-Powered-By'))->not->toBe('PHP');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://legacy.test/api/all_offer?x=1'
        && $request->hasHeader('Api-Code', API_KEY)
        && $request->hasHeader('X-Forwarded-For')
        && $request->method() === 'GET');

    // A POST still on the proxy. `add_to_cart` used to be the example here; wave 3 MOVED it, so
    // the example is now a wishlist write, which stays proxied until the auth wave.
    withHeaders(['Api-Code' => API_KEY])->post('/api/add_wishlist', ['product_id' => 1])->assertOk();
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'http://legacy.test/api/add_wishlist' && $request->method() === 'POST');

    get('/api/v2/nope/meta')->assertNotFound();                  // v2 misses are never proxied
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'v2/'));

    // Whitelist (review 🟡-8): only the study's proxy rows reach the legacy host.
    foreach (['definitely/not/a/route', 'admin', 'auth', 'me', 'all_offers'] as $refused) {
        withHeaders(['Api-Code' => API_KEY])->getJson('/api/'.$refused)->assertNotFound();
    }
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'definitely') || str_ends_with($request->url(), '/api/admin') || str_ends_with($request->url(), '/api/me'));
    foreach (['auth/me', 'login', 'updateProfile', 'all_wishlist/3', 'all_offer', 'all_blog', 'add_product_rating'] as $allowed) {
        expect(ProxyController::allowed($allowed))->toBeTrue($allowed);
    }

    // …and every wave-3 path is now OFF the whitelist: it is answered here, never forwarded.
    foreach (['add_to_cart', 'remove_from_cart', 'delete_cart/9', 'me/cart', 'cart/validate', 'cart/merge',
        'add_order', 'callback_payment', 'me/orders', 'me/addresses', 'me/addresses/5', 'add_address'] as $moved) {
        expect(ProxyController::allowed($moved))->toBeFalse($moved);
    }
});

it('answers all twelve wave-3 paths itself, and forwards none of them', function () {
    Http::fake(['legacy.test/*' => Http::response('[]', 200)]);

    // Every one of them resolves to a compat controller — a 404 here would mean the proxy
    // catch-all swallowed it, which is how a "moved" path silently stays on the legacy host.
    $paths = [
        ['GET', '/api/me/cart'], ['POST', '/api/add_to_cart'], ['POST', '/api/remove_from_cart'],
        ['DELETE', '/api/delete_cart/1'], ['POST', '/api/cart/validate'], ['POST', '/api/cart/merge'],
        ['POST', '/api/add_order'], ['GET', '/api/callback_payment'], ['GET', '/api/me/orders'],
        ['GET', '/api/me/addresses'], ['DELETE', '/api/me/addresses/1'], ['POST', '/api/add_address'],
    ];
    foreach ($paths as [$method, $path]) {
        $route = app('router')->getRoutes()->match(Request::create($path, $method));
        $action = $route->getActionName();
        $where = "{$method} {$path} => {$action}";
        expect($where)->toContain('Compat\\')->and($where)->not->toContain('ProxyController');
    }

    Http::assertNothingSent();
});

it('serves the legacy sitemap contract: bare path 302s to the negotiated locale, /{locale}/sitemap.xml serves XML', function () {
    get('/sitemap.xml')->assertStatus(302)->assertRedirect('/en/sitemap.xml');
    withHeaders(['Accept-Language' => 'ar'])->get('/sitemap.xml')->assertRedirect('/ar/sitemap.xml');
    $xml = (string) get('/en/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<loc>https://watchizereg.com/category/Watches</loc>')
        ->toContain('<loc>https://watchizereg.com/product/')
        ->toContain('<loc>https://watchizereg.com/brand/');
    get('/fr/sitemap.xml')->assertNotFound();
});
