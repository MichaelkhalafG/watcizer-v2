<?php

use App\Compat\CompatServices;
use App\Storefront\StorefrontCache;
use App\Support\LegacySlug;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * C-1 stage 4 — the product page finds its product through core (`GET /api/catalog/product?slug=`)
 * instead of loading the whole catalogue to search it. The rule is the storefront's
 * `findProductInCatalog`: digits are an id; anything else is toSlug(English title) — LegacySlug mirrors
 * toSlug exactly (LegacySlugTest) — and the FIRST product in catalogue order with that slug wins, so
 * twins that share a URL resolve exactly as they did in the browser (catalogue order itself is pinned
 * by CatalogListingTest's page-order parity).
 */

const SLUG_API_KEY = 'catalog-product-slug-test-key';

beforeEach(function () {
    config(['compat.api_key' => SLUG_API_KEY]);
    app(StorefrontCache::class)->flush(1);
});

it('resolves every visible product by its slug to the first product in catalogue order with that slug', function () {
    $listing = app(CompatServices::class)->listing;
    $first = [];
    foreach ($listing->entries() as $e) {
        $first[$e['slug']] ??= $e['id'];
    }
    $wrong = [];
    foreach ($listing->entries() as $e) {
        if ($e['slug'] !== '' && $listing->idForParam($e['slug']) !== $first[$e['slug']]) {
            $wrong[] = $e['slug'];
        }
    }

    expect($wrong)->toBe([])
        ->and(count($first))->toBeGreaterThan(50);
});

it('reads the slug from the English title with the storefront rule, and digits as an id', function () {
    $listing = app(CompatServices::class)->listing;
    $e = $listing->entries()[0];
    $title = T::str(DB::table('catalog_product_translations')->where('product_id', $e['id'])->where('locale', 'en')->value('title'));

    expect($e['slug'])->toBe(LegacySlug::make($title))
        ->and($listing->idForParam((string) $e['id']))->toBe($e['id'])
        ->and($listing->idForParam('no-such-watch-anywhere'))->toBeNull()
        ->and($listing->idForParam(''))->toBeNull();
});

it('answers GET catalog/product with the one product as cards, or 404', function () {
    $e = app(CompatServices::class)->listing->entries()[0];

    $hit = T::arr(withHeaders(['Api-Code' => SLUG_API_KEY])->getJson('/api/catalog/product?slug='.rawurlencode($e['slug']))->assertOk()->json());
    // `meta` joined the response in S-AR stage 2 (2026-10-01) — deliberately, beside the rows, which
    // keep the legacy shape.
    expect(array_keys($hit))->toBe(['products', 'ratings', 'images', 'meta'])
        ->and(array_column(T::arr($hit['products']), 'id'))->toBe([$e['id']])
        ->and(array_keys(T::arr($hit['meta'])))->toBe(['title', 'description'])
        ->and(array_keys(T::arr(T::arr($hit['meta'])['title'])))->toBe(['en', 'ar']);

    withHeaders(['Api-Code' => SLUG_API_KEY])->getJson('/api/catalog/product?slug=no-such-watch-anywhere')->assertNotFound();
    withHeaders(['Api-Code' => SLUG_API_KEY])->getJson('/api/catalog/product')->assertStatus(422);
});
