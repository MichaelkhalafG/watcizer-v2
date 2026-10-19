<?php

use App\Storefront\StorefrontUrls;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * COMPAT-ASSET-HOST (2026-10-14): the compat payloads name images on the STOREFRONT'S OWN host.
 *
 * `catalog/meta`'s brand logos, sub-type and banner images and `products/{id}`'s cover, gallery and
 * brand logo were all built on the one global `compat.asset_base`, so Brand Fashion's `catalog/meta`
 * named `api.watchizereg.com` 81 times (66 brand logos, 15 sub-type images) and 15 category tiles on
 * its home page loaded from Watchizer's host. The v2 routes already used `StorefrontUrls::assetBase()`.
 *
 * Two contracts, pinned separately:
 *   • the DEFAULT storefront's payloads are built on `compat.asset_base` exactly as before — the live
 *     shop's JSON must not move a byte (the deploy gate captured it before and after);
 *   • every other storefront's payloads are built on its own `StorefrontUrls::assetBase()` and never
 *     name another shop's host.
 */

const CAH_API_KEY = 'compat-asset-host-test-api-code';

beforeEach(function () {
    config(['compat.api_key' => CAH_API_KEY]);
});

/** @return list<string> every absolute image URL in the payload (logo_url, image_url, image, images[]) */
function compatImageUrls(mixed $payload): array
{
    $out = [];
    $walk = function (mixed $node, ?string $key) use (&$walk, &$out): void {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $walk($v, is_string($k) ? $k : $key);
            }

            return;
        }
        if (is_string($node) && in_array($key, ['logo_url', 'image_url', 'image', 'images'], true) && preg_match('#^https?://#', $node) === 1) {
            $out[] = $node;
        }
    };
    $walk($payload, null);

    return $out;
}

/** A product visible on this storefront that has a cover image, so `products/{id}` carries image URLs. */
function productWithCoverOn(int $storefrontId): int
{
    $id = DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->join('catalog_product_images as i', fn (JoinClause $j) => $j->on('i.product_id', '=', 'p.id')->where('i.is_cover', 1))
        ->where('sp.storefront_id', $storefrontId)->where('sp.is_visible', 1)->where('p.is_active', 1)
        ->orderBy('p.id')->value('p.id');
    expect($id)->not->toBeNull("storefront {$storefrontId} has no visible product with a cover");

    return T::int($id);
}

it('builds Brand Fashion\'s compat image URLs on Brand Fashion\'s own host, and never on Watchizer\'s', function () {
    $fashion = Shops::id('brandfashion');
    $own = StorefrontUrls::assetBase($fashion);
    $meta = withHeaders(['Api-Code' => CAH_API_KEY])->getJson(Shops::api('brandfashion').'/api/catalog/meta')->assertOk()->json();
    $detail = withHeaders(['Api-Code' => CAH_API_KEY])->getJson(Shops::api('brandfashion').'/api/products/'.productWithCoverOn($fashion))->assertOk()->json();

    $urls = array_merge(compatImageUrls($meta), compatImageUrls($detail));
    expect(count($urls))->toBeGreaterThan(10, 'the payloads carry no image URLs to check');

    $wrong = array_values(array_filter($urls, fn (string $u): bool => ! str_starts_with($u, $own.'/Uploads_Images/')));
    expect($wrong)->toBe([], count($wrong).' image URL(s) not on '.$own.': '.implode(', ', array_slice($wrong, 0, 5)));
    expect(array_values(array_filter($urls, fn (string $u): bool => str_contains($u, 'watchizereg'))))->toBe([]);
});

it('keeps the default storefront\'s compat image URLs on compat.asset_base, exactly as before', function () {
    $base = rtrim(config()->string('compat.asset_base'), '/');
    $meta = withHeaders(['Api-Code' => CAH_API_KEY])->getJson(Shops::api('watchizer').'/api/catalog/meta')->assertOk()->json();
    $detail = withHeaders(['Api-Code' => CAH_API_KEY])->getJson(Shops::api('watchizer').'/api/products/'.productWithCoverOn(Shops::id('watchizer')))->assertOk()->json();

    $urls = array_merge(compatImageUrls($meta), compatImageUrls($detail));
    expect(count($urls))->toBeGreaterThan(10);
    $wrong = array_values(array_filter($urls, fn (string $u): bool => ! str_starts_with($u, $base.'/Uploads_Images/')));
    expect($wrong)->toBe([], count($wrong).' image URL(s) not on '.$base.': '.implode(', ', array_slice($wrong, 0, 5)));

    expect(StorefrontUrls::compatAssetBase(Shops::id('watchizer')))->toBe($base)
        ->and(StorefrontUrls::compatAssetBase(Shops::id('brandfashion')))->toBe(StorefrontUrls::assetBase(Shops::id('brandfashion')));
});
