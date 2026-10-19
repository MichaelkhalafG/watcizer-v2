<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * V2-SEARCH-SCALE (2026-10-17). Measured on MariaDB 11.8.9 at 8,039 products: the v2 listing's page query
 * under `q=watch` took 3,371 ms and its count 2,922 ms — not because of the FULLTEXT (the same MATCH alone
 * answers in ~95 ms) but because the index-ordered plan (`FORCE INDEX` on the sort index, review 🟠-5) made
 * the optimizer reach `catalog_products` by `ref` over that index for EVERY fulltext hit (key_len 1: only
 * `is_active`, ~8,100 rows each). Without the hint the same page is 94 ms: `p` is reached by PRIMARY.
 *
 * The fix is one line in ProductListing::run() — no forced plan while a search is on — and this test pins
 * what the local copy CAN assert about it: the SQL the search runs carries no FORCE INDEX, and the engine's
 * own plan reaches `catalog_products` by its primary key (eq_ref / const), never by `ref` over the sort index.
 * The wall time itself is a scale measurement and lives in RUN_20261017.md; it cannot be asserted at 1,014.
 */

beforeEach(fn () => H::flush());

/** @return list<array{query: string, bindings: array<int, mixed>}> the SQL a request ran */
function searchQueryLog(string $path): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    getJson(H::base($path))->assertOk();
    /** @var list<array{query: string, bindings: array<int, mixed>, time: float}> $log */
    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    return array_map(fn (array $q) => ['query' => $q['query'], 'bindings' => $q['bindings']], $log);
}

it('runs a search WITHOUT the forced sort index, and the plan reaches catalog_products by its primary key', function () {
    getJson(H::base('products?q=watch&locale=en'))->assertOk();   // warm the per-version caches and this hash's count
    $log = searchQueryLog('products?q=watch&locale=en&page=2');    // page 2: the count is cached, the page query is not
    $page = collect($log)->first(fn (array $q) => str_contains($q['query'], 'MATCH(body)') && str_contains($q['query'], 'limit 24'));
    expect($page)->not->toBeNull('the search page query was not found in the log');
    /** @var array{query: string, bindings: array<int, mixed>} $page */
    expect($page['query'])->not->toContain('FORCE INDEX', 'the index-ordered plan is the 3-second cost under a search (V2-SEARCH-SCALE)');

    $plan = array_map(fn ($row): array => (array) $row, DB::select('EXPLAIN '.$page['query'], $page['bindings']));
    $products = collect($plan)->first(fn (array $row) => in_array(T::str($row['table'] ?? ''), ['p', 'catalog_products'], true));
    expect($products)->not->toBeNull('catalog_products is not in the plan');
    /** @var array<string, mixed> $products */
    expect(T::str($products['type'] ?? ''))->toBeIn(['eq_ref', 'const', 'ref'])
        ->and(T::str($products['key'] ?? ''))->toBe('PRIMARY', 'catalog_products must be reached by its primary key, not by the sort index per fulltext hit');
});

it('keeps the forced sort index for a plain listing — the search exception is the only exception', function () {
    getJson(H::base('products?sort=newest'))->assertOk();
    $log = searchQueryLog('products?sort=newest&page=2');
    $page = collect($log)->first(fn (array $q) => str_contains($q['query'], 'limit 24'));
    expect($page)->not->toBeNull();
    /** @var array{query: string, bindings: array<int, mixed>} $page */
    expect($page['query'])->toContain('FORCE INDEX (`cp_active_created_idx`)');
});

it('answers the short-term LIKE path and an empty search without error', function () {
    foreach (['products?q=a&locale=en', 'products?q=ab&locale=en', 'products?q=%20%20&locale=en', 'products?q=%D8%B3%D8%A7%D8%B9%D8%A9&locale=ar'] as $path) {
        getJson(H::base($path))->assertOk()->assertJsonStructure(['data', 'meta' => ['total']]);
    }
});
