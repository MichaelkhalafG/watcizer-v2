<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * §5.3 query budget per endpoint, asserted with the query log on the default connection:
 * listing ≤ 6, PDP ≤ 13, meta ≤ 20, sitemap chunk ≤ 3.
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
        ->and($warm['listing'])->toBeLessThanOrEqual(6)
        ->and($warm['listing_category'])->toBeLessThanOrEqual(6)
        ->and($warm['listing_search'])->toBeLessThanOrEqual(6)
        ->and($warm['pdp_cold'])->toBeLessThanOrEqual(13)
        ->and($warm['pdp_warm'])->toBeLessThanOrEqual(3)
        ->and($warm['sitemap_products'])->toBeLessThanOrEqual(3)
        ->and($warm['sitemap_categories'])->toBeLessThanOrEqual(3);
});
