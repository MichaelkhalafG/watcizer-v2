<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * ── No v2 answer may be stored by a shared cache (2026-10-19, found in the deploy rehearsal) ──
 *
 * The 2026-09-29 incident (tests/Feature/Compat/NoSharedCacheTest.php) again, on v2. v2's cached group answered
 * `public, s-maxage=600`, and Hostinger's CDN in front of the API hosts keeps ONE copy per URL — it ignores
 * `Vary: Origin`. Measured on production before the fix (read-only GETs): a URL first read with no Origin — the
 * storefront's own server, a crawler — was then served to the browser as a CDN HIT with NO
 * Access-Control-Allow-Origin; a URL warmed from michaelkhalaf.com reached www.michaelkhalaf.com with the apex's
 * header. The browser refuses both. Brand Fashion's listing, search and the cart's rails read v2 from the browser.
 *
 * `private` keeps the browser's own cache and the ETag and forbids the CDN. This walks every v2 GET a storefront
 * serves, on both storefronts, and fails on any `public` or `s-maxage` — the uncached group's routes included, so a
 * route moved between the groups cannot slip through.
 */
it('marks every v2 answer private, never public or s-maxage', function () {
    $slug = T::str(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 2)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->orderByDesc('sp.product_id')->value('sp.slug'));

    $failures = [];
    foreach (['watchizer', 'brandfashion'] as $code) {
        $paths = ['meta', 'categories', 'home', 'products?per_page=2', 'payment-methods', 'offers', 'auth/providers', 'sitemap.xml'];
        if ($code === 'brandfashion') {
            $paths[] = 'products/'.$slug;
            $paths[] = 'products/'.$slug.'/related';
        }
        foreach ($paths as $path) {
            $response = getJson("/api/v2/{$code}/{$path}");
            $control = (string) $response->headers->get('Cache-Control');
            if ($response->status() !== 200) {
                $failures[] = "{$code}/{$path}: HTTP {$response->status()}";
            } elseif (! str_contains($control, 'private') || str_contains($control, 'public') || str_contains($control, 's-maxage')) {
                $failures[] = "{$code}/{$path}: {$control}";
            }
        }
    }

    expect($failures)->toBe([]);
});
