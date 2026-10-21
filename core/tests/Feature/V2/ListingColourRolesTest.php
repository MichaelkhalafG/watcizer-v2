<?php

use App\Storefront\ProductListing;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * THE TWO COLOUR SECTIONS, one per role (2026-10-23).
 *
 * Why they exist: `catalog_product_color.role` holds exactly two values, `dial` and `band`, and there is no
 * third. A watch carries both and they are different facts about the same watch, which is why compat has
 * always offered two sections. A dress carries ONE colour and it is stored under `band`, because that is the
 * column the dashboard writes to — so a fashion result has band colours and no dial colours at all.
 *
 * v2's listing merged the two into one `color` facet, and when Watchizer's listing moved here that would have
 * taken a watch shop's dial/band distinction away. These tests pin the promise: the two sections are counted
 * and filtered independently, by role, and the result decides which of them has anything in it — core knows
 * nothing about categories, and nothing here special-cases a product kind.
 */

/**
 * The ids one colour section serves for a query, best first.
 *
 * @return list<int>
 */
function colourFacetIds(string $section, string $query = ''): array
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
function colourTotal(string $query): int
{
    return T::int(getJson(H::base('products?per_page=1'.($query === '' ? '' : '&'.$query)))->assertOk()->json('meta.total'));
}

it('serves one section per colour role, and only values the result contains', function () {
    foreach (array_keys(ProductListing::COLOUR_FACETS) as $name) {
        $section = (string) $name;
        foreach (colourFacetIds($section) as $id) {
            expect(colourTotal($section.'='.$id))->toBeGreaterThan(0, "{$section} {$id} is served but matches nothing");
        }
    }
});

it('counts and filters each role independently, agreeing with the pivot itself', function () {
    foreach (ProductListing::COLOUR_FACETS as $section => $role) {
        $ids = colourFacetIds((string) $section);
        if ($ids === []) {
            continue;   // a catalogue with none of this role: the section is empty and draws nothing
        }
        $id = (int) $ids[0];
        $section = (string) $section;

        // the listing's own count, against the same count taken straight from the pivot
        $expected = DB::table('catalog_product_color as pc')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'pc.product_id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
            })
            ->join('catalog_products as p', 'p.id', '=', 'pc.product_id')
            ->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->where('pc.role', (string) $role)->where('pc.color_id', $id)
            ->distinct()->count('pc.product_id');

        expect(colourTotal($section.'='.$id))->toBe($expected, "{$section}={$id} disagrees with the pivot")
            ->and(colourTotal($section.'=99999999'))->toBe(0, "{$section} ignored an unknown id");
    }
});

it('AND-s the two sections together, because a black dial and a brown strap is one watch', function () {
    $dials = colourFacetIds('dial_color');
    $bands = colourFacetIds('band_color');
    if ($dials === [] || $bands === []) {
        expect(true)->toBeTrue('this storefront carries only one colour role');

        return;
    }
    [$d, $b] = [(int) $dials[0], (int) $bands[0]];
    $both = colourTotal("dial_color={$d}&band_color={$b}");

    // an intersection, never a union: narrower than either alone, and never their sum
    expect($both)->toBeLessThanOrEqual(min(colourTotal("dial_color={$d}"), colourTotal("band_color={$b}")), 'the two sections behaved as a union');
});

it('keeps the merged `color` key filtering a colour in ANY role, for callers that still send it', function () {
    $dials = colourFacetIds('dial_color');
    if ($dials === []) {
        expect(true)->toBeTrue('no dial colours on this storefront');

        return;
    }
    $id = (int) $dials[0];

    // any-role is at least as wide as the one role, which is the whole point of keeping it
    expect(colourTotal('color='.$id))->toBeGreaterThanOrEqual(colourTotal('dial_color='.$id));
});

it('lets the RESULT decide which sections have anything in them, with no idea of product kind', function () {
    // A category whose products carry dial colours (watches) and one whose products do not (anything whose
    // colour is a single plain field) are told apart by the counts alone — which is what the sidebar draws from.
    $cats = T::arr(getJson(H::base('products?facets=1&per_page=1'))->assertOk()->json('facets.category'));
    $withDial = 0;
    $withoutDial = 0;
    foreach ($cats as $row) {
        $c = T::arr($row);
        if (T::int($c['depth'] ?? null) !== 1) {
            continue;
        }
        $path = T::str($c['path'] ?? '');
        $dial = colourFacetIds('dial_color', 'category='.$path);
        $band = colourFacetIds('band_color', 'category='.$path);
        if ($dial !== []) {
            $withDial++;
            // where a dial colour exists, so does a band colour: it is the same watch
            expect($band)->not->toBeEmpty("{$path} serves dial colours but no band colours");
        } elseif ($band !== []) {
            $withoutDial++;   // one plain colour, stored under `band` — the section the sidebar renames
        }
    }
    // the catalogue this runs against must actually contain both kinds, or the test proves nothing
    expect($withDial)->toBeGreaterThan(0, 'no family carries dial colours')
        ->and($withoutDial)->toBeGreaterThan(0, 'no family carries a single plain colour');
});
