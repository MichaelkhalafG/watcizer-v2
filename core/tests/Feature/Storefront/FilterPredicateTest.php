<?php

use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

function nodeBinary(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

/*
 * ── Every listing filter the storefront can set is one the predicate actually reads ──────────
 *
 * Found after the Phase 2 cutover (2026-09-24): picking "Electronics" showed the whole catalogue.
 * `Frontend-next/src/utils/filterPredicate.js` — the ONE predicate behind the listing, its counts
 * and its SEO description — never read `filters.categories`. The listing set it correctly
 * (`listingSeo.js`, `ListingClient`'s filter state) and every product passed. Watches and Fashion
 * only looked right because `ListingClient` pre-splits the catalogue on those two English names;
 * every other category, in both languages, did nothing — on legacy too, since July.
 *
 * `all_product` takes no filter parameter on either host, so the compat harness had nothing to
 * diff: the defect lived entirely in the browser. The storefront has no test runner, so the guard
 * lives in the suite that runs on every change, as `JsonLdSinkTest` does.
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

it('reads every filter key the storefront can set', function () {
    $keys = settableFilterKeys();
    preg_match_all("/on\\('([a-zA-Z]+)'\\)/", storefrontFile('src/utils/filterPredicate.js'), $m);
    $read = $m[1];

    // The reset object alone lists thirteen; a parse that found fewer would pass for the wrong reason.
    expect(count($keys))->toBeGreaterThanOrEqual(13)
        ->and(array_values(array_diff($keys, $read)))->toBe([]);
});

it('narrows by category when the predicate actually RUNS', function () {
    // The predicate has no imports, so a verbatim copy runs as an ES module on its own.
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wz-predicate-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/predicate.mjs', storefrontFile('src/utils/filterPredicate.js'));
    file_put_contents($dir.'/run.mjs', <<<'JS'
        import { passesFilters } from './predicate.mjs'
        const products = [
          { id: 1, brand_id: 5, category_type_id: 10, sub_type_id: 100 },
          { id: 2, brand_id: 5, category_type_id: 20, sub_type_id: 200 },
          { id: 3, brand_id: 6, category_type_id: 20, sub_type_id: 201 },
        ]
        const ids = (f, except = null) => products.filter((p) => passesFilters(p, f, except)).map((p) => p.id)
        console.log(JSON.stringify({
          category: ids({ categories: [20] }),
          categoryAndBrand: ids({ categories: [20], brands: [5] }),
          exceptCategories: ids({ categories: [20] }, 'categories'),
          none: ids({ categories: [] }),
        }))
        JS);

    $out = shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1');
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect(json_decode((string) $out, true))->toBe([
        'category' => [2, 3],
        'categoryAndBrand' => [2],
        'exceptCategories' => [1, 2, 3],   // SmartSuggestions counts a category as if its own section were clear
        'none' => [1, 2, 3],
    ]);
})->skip(fn () => nodeBinary() === '', 'node is not installed here; the key check above still guards the predicate');

it('finds a REAL two-tone product under EITHER of its colours', function () {
    /*
     * 2026-09-27. A two-tone finish is two colours in one role (strap Gold + Silver), and a
     * shopper filtering for either colour must find it. The chain is the storefront's own: the
     * compat `all_product` payload → `transformProduct`'s colour mapping (mirrored below: it
     * imports other modules, and the mapping is one line) → `catalogProjection.js` →
     * `filterPredicate.js`, both copied verbatim and run in node.
     */
    config(['compat.api_key' => 'filter-two-tone-key']);
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

    $all = withHeaders(['Api-Code' => 'filter-two-tone-key'])->getJson('/api/all_product')->assertOk()->json();
    $product = collect(is_array($all) ? $all : [])->firstWhere('id', $productId);
    expect($product)->not->toBeNull();

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wz-twotone-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/predicate.mjs', storefrontFile('src/utils/filterPredicate.js'));
    file_put_contents($dir.'/projection.mjs', storefrontFile('src/lib/catalogProjection.js'));
    file_put_contents($dir.'/product.json', (string) json_encode($product));
    file_put_contents($dir.'/run.mjs', str_replace(['__A__', '__B__', '__OTHER__'], [(string) $band[0], (string) $band[1], (string) $other], <<<'JS'
        import { readFileSync } from 'node:fs'
        import { passesFilters } from './predicate.mjs'
        import { projectCatalogCard } from './projection.mjs'
        const raw = JSON.parse(readFileSync(new URL('./product.json', import.meta.url)))
        // transformProduct.js getColors(): each colour row's `id` becomes `color_id`.
        const colors = (list) => (list || []).map((c) => ({ color_id: c.id, color_value: c.color_value }))
        const p = projectCatalogCard({ ...raw, dial_colors: colors(raw.dial_color), band_colors: colors(raw.band_color) })
        console.log(JSON.stringify({
          first: passesFilters(p, { bandColors: [__A__] }),
          second: passesFilters(p, { bandColors: [__B__] }),
          unrelated: passesFilters(p, { bandColors: [__OTHER__] }),
        }))
        JS));

    $out = shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1');
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    expect(json_decode((string) $out, true))->toBe(['first' => true, 'second' => true, 'unrelated' => false]);
})->skip(fn () => nodeBinary() === '', 'node is not installed here');
