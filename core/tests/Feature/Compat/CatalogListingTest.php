<?php

use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `catalog/listing` — the storefront's listing on the server (C-1 stage 3, 2026-09-27).
 *
 * THE CONTRACT IS THE STOREFRONT'S PREVIOUS BEHAVIOUR, WITH THREE BUGS FIXED ON PURPOSE. The
 * browser used to download the whole catalogue and, per filter change, run `transformProduct.js` →
 * `passesFilters` → search → sort → page, and count every sidebar / chip option with
 * `passesFilters(p, filters, ownSection)`. This test runs that — the storefront's own transform and
 * predicate copied verbatim, and the pipeline glue from ListingClient / SideBar / SmartSuggestions —
 * in node, over the same responses, for a battery of scenarios built from the real data, and
 * requires the endpoint to agree: the total, the page's ids IN ORDER, and every facet count.
 *
 * The reference asserts the NEW rules (developer's decision, 2026-09-27), deliberately, where the
 * old glue was wrong — it is not loosened, it is changed:
 *  1. PRICE — the filter and the price sorts read what the card shows and checkout charges (the
 *     sale price only when 0 < sale < list, else the list price), not a blank sale price as 0.
 *  2. FACETS — a product the search does not match counts in no facet.
 *  3. SEARCH — title, short description and brand in BOTH languages plus the keywords, with Arabic
 *     spelling folded (written here independently of the PHP). The near-miss fallback, which only
 *     runs when nothing matches exactly, has its own tests below; every search scenario here must
 *     have exact matches, and the reference says so if one does not.
 */

const LISTING_API_KEY = 'catalog-listing-test-key';

beforeEach(function () {
    config(['compat.api_key' => LISTING_API_KEY]);
});

function listingNode(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

function listingStorefrontFile(string $relative): string
{
    $path = dirname(base_path()).'/Frontend-next/'.$relative;
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return (string) file_get_contents($path);
}

/** @return array<mixed> */
function listingGet(string $uri): array
{
    return T::arr(withHeaders(['Api-Code' => LISTING_API_KEY])->getJson($uri)->assertOk()->json());
}

it('reproduces the storefront listing — totals, page order and every facet count', function () {
    // No product has a blank sale price today, but one added without it is possible, and the JS
    // treats it as 0 in the price filter and the price sorts. Blank two (rolled back) so that path
    // is exercised: one early in the default order, one later.
    $blank = array_map(fn (mixed $id): int => T::int($id), DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')->where('sp.storefront_id', 1)
        ->whereNull('cp.deleted_at')->orderByDesc('cp.id')->limit(40)->pluck('cp.id')->all());
    DB::table('catalog_products')->whereIn('id', [$blank[0], $blank[count($blank) - 1]])->update(['sale_price' => null]);
    app(StorefrontCache::class)->flush(1);

    $all = withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/all_product')->assertOk()->json();
    $meta = listingGet('/api/catalog/meta');
    $ratings = withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/all_product_rating')->assertOk()->json();
    $images = withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/all_product_image')->assertOk()->json();
    $rows = array_map(fn (mixed $r): array => T::arr($r), T::arr($all));
    expect(count($rows))->toBeGreaterThan(50);

    // Scenario values taken from the data, so every filter bites on something real.
    $pick = function (string $field) use ($rows): array {
        $n = [];
        foreach ($rows as $r) {
            if (is_int($r[$field] ?? null)) {
                $n[$r[$field]] = ($n[$r[$field]] ?? 0) + 1;
            }
        }
        arsort($n);

        return array_map('intval', array_keys($n));
    };
    $colours = function (string $rel) use ($rows): array {
        $n = [];
        foreach ($rows as $r) {
            foreach (T::arr($r[$rel] ?? []) as $c) {
                $id = T::arr($c)['id'] ?? null;
                if (is_int($id)) {
                    $n[$id] = ($n[$id] ?? 0) + 1;
                }
            }
        }
        arsort($n);

        return array_map('intval', array_keys($n));
    };
    $brands = $pick('brand_id');
    $cats = $pick('category_type_id');
    $subs = $pick('sub_type_id');
    $grades = $pick('grade_id');
    $mats = $pick('band_material_id');
    $movs = $pick('watch_movement_id');
    $shapes = $pick('case_shape_id');
    $disps = $pick('dial_display_type_id');
    $dials = $colours('dial_color');
    $bands = $colours('band_color');
    $firstTitle = function (string $locale) use ($rows): string {
        foreach ($rows as $r) {
            foreach (T::arr($r['translations'] ?? []) as $t) {
                $t = T::arr($t);
                if (($t['locale'] ?? null) === $locale && is_string($t['product_title'] ?? null) && mb_strlen($t['product_title']) > 6) {
                    return mb_substr($t['product_title'], 0, 5);
                }
            }
        }

        return 'a';
    };

    $none = ['brands' => [], 'categories' => [], 'subTypes' => [], 'genders' => [], 'offers' => false, 'price' => [0, 99999999], 'dialColors' => [], 'bandColors' => [], 'materials' => [], 'movements' => [], 'shapes' => [], 'displayTypes' => [], 'grades' => []];
    $s = fn (array $f, string $q = '', string $sort = 'default', int $page = 1, string $lang = 'en'): array => ['f' => array_merge($none, $f), 'q' => $q, 'sort' => $sort, 'page' => $page, 'lang' => $lang];
    $scenarios = [
        $s([]), $s([], '', 'default', 2), $s([], '', 'default', 999),
        $s([], '', 'price_asc'), $s([], '', 'price_desc'), $s([], '', 'newest'), $s([], '', 'rating'), $s([], '', 'price_asc', 3),
        $s(['brands' => [$brands[0]]]), $s(['brands' => array_slice($brands, 0, 2)], '', 'price_desc'),
        $s(['categories' => [$cats[0]]]), $s(['categories' => [$cats[0]], 'brands' => [$brands[1] ?? $brands[0]]]),
        $s(['subTypes' => [$subs[0]]]), $s(['genders' => ['Men']]), $s(['genders' => ['Women', 'Unisex']], '', 'newest'),
        $s(['offers' => true]), $s(['price' => [1000, 99999999]]), $s(['price' => [0, 5000]], '', 'price_asc'), $s(['price' => [2000, 20000]]),
        $s(['dialColors' => [$dials[0]]]), $s(['bandColors' => array_slice($bands, 0, 2)]),
        $s(['materials' => [$mats[0]]]), $s(['movements' => [$movs[0]]]), $s(['shapes' => [$shapes[0]]]), $s(['displayTypes' => [$disps[0]]]),
        $s(['grades' => [$grades[0]]]),
        $s(['brands' => [$brands[0]], 'genders' => ['Men'], 'dialColors' => [$dials[0]], 'price' => [1000, 99999999]], '', 'rating'),
        $s(['categories' => [$cats[0]], 'subTypes' => [$subs[0]], 'movements' => [$movs[0]]]),
        $s([], 'rolex'), $s([], 'ROLEX  '), $s([], $firstTitle('en')), $s([], 'watch', 'price_asc'), $s([], 'rol'),
        $s([], 'rolex', 'default', 1, 'ar'), $s([], $firstTitle('ar'), 'default', 1, 'en'), $s(['brands' => [$brands[0]]], 'rol', 'price_desc'),
        $s([], $firstTitle('ar'), 'default', 1, 'ar'), $s(['brands' => [$brands[0]]], 'ساعة', 'default', 1, 'ar'), $s([], 'rolex', 'default', 1, 'ar'),
        $s(['genders' => ['Men']], 'a', 'newest', 2),
    ];

    $csv = fn (mixed $v): string => implode(',', array_map(fn (mixed $x): string => is_scalar($x) ? (string) $x : '', T::arr($v)));
    $results = [];
    foreach ($scenarios as $i => $sc) {
        $f = T::arr($sc['f']);
        $price = T::arr($f['price']);
        $min = is_numeric($price[0]) ? (float) $price[0] : 0.0;
        $max = is_numeric($price[1]) ? (float) $price[1] : 99999999.0;
        $query = http_build_query(array_filter([
            'brands' => $csv($f['brands']), 'categories' => $csv($f['categories']), 'subTypes' => $csv($f['subTypes']),
            'genders' => $csv($f['genders']), 'offers' => $f['offers'] === true ? '1' : '',
            'minPrice' => $min > 0 ? (string) $min : '', 'maxPrice' => $max < 99999999 ? (string) $max : '',
            'dialColors' => $csv($f['dialColors']), 'bandColors' => $csv($f['bandColors']), 'materials' => $csv($f['materials']),
            'movements' => $csv($f['movements']), 'shapes' => $csv($f['shapes']), 'displayTypes' => $csv($f['displayTypes']),
            'grades' => $csv($f['grades']), 'q' => $sc['q'], 'sort' => $sc['sort'], 'page' => (string) $sc['page'], 'lang' => $sc['lang'],
        ], fn (string $v): bool => $v !== ''));
        $r = listingGet('/api/catalog/listing?'.$query);
        $results[$i] = [
            'total' => $r['total'],
            'ids' => array_map(fn (mixed $p): int => T::int(T::arr($p)['id']), T::arr($r['products'])),
            'facets' => $r['facets'],
            'ratingIds' => array_values(array_unique(array_map(fn (mixed $x): int => T::int(T::arr($x)['product_id']), T::arr($r['ratings'])))),
        ];
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wz-listing-'.bin2hex(random_bytes(4));
    mkdir($dir.'/utils', 0777, true);
    mkdir($dir.'/lib', 0777, true);
    // The storefront's own modules, verbatim — only the extensionless imports get their `.js`.
    file_put_contents($dir.'/utils/transformProduct.js', str_replace("from './imageUrl'", "from './imageUrl.js'", listingStorefrontFile('src/utils/transformProduct.js')));
    file_put_contents($dir.'/utils/imageUrl.js', str_replace("from '../lib/env'", "from '../lib/env.js'", listingStorefrontFile('src/utils/imageUrl.js')));
    file_put_contents($dir.'/lib/env.js', listingStorefrontFile('src/lib/env.js'));
    file_put_contents($dir.'/utils/filterPredicate.js', listingStorefrontFile('src/utils/filterPredicate.js'));
    file_put_contents($dir.'/package.json', '{"type":"module"}');
    file_put_contents($dir.'/data.json', json_encode(['all' => $all, 'tables' => $meta['tables'], 'ratings' => $ratings, 'images' => $images, 'scenarios' => $scenarios, 'results' => $results], JSON_UNESCAPED_UNICODE));
    file_put_contents($dir.'/run.js', <<<'JS'
        import { readFileSync } from 'node:fs'
        import { transformProductData } from './utils/transformProduct.js'
        import { passesFilters } from './utils/filterPredicate.js'

        const d = JSON.parse(readFileSync(new URL('./data.json', import.meta.url)))
        const lists = {
          en: transformProductData(d.all, d.tables, d.ratings, d.images, 'en'),
          ar: transformProductData(d.all, d.tables, d.ratings, d.images, 'ar'),
        }
        const PAGE_SIZE = 24
        // What each sidebar / chip section counts (SideBar.jsx, SmartSuggestions.jsx matchers).
        const SECTION = {
          brands: (p) => [p.brand_id], categories: (p) => [p.category_type_id], subTypes: (p) => [p.sub_type_id],
          genders: (p) => p.genders_en || [], dialColors: (p) => (p.dial_colors || []).map((c) => c.color_id),
          bandColors: (p) => (p.band_colors || []).map((c) => c.color_id), materials: (p) => [p.band_material_id],
          movements: (p) => [p.watch_movement_id], shapes: (p) => [p.case_shape_id],
          displayTypes: (p) => [p.dial_display_type_id], grades: (p) => [p.grade_id],
        }
        const bad = []
        // Rule 3: search — both languages, Arabic folded; an independent implementation.
        const fold = (t) =>
          (t || '').trim().toLowerCase()
            .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
            .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
            .replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 0x660))
        const arById = new Map(lists.ar.map((p) => [p.id, p]))
        const searchText = (p) => {
          const a = arById.get(p.id) || {}
          return [p.product_title, a.product_title, p.short_description, a.short_description, p.brand, a.brand, p.search_keywords]
            .filter(Boolean).map(fold)
        }
        // Rule 1: price — the card's own rule (ProductCard.jsx: hasSale = 0 < sale < price).
        const paid = (p) => {
          const price = Number(p.selling_price || 0)
          const sale = Number(p.sale_price_after_discount || 0)
          return sale > 0 && sale < price ? sale : price
        }
        // passesFilters reads `sale_price_after_discount` for the price filter: hand it the paid price.
        const priced = lists.en.map((p) => ({ ...p, sale_price_after_discount: paid(p) }))

        d.scenarios.forEach((sc, i) => {
          const filters = sc.f
          const q = fold(sc.q)
          const matches = (p) => !q || searchText(p).some((t) => t.includes(q))
          if (q && !priced.some(matches)) bad.push(`#${i}: "${sc.q}" matches nothing exactly — that is the near-miss path, tested separately; pick another query`)
          const list = priced.filter(matches)
          let filtered = list.filter((product) => passesFilters(product, filters))
          if (sc.sort === 'price_asc') filtered = [...filtered].sort((a, b) => a.sale_price_after_discount - b.sale_price_after_discount)
          else if (sc.sort === 'price_desc') filtered = [...filtered].sort((a, b) => b.sale_price_after_discount - a.sale_price_after_discount)
          else if (sc.sort === 'newest') filtered = [...filtered].sort((a, b) => b.id - a.id)
          else if (sc.sort === 'rating') filtered = [...filtered].sort((a, b) => (b.rating || 0) - (a.rating || 0))
          const ids = filtered.slice((sc.page - 1) * PAGE_SIZE, sc.page * PAGE_SIZE).map((p) => p.id)
          // Rule 2: facets count only what the search matched — `list` is already the matches.
          const facets = {}
          for (const [key, values] of Object.entries(SECTION)) {
            const counts = {}
            for (const p of list) {
              if (!passesFilters(p, filters, key)) continue
              for (const v of new Set(values(p))) if (v !== null && v !== undefined) counts[String(v)] = (counts[String(v)] || 0) + 1
            }
            facets[key] = counts
          }
          const got = d.results[i]
          const norm = (o) => JSON.stringify(Object.keys(o).sort().map((k) => [k, Object.keys(o[k] || {}).sort().map((v) => [v, o[k][v]])]))
          if (got.total !== filtered.length) bad.push(`#${i} ${JSON.stringify(sc)}: total ${got.total} vs storefront ${filtered.length}`)
          if (JSON.stringify(got.ids) !== JSON.stringify(ids)) bad.push(`#${i} ${JSON.stringify(sc)}: page ids ${got.ids.slice(0, 8)} vs storefront ${ids.slice(0, 8)}`)
          if (norm(got.facets) !== norm(facets)) {
            for (const k of Object.keys(SECTION)) {
              if (norm({ x: got.facets[k] || {} }) !== norm({ x: facets[k] })) bad.push(`#${i} facet ${k}: ${JSON.stringify(got.facets[k])} vs storefront ${JSON.stringify(facets[k])}`.slice(0, 400))
            }
          }
          const ratedOnPage = new Set(d.ratings.filter((r) => ids.includes(r.product_id)).map((r) => r.product_id))
          if (JSON.stringify([...ratedOnPage].sort()) !== JSON.stringify([...got.ratingIds].sort())) bad.push(`#${i}: ratings for the page differ`)
        })
        console.log(bad.length ? bad.join('\n') : `OK ${d.scenarios.length} scenarios`)
        JS);

    $out = trim((string) shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.js').' 2>&1'));
    foreach ([...(glob($dir.'/*/*') ?: []), ...(glob($dir.'/*.*') ?: [])] as $file) {
        unlink($file);
    }
    @rmdir($dir.'/utils');
    @rmdir($dir.'/lib');
    @rmdir($dir);

    expect($out)->toBe('OK '.count($scenarios).' scenarios');
})->skip(fn () => listingNode() === '', 'node is not installed here');

it('returns raw rows, ratings and images for cards by id, in the order asked', function () {
    $all = array_map(fn (mixed $r): array => T::arr($r), T::arr(withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/all_product')->assertOk()->json()));
    $ids = [T::int($all[5]['id']), T::int($all[0]['id']), 999999999, T::int($all[2]['id'])];
    $r = listingGet('/api/catalog/cards?ids='.implode(',', $ids));

    $byId = [];
    foreach ($all as $row) {
        $byId[T::int($row['id'])] = $row;
    }
    expect(array_map(fn (mixed $p): int => T::int(T::arr($p)['id']), T::arr($r['products'])))->toBe([$ids[0], $ids[1], $ids[3]])
        ->and(T::arr($r['products'])[0])->toBe($byId[$ids[0]]);
});

it('refuses a bad sort, a huge page size and a missing api code', function () {
    withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/catalog/listing?sort=drop')->assertStatus(422);
    withHeaders(['Api-Code' => LISTING_API_KEY])->getJson('/api/catalog/listing?per_page=5000')->assertStatus(422);
    withHeaders(['Api-Code' => 'wrong'])->getJson('/api/catalog/listing')->assertUnauthorized();
    withHeaders(['Api-Code' => 'wrong'])->getJson('/api/catalog/cards?ids=1')->assertUnauthorized();
});
