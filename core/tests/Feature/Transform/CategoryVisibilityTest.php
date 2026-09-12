<?php

use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Assert;
use Tests\Support\LedgerState;

use function Pest\Laravel\artisan;

/*
 * The permanent dynamic menu rule (CLEAN_CORE_STUDY §3.3, decided 2026-09-06): a category
 * node is visible iff it (or a descendant) holds a product that is visible on THIS storefront,
 * active and not soft-deleted. Exercised on the real transform output inside the test
 * transaction (rolled back afterwards). The four break-cases of the 2026-09-07 review are
 * permanent tests here: soft-deleted product, inactive product, other-storefront-only
 * product, deep-descendant path.
 */

function transformForVisibility(): void
{
    $dir = storage_path('framework/testing/transform-visibility-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pending = artisan('core:transform', ['--output' => $dir, '--force' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode(0);
}

function nodeId(string $source, int $legacyId, ?int $legacyParentId): int
{
    $q = DB::table('storefront_categories')->where('storefront_id', Storefront::WATCHIZER_ID)->where('legacy_source', $source)->where('legacy_id', $legacyId);
    $q = $legacyParentId === null ? $q->whereNull('legacy_parent_id') : $q->where('legacy_parent_id', $legacyParentId);
    $row = $q->select(['id'])->first();
    if (! $row instanceof stdClass) {
        throw new RuntimeException("no node for $source $legacyId");
    }

    return Row::int($row, 'id');
}

/** @return list<int> */
function visibleNodeIds(int $storefrontId = Storefront::WATCHIZER_ID): array
{
    return array_values(StorefrontCategory::query()->visibleInMenu($storefrontId)->orderBy('id')->pluck('id')->map(fn (mixed $v) => (int) (is_numeric($v) ? $v : 0))->all());
}

/**
 * Product ids placed in a node.
 *
 * @return list<int>
 */
function productsIn(int $nodeId): array
{
    return array_values(DB::table('storefront_category_product')->where('storefront_category_id', $nodeId)->orderBy('product_id')->pluck('product_id')->map(fn (mixed $v) => (int) (is_numeric($v) ? $v : 0))->all());
}

/**
 * A depth-2 node of this storefront that holds NO products — derived, never named.
 *
 * ── Why this helper exists (rehearsal #3, 2026-09-12) ─────────────────────────────────────────
 *
 * These tests used to say `nodeId('sub_type', 1, 1)  // pinned orphan, zero products` and treat
 * Diver as "the empty one". Diver had 16 products in the next production dump, so two tests failed
 * while the visibility rule was working perfectly — the rule lit up a node that genuinely held
 * products. Tourbillon and Automatic moved the same week.
 *
 * WHICH sub type is empty is a property of the catalogue and changes without notice; that a node
 * with no products stays out of the menu is the rule under test. So the node is chosen at runtime
 * and the test says nothing about its name. It returns null when the catalogue has no empty node
 * at all — a legitimate state that the caller skips on, rather than failing on.
 */
function emptyNodeId(int $categoryTypeId = 1, int $storefrontId = Storefront::WATCHIZER_ID): ?int
{
    $row = DB::table('storefront_categories as sc')
        ->leftJoin('storefront_category_product as scp', function (JoinClause $join) use ($storefrontId): void {
            $join->on('scp.storefront_category_id', '=', 'sc.id')->where('scp.storefront_id', '=', $storefrontId);
        })
        ->where('sc.storefront_id', $storefrontId)
        ->where('sc.legacy_source', 'sub_type')
        ->where('sc.legacy_parent_id', $categoryTypeId)
        ->groupBy('sc.id')
        ->havingRaw('COUNT(scp.id) = 0')
        ->orderBy('sc.id')
        ->select(['sc.id'])
        ->first();

    return $row instanceof stdClass ? Row::int($row, 'id') : null;
}

/**
 * A depth-2 node under this category type that DOES hold products, by rank — `fewest` for a node
 * a test can empty without emptying its whole root, `most` for the sibling that must stay visible.
 *
 * Derived for the same reason as {@see emptyNodeId()}: the file used to name Jewelry, Bracelets,
 * Belts, Wallets and Bags with their product counts in a comment, and those counts are already
 * wrong. Only the SHAPE matters to these tests.
 */
function populatedNodeId(int $categoryTypeId, string $pick = 'fewest', int $storefrontId = Storefront::WATCHIZER_ID): ?int
{
    $row = DB::table('storefront_categories as sc')
        ->join('storefront_category_product as scp', function (JoinClause $join) use ($storefrontId): void {
            $join->on('scp.storefront_category_id', '=', 'sc.id')->where('scp.storefront_id', '=', $storefrontId);
        })
        ->where('sc.storefront_id', $storefrontId)
        ->where('sc.legacy_source', 'sub_type')
        ->where('sc.legacy_parent_id', $categoryTypeId)
        ->groupBy('sc.id')
        ->orderBy(DB::raw('COUNT(scp.id)'), $pick === 'most' ? 'desc' : 'asc')
        ->orderBy('sc.id')
        ->select(['sc.id'])
        ->first();

    return $row instanceof stdClass ? Row::int($row, 'id') : null;
}

/** The derived node, or a skip naming what the catalogue lacks — never a silent pass. */
function requireNodeId(?int $id, string $what): int
{
    if ($id === null) {
        // `Assert::markTestSkipped()` is typed `never`, so the skip is also what tells the
        // analyser this function cannot return null — the same reason `LedgerState` uses it.
        Assert::markTestSkipped("this catalogue has no {$what}, so the rule cannot be exercised on real data");
    }

    return $id;
}

/**
 * How many nodes the rule MUST light up, derived from legacy rather than hard-coded: every
 * (category type, sub type) pair that carries a product, plus every category type that carries
 * one. Hard-coding this broke on the first fresh dump (rehearsal #2: 9 became 13), which hid the
 * assertion instead of testing it.
 */
function expectedVisibleNodeCount(): int
{
    $pairs = DB::connection('legacy')->table('products')
        ->whereNotNull('category_type_id')->whereNotNull('sub_type_id')
        ->distinct()->count(DB::raw('CONCAT(category_type_id, ":", sub_type_id)'));
    $types = DB::connection('legacy')->table('products')
        ->whereNotNull('category_type_id')
        ->distinct()->count('category_type_id');

    return $types + $pairs;
}

beforeEach(function () {
    // A dirty ledger is a database state, not a defect — skip with the reason instead of failing
    // this file's tests one broken assertion at a time. See Tests\Support\LedgerState.
    LedgerState::skipIfDirty();
});

it('hides zero-product nodes and shows them the moment a visible product is placed there', function () {
    transformForVisibility();

    $sf = Storefront::WATCHIZER_ID;
    $watches = nodeId('category_type', 1, null);
    $chronograph = requireNodeId(populatedNodeId(1, 'most'), 'populated watch sub type');
    $empty = requireNodeId(emptyNodeId(1), 'watch sub type with no products');
    $legacyRoot = nodeId('category_root', 0, null);

    $visible = visibleNodeIds();
    expect($visible)->toContain($watches)
        ->and($visible)->toContain($chronograph)
        ->and($visible)->not->toContain($empty)
        ->and($visible)->not->toContain($legacyRoot)
        ->and(count($visible))->toBe(expectedVisibleNodeCount());   // every populated category type + every populated pair, from the data

    $productId = productsIn($chronograph)[0];
    DB::table('storefront_category_product')->insert([
        'storefront_id' => $sf, 'storefront_category_id' => $empty, 'product_id' => $productId,
        'sort_order' => 0, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(visibleNodeIds())->toContain($empty);

    DB::table('storefront_product')->where('storefront_id', $sf)->where('product_id', $productId)->update(['is_visible' => 0]);
    $after = visibleNodeIds();
    expect($after)->not->toContain($empty)
        ->and($after)->toContain($watches);
});

it('break-case 1: a node whose only products are soft-deleted is hidden', function () {
    transformForVisibility();
    $jewelry = requireNodeId(populatedNodeId(2, 'fewest'), 'fashion sub type with products');
    expect(visibleNodeIds())->toContain($jewelry);

    DB::table('catalog_products')->whereIn('id', productsIn($jewelry))->update(['deleted_at' => now()]);

    expect(visibleNodeIds())->not->toContain($jewelry)
        ->and(visibleNodeIds())->toContain(nodeId('category_type', 2, null));   // Fashion still has Bags etc.
});

it('break-case 2: a node whose only products are inactive (catalog_products.is_active = 0) is hidden', function () {
    transformForVisibility();
    $bracelets = requireNodeId(populatedNodeId(2, 'fewest'), 'fashion sub type with products');
    expect(visibleNodeIds())->toContain($bracelets);

    DB::table('catalog_products')->whereIn('id', productsIn($bracelets))->update(['is_active' => 0]);

    expect(visibleNodeIds())->not->toContain($bracelets);
});

it('break-case 3: a product visible only on ANOTHER storefront does not light up this storefront\'s node', function () {
    transformForVisibility();
    $sf = Storefront::WATCHIZER_ID;
    $belts = requireNodeId(populatedNodeId(2, 'fewest'), 'fashion sub type with products');
    $ids = productsIn($belts);
    expect(visibleNodeIds())->toContain($belts);

    // second storefront with the same products visible there, hidden on Watchizer
    $other = (int) DB::table('storefronts')->insertGetId(['code' => 'other', 'name' => 'Other', 'locales' => '["en"]', 'default_locale' => 'en', 'currency' => 'EGP', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ids as $id) {
        DB::table('storefront_product')->insert(['storefront_id' => $other, 'product_id' => $id, 'is_visible' => 1, 'is_featured' => 0, 'sort_order' => 0, 'slug' => "other-$id", 'effective_price' => 1, 'effective_sale_price' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('storefront_product')->where('storefront_id', $sf)->whereIn('product_id', $ids)->update(['is_visible' => 0]);

    expect(visibleNodeIds($sf))->not->toContain($belts);

    // and the other storefront's own tree stays independent: Watchizer nodes never show for it
    expect(visibleNodeIds($other))->toBe([]);
});

it('break-case 4: a visible product placed only on a deep descendant lights up every ancestor, and only through the path', function () {
    transformForVisibility();
    $sf = Storefront::WATCHIZER_ID;
    $fashion = nodeId('category_type', 2, null);
    $wallets = requireNodeId(populatedNodeId(2, 'fewest'), 'fashion sub type with products');
    $ids = productsIn($wallets);

    // depth-3 child under Wallets, with a real materialised path
    $walletsPath = DB::table('storefront_categories')->where('id', $wallets)->value('path');
    $leaf = (int) DB::table('storefront_categories')->insertGetId([
        'storefront_id' => $sf, 'parent_id' => $wallets, 'depth' => 3, 'path' => '', 'slug' => 'leather-wallets',
        'is_active' => 1, 'show_in_menu' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('storefront_categories')->where('id', $leaf)->update(['path' => (is_string($walletsPath) ? $walletsPath : '/').$leaf.'/']);

    // move the products: only the leaf holds them now
    DB::table('storefront_category_product')->where('storefront_id', $sf)->whereIn('product_id', $ids)->delete();
    foreach ($ids as $id) {
        DB::table('storefront_category_product')->insert(['storefront_id' => $sf, 'storefront_category_id' => $leaf, 'product_id' => $id, 'sort_order' => 0, 'is_primary' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $visible = visibleNodeIds($sf);
    expect($visible)->toContain($leaf)
        ->and($visible)->toContain($wallets)
        ->and($visible)->toContain($fashion);

    // a sibling with a path that merely LOOKS similar is not lit: path prefix must be exact
    $sibling = requireNodeId(populatedNodeId(2, 'most'), 'second fashion sub type with products');
    DB::table('storefront_product')->where('storefront_id', $sf)->whereIn('product_id', productsIn($sibling))->update(['is_visible' => 0]);
    expect(visibleNodeIds($sf))->not->toContain($sibling);

    // hide the leaf's products → the whole chain above goes dark unless another branch holds products
    DB::table('storefront_product')->where('storefront_id', $sf)->whereIn('product_id', $ids)->update(['is_visible' => 0]);
    $after = visibleNodeIds($sf);
    expect($after)->not->toContain($leaf)
        ->and($after)->not->toContain($wallets)
        ->and($after)->toContain($fashion);        // Belts / Jewelry / Bracelets still light Fashion
});

it('bypass attempt (re-verification 2026-09-07): a placement without a storefront_product row, or with a foreign storefront_id, never lights a node', function () {
    transformForVisibility();
    $sf = Storefront::WATCHIZER_ID;
    $empty = requireNodeId(emptyNodeId(1), 'watch sub type with no products');
    $chronograph = requireNodeId(populatedNodeId(1, 'most'), 'populated watch sub type');
    $productId = productsIn($chronograph)[0];

    // (a) placement row exists but the product has NO storefront_product row on this storefront at all
    DB::table('storefront_product')->where('storefront_id', $sf)->where('product_id', $productId)->delete();
    DB::table('storefront_category_product')->insert(['storefront_id' => $sf, 'storefront_category_id' => $empty, 'product_id' => $productId, 'sort_order' => 0, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now()]);
    expect(visibleNodeIds($sf))->not->toContain($empty);

    // (b) the product is visible on this storefront, but the placement row carries ANOTHER storefront_id
    $other = (int) DB::table('storefronts')->insertGetId(['code' => 'other2', 'name' => 'Other 2', 'locales' => '["en"]', 'default_locale' => 'en', 'currency' => 'EGP', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('storefront_product')->insert(['storefront_id' => $sf, 'product_id' => $productId, 'is_visible' => 1, 'is_featured' => 0, 'sort_order' => 0, 'slug' => "back-$productId", 'effective_price' => 1, 'effective_sale_price' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('storefront_category_product')->where('storefront_category_id', $empty)->update(['storefront_id' => $other]);
    expect(visibleNodeIds($sf))->not->toContain($empty);

    // control: the same placement stamped with the right storefront lights the node
    DB::table('storefront_category_product')->where('storefront_category_id', $empty)->update(['storefront_id' => $sf]);
    expect(visibleNodeIds($sf))->toContain($empty);
});
