<?php

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * The card's attribute colours (spec §8.2, 2026-10-16): a v2 card row and a v2 detail both carry
 * `colors[]` — the product's `catalog_product_color` links, dial → band → main, each colour once,
 * with `id`, `hex`, `name{}` and `role` — the exact shape the card's swatch row consumes.
 *
 * Three things, because the claim was three things: the shape when a product HAS colours; an EMPTY
 * ARRAY (never null) when it has none — the row is always reserved, so "no colours" is the normal
 * case, not a special one; and the cost — ONE batch query against `catalog_product_color` per page
 * of cards, inside the listing budget — asserted, so "one batch query" cannot rot into a sentence.
 */

beforeEach(fn () => H::flush());

/**
 * The visible product on the storefront with the most colour links, and one with none (made so inside the transaction).
 *
 * @return array{coloured: array{id: int, slug: string, links: int}, bare: array{id: int, slug: string}}
 */
function colouredAndBare(): array
{
    $sf = 1; // H::base() addresses storefront 1 (ApiTestHelpers::SF)
    $coloured = DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->join('catalog_product_color as c', 'c.product_id', '=', 'p.id')
        ->where('sp.storefront_id', $sf)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->groupBy('sp.product_id', 'sp.slug')
        ->orderByRaw('COUNT(*) DESC')->orderBy('sp.product_id')
        ->first(['sp.product_id', 'sp.slug', DB::raw('COUNT(*) as links')]);
    if ($coloured === null) {
        throw new RuntimeException('no visible product carries a colour — the fixture needs one');
    }

    $bare = DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', $sf)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->where('sp.product_id', '!=', T::int($coloured->product_id))
        ->orderBy('sp.product_id')
        ->first(['sp.product_id', 'sp.slug']);
    if ($bare === null) {
        throw new RuntimeException('the storefront has one visible product only — the fixture needs two');
    }
    // Bare on purpose: its colour links are removed inside this test's transaction.
    DB::table('catalog_product_color')->where('product_id', T::int($bare->product_id))->delete();

    return [
        'coloured' => ['id' => T::int($coloured->product_id), 'slug' => T::str($coloured->slug), 'links' => T::int($coloured->links)],
        'bare' => ['id' => T::int($bare->product_id), 'slug' => T::str($bare->slug)],
    ];
}

/**
 * The expected list for a product, built from the pivot and the lookup tables directly — the oracle.
 *
 * @return list<array{id: int, hex: string, role: string}>
 */
function expectedColours(int $productId): array
{
    $order = ['dial' => 0, 'band' => 1, 'main' => 2];
    $links = DB::table('catalog_product_color')->where('product_id', $productId)->get(['color_id', 'role', 'position'])->all();
    usort($links, fn ($a, $b) => [$order[T::str($a->role)] ?? 9, T::int($a->position), T::int($a->color_id)] <=> [$order[T::str($b->role)] ?? 9, T::int($b->position), T::int($b->color_id)]);
    $out = [];
    $seen = [];
    foreach ($links as $l) {
        $cid = T::int($l->color_id);
        if (isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;
        $out[] = ['id' => $cid, 'hex' => T::str(DB::table('catalog_colors')->where('id', $cid)->value('hex')), 'role' => T::str($l->role)];
    }

    return $out;
}

it('puts a product\'s attribute colours on its card row and its detail, dial then band, each once, with hex, name and role', function () {
    ['coloured' => $p] = colouredAndBare();
    H::flush();
    $expected = expectedColours($p['id']);
    expect($expected)->not->toBeEmpty();

    // the detail
    $detail = H::arr(getJson(H::base('products/'.$p['slug']))->assertOk()->json('product'));
    $colours = H::rows($detail['colors']);
    expect(array_map(fn (array $c) => [$c['id'], $c['hex'], $c['role']], $colours))->toBe(array_map(fn (array $e) => [$e['id'], $e['hex'], $e['role']], $expected))
        ->and($colours[0])->toHaveKeys(['id', 'hex', 'name', 'role'])
        ->and(H::arr($colours[0]['name']))->toHaveKey('en')
        ->and(T::str($colours[0]['hex']))->toMatch('/^#[0-9A-Fa-f]{6}$/');

    // the same list on the listing's card row for that product (the swatch row reads card rows)
    $page = getJson(H::base('products?q='.urlencode(T::str(H::arr($detail['title'])['en'] ?? '')).'&per_page=96&locale=en'))->assertOk()->json('data');
    $row = collect(H::rows($page))->first(fn (array $r) => $r['id'] === $p['id']);
    if ($row === null) {
        // search did not surface it (a title with no searchable words) — page through the plain listing instead
        for ($n = 1, $row = null; $row === null && $n <= 12; $n++) {
            $rows = H::rows(getJson(H::base("products?per_page=96&page={$n}"))->assertOk()->json('data'));
            $row = collect($rows)->first(fn (array $r) => $r['id'] === $p['id']);
            if ($rows === []) {
                break;
            }
        }
    }
    if ($row === null) {
        throw new RuntimeException('the coloured product did not appear in the listing');
    }
    expect(array_map(fn (array $c) => [$c['id'], $c['hex'], $c['role']], H::rows($row['colors'])))->toBe(array_map(fn (array $e) => [$e['id'], $e['hex'], $e['role']], $expected));
});

it('answers an EMPTY ARRAY, never null, for a product without attribute colours — the reserved row\'s normal case', function () {
    ['bare' => $p] = colouredAndBare();
    H::flush();

    $detail = H::arr(getJson(H::base('products/'.$p['slug']))->assertOk()->json('product'));
    expect($detail)->toHaveKey('colors')
        ->and($detail['colors'])->toBeArray()->toBe([])
        ->and(H::arr($detail['attributes'])['colors'])->toBe(['dial' => [], 'band' => [], 'main' => []]);

    $first = H::rows(getJson(H::base('products?per_page=96'))->assertOk()->json('data'));
    foreach ($first as $row) {
        // every row carries the key, and it is a list — [] when the product has no colour
        expect($row)->toHaveKey('colors')->and($row['colors'])->toBeArray();
    }
});

it('costs the listing exactly ONE query against catalog_product_color per page, inside the §5.3 budget', function () {
    // warm the per-version caches first, as QueryBudgetTest does, so only the page's own queries count
    getJson(H::base('products?per_page=96'))->assertOk();
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    getJson(H::base('products?per_page=96'))->assertOk();
    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $colourQueries = array_values(array_filter($log, fn (array $q) => str_contains(strtolower((string) $q['query']), 'catalog_product_color')));
    expect($colourQueries)->toHaveCount(1, 'the card colours must be ONE batch query per page, not one per card')
        ->and(count($log))->toBeLessThanOrEqual(6, 'the listing budget of QueryBudgetTest (≤ 6 warm) holds with the colours on the row');
});
