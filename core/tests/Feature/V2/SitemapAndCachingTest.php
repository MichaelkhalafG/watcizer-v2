<?php

use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * Sitemaps (§6.4) and HTTP caching (§5.2 first row).
 */

beforeEach(fn () => H::flush());

it('serves a per-locale sitemap index and chunks with alternates and covers', function () {
    $index = get(H::base('sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = (string) $index->getContent();
    expect($xml)->toContain('/sitemaps/ar/products-1.xml')->toContain('/sitemaps/en/products-1.xml')->toContain('/sitemaps/ar/categories.xml')->toContain('/sitemaps/en/brands.xml')->toContain('/sitemaps/ar/static.xml');

    $ar = (string) get(H::base('sitemaps/ar/products-1.xml'))->assertOk()->getContent();
    $en = (string) get(H::base('sitemaps/en/products-1.xml'))->assertOk()->getContent();
    /*
     * WHICH LANGUAGE LIVES AT THE BARE URL, asserted — and it is English.
     *
     * `Sitemaps::prefix()` leaves the storefront's default locale unprefixed and puts the other
     * under `/{locale}/`, so this test is really an assertion about `storefronts.default_locale`.
     * It used to read the other way round, with Arabic unprefixed, which would have published
     * `watchizereg.com/product/…` as the Arabic page and moved every English page to `/en/`.
     *
     * the storefront opens in ENGLISH — it always has, and the customer switches for themselves (developer, 2026-09-18).
     */
    expect($en)->toContain('<loc>https://watchizereg.com/product/')
        ->toContain('hreflang="ar" href="https://watchizereg.com/ar/product/')
        ->toContain('hreflang="x-default" href="https://watchizereg.com/product/')
        ->toContain('<image:image>');
    expect($ar)->toContain('<loc>https://watchizereg.com/ar/product/');
    expect(substr_count($ar, '<url>'))->toBe(substr_count($en, '<url>'));

    // The default locale's chunk, so the bare `/category/` and `/c/` paths are the ones checked.
    $cats = (string) get(H::base('sitemaps/en/categories.xml'))->assertOk()->getContent();
    expect($cats)->toContain('<loc>https://watchizereg.com/category/')->toContain('<loc>https://watchizereg.com/c/');
    get(H::base('sitemaps/ar/products-99.xml'))->assertNotFound();
    get(H::base('sitemaps/fr/products-1.xml'))->assertNotFound();
    get(H::base('sitemaps/ar/nope.xml'))->assertNotFound();
});

it('sets public cache headers, a strong ETag, cache tags and answers 304 on If-None-Match', function () {
    $res = getJson(H::base('meta'))->assertOk();
    $res->assertHeader('Cache-Control', config()->string('storefront.http_cache.control'));
    $etag = $res->headers->get('ETag');
    expect($etag)->toStartWith('"')->and($res->headers->get('Cache-Tag'))->toContain('sf1');

    getJson(H::base('meta'), ['If-None-Match' => (string) $etag])->assertStatus(304);

    $slug = H::visibleSlug();
    $detail = getJson(H::base('products/'.$slug))->assertOk();
    expect($detail->headers->get('Cache-Tag'))->toContain('sf1-p');

    getJson(H::base('products/no-such-product'))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
});

it('keeps every endpoint under its payload ceiling on the local catalog (§5.5)', function () {
    expect(strlen((string) getJson(H::base('meta'))->getContent()))->toBeLessThanOrEqual(300 * 1024);
    expect(strlen((string) getJson(H::base('products?per_page=24'))->getContent()))->toBeLessThanOrEqual(120 * 1024);
    expect(strlen((string) getJson(H::base('products/'.H::visibleSlug()))->getContent()))->toBeLessThanOrEqual(60 * 1024);
    expect(strlen((string) get(H::base('sitemaps/ar/products-1.xml'))->getContent()))->toBeLessThanOrEqual(2 * 1024 * 1024);
});
