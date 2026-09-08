<?php

use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\LegacyShadow;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;

/*
 * Milestone audit — the transform must invalidate the read layer.
 *
 * Nothing else writes the clean tables yet, so a rehearsal (or switch night) used to leave the
 * v2 API serving the previous catalog out of the file cache: the tree, meta, listing counts,
 * product DTOs and sitemaps all live under per-storefront version keys with TTLs up to an hour.
 * These tests move a product between categories in legacy (on a TEMPORARY shadow), run the
 * transform, and read the API again — with no cache:clear anywhere.
 */

function transformAfterShadow(int $expectedExit): void
{
    $dir = storage_path('framework/testing/cache-bump-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pending = artisan('core:transform', ['--only' => '19', '--output' => $dir, '--force' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode($expectedExit);
    $pending->run();
}

/** The v2 slug path ("watches/sport") of a legacy sub type. */
function subTypePath(int $legacySubTypeId): string
{
    $row = DB::table('storefront_categories as c')
        ->join('storefront_categories as p', 'p.id', '=', 'c.parent_id')
        ->where('c.storefront_id', 1)->where('c.legacy_source', 'sub_type')->where('c.legacy_id', $legacySubTypeId)
        ->first(['c.slug as child', 'p.slug as parent']);

    if ($row === null) {
        return '';
    }

    return Row::str($row, 'parent').'/'.Row::str($row, 'child');
}

/** product_count of a node, as the live API reports it. */
function apiCount(string $path): int
{
    foreach (H::rows(getJson(H::base('categories'))->assertOk()->json('tree')) as $root) {
        foreach (H::rows($root['children'] ?? []) as $child) {
            if (($child['path'] ?? null) === $path) {
                $count = $child['product_count'] ?? 0;

                return is_int($count) ? $count : 0;
            }
        }
    }

    return -1;
}

afterEach(fn () => LegacyShadow::closeAll());

it('bumps the storefront cache version on a real run, and never on a dry run', function () {
    $cache = app(StorefrontCache::class);
    $dir = storage_path('framework/testing/cache-bump-dry-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $before = $cache->version(1);
    $dry = artisan('core:transform', ['--dry-run' => true, '--only' => '19', '--output' => $dir, '--force' => true]);
    if (! $dry instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $dry->run();
    expect($cache->version(1))->toBe($before);                 // rolled back → nothing to invalidate

    transformAfterShadow(expectedExit: 0);
    expect($cache->version(1))->toBeGreaterThan($before);
});

it('serves the new category counts after a transform with no cache:clear', function () {
    $sportPath = subTypePath(4);
    $chronoPath = subTypePath(2);

    // Warm the tree cache, twice — the second read is served from it.
    $sportBefore = apiCount($sportPath);
    $chronoBefore = apiCount($chronoPath);
    expect($sportBefore)->toBeGreaterThan(0)->and(apiCount($sportPath))->toBe($sportBefore);

    // Legacy moves one product from Sport to Chronograph; the transform writes it and bumps.
    $productId = DB::connection('legacy')->table('products')->where('category_type_id', 1)->where('sub_type_id', 4)->orderBy('id')->value('id');
    $productId = is_int($productId) ? $productId : 0;
    LegacyShadow::open('products', function (callable $table) use ($productId) {
        $table()->where('id', $productId)->update(['sub_type_id' => 2]);
    });
    transformAfterShadow(expectedExit: 1);                     // the abandoned placement breaks the exact count (by design)
    LegacyShadow::closeAll();

    // The new node's count reaches the API with no cache:clear — the version bump did it.
    expect(apiCount($chronoPath))->toBe($chronoBefore + 1);

    // Sport does NOT drop: the abandoned placement row survives (the transform never deletes), so
    // the old category keeps counting the product. Only a drop-and-rebuild clears it — which is
    // why switch night rebuilds rather than running incrementally (milestone audit 🔴-1).
    expect(apiCount($sportPath))->toBe($sportBefore);

    // Legacy says Sport again: the primary moves back, the residue stays, the API follows at once.
    transformAfterShadow(expectedExit: 1);
    expect(apiCount($sportPath))->toBe($sportBefore)
        ->and(apiCount($chronoPath))->toBe($chronoBefore + 1);
});
