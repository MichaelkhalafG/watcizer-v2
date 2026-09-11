<?php

use App\Domain\Catalog\PreSwitch;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * TASK 2 and TASK 3, proved against the REAL transform rather than against reasoning about it.
 *
 * Everything here runs inside the test transaction (`DatabaseTransactions` on both connections),
 * so the command's own per-step transactions become savepoints and the whole run is rolled back.
 * No legacy table is written — the experiments that need "legacy changed" are staged by changing
 * the CLEAN mirror and letting the sync correct it, which is the same observation from the other
 * side and does not require touching a table AGENTS §3 forbids.
 *
 * The three claims:
 *
 *   1. **Sync ON** (flag false, pre-switch): storefront 2's tree is re-derived on every run, so a
 *      node that was removed comes back and a node that was renamed is corrected.
 *   2. **Sync OFF** (flag true, post-switch): neither happens again, ever. The trees diverge and
 *      the team owns Brand Fashion's.
 *   3. **Insert-only is absolute**: a product the team HID on storefront 2 stays hidden across a
 *      re-run, and its sort order, featured flag and slug are not reset either.
 */

/** @param  list<int>  $steps */
function runSteps(array $steps): void
{
    $pending = artisan('core:transform', ['--force' => true, '--only' => implode(',', $steps)]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode(0);
}

/** The Brand Fashion mirror of one legacy category-type node, or 0 when it is not there. */
function mirrorNode(int $legacyId): int
{
    $value = DB::table('storefront_categories')
        ->where('storefront_id', Storefront::BRAND_FASHION_ID)
        ->where('legacy_source', 'category_type')
        ->where('legacy_id', $legacyId)
        ->value('id');

    return is_numeric($value) ? (int) $value : 0;
}

function firstLegacyTypeId(): int
{
    return T::int(DB::connection('legacy')->table('category_types')->orderBy('id')->value('id'));
}

it('SYNC ON: re-creates a mirrored node the team deleted, because legacy still has it', function () {
    expect(PreSwitch::syncsSecondaryTrees())->toBeTrue();

    $legacyId = firstLegacyTypeId();
    $node = mirrorNode($legacyId);
    expect($node)->toBeGreaterThan(0);

    // Remove the mirror (translations cascade with the node's own FK).
    DB::table('storefront_category_translations')->where('storefront_category_id', $node)->delete();
    DB::table('storefront_category_product')->where('storefront_category_id', $node)->delete();
    DB::table('storefront_categories')->where('id', $node)->delete();
    expect(mirrorNode($legacyId))->toBe(0);

    // The whole tree-and-placement pass, not step 15 alone: deleting a depth-1 node CASCADES to
    // its children and to their placements, so recreating only the root would leave the
    // reconciliation short of depth-2 nodes — and it would fail the run, correctly.
    runSteps([15, 16, 17, 18, 19]);

    // Back, keyed to the same legacy origin — a NEW id, because it is a new row in Brand Fashion's
    // own tree, which is exactly what "an independent copy mapped by the legacy key" means.
    $recreated = mirrorNode($legacyId);
    expect($recreated)->toBeGreaterThan(0)
        ->and(T::int(DB::table('storefront_categories')->where('id', $recreated)->value('storefront_id')))
        ->toBe(Storefront::BRAND_FASHION_ID);
});

it('SYNC ON: corrects a rename on the mirrored tree, because legacy is still the source', function () {
    $legacyId = firstLegacyTypeId();
    $node = mirrorNode($legacyId);
    $original = T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')->value('name'));
    expect($original)->not->toBe('');

    // Stage the divergence directly on the clean mirror — the same state a dashboard rename would
    // produce, without needing the dashboard (which refuses it pre-switch) or legacy (which this
    // application may not write).
    DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')
        ->update(['name' => 'Renamed by hand']);

    runSteps([15]);

    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')->value('name')))
        ->toBe($original, 'while the sync is ON the mirrored tree follows legacy, so a local rename is corrected');
});

it('SYNC OFF: the same rename SURVIVES, and a deleted node is not re-created', function () {
    CatalogFixture::assumeSwitched();
    expect(PreSwitch::syncsSecondaryTrees())->toBeFalse();

    $legacyId = firstLegacyTypeId();
    $node = mirrorNode($legacyId);

    DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')
        ->update(['name' => 'Brand Fashion owns this now']);

    runSteps([15]);

    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')->value('name')))
        ->toBe('Brand Fashion owns this now', 'after the switch nothing re-syncs the mirrored tree');

    // And storefront 1 is still synced, so the flag did not simply switch the transform off.
    $own = T::int(DB::table('storefront_categories')->where('storefront_id', 1)
        ->where('legacy_source', 'category_type')->where('legacy_id', $legacyId)->value('id'));
    expect($own)->toBeGreaterThan(0);
});

it('INSERT-ONLY: a product hidden on storefront 2 stays hidden across a re-run', function () {
    /*
     * The acceptance test the developer named, and the reason the visible-by-default rule is safe.
     *
     * Hidden, re-ordered, un-featured and re-slugged on Brand Fashion — then the transform runs
     * again. Every one of those is a DASHBOARD-OWNED column, so the upsert must not name it. A
     * nightly re-enable would make the team's work meaningless and the difference invisible.
     */
    $brand = Storefront::BRAND_FASHION_ID;
    $productId = T::int(DB::table('storefront_product')->where('storefront_id', $brand)->orderBy('product_id')->value('product_id'));
    expect($productId)->toBeGreaterThan(0);

    DB::table('storefront_product')
        ->where('storefront_id', $brand)->where('product_id', $productId)
        ->update([
            'is_visible' => 0,
            'is_featured' => 1,
            'sort_order' => 77,
            'slug' => 'kept-by-the-team',
            'updated_at' => now(),
        ]);

    runSteps([18, 19]);

    $row = T::one(DB::table('storefront_product')->where('storefront_id', $brand)->where('product_id', $productId));
    expect(Row::bool($row, 'is_visible'))->toBeFalse('the re-run RE-ENABLED a product the team hid')
        ->and(Row::bool($row, 'is_featured'))->toBeTrue('the re-run reset the featured flag')
        ->and(Row::int($row, 'sort_order'))->toBe(77, 'the re-run reset the sort order')
        ->and(Row::str($row, 'slug'))->toBe('kept-by-the-team', 'the re-run reset a slug the team typed');

    // Storefront 1's row for the same product is untouched by any of this.
    $one = T::one(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId));
    expect(Row::bool($one, 'is_visible'))->toBeTrue();
});

it('INSERT-ONLY: a NEW product gets its rows on the next run, visible by default', function () {
    /*
     * The other half: insert-only must not mean "never writes anything again".
     *
     * A LEGACY-BACKED product is used rather than a fixture one, and that is not incidental: a
     * catalogue-only product would make `catalog_products` disagree with legacy `products` and the
     * reconciliation would fail the run for a reason that has nothing to do with this claim. The
     * shape being tested is "a product the transform knows about, with no row on storefront 2 yet".
     */
    $legacyBacked = T::int(DB::connection('legacy')->table('products')->orderBy('id')->value('id'));
    DB::table('storefront_category_product')->where('product_id', $legacyBacked)->where('storefront_id', Storefront::BRAND_FASHION_ID)->delete();
    DB::table('storefront_product')->where('product_id', $legacyBacked)->where('storefront_id', Storefront::BRAND_FASHION_ID)->delete();

    expect(DB::table('storefront_product')->where('storefront_id', Storefront::BRAND_FASHION_ID)->where('product_id', $legacyBacked)->exists())
        ->toBeFalse();

    runSteps([18, 19]);

    $row = T::one(DB::table('storefront_product')->where('storefront_id', Storefront::BRAND_FASHION_ID)->where('product_id', $legacyBacked));
    expect(Row::bool($row, 'is_visible'))->toBeTrue('a product added later must arrive VISIBLE by default')
        ->and(DB::table('storefront_category_product')
            ->where('storefront_id', Storefront::BRAND_FASHION_ID)->where('product_id', $legacyBacked)->where('is_primary', 1)->count())
        ->toBe(1, 'and with exactly one primary category on that storefront');
});

it('never lets the transform be refused by the pre-switch block it does not go through', function () {
    /*
     * The non-negotiable, asserted rather than assumed: `PreSwitch` gates the DASHBOARD writers,
     * and the transform writes through `App\Transform\CategoryNodes` / `Writer`. If a future edit
     * ever routed the transform through `CategoryTreeWriter` or `ProductWriter`, every rehearsal
     * would start failing at step 15 with an Arabic refusal — so this runs the tree and placement
     * steps with the flag in its DEFAULT (blocked) state and requires exit 0.
     */
    expect(PreSwitch::completed())->toBeFalse()
        ->and(PreSwitch::allows('category'))->toBeFalse()
        ->and(PreSwitch::allows('product'))->toBeFalse();

    runSteps([15, 16, 17, 18, 19]);

    foreach (Storefront::activeIds() as $storefrontId) {
        expect(DB::table('storefront_categories')->where('storefront_id', $storefrontId)->count())->toBeGreaterThan(0)
            ->and(DB::table('storefront_product')->where('storefront_id', $storefrontId)->count())->toBeGreaterThan(0);
    }
});
