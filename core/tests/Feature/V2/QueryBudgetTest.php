<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * §5.3 query budget per endpoint, asserted with the query log on the default connection:
 * listing ≤ 7, PDP ≤ 15, meta ≤ 20, sitemap chunk ≤ 3.
 *
 * 2026-10-17, the listing moved 6 → 7 for ONE reason: the Brand Fashion card's picker (spec v2.11
 * §8.6 — Michael's reference: a colour row whose hover shows that colour's sizes) needs each card's
 * active variant rows, and a card cannot render what the row does not carry. `ProductCards::variants()`
 * is one batch query per page over `cpv_product_active_idx` (measured 2026-10-17 on 11.8.9 at
 * 127.0.0.1:3307 with a synthetic 3 × 3 matrix on every product: 24 cards 0.8–1.4 ms, 96 cards
 * 2.8–3.4 ms, RUN_20261017.md row 43). It does not grow with the number of rows per product. The
 * card's second photo (`hover_image`) came in the same pass at NO extra query: the cover query now
 * reads two rows per product instead of one. The cold PDP moved 14 → 15 for the same query: its
 * `related[]` are full cards, built by the same `ProductCards::build()`, so they carry the picker rows
 * too (the detail's own `variants` is the full list it always had, untouched).
 *
 * 2026-10-16, two deliberate moves of one query each, both measured here: the cold PDP went
 * 13 → 14 because the per-version lookup cache now loads a twelfth table, `sizes` (V2-SIZE-NULL —
 * it was the one lookup table the registry lacked, and every variant answered `size: null`); the
 * listing gained the card's attribute colours (spec §8.2's swatch row) as one batch query per page
 * — 5 → 6 warm, inside the existing budget. Neither grows with the number of rows.
 *
 * The PDP budget was 12 until wave 3.5, which added ONE query: the product's active variants.
 * It is one flat SELECT on an indexed `product_id`, it runs once per cold render (the warm PDP
 * is still a single cache read), and it does not grow with the number of variants — the budget
 * exists to catch an N+1, not to freeze the feature set. Budgets hold with the per-version
 * lookup/tree caches warm (the first request of a storefront version builds them once: the
 * cold numbers are printed for the record). preventLazyLoading is on, and the read API uses
 * the query builder only — there is no relation to lazy-load.
 */

beforeEach(fn () => H::flush());

function countQueries(Closure $request): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $request();
    $n = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    return $n;
}

it('stays within the per-endpoint query budgets once the storefront caches are warm', function () {
    $slug = H::visibleSlug();
    $node = H::smallSubtree();
    $cold = [
        'meta' => countQueries(fn () => getJson(H::base('meta'))->assertOk()),
        'listing' => countQueries(fn () => getJson(H::base('products'))->assertOk()),
    ];
    $warm = [
        'meta' => countQueries(fn () => getJson(H::base('meta'))->assertOk()),
        'listing' => countQueries(fn () => getJson(H::base('products?per_page=96'))->assertOk()),
        'listing_category' => countQueries(fn () => getJson(H::base('products?category='.$node['path']))->assertOk()),
        'listing_search' => countQueries(fn () => getJson(H::base('products?q=watch&locale=en'))->assertOk()),
        'pdp_cold' => countQueries(fn () => getJson(H::base('products/'.$slug))->assertOk()),
        'pdp_warm' => countQueries(fn () => getJson(H::base('products/'.$slug))->assertOk()),
        'sitemap_products' => countQueries(fn () => get(H::base('sitemaps/ar/products-1.xml'))->assertOk()),
        'sitemap_categories' => countQueries(fn () => get(H::base('sitemaps/en/categories.xml'))->assertOk()),
    ];
    fwrite(STDERR, "\nquery budget — cold: ".json_encode($cold).' warm: '.json_encode($warm)."\n");

    expect($warm['meta'])->toBeLessThanOrEqual(20)
        ->and($cold['meta'])->toBeLessThanOrEqual(20)
        ->and($warm['listing'])->toBeLessThanOrEqual(7)
        ->and($warm['listing_category'])->toBeLessThanOrEqual(7)
        ->and($warm['listing_search'])->toBeLessThanOrEqual(7)
        ->and($warm['pdp_cold'])->toBeLessThanOrEqual(15)
        ->and($warm['pdp_warm'])->toBeLessThanOrEqual(3)
        ->and($warm['sitemap_products'])->toBeLessThanOrEqual(3)
        ->and($warm['sitemap_categories'])->toBeLessThanOrEqual(3);
});
