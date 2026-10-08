<?php

use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;

/*
 * L8 (2026-10-06). The listing index (`CompatListing::buildIndex`) and the header nav
 * (`CompatCatalog::buildNav`) are now built from a LEAN SQL read (`CompatCatalog::leanCatalog`),
 * never from the full legacy `all_product` rows — that build cost 55 MB held / 136 MB peak on Brand
 * Fashion's 7,579 products and took the warm-up over PHP's 128 MB (handover §3.2).
 *
 * This is the PARITY PROOF the plan's START HERE asks for FIRST: the lean builders must reproduce the
 * pre-L8 row builders EXACTLY on Watchizer's catalogue — same values, same types, same order. The
 * reference builders (`buildIndexFromRows`, `buildNavFromRows`) read the retained `wholeForParity()`
 * rows, which are the exact pre-L8 input; nothing on a request path builds those rows any more.
 *
 * It is a DIFFERENTIAL test (both sides derived from the live catalogue), not a frozen fixture, so it
 * cannot drift from the data or pass vacuously (AGENTS §4). Watchizer's 698 products carry the watch
 * specs, genders and colours that exercise every branch the lean reduction had to reproduce.
 */

function l8Services(int $storefrontId = 1): CompatServices
{
    return new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned($storefrontId));
}

/** Invoke a private no-argument builder. */
function l8Build(object $object, string $method): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object);
}

it('builds the listing index leanly, byte-identical to the pre-L8 row builder on Watchizer', function () {
    $svc = l8Services(1);
    $rows = $svc->catalog->wholeForParity(config()->string('compat.pinned_locale'));

    $lean = l8Build($svc->listing, 'buildIndex');
    $reference = $svc->listing->buildIndexFromRows($rows);

    // Not vacuous: the catalogue really produced entries, with the relations the lean read reduces.
    // The non-empty / count checks read the typed reference; equality then carries them to the lean build.
    expect($reference['entries'])->not->toBeEmpty()
        ->and(count($reference['entries']))->toBe(DB::table('storefront_product')->where('storefront_id', 1)->where('is_visible', 1)->count())
        ->and($lean)->toBe($reference);
});

it('builds the header nav leanly, byte-identical to the pre-L8 row builder on Watchizer', function () {
    $svc = l8Services(1);
    $rows = $svc->catalog->wholeForParity(config()->string('compat.pinned_locale'));

    $lean = l8Build($svc->catalog, 'buildNav');
    $reference = $svc->catalog->buildNavFromRows($rows);

    expect($reference['brand_ids'])->not->toBeEmpty()
        ->and($reference['genders'])->not->toBeEmpty()
        ->and($lean)->toBe($reference);
});

// The structural guard that the whole catalogue can no longer be cached at all — the retired
// `compat_all_product` / `compat_all_product_image` families are gone from the invalidation map, so
// `key()` refuses them — lives in CatalogWarmTest ('has no whole-catalogue cache family to write').
