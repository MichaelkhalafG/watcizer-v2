<?php

use App\Domain\Content\QualityGate;
use App\Storefront\AuthorizedDealer;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * AUTHORIZED DEALER, PER BRAND AND PER SHOP (Michael, 2026-11-14): off by default; on only for the brands switched on;
 * never on a product the hide-from-search rule matches; the quality gate refuses the claim for any other brand.
 */

/** @param  list<string>  $slugs  the brands this shop is an authorized dealer of */
function setDealers(int $sf, array $slugs): void
{
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $sf)->value('settings') ?? '{}'), true) ?: []);
    $public = T::arr($settings['public'] ?? []);
    $public['authorized_brands'] = $slugs;
    $settings['public'] = $public;
    DB::table('storefronts')->where('id', $sf)->update(['settings' => json_encode($settings)]);
    app(StorefrontCache::class)->flush($sf);
}

it('is off by default and per shop', function () {
    setDealers(1, []);
    expect(AuthorizedDealer::brands(1))->toBe([]);
    setDealers(1, ['hugo-boss']);
    setDealers(2, []);
    expect(AuthorizedDealer::brands(1))->toBe(['hugo-boss'])
        ->and(AuthorizedDealer::brands(2))->toBe([])
        ->and(AuthorizedDealer::forProduct(1, 'hugo-boss', null))->toBeTrue()
        ->and(AuthorizedDealer::forProduct(1, 'hugo-boss', 'restricted'))->toBeFalse()
        ->and(AuthorizedDealer::forProduct(1, 'hugo-boss', 'hidden'))->toBeFalse()
        ->and(AuthorizedDealer::forProduct(1, 'guess', null))->toBeFalse()
        ->and(AuthorizedDealer::forProduct(2, 'hugo-boss', null))->toBeFalse();
});

it('marks the product payload only for a switched-on brand and never a product kept from search engines', function () {
    $id = CatalogFixture::product('watch', 2000.0);
    CatalogFixture::onStorefront($id, true, 1);
    DB::table('catalog_products')->where('id', $id)->update(['search_class' => null, 'search_override' => null]);
    $slug = T::str(DB::table('storefront_product')->where('product_id', $id)->where('storefront_id', 1)->value('slug'));
    $brand = T::str(DB::table('catalog_brands')->where('id', DB::table('catalog_products')->where('id', $id)->value('brand_id'))->value('slug'));
    $read = fn (): mixed => getJson("/api/v2/watchizer/products/{$slug}")->assertOk()->json('product.authorized_dealer');

    setDealers(1, []);
    expect($read())->toBeFalse();
    setDealers(1, [$brand]);
    expect($read())->toBeTrue();
    DB::table('catalog_products')->where('id', $id)->update(['search_override' => 0]);
    app(StorefrontCache::class)->flush(1);
    expect($read())->toBeFalse();
});

it('lets the gate pass the claim only in a text about a switched-on brand', function () {
    $brandId = T::int(DB::table('catalog_brands')->where('slug', 'hugo-boss')->value('id'));
    $other = T::int(DB::table('catalog_brands')->where('slug', 'guess')->value('id'));
    $text = ['title' => 'Hugo Boss', 'meta_title' => '', 'meta_description' => 'x', 'body' => "## A\nWatchizer is an authorized dealer for this brand.\n"];
    $reasons = fn (int $brand): array => T::arr(app(QualityGate::class)->checkArticle(1, 'x', $text, $text, ['brand_id' => $brand])['en']['reasons']);
    $claim = fn (array $r): bool => array_filter($r, fn ($x): bool => str_contains(T::str($x), 'authorized-dealer claim')) !== [];

    setDealers(1, ['hugo-boss']);
    expect($claim($reasons($brandId)))->toBeFalse()
        ->and($claim($reasons($other)))->toBeTrue();
    setDealers(1, []);
    expect($claim($reasons($brandId)))->toBeTrue();
});
