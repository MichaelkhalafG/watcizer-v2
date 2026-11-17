<?php

use App\Domain\Catalog\SearchExposure;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * THE PUBLIC PRODUCT FEED (2026-11-17): every product a shop shows to search engines — titles, brand, page (the sitemap's
 * address), price and the price paid now, stock, photo — written hourly to a file and served read-only; never a product
 * kept from search engines nor a restricted one.
 */
beforeEach(function () {
    config(['feeds.path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'public-feed-test-'.bin2hex(random_bytes(3))]);
});

it('writes and serves every product shown to search engines, never a hidden or a restricted one', function () {
    getJson('/api/v2/brandfashion/feed')->assertNotFound();                  // nothing written yet

    $open = CatalogFixture::product('watch', 3000.0);
    $hidden = CatalogFixture::product('watch', 3100.0);
    $restricted = CatalogFixture::product('watch', 3200.0);
    foreach ([$open, $hidden, $restricted] as $id) {
        CatalogFixture::onStorefront($id, true, 2);
    }
    DB::table('catalog_products')->where('id', $hidden)->update(['search_override' => 0]);
    DB::table('catalog_products')->where('id', $restricted)->update(['search_class' => SearchExposure::RESTRICTED, 'search_override' => null]);
    app(StorefrontCache::class)->flush(2);

    expect(Artisan::call('feeds:public', ['--storefront' => ['brandfashion']]))->toBe(0);
    $doc = T::arr(getJson('/api/v2/brandfashion/feed')->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8')->json());
    $items = collect(T::arr($doc['products']))->keyBy(fn (mixed $p): int => T::int(T::arr($p)['id']));
    expect($items->has($open))->toBeTrue()
        ->and($items->has($hidden))->toBeFalse()
        ->and($items->has($restricted))->toBeFalse()
        ->and(T::int($doc['count']))->toBe($items->count());
    $p = T::arr($items[$open]);
    expect(T::arr($p['url'])['en'])->toContain('/product/')
        ->and(T::arr($p['url'])['ar'])->toContain('/ar/product/')
        ->and($p['currency'])->toBe('EGP')
        ->and(T::float($p['price_now']) <= T::float($p['price']))->toBeTrue();
});
