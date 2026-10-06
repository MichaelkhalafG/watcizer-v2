<?php

use App\Compat\CatalogWarmer;
use App\Compat\CompatListing;
use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * The rows cache and the index warm-up (2026-09-28).
 *
 *  - A listing page reads its cards from per-product entries (built live on a miss, written by the
 *    warm-up). They must be exactly what the old path
 *    picked out of the whole cached catalogue: same rows, same order, same ratings and images.
 *  - `catalog:warm`, and a flush once its request has answered, leave the index built, so the
 *    next shopper's tap does not build it (`timing.cold` false).
 *  - A warm-up racing a write never stores its result as current.
 */

beforeEach(function () {
    app(StorefrontCache::class)->flush(1);
    StorefrontCache::takeFlushed();
});

function warmCompat(): CompatServices
{
    return new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned(1));
}

/**
 * The controller's "no filter chosen" shape.
 *
 * @return array{brands: list<int>, categories: list<int>, subTypes: list<int>, genders: list<string>, offers: bool, price: array{0: float, 1: float}, dialColors: list<int>, bandColors: list<int>, materials: list<int>, movements: list<int>, shapes: list<int>, displayTypes: list<int>, grades: list<int>}
 */
function noListingFilters(): array
{
    return [
        'brands' => [], 'categories' => [], 'subTypes' => [], 'genders' => [], 'offers' => false,
        'price' => [0.0, (float) CompatListing::PRICE_MAX], 'dialColors' => [], 'bandColors' => [],
        'materials' => [], 'movements' => [], 'shapes' => [], 'displayTypes' => [], 'grades' => [],
    ];
}

/**
 * A page of ids that exercises every part of a card, and what the OLD path returned for it.
 *
 * @return array{ids: list<int>, expected: array<string, mixed>}
 */
function cardsCase(): array
{
    $compat = warmCompat();
    $locale = config()->string('compat.pinned_locale');
    // The row-built reference catalogue (all_product / all_product_image were retired, L8): cards() must
    // still return exactly what the old whole-catalogue pick did, built from the same rows and gallery.
    $all = $compat->catalog->wholeForParity($locale);
    $visible = array_map(fn (array $r): int => T::int($r['id']), $all);

    // Products that carry each thing a card needs: gallery images, ratings, and plain ones.
    $withImages = DB::table('catalog_product_images')->where('is_cover', 0)->whereIn('product_id', $visible)->distinct()->limit(8)->pluck('product_id')->map(fn ($v): int => T::int($v))->all();
    $withRatings = DB::connection('legacy')->table('product_ratings')->whereIn('product_id', $visible)->distinct()->limit(8)->pluck('product_id')->map(fn ($v): int => T::int($v))->all();
    $plain = array_slice($visible, 100, 8);
    // Out of order on purpose, a duplicate-free mix, and one id the catalogue does not show.
    $ids = array_values(array_unique([...array_reverse($withImages), 999999999, ...$plain, ...$withRatings]));
    expect($withImages)->not->toBeEmpty()->and($plain)->not->toBeEmpty();

    // The old path, verbatim: filter the cached whole catalogue, images and ratings.
    $wanted = array_flip($ids);
    $byId = [];
    foreach ($all as $row) {
        $id = T::int($row['id']);
        if (isset($wanted[$id])) {
            $byId[$id] = $row;
        }
    }
    $expected = [
        'products' => array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids))),
        'ratings' => array_values(array_filter($compat->catalog->allProductRating(), fn (array $r) => isset($byId[T::int($r['product_id'])]))),
        'images' => array_values(array_filter($compat->catalog->productImages($visible), fn (array $r) => isset($byId[T::int($r['product_id'])]))),
    ];
    expect(count($expected['products']))->toBe(count($ids) - 1)->and($expected['images'])->not->toBeEmpty();

    return ['ids' => $ids, 'expected' => $expected];
}

/** How many queries read `catalog_products` while $fn runs. */
function productQueries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains(T::str($q['query']), 'catalog_products')));
    DB::disableQueryLog();

    return $n;
}

it('builds a page of cards that are not cached yet, identical to the old whole-catalogue pick, and caches them', function () {
    ['ids' => $ids, 'expected' => $expected] = cardsCase();
    app(StorefrontCache::class)->flush(1);

    $cards = null;
    $built = productQueries(function () use (&$cards, $ids): void {
        $cards = warmCompat()->listing->cards($ids);
    });
    expect($cards)->toBe($expected)->and($built)->toBeGreaterThan(0);

    // The same page again comes out of the per-product entries: identical, and the only product
    // query left is the one for the id the catalogue does not show (never cached, never found).
    $again = null;
    $read = productQueries(function () use (&$again, $ids): void {
        $again = warmCompat()->listing->cards($ids);
    });
    expect($again)->toBe($expected)->and($read)->toBe(1)
        ->and(productQueries(fn () => warmCompat()->listing->cards(array_values(array_diff($ids, [999999999])))))->toBe(0);
});

it('serves a page of cards from the entries warm() wrote, identical to the old pick', function () {
    ['ids' => $ids, 'expected' => $expected] = cardsCase();
    app(StorefrontCache::class)->flush(1);
    warmCompat()->listing->warm();

    $cards = null;
    $n = productQueries(function () use (&$cards, $ids): void {
        $cards = warmCompat()->listing->cards(array_values(array_diff($ids, [999999999])));
    });

    expect($cards)->toBe($expected)->and($n)->toBe(0);
});

it('never reads card entries stored under the version before a write', function () {
    $cache = app(StorefrontCache::class);
    $before = $cache->version(1);
    $cache->flush(1); // a dashboard write landed while a warm-up was building

    $cache->putMany(1, 'compat_card', ['en:1' => ['row' => [], 'images' => []]], 60, $before);

    expect($cache->many(1, 'compat_card', ['en:1']))->toBe([]);
});

it('has no whole-catalogue cache family to write at all (L8)', function () {
    // L8 (2026-10-06) retired the whole-catalogue caches: nothing builds or caches all_product /
    // all_product_image any more (the index and nav are built leanly, a page reads its 24 card
    // files). They are GONE from the invalidation map, so the family name is not even writable —
    // `key()` refuses an unlisted family — which is the structural guarantee that no request can
    // cache the whole catalogue of a large storefront. The per-product and derived families remain.
    expect(StorefrontCache::INVALIDATION_MAP)->not->toHaveKey('compat_all_product')
        ->and(StorefrontCache::INVALIDATION_MAP)->not->toHaveKey('compat_all_product_image')
        ->and(StorefrontCache::INVALIDATION_MAP)->toHaveKeys(['compat_card', 'compat_nav', 'compat_listing']);

    // And a page of cards still serves without the retired family existing.
    $compat = warmCompat();
    $first = $compat->listing->entries()[0]['id'] ?? null;
    expect($first)->toBeInt();
    $compat->listing->cards([T::int($first)]);
});

it('leaves the index built after catalog:warm, so the next query does not build it', function () {
    $pending = artisan('catalog:warm', ['--storefront' => [1]]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertSuccessful()->run(); // a PendingCommand only runs when run() or destroyed

    $compat = warmCompat();
    $compat->listing->query(noListingFilters(), '', 'default', 1, 24);
    expect($compat->listing->timing['cold'])->toBeFalse();

    // Control: after a flush with nothing warming, the next query does build it.
    app(StorefrontCache::class)->flush(1);
    $cold = warmCompat();
    $cold->listing->query(noListingFilters(), '', 'default', 1, 24);
    expect($cold->listing->timing['cold'])->toBeTrue();
});

it('rebuilds the index once a request that flushed has answered, when warm_on_write is on', function () {
    config(['compat.warm_on_write' => true]);
    app(StorefrontCache::class)->flush(1);

    app()->terminate();

    $compat = warmCompat();
    $compat->listing->query(noListingFilters(), '', 'default', 1, 24);
    expect($compat->listing->timing['cold'])->toBeFalse()
        ->and(StorefrontCache::takeFlushed())->toBe([]);
});

it('does not warm after a flush when warm_on_write is off', function () {
    config(['compat.warm_on_write' => false]);
    app(StorefrontCache::class)->flush(1);

    app()->terminate();

    $compat = warmCompat();
    $compat->listing->query(noListingFilters(), '', 'default', 1, 24);
    expect($compat->listing->timing['cold'])->toBeTrue();
});

it('never stores a warm-up as current when a write bumps the version while it builds', function () {
    $cache = app(StorefrontCache::class);
    $before = $cache->key(1, 'compat_listing');

    $cache->refresh(1, 'compat_listing', '', 60, function () use ($cache): array {
        $cache->flush(1); // a dashboard write lands mid-build

        return ['entries' => [], 'vocab' => []];
    });

    expect(Cache::has($before))->toBeTrue()
        ->and(Cache::has($cache->key(1, 'compat_listing')))->toBeFalse();
});

it('warms only the storefronts listed as live, never every active one', function () {
    // Brand Fashion (2) is active but not served here; its build took ~41 s and 206 MB (2026-09-28).
    expect(DB::table('storefronts')->where('id', 2)->value('is_active'))->toBeTruthy();
    $warmer = app(CatalogWarmer::class);
    expect($warmer->activeStorefronts())->toBe([1])
        ->and($warmer->allowed([1, 2]))->toBe([1])
        ->and($warmer->allowed([2]))->toBe([]);

    config(['compat.warm_storefronts' => [1, 2]]);
    expect($warmer->activeStorefronts())->toBe([1, 2]);
});
