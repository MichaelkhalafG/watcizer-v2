<?php

use App\Compat\CompatListing;
use App\Compat\CompatServices;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * ── Every listing filter the storefront can set is one the listing actually reads ──────────────
 *
 * Found after the Phase 2 cutover (2026-09-24): picking "Electronics" showed the whole catalogue.
 * The browser's predicate (`filterPredicate.js`) never read `filters.categories`, so every product
 * passed; the filter state was set correctly and nothing used it.
 *
 * Since C-1 stage 3 the filtering is core's (`CompatListing`, `catalog/listing`), and stage 4 slice D
 * deleted the browser predicate — its frozen copy is `tests/Fixtures/filter-predicate-reference.js`,
 * which `CatalogListingTest` holds core's listing to. So these guards now point at core: the keys
 * the storefront can set must be keys the listing reads, and the two cases that broke must work there.
 * The storefront has no test runner, so the guard lives in the suite that runs on every change.
 */

function storefrontFile(string $relative): string
{
    $path = base_path('../Frontend-next/'.$relative);
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return (string) file_get_contents($path);
}

/**
 * The filter keys the storefront can SET: `ListingClient`'s full filter state (its reset object)
 * plus every `filters.<key> =` in `listingSeo.js`.
 *
 * @return list<string>
 */
function settableFilterKeys(): array
{
    $keys = [];

    $client = storefrontFile('app/(main)/listing/ListingClient.jsx');
    if (preg_match('/setFilters\(\{(.*?)\}\)/s', $client, $reset) !== 1) {
        throw new RuntimeException('ListingClient no longer resets its filters with setFilters({...}) — update this parser.');
    }
    preg_match_all('/^\s*([a-zA-Z]+)\s*:/m', $reset[1], $m);
    array_push($keys, ...$m[1]);

    preg_match_all('/filters\.([a-zA-Z]+)\s*=(?!=)/', storefrontFile('src/lib/listingSeo.js'), $m);
    array_push($keys, ...$m[1]);

    return array_values(array_unique($keys));
}

/**
 * Every visible product id the listing returns for these filters (all pages at once).
 *
 * @param  array<string, mixed>  $only
 * @return list<int>
 */
function listingIds(array $only): array
{
    $filters = array_merge([
        'brands' => [], 'categories' => [], 'subTypes' => [], 'genders' => [], 'offers' => false,
        'price' => [0.0, (float) CompatListing::PRICE_MAX], 'dialColors' => [], 'bandColors' => [], 'materials' => [],
        'movements' => [], 'shapes' => [], 'displayTypes' => [], 'grades' => [],
    ], $only);
    /** @var array{brands: list<int>, categories: list<int>, subTypes: list<int>, genders: list<string>, offers: bool, price: array{0: float, 1: float}, dialColors: list<int>, bandColors: list<int>, materials: list<int>, movements: list<int>, shapes: list<int>, displayTypes: list<int>, grades: list<int>} $filters */
    $result = app(CompatServices::class)->listing->query($filters, '', 'default', 1, 100000);

    return array_map(fn (array $p): int => T::int($p['id'] ?? null), $result['products']);
}

it('reads every filter key the storefront can set', function () {
    $keys = settableFilterKeys();

    // The reset object alone lists thirteen; a parse that found fewer would pass for the wrong reason.
    expect(count($keys))->toBeGreaterThanOrEqual(13)
        ->and(array_values(array_diff($keys, CompatListing::FILTERS)))->toBe([]);
});

it('narrows by category — every category type, not only Watches and Fashion', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $all = listingIds([]);
    $categories = array_values(array_unique(array_filter(array_map(fn (array $e): ?int => $e['categories'], $entries), fn (?int $c): bool => $c !== null)));
    expect(count($categories))->toBeGreaterThanOrEqual(2);

    foreach ($categories as $category) {
        $want = array_map(fn (array $e): int => $e['id'], array_values(array_filter($entries, fn (array $e): bool => $e['categories'] === $category)));
        $got = listingIds(['categories' => [$category]]);
        sort($want);
        sort($got);
        expect($got)->toBe($want)
            ->and(count($got))->toBeLessThan(count($all));        // the Electronics bug: it returned everything
    }
});

it('finds a REAL two-tone product under EITHER of its colours', function () {
    /*
     * 2026-09-27. A two-tone finish is two colours in one role (strap Gold + Silver), and a
     * shopper filtering for either colour must find it.
     */
    $row = T::row(DB::table('catalog_product_color as pc')
        ->join('catalog_products as p', 'p.id', '=', 'pc.product_id')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
        })
        ->where('p.family', 'watch')->whereNull('p.deleted_at')->where('pc.role', 'band')
        ->groupBy('pc.product_id')->havingRaw('COUNT(*) = 2')->orderBy('pc.product_id')
        ->first(['pc.product_id']));
    $productId = Row::int($row, 'product_id');
    $band = array_map(fn (mixed $c): int => T::int($c), DB::table('catalog_product_color')->where('product_id', $productId)->where('role', 'band')->orderBy('color_id')->pluck('color_id')->all());
    $other = T::int(DB::table('catalog_colors')->whereNotIn('id', $band)->orderBy('id')->value('id'));

    expect(listingIds(['bandColors' => [$band[0]]]))->toContain($productId)
        ->and(listingIds(['bandColors' => [$band[1]]]))->toContain($productId)
        ->and(listingIds(['bandColors' => [$other]]))->not->toContain($productId);
});
