<?php

use App\Storefront\StorefrontCache;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
 * `catalog/listing` — the three rules fixed on purpose (developer's decision, 2026-09-27), each with
 * explicit cases. The parity test (CatalogListingTest) holds the whole listing to the reference;
 * these pin what a shopper was promised:
 *
 *  - the search matches both languages and folds Arabic spelling;
 *  - one typo is tolerated ONLY when nothing matches exactly, against titles and brand names only,
 *    and a search that already matches never gains results;
 *  - a blank sale price is the list price, not 0;
 *  - facet counts follow the search;
 *  - and the key may ride in the query string on a GET (no CORS preflight), never on a write.
 */

const SEARCH_API_KEY = 'catalog-search-test-key';

beforeEach(function () {
    config(['compat.api_key' => SEARCH_API_KEY]);
    app(StorefrontCache::class)->flush(1);
});

/** @return array<mixed> */
function searchListing(string $query): array
{
    return T::arr(withHeaders(['Api-Code' => SEARCH_API_KEY])->getJson('/api/catalog/listing?'.$query)->assertOk()->json());
}

function searchTotal(string $query): int
{
    return T::int(searchListing($query)['total']);
}

/** @return list<int> every product id of the listing for this query, all pages */
function searchIds(string $query): array
{
    $ids = [];
    for ($page = 1; $page < 100; $page++) {
        $r = searchListing($query.'&per_page=96&page='.$page);
        $products = T::arr($r['products']);
        foreach ($products as $p) {
            $ids[] = T::int(T::arr($p)['id']);
        }
        if (count($ids) >= T::int($r['total']) || $products === []) {
            break;
        }
    }
    sort($ids);

    return $ids;
}

/** @return array{id: int, en: string, ar: string} the Rolex brand, which the dev copy carries */
function rolexBrand(): array
{
    $row = T::row(DB::table('catalog_brand_translations as en')
        ->join('catalog_brand_translations as ar', function (JoinClause $j): void {
            $j->on('ar.brand_id', '=', 'en.brand_id')->where('ar.locale', '=', 'ar');
        })
        ->where('en.locale', 'en')->where('en.name', 'Rolex')->first(['en.brand_id as id', 'en.name as en', 'ar.name as ar']));

    return ['id' => T::int($row->id), 'en' => T::str($row->en), 'ar' => T::str($row->ar)];
}

it('finds a brand typed in the other language', function () {
    $rolex = rolexBrand();
    $brandProducts = searchTotal('brands='.$rolex['id']);
    expect($brandProducts)->toBeGreaterThan(0);

    // The Arabic name in an English session, and the English name in an Arabic one, both reach
    // every Rolex product; the shop language no longer changes a search.
    expect(searchIds('brands='.$rolex['id'].'&q='.urlencode($rolex['ar']).'&lang=en'))->toHaveCount($brandProducts)
        ->and(searchTotal('q=Rolex&lang=ar'))->toBe(searchTotal('q=Rolex&lang=en'));
});

it('folds Arabic spelling on both sides', function () {
    // "ساعة" (watch) spelt with ه, and with a stray tatweel and a fatha, finds the same products.
    $proper = searchTotal('q='.urlencode('ساعة'));
    expect($proper)->toBeGreaterThan(0)
        ->and(searchTotal('q='.urlencode('ساعه')))->toBe($proper)
        ->and(searchTotal('q='.urlencode('ساعـةَ')))->toBe($proper);
});

it('tolerates one typo when nothing matches exactly — a wrong letter or two swapped', function () {
    $rolex = rolexBrand();
    $all = searchIds('brands='.$rolex['id']);

    foreach (['rolx', 'rolec', 'rloex', 'ROLXE'] as $typo) {
        expect(searchTotal('q='.$typo.'&per_page=1'))->toBeGreaterThan(0, "'{$typo}' found nothing")
            ->and(searchIds('brands='.$rolex['id'].'&q='.$typo))->toBe($all);
    }
    // One letter wrong in Arabic too (رولكس → رولكز).
    $arTypo = mb_substr($rolex['ar'], 0, -1).'ز';
    expect(searchIds('brands='.$rolex['id'].'&q='.urlencode($arTypo)))->toBe($all);
});

it('keeps the fallback narrow: short words, nonsense and two typos find nothing', function () {
    expect(searchTotal('q=rlx'))->toBe(0)          // 3 letters: must match exactly
        ->and(searchTotal('q=qqqqzzzz'))->toBe(0)
        ->and(searchTotal('q=ralix'))->toBe(0);    // exactly two edits from "rolex" (o→a, e→i): no
});

it('never adds near misses to a search that already matches', function () {
    // "marker" matches exactly, and "parker" — one letter away — is a real word in other products'
    // titles (found by searching the dev copy's vocabulary, 2026-09-27). A search that matches must
    // return exactly its exact matches: the Parker-only products must not slip in.
    // Which products contain which word is read from the raw rows — independent of the search
    // under test, so a search that wrongly returned Parker products for "marker" cannot hide them.
    $parkerOnly = [];
    foreach (T::arr(withHeaders(['Api-Code' => SEARCH_API_KEY])->getJson('/api/all_product')->json()) as $p) {
        $p = T::arr($p);
        $text = mb_strtolower(json_encode([$p['translations'] ?? null, $p['search_keywords'] ?? null], JSON_UNESCAPED_UNICODE) ?: '');
        if (str_contains($text, 'parker') && ! str_contains($text, 'marker')) {
            $parkerOnly[] = T::int($p['id']);
        }
    }
    $marker = searchIds('q=marker');
    // The fixture this test needs; if the dev copy loses it, say so rather than pass vacuously.
    expect($marker)->not->toBeEmpty('no product matches "marker" any more — pick another real near-miss pair')
        ->and($parkerOnly)->not->toBeEmpty('no "parker"-only product any more — pick another real near-miss pair');
    expect(array_values(array_intersect($marker, $parkerOnly)))->toBe([]);

    // And "rolex": exactly the products whose text or brand contains it.
    $exact = searchIds('q=rolex');
    $byText = [];
    foreach (T::arr(withHeaders(['Api-Code' => SEARCH_API_KEY])->getJson('/api/all_product')->json()) as $p) {
        $p = T::arr($p);
        $text = mb_strtolower(json_encode([$p['translations'] ?? null, $p['search_keywords'] ?? null], JSON_UNESCAPED_UNICODE) ?: '');
        if (str_contains($text, 'rolex')) {
            $byText[] = T::int($p['id']);
        }
    }
    $rolex = rolexBrand();
    $byText = array_values(array_unique([...$byText, ...searchIds('brands='.$rolex['id'])]));
    sort($byText);

    expect($exact)->toBe($byText);
});

it('prices a product without a sale price at its list price, not 0', function () {
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')->where('sp.storefront_id', 1)
        ->whereNull('cp.deleted_at')->where('cp.selling_price', '>', 1000)->orderBy('cp.id')->first(['cp.id', 'cp.brand_id', 'cp.selling_price']));
    $id = T::int($row->id);
    $list = (float) T::str($row->selling_price);
    DB::table('catalog_products')->where('id', $id)->update(['sale_price' => null]);
    app(StorefrontCache::class)->flush(1);

    $brand = 'brands='.T::int($row->brand_id);
    // In a range around its list price: found. In a range that only 0 would satisfy: not.
    expect(searchIds($brand.'&minPrice='.floor($list - 1).'&maxPrice='.ceil($list + 1)))->toContain($id)
        ->and(searchIds($brand.'&minPrice=1&maxPrice='.floor($list - 1)))->not->toContain($id);

    // And it no longer sorts as the cheapest thing in the shop.
    $cheapest = T::arr(searchListing('sort=price_asc&per_page=1')['products']);
    expect(T::int(T::arr($cheapest[0])['id']))->not->toBe($id);
});

it('counts facets over the search results only', function () {
    $r = searchListing('q=rol');
    $facets = T::arr($r['facets']);
    $brandSum = array_sum(array_map(fn (mixed $n): int => T::int($n), T::arr($facets['brands'])));
    $categorySum = array_sum(array_map(fn (mixed $n): int => T::int($n), T::arr($facets['categories'])));

    // Every product has exactly one brand and one category type: with no brand or category filter
    // the counts add up to the search total — not to the whole catalogue.
    expect($brandSum)->toBe(T::int($r['total']))
        ->and($categorySum)->toBe(T::int($r['total']))
        ->and(T::int($r['total']))->toBeLessThan(searchTotal(''));
});

it('accepts the key in the query string on a GET, and nowhere else', function () {
    getJson('/api/catalog/listing?api_code='.SEARCH_API_KEY)->assertOk();
    getJson('/api/catalog/cards?ids=1&api_code='.SEARCH_API_KEY)->assertOk();
    getJson('/api/catalog/listing?api_code=wrong')->assertUnauthorized();
    // A write never takes it from the query string.
    postJson('/api/add_to_cart?api_code='.SEARCH_API_KEY, ['product_id' => 1, 'quantity' => 1])->assertUnauthorized();
});
