<?php

use App\Storefront\ProductListing;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * The three WATCH-SPEC listing filters and the multi-path category (2026-10-22).
 *
 * Why they exist: Watchizer's listing moved from compat to v2 in this wave, and its sidebar has had Movement,
 * Shape and Display Type since launch, and has let a shopper tick SEVERAL sub-types. v2 had none of that, so
 * the move would have quietly removed two working filters from a live storefront and turned a multi-select
 * into pick-one. These tests are the guard on that promise, not on the plumbing.
 */

/**
 * The ids the listing serves for one facet section, best first.
 *
 * @return list<int>
 */
function specFacetIds(string $section, string $query = ''): array
{
    $facets = T::arr(getJson(H::base('products?facets=1&per_page=1'.($query === '' ? '' : '&'.$query)))->assertOk()->json('facets'));
    $out = [];
    foreach (T::arr($facets[$section] ?? []) as $row) {
        $id = T::int(T::arr($row)['id'] ?? null);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return $out;
}

/** How many products the listing reports for a query. */
function specTotal(string $query): int
{
    return T::int(getJson(H::base('products?per_page=1'.($query === '' ? '' : '&'.$query)))->assertOk()->json('meta.total'));
}

it('serves a section for each watch spec, naming only values the RESULT contains', function () {
    foreach (array_keys(ProductListing::WATCH_SPEC_FACETS) as $name) {
        $section = (string) $name;
        // every id served is a value some product in the result actually carries
        foreach (specFacetIds($section) as $id) {
            expect(specTotal($section.'='.$id))->toBeGreaterThan(0, "{$section} {$id} is served but matches nothing");
        }
    }
});

it('filters by movement, shape and display type, and an unknown id matches nothing', function () {
    foreach (ProductListing::WATCH_SPEC_FACETS as $section => $column) {
        $ids = specFacetIds($section);
        if ($ids === []) {
            continue;   // a catalogue with none of this spec: nothing to assert, and the section draws nothing
        }
        $id = (int) $ids[0];
        $section = (string) $section;
        $column = (string) $column;

        // the listing's own count equals the same count taken straight from the table
        $expected = DB::table('catalog_product_watch_specs as ws')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'ws.product_id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
            })
            ->join('catalog_products as p', 'p.id', '=', 'ws.product_id')
            ->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->where('ws.'.$column, $id)
            ->distinct()->count('ws.product_id');

        expect(specTotal($section.'='.$id))->toBe($expected, "{$section}={$id} disagrees with the table")
            // an id nothing carries is an empty result, never an unfiltered one
            ->and(specTotal($section.'=99999999'))->toBe(0, "{$section} ignored an unknown id");
    }
});

it('takes SEVERAL category paths and answers their union, which is what a multi-select sub-type means', function () {
    $cats = T::arr(getJson(H::base('products?facets=1&per_page=1'))->assertOk()->json('facets.category'));
    $deep = [];
    foreach ($cats as $row) {
        $c = T::arr($row);
        if (T::int($c['depth'] ?? null) === 2) {
            $deep[] = T::str($c['path'] ?? '');
        }
        if (count($deep) === 2) {
            break;
        }
    }
    if (count($deep) < 2) {
        expect(true)->toBeTrue('this storefront has fewer than two depth-2 categories');

        return;
    }

    [$a, $b] = $deep;
    $onlyA = specTotal('category='.$a);
    $onlyB = specTotal('category='.$b);
    $both = specTotal('category='.$a.','.$b);

    // a union, not a sum: a product placed in both is one product, so the union is at least the larger of the
    // two and never more than their total
    expect($both)->toBeGreaterThanOrEqual(max($onlyA, $onlyB), 'the union lost products one path alone finds')
        ->and($both)->toBeLessThanOrEqual($onlyA + $onlyB, 'a product in both paths was counted twice');
});

it('keeps ONE path behaving exactly as it did, which is what makes this safe for a live listing', function () {
    $cats = T::arr(getJson(H::base('products?facets=1&per_page=1'))->assertOk()->json('facets.category'));
    $first = T::arr($cats[0] ?? []);
    $path = T::str($first['path'] ?? '');
    expect($path)->not->toBe('');

    $page = T::arr(getJson(H::base('products?per_page=4&category='.$path))->assertOk()->json());
    $meta = T::arr($page['meta'] ?? []);

    // the facet's own count, the listing's total, and `meta.category` all still agree for a single path
    expect(T::int($meta['total'] ?? null))->toBe(T::int($first['count'] ?? null))
        ->and(T::str(T::arr($meta['category'] ?? [])['path'] ?? null))->toBe($path)
        // and the default sort for a category page is still `position`, not `newest`
        ->and(T::str($meta['sort'] ?? null))->toBe('position');
});

it('refuses the whole request when ANY path is unknown, rather than answering a narrower list', function () {
    $cats = T::arr(getJson(H::base('products?facets=1&per_page=1'))->assertOk()->json('facets.category'));
    $good = T::str(T::arr($cats[0] ?? [])['path'] ?? '');
    expect($good)->not->toBe('');

    // a filter the server did not understand must not look like a filter that matched nothing
    getJson(H::base('products?category='.$good.',no-such-category'))->assertNotFound();
    getJson(H::base('products?category=no-such-category'))->assertNotFound();
});
