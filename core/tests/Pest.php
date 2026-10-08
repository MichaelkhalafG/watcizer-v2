<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests run against the LOCAL copy of the shared database inside
| transactions on BOTH connections that are rolled back (DatabaseTransactions,
| see Tests\TestCase::$connectionsToTransact). RefreshDatabase and migrate:fresh
| are never used: the database also holds the legacy tables and
| DB::prohibitDestructiveCommands() is on for the whole app. The local-host
| guard and withoutVite() live in Tests\TestCase::setUp().
*/

use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\LegacyShadow;
use Tests\Support\Scratch;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
 * The legacy SHADOW is closed after every feature test, whoever opened it.
 *
 * `Tests\Support\LegacyShadow::open()` creates a TEMPORARY table and renames it over a real legacy
 * table, so the shadow hides the real one for the rest of the CONNECTION's life — and the
 * connection lives as long as the process, not as long as the test. No transaction can undo it:
 * TEMPORARY tables and DDL sit outside transaction semantics entirely.
 *
 * Until now the cleanup was opt-in per file — three files each carrying their own
 * `afterEach(fn () => LegacyShadow::closeAll())`. All three were correct; the fourth file to use
 * the helper and forget the line would leave a frozen snapshot in front of a real legacy table for
 * every remaining test in the run — and unlike a lost transaction, which is bounded to the test
 * that loses it, this one really does reach everything downstream, because the shadow lives on the
 * CONNECTION.
 *
 * Registered once, here, so the contract is structural. `closeAll()` is a no-op when nothing is
 * open, so this costs the other ~1,370 tests nothing.
 *
 * ── and the same for SCRATCH RUN DIRECTORIES ─────────────────────────────────────────────────
 *
 * One floor down, same argument. `core:transform --output <dir>` writes `summary.json`,
 * `audit.csv` and friends into a directory the test names, and seven files used to name it
 * `<label>-<pid>` and KEEP it if it already existed. PIDs repeat, so a run could inherit an older
 * run's summary and assert against it — `OnePrimaryPlacementTest` and `TransformCommandTest` both
 * read that file back — and the directories accumulated besides (2 503 under `storage/transform`,
 * 1 987 under `storage/framework/testing` by 2026-09-19).
 *
 * `Tests\Support\Scratch::dir()` keys on `uniqid('', true)` instead, and this line is the other
 * half of the bargain: registered once, so the eighth caller cannot forget it. `cleanAll()` is a
 * no-op when the test asked for no directory.
 */
pest()->afterEach(function (): void {
    LegacyShadow::closeAll();
    Scratch::cleanAll();
})->in('Feature');

/**
 * The whole catalogue in the legacy `all_product` row shape, built IN-PROCESS — the reference
 * several catalog tests compared against the `/api/all_product` endpoint before L8 retired it
 * (2026-10-06). It is the pre-L8 builder `CompatCatalog::wholeForParity`, so a feature built
 * leanly (listing, nav, home, related, search) is still checked against the row-built catalogue,
 * just without an HTTP endpoint that can crash core out of memory on a large storefront.
 *
 * @return list<array<string, mixed>>
 */
function catalogueReferenceRows(int $storefrontId = 1): array
{
    return catalogueServices($storefrontId)->catalog->wholeForParity(config()->string('compat.pinned_locale'));
}

/**
 * The gallery rows of the given products in the legacy `all_product_image` shape, in-process — the
 * reference for the endpoint retired with `all_product`. Pass every visible id for the whole set.
 *
 * @param  list<int>  $ids
 * @return list<array<string, mixed>>
 */
function catalogueReferenceImages(array $ids, int $storefrontId = 1): array
{
    return catalogueServices($storefrontId)->catalog->productImages($ids);
}

function catalogueServices(int $storefrontId = 1): CompatServices
{
    return new CompatServices(
        app(StorefrontCache::class),
        app(InventoryService::class),
        CompatStorefront::pinned($storefrontId),
    );
}
