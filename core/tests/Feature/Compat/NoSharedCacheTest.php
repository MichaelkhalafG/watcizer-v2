<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * ── No API response the storefront's BROWSER reads may be stored by a shared cache (2026-09-29) ──
 *
 * Hostinger's CDN in front of api.watchizereg.com keeps ONE copy per URL — it drops Laravel's
 * `Vary: Origin` — so a `public` response carried whatever CORS header the request that filled the
 * copy happened to get. The storefront's own server-side fetches send no Origin, got no
 * Access-Control-Allow-Origin, and every browser then failed CORS on that URL until the copy
 * expired: product ratings, measured live (a www request got the apex's CORS header from a HIT).
 *
 * `private` keeps the browser's own cache and ETag and forbids the CDN. This walks every GET the
 * storefront's browser makes to core and fails on any `public` or `s-maxage`.
 */

const NO_SHARED_KEY = 'no-shared-cache-test-key';

beforeEach(function () {
    config(['compat.api_key' => NO_SHARED_KEY]);
});

it('marks every browser-read API response private, never public or s-maxage', function () {
    $productId = T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('is_visible', 1)->min('product_id'));
    // `all_product` / `all_product_image` dropped off this list when they were retired (L8, 2026-10-06):
    // they answer 410, not a 200 browser-read response. `all_product_rating` stays — the product page reads it.
    $paths = [
        'catalog/meta', 'show_shipping_city', 'catalog/nav', 'catalog/listing',
        'catalog/cards?ids='.$productId, 'catalog/related?product='.$productId, 'catalog/related?cart='.$productId, 'catalog/product?slug='.$productId, 'catalog/home', 'catalog/blogs',
        'all_product_rating', 'products/'.$productId,
    ];
    $failures = [];
    foreach ($paths as $path) {
        $res = withHeaders(['Api-Code' => NO_SHARED_KEY, 'Origin' => 'https://watchizereg.com'])->get('/api/'.$path);
        $cc = (string) $res->headers->get('Cache-Control');
        if ($res->getStatusCode() !== 200 || ! str_contains($cc, 'private') || str_contains($cc, 'public') || str_contains($cc, 's-maxage')) {
            $failures[] = "{$path}: {$res->getStatusCode()} Cache-Control: {$cc}";
        }
    }

    $methods = withHeaders(['Origin' => 'https://watchizereg.com'])->get('https://api.watchizereg.com/api/v2/watchizer/payment-methods');
    $cc = (string) $methods->headers->get('Cache-Control');
    if (str_contains($cc, 'public') || str_contains($cc, 's-maxage') || ! str_contains($cc, 'private')) {
        $failures[] = "v2 payment-methods: Cache-Control: {$cc}";
    }

    expect($failures)->toBe([]);
});
