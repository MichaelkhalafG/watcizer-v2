<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutMiddleware;

/*
 * The v2 listing's READ half for a filter sidebar (2026-10-17, B4-LISTING-REQUIREMENT gaps 2–4):
 *
 *  - `facets=1` (opt-in) adds a `facets` block: per-result option counts for brand, gender, colour,
 *    material and grade, the `in_stock` split and the price bounds of the result. Each section is
 *    counted "as if its own filter were cleared" (the compat semantics the sidebars already assume),
 *    so a count is exactly the `meta.total` the same filter would produce — which is how it is
 *    asserted here: against the listing itself, never against a second copy of the visibility rule.
 *  - the block costs SEVEN aggregate queries once per filter hash and is then a cache hit, so the
 *    listing's warm budget (QueryBudgetTest, ≤ 6) is untouched by it — asserted.
 *  - `sort=available` (§19-5 "available first"): in stock first, then today's order, then product id —
 *    a TOTAL order, so offset paging shows every product exactly once; asserted by paging a whole
 *    category. What a stock change does to a reader mid-way is asserted too, both directions, because
 *    "stable" has to say what it does not promise.
 *  - a brand token may be a numeric id and a gender token an English name (the Brand Fashion adapter's
 *    vocabulary) — each equal to its slug / id form.
 */

beforeEach(function (): void {
    H::flush();
    // This file pages whole categories several times over (the stability proofs): that is more requests
    // in a minute than the per-IP `api` limiter allows, and the limiter is not what it measures.
    withoutMiddleware(ThrottleRequests::class);
});

/** @return array<string, mixed> */
function facetPage(string $query): array
{
    return H::arr(getJson(H::base('products?'.$query))->assertOk()->json());
}

function facetTotal(string $query): int
{
    return T::int(H::arr(facetPage($query.'&per_page=1')['meta'])['total']);
}

/** @return array<string, mixed> the facets block of a query */
function facetsOf(string $query): array
{
    return H::arr(facetPage($query.'&facets=1&per_page=1')['facets'] ?? []);
}

/**
 * @param  array<string, mixed>  $facets
 * @return list<array<string, mixed>>
 */
function facetRows(array $facets, string $section): array
{
    return H::rows($facets[$section] ?? []);
}

/**
 * A product's two stock buckets, set through InventoryService (the only writer the StockWriteGuard allows);
 * `in_stock` is re-derived by it. Returns the buckets as they were, for restoring.
 *
 * @return array{express: int, market: int}
 */
function facetSetStock(int $productId, int $express, int $market): array
{
    $before = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']);
    $inv = app(InventoryService::class);
    $inv->set(StockTarget::product($productId), 'express', $express, 'adjustment');
    $inv->set(StockTarget::product($productId), 'market', $market, 'adjustment');

    return ['express' => $before === null ? 0 : T::int($before->stock_express), 'market' => $before === null ? 0 : T::int($before->stock_market)];
}

function facetQueryCount(Closure $request): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $request();
    $n = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    return $n;
}

/**
 * The depth-1 category with the most products that has BOTH stock states: {path, total, in, out}.
 *
 * @return array{path: string, total: int, in: int, out: int}
 */
function facetMixedCategory(): array
{
    $best = null;
    foreach (H::treePaths(getJson(H::base('meta'))->assertOk()->json('tree')) as $path) {
        if (str_contains($path, '/')) {
            continue;
        }
        $page = facetPage('category='.$path.'&facets=1&per_page=1');
        $stock = H::arr(H::arr($page['facets'])['in_stock']);
        $total = T::int(H::arr($page['meta'])['total']);
        if (T::int($stock['in']) > 0 && T::int($stock['out']) > 0 && ($best === null || $total > $best['total'])) {
            $best = ['path' => $path, 'total' => $total, 'in' => T::int($stock['in']), 'out' => T::int($stock['out'])];
        }
    }
    if ($best === null) {
        throw new RuntimeException('no depth-1 category carries both in-stock and out-of-stock products');
    }

    return $best;
}

/**
 * Page through `query` with `sort=available`; the rows' (id, in_stock, sort_order) in page order.
 *
 * @return list<array{0: int, 1: bool, 2: int}>
 */
function facetWalk(string $query, int $perPage = 96, int $fromPage = 1, ?int $toPage = null): array
{
    $out = [];
    for ($p = $fromPage; $toPage === null || $p <= $toPage; $p++) {
        $page = facetPage($query.'&sort=available&per_page='.$perPage.'&page='.$p);
        foreach (H::rows($page['data']) as $row) {
            $stock = H::arr($row['stock']);
            $out[] = [T::int($row['id']), (bool) $stock['in_stock'], T::int($row['sort_order'])];
        }
        if (H::arr($page['links'])['next'] === null) {
            break;
        }
    }

    return $out;
}

it('serves the facet block only when asked, with the five sections, the stock split and the price bounds', function () {
    expect(facetPage('per_page=1'))->not->toHaveKey('facets')
        ->and(facetPage('facets=0&per_page=1'))->not->toHaveKey('facets');

    $f = facetsOf('');
    expect(array_keys($f))->toBe(['category', 'brand', 'gender', 'color', 'material', 'grade', 'in_stock', 'price']);
    foreach (['brand', 'gender', 'color', 'material', 'grade'] as $section) {
        $rows = facetRows($f, $section);
        expect($rows)->not->toBeEmpty("section {$section} is empty on the whole catalogue");
        $counts = array_map(fn (array $r) => T::int($r['count']), $rows);
        $sorted = $counts;
        rsort($sorted);
        expect($counts)->toBe($sorted, "section {$section} is not highest-count first")
            ->and(array_filter($counts, fn (int $c) => $c < 1))->toBe([], 'a value with no match is not served (spec §10.2)');
        expect($rows[0])->toHaveKeys(['id', 'name', 'count'])->and(H::arr($rows[0]['name']))->toHaveKey('en');
    }
    expect(facetRows($f, 'brand')[0])->toHaveKey('slug')
        ->and(facetRows($f, 'color')[0])->toHaveKey('hex');
    // category: every served node is a visible menu node whose count is the total of its own page, in tree order
    $cats = facetRows($f, 'category');
    expect($cats)->not->toBeEmpty();
    $paths = array_map(fn (array $c) => T::str($c['path']), $cats);
    $sorted = $paths;
    sort($sorted, SORT_STRING);
    expect($paths)->toBe($sorted, 'category nodes come in tree (path) order');
    foreach (array_slice($cats, 0, 3) as $c) {
        expect($c)->toHaveKeys(['id', 'slug', 'path', 'depth', 'name', 'count'])
            ->and(T::int($c['count']))->toBe(facetTotal('category='.T::str($c['path'])), 'category '.T::str($c['path']));
    }

    $stock = H::arr($f['in_stock']);
    $price = H::arr($f['price']);
    expect(T::int($stock['in']) + T::int($stock['out']))->toBe(facetTotal(''), 'the stock split sums to the result')
        ->and($price)->toHaveKeys(['min', 'max'])
        ->and(T::float($price['min']))->toBeLessThanOrEqual(T::float($price['max']));
});

it('counts each section as if its own filter were cleared — every count is the total the same filter produces', function () {
    $all = facetsOf('');
    $brand = facetRows($all, 'brand')[0];     // the biggest brand
    $gender = facetRows($all, 'gender')[0];
    $bSlug = T::str($brand['slug']);
    $gId = T::int($gender['id']);

    $f = facetsOf("brand={$bSlug}&gender={$gId}");
    // brand section: the brand filter is cleared, the gender filter stays
    foreach (array_slice(facetRows($f, 'brand'), 0, 3) as $row) {
        expect(T::int($row['count']))->toBe(facetTotal('brand='.T::str($row['slug']).'&gender='.$gId), 'brand '.T::str($row['slug']));
    }
    // gender section: the gender filter is cleared, the brand filter stays
    foreach (facetRows($f, 'gender') as $row) {
        expect(T::int($row['count']))->toBe(facetTotal("brand={$bSlug}&gender=".T::int($row['id'])), 'gender '.T::int($row['id']));
    }
    // colour, material, grade: both filters stay (neither is their own)
    foreach (['color', 'material', 'grade'] as $section) {
        $row = facetRows($f, $section)[0] ?? null;
        if ($row === null) {
            continue;
        }
        expect(T::int($row['count']))->toBe(facetTotal("brand={$bSlug}&gender={$gId}&{$section}=".T::int($row['id'])), "{$section} ".T::int($row['id']));
    }
    // the stock split and the price bounds follow the result of the two filters
    $stock = H::arr($f['in_stock']);
    expect(T::int($stock['in']))->toBe(facetTotal("brand={$bSlug}&gender={$gId}&in_stock=1"))
        ->and(T::int($stock['in']) + T::int($stock['out']))->toBe(facetTotal("brand={$bSlug}&gender={$gId}"));
    $cheapest = H::rows(facetPage("brand={$bSlug}&gender={$gId}&sort=price_asc&per_page=1")['data'])[0];
    $dearest = H::rows(facetPage("brand={$bSlug}&gender={$gId}&sort=price_desc&per_page=1")['data'])[0];
    expect(T::float(H::arr($f['price'])['min']))->toBe(T::float(H::arr($cheapest['price'])['amount']))
        ->and(T::float(H::arr($f['price'])['max']))->toBe(T::float(H::arr($dearest['price'])['amount']));
});

it('serves the price bounds of the result WITHOUT the price filter, so the inputs keep their range', function () {
    $all = facetsOf('');
    $price = H::arr($all['price']);
    [$min, $max] = [T::float($price['min']), T::float($price['max'])];
    expect($max)->toBeGreaterThan($min);
    $mid = (int) floor(($min + $max) / 2);

    $narrow = facetsOf("price_max={$mid}");
    expect(H::arr($narrow['price']))->toBe($price, 'the bounds ignore the price filter (as the other sections ignore their own)');
    $stock = H::arr($narrow['in_stock']);
    expect(T::int($stock['in']) + T::int($stock['out']))->toBe(facetTotal("price_max={$mid}"), 'while the stock split follows it')
        ->and(T::int($stock['in']) + T::int($stock['out']))->toBeLessThan(facetTotal(''));
});

it('accepts a brand by numeric id and a gender by English name — the adapter\'s vocabulary — equal to slug and id', function () {
    $all = facetsOf('');
    $brand = facetRows($all, 'brand')[0];
    $gender = facetRows($all, 'gender')[0];
    $name = T::str(H::arr($gender['name'])['en']);

    expect(facetTotal('brand='.T::int($brand['id'])))->toBe(facetTotal('brand='.T::str($brand['slug'])))->toBe(T::int($brand['count']))
        ->and(facetTotal('gender='.strtolower($name)))->toBe(facetTotal('gender='.T::int($gender['id'])))->toBe(T::int($gender['count']))
        ->and(facetTotal('brand=999999'))->toBe(0, 'an unknown id matches nothing, like an unknown slug')
        ->and(facetTotal('gender=nobody'))->toBe(0);
});

it('costs EIGHT queries once per filter hash and is a cache hit after, inside the listing\'s warm budget', function () {
    getJson(H::base('products?per_page=24'))->assertOk();   // per-version caches + this hash's count
    $warmPlain = facetQueryCount(fn () => getJson(H::base('products?per_page=24'))->assertOk());
    $coldFacets = facetQueryCount(fn () => getJson(H::base('products?per_page=24&facets=1'))->assertOk());
    $warmFacets = facetQueryCount(fn () => getJson(H::base('products?per_page=24&facets=1'))->assertOk());
    fwrite(STDERR, "\nfacets budget — plain warm {$warmPlain}, facets cold {$coldFacets}, facets warm {$warmFacets}\n");

    expect($coldFacets - $warmPlain)->toBe(8, 'the facet block is eight aggregate queries (category added 2026-10-17), not one per value')
        ->and($warmFacets)->toBe($warmPlain)->toBeLessThanOrEqual(6, 'a cached block adds nothing to the §5.3 listing budget');
});

it('pages a whole category under sort=available showing every product exactly once, in stock first, in a total order', function () {
    $cat = facetMixedCategory();
    $walk = facetWalk('category='.$cat['path']);
    $ids = array_map(fn (array $r) => $r[0], $walk);

    expect(count($ids))->toBe($cat['total'])
        ->and(count(array_unique($ids)))->toBe($cat['total'], 'a product appeared twice');
    $flags = array_map(fn (array $r) => $r[1], $walk);
    $firstOut = array_search(false, $flags, true);
    expect($firstOut)->toBe($cat['in'], 'the in-stock block is exactly the in-stock count')
        ->and(array_slice($flags, (int) $firstOut))->each->toBeFalse();
    $keys = array_map(fn (array $r) => [$r[1] ? 0 : 1, $r[2], $r[0]], $walk);
    $sorted = $keys;
    sort($sorted);
    expect($keys)->toBe($sorted, 'the declared order (in_stock desc, sort_order asc, product_id asc) holds across pages');
});

it('says what a stock change does to a reader mid-way: leaving stock ahead is seen once later; entering stock behind is missed and shifts one card onto the next page', function () {
    $cat = facetMixedCategory();
    $q = 'category='.$cat['path'];
    $perPage = 24;
    $pages = (int) ceil($cat['total'] / $perPage);
    if ($pages < 3) {
        throw new RuntimeException('fixture: the category needs three pages of 24');
    }

    // (a) a product on page 3, in stock, goes OUT of stock after the reader has read pages 1–2
    $first = facetWalk($q, $perPage, 1, 2);
    $page3 = facetWalk($q, $perPage, 3, 3);
    // a product without variants: its in_stock follows its own two buckets
    $leaving = collect($page3)->first(fn (array $r) => $r[1] && ! StockTarget::productHasVariants($r[0]));
    if ($leaving === null) {
        throw new RuntimeException('fixture: page 3 carries no in-stock product without variants');
    }
    $was = facetSetStock($leaving[0], 0, 0);
    expect(DB::table('catalog_products')->where('id', $leaving[0])->value('in_stock'))->toBe(0);
    $rest = facetWalk($q, $perPage, 3);
    $seen = array_count_values(array_map(fn (array $r) => $r[0], array_merge($first, $rest)));
    expect($seen[$leaving[0]] ?? 0)->toBe(1, 'the product that left stock is seen exactly once — later, in the tail')
        ->and(count($seen))->toBe($cat['total'], 'nothing is missed')
        ->and(max([...$seen, 0]))->toBe(1, 'nothing is duplicated');
    facetSetStock($leaving[0], $was['express'], $was['market']);

    // (b) the tail product that would land EARLIEST in the in-stock block comes INTO stock after the reader
    //     has passed the page it lands on
    $all = facetWalk($q, $perPage);
    $key = fn (array $r): array => [$r[2], $r[0]];
    $tail = array_values(array_filter($all, fn (array $r) => ! $r[1] && ! StockTarget::productHasVariants($r[0])));
    usort($tail, fn (array $a, array $b): int => $key($a) <=> $key($b));
    $entering = $tail[0] ?? null;
    if ($entering === null) {
        throw new RuntimeException('fixture: the category has no out-of-stock product without variants');
    }
    $inBlock = array_values(array_filter($all, fn (array $r) => $r[1]));
    $idx = count(array_filter($inBlock, fn (array $r) => $key($r) < $key($entering)));   // where it would be inserted
    $k = intdiv($idx, $perPage) + 1;                                                        // the page it would land on
    if ($k * $perPage >= count($all)) {
        throw new RuntimeException('fixture: the product would land on the last page — nothing after it to observe');
    }
    $lastOnPageK = $all[$k * $perPage - 1];
    $head = facetWalk($q, $perPage, 1, $k);
    $was = facetSetStock($entering[0], 1, 0);
    expect(DB::table('catalog_products')->where('id', $entering[0])->value('in_stock'))->toBe(1);
    $rest = facetWalk($q, $perPage, $k + 1);
    $seen = array_count_values(array_map(fn (array $r) => $r[0], array_merge($head, $rest)));
    $twice = array_keys(array_filter($seen, fn (int $n) => $n > 1));
    expect($seen)->not->toHaveKey($entering[0], 'the product that entered stock behind the reader is never seen (offset paging cannot show it)')
        ->and($twice)->toBe([$lastOnPageK[0]], "exactly one card — the last of page {$k} — is shown again at the top of page ".($k + 1))
        ->and(count($seen))->toBe($cat['total'] - 1);
    facetSetStock($entering[0], $was['express'], $was['market']);
});
