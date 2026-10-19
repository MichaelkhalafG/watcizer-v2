<?php

use App\Domain\Content\HomeRails;
use App\Storefront\StorefrontHome;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * V2-READ-HALF step 1 (2026-10-17): the two v2 read endpoints the Brand Fashion home and PDP needed and
 * compat alone served — `home` and `products/{slug}/related`. Both reuse compat's RULES through a pinned
 * CompatServices (the picks are what `catalog/home` and `catalog/related` pick) and answer v2 card rows.
 *
 *  - home: the dashboard's active rails, in order, each with its cards in the rail's order (a rail with no
 *    cards is left out, as compat leaves it out); `deals` = ≥ 30% off AND in stock (§19-12's decided rule),
 *    newest first; `categories` = the depth-1 menu nodes with their live count and one real photo; `banner`
 *    = the active home banner or null (§11.2: with / without). Cached under the `home` family.
 *  - related: `addons` in stock and of a DIFFERENT family from the product; `similar` of the SAME family;
 *    neither contains the product; an unknown slug is 404.
 *  - a storefront sees only its own rails (storefront 1's rails never leak into storefront 2's home).
 *  - the query budget, cold and warm, is printed and bounded.
 */

beforeEach(fn () => H::flush());

const BF = '/api/v2/brandfashion/';

function hrQueries(Closure $request): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $request();
    $n = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    return $n;
}

it('serves the home: the storefront\'s rails with v2 cards in order, the deals band, the category entries and the banner', function () {
    $home = H::arr(getJson(H::base('home'))->assertOk()->json());
    expect(array_keys($home))->toBe(['rails', 'deals', 'categories', 'banner', 'brands']);

    $active = HomeRails::active(1);
    $rails = H::rows($home['rails']);
    expect(count($rails))->toBeLessThanOrEqual(count($active), 'a rail the dashboard does not have cannot be served');
    $ids = array_map(fn (array $r) => T::int($r['id']), $rails);
    $order = array_map(fn (array $r) => $r['id'], $active);
    expect(array_values(array_intersect($order, $ids)))->toBe($ids, 'rails come in the dashboard\'s order');
    foreach ($rails as $rail) {
        expect($rail)->toHaveKeys(['id', 'kind', 'target', 'title', 'count', 'cards'])
            ->and(T::int($rail['count']))->toBe(count(H::rows($rail['cards'])))->toBeGreaterThan(0);
        $def = collect($active)->firstWhere('id', T::int($rail['id']));
        expect(T::int($rail['count']))->toBeLessThanOrEqual(T::int($def['card_count'] ?? 0) ?: HomeRails::MAX_CARDS);
        foreach (H::rows($rail['cards']) as $card) {
            expect($card)->toHaveKeys(['id', 'slug', 'family', 'title', 'brand', 'image', 'colors', 'price', 'stock']);
            if ($rail['kind'] === HomeRails::OFFERS) {
                expect(H::arr($card['price'])['has_sale'])->toBeTrue('an offers rail carries discounted products only');
            }
        }
    }

    foreach (H::rows($home['deals']) as $card) {
        expect(T::int(H::arr($card['price'])['discount_pct']))->toBeGreaterThanOrEqual(StorefrontHome::DEALS_MIN_PCT)
            ->and(H::arr($card['stock'])['in_stock'])->toBeTrue();
    }
    expect(count(H::rows($home['deals'])))->toBeLessThanOrEqual(StorefrontHome::DEALS_LIMIT);

    $tree = H::rows(getJson(H::base('meta'))->assertOk()->json('tree'));
    $byPath = collect($tree)->keyBy('path');
    foreach (H::rows($home['categories']) as $cat) {
        expect($cat)->toHaveKeys(['id', 'slug', 'path', 'name', 'count', 'image', 'family'])
            ->and(T::int($cat['count']))->toBe(T::int(H::arr($byPath[$cat['path']] ?? [])['product_count'] ?? -1), 'the entry count is the menu count');
    }
    $banner = $home['banner'];
    expect($banner === null || (is_array($banner) && isset($banner['image'])))->toBeTrue();
});

it('keeps each storefront\'s rails to itself — storefront 1\'s grade rails never reach brandfashion\'s home', function () {
    $wz = H::rows(H::arr(getJson(H::base('home'))->assertOk()->json())['rails']);
    $bf = H::rows(H::arr(getJson(BF.'home')->assertOk()->json())['rails']);
    $wzIds = array_map(fn (array $r) => T::int($r['id']), $wz);
    $bfIds = array_map(fn (array $r) => T::int($r['id']), $bf);
    expect(array_intersect($wzIds, $bfIds))->toBe([], 'a rail row belongs to one storefront')
        ->and(array_map(fn (array $r) => T::int($r['id']), array_filter($bf, fn (array $r) => true)))->toEqualCanonicalizing(
            array_values(array_intersect($bfIds, array_map(fn (array $r) => $r['id'], HomeRails::active(2))))
        );
});

it('serves the PDP\'s two rails: add-ons of another family in stock, similar of the same family, never the product itself; 404 for an unknown slug', function () {
    $slug = H::visibleSlug();
    $detail = H::arr(getJson(H::base('products/'.$slug))->assertOk()->json('product'));
    $family = T::str($detail['family']);
    $id = T::int($detail['id']);

    $r = H::arr(getJson(H::base('products/'.$slug.'/related'))->assertOk()->json());
    expect(array_keys($r))->toBe(['product_id', 'addons', 'similar', 'locale'])
        ->and(T::int($r['product_id']))->toBe($id);
    foreach (H::rows($r['addons']) as $card) {
        expect(T::int($card['id']))->not->toBe($id)
            ->and(T::str($card['family']))->not->toBe($family, 'an add-on is of a DIFFERENT family')
            ->and(H::arr($card['stock'])['in_stock'])->toBeTrue('an add-on is in stock');
    }
    foreach (H::rows($r['similar']) as $card) {
        expect(T::int($card['id']))->not->toBe($id)
            ->and(T::str($card['family']))->toBe($family, 'a similar product is of the SAME family');
    }
    // the exclude list is honoured (the cart's lines are never suggested)
    $first = H::rows($r['similar'])[0]['id'] ?? null;
    if ($first !== null) {
        $again = H::arr(getJson(H::base('products/'.$slug.'/related?exclude='.T::int($first)))->assertOk()->json());
        expect(array_map(fn (array $c) => $c['id'], H::rows($again['similar'])))->not->toContain($first);
    }

    getJson(H::base('products/no-such-product/related'))->assertNotFound();
});

it('stays inside a bounded query budget: home cold ≤ 80 (measured 67 on storefront 1 with 11 rails) and a cache hit after; related ≤ 12', function () {
    getJson(H::base('meta'))->assertOk();   // per-version caches
    $homeCold = hrQueries(fn () => getJson(H::base('home'))->assertOk());
    $homeWarm = hrQueries(fn () => getJson(H::base('home'))->assertOk());
    $slug = H::visibleSlug();
    getJson(H::base('products/'.$slug.'/related'))->assertOk();   // compat's listing index for the storefront, once
    $related = hrQueries(fn () => getJson(H::base('products/'.$slug.'/related'))->assertOk());
    fwrite(STDERR, "\nhome/related budget — home cold {$homeCold}, home warm {$homeWarm}, related warm {$related}\n");

    expect($homeCold)->toBeLessThanOrEqual(80)
        ->and($homeWarm)->toBeLessThanOrEqual(3, 'the home is one cache read when warm')
        ->and($related)->toBeLessThanOrEqual(12);
});
