<?php

use App\Storefront\StorefrontCache;
use App\Storefront\Warranty;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * WHO GIVES THE WARRANTY (Michael, 2026-11-14): the brand agent for most watches, the store for a few — the product's
 * own choice wins, else its brand's switch; a product the hide-from-search rule matches is ALWAYS the store's.
 */
it('decides the provider: hide-from-search → store, the product, the brand, else the agent for a watch', function () {
    expect(Warranty::provider(null, false, 'watch', null, 3))->toBe('agent')
        ->and(Warranty::provider(null, true, 'watch', null, 3))->toBe('store')
        ->and(Warranty::provider('store', false, 'watch', null, 3))->toBe('store')
        ->and(Warranty::provider('agent', true, 'watch', null, 3))->toBe('agent')
        ->and(Warranty::provider('agent', false, 'watch', 'restricted', 1))->toBe('store')       // never the agent's
        ->and(Warranty::provider(null, false, 'watch', 'hidden', 1))->toBe('store')
        ->and(Warranty::provider(null, false, 'bag', null, 1))->toBeNull()
        ->and(Warranty::provider('store', false, 'bag', null, 1))->toBe('store')
        ->and(Warranty::provider(null, false, 'watch', null, 0))->toBeNull()                    // no warranty, no provider
        ->and(Warranty::provider(null, false, 'watch', null, null))->toBeNull();
});

it('sends the provider with the v2 product, the brand switch and the product override applied', function () {
    $id = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($id, true, 1);
    DB::table('catalog_products')->where('id', $id)->update(['warranty_years' => 2, 'search_class' => null, 'search_override' => null]);
    $slug = T::str(DB::table('storefront_product')->where('product_id', $id)->where('storefront_id', 1)->value('slug'));
    $brand = T::int(DB::table('catalog_products')->where('id', $id)->value('brand_id'));
    $read = function () use ($slug): mixed {
        app(StorefrontCache::class)->flush(1);

        return getJson("/api/v2/watchizer/products/{$slug}")->assertOk()->json('product.warranty_provider');
    };

    DB::table('catalog_brands')->where('id', $brand)->update(['warranty_by_store' => 0]);
    expect($read())->toBe('agent');
    DB::table('catalog_brands')->where('id', $brand)->update(['warranty_by_store' => 1]);
    expect($read())->toBe('store');
    DB::table('catalog_products')->where('id', $id)->update(['warranty_provider' => 'agent']);
    expect($read())->toBe('agent');
    DB::table('catalog_products')->where('id', $id)->update(['search_override' => 0]);       // kept from search engines
    expect($read())->toBe('store');
});
