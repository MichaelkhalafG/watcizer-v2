<?php

use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

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

it('hides zero-product nodes and shows them the moment a visible product is placed there', function () {
    transformForVisibility();

    $sf = Storefront::WATCHIZER_ID;
    $watches = nodeId('category_type', 1, null);
    $chronograph = nodeId('sub_type', 2, 1);      // 111 products
    $diver = nodeId('sub_type', 1, 1);            // pinned orphan under Watches, zero products
    $legacyRoot = nodeId('category_root', 0, null);

    $visible = visibleNodeIds();
    expect($visible)->toContain($watches)
        ->and($visible)->toContain($chronograph)
        ->and($visible)->not->toContain($diver)
        ->and($visible)->not->toContain($legacyRoot)
        ->and(count($visible))->toBe(expectedVisibleNodeCount());   // every populated category type + every populated pair, from the data

    $productId = productsIn($chronograph)[0];
    DB::table('storefront_category_product')->insert([
        'storefront_id' => $sf, 'storefront_category_id' => $diver, 'product_id' => $productId,
        'sort_order' => 0, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(visibleNodeIds())->toContain($diver);

    DB::table('storefront_product')->where('storefront_id', $sf)->where('product_id', $productId)->update(['is_visible' => 0]);
    $after = visibleNodeIds();
    expect($after)->not->toContain($diver)
        ->and($after)->toContain($watches);
});

it('break-case 1: a node whose only products are soft-deleted is hidden', function () {
    transformForVisibility();
    $jewelry = nodeId('sub_type', 21, 2);          // 2 products
    expect(visibleNodeIds())->toContain($jewelry);

    DB::table('catalog_products')->whereIn('id', productsIn($jewelry))->update(['deleted_at' => now()]);

    expect(visibleNodeIds())->not->toContain($jewelry)
        ->and(visibleNodeIds())->toContain(nodeId('category_type', 2, null));   // Fashion still has Bags etc.
});

it('break-case 2: a node whose only products are inactive (catalog_products.is_active = 0) is hidden', function () {
    transformForVisibility();
    $bracelets = nodeId('sub_type', 22, 2);        // 2 products
    expect(visibleNodeIds())->toContain($bracelets);

    DB::table('catalog_products')->whereIn('id', productsIn($bracelets))->update(['is_active' => 0]);

    expect(visibleNodeIds())->not->toContain($bracelets);
});

it('break-case 3: a product visible only on ANOTHER storefront does not light up this storefront\'s node', function () {
    transformForVisibility();
    $sf = Storefront::WATCHIZER_ID;
    $belts = nodeId('sub_type', 16, 2);            // 2 products
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
    $wallets = nodeId('sub_type', 17, 2);          // 5 products
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
    $sibling = nodeId('sub_type', 18, 2);          // Bags, has its own products → still visible on its own
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
    $diver = nodeId('sub_type', 1, 1);              // zero products
    $chronograph = nodeId('sub_type', 2, 1);
    $productId = productsIn($chronograph)[0];

    // (a) placement row exists but the product has NO storefront_product row on this storefront at all
    DB::table('storefront_product')->where('storefront_id', $sf)->where('product_id', $productId)->delete();
    DB::table('storefront_category_product')->insert(['storefront_id' => $sf, 'storefront_category_id' => $diver, 'product_id' => $productId, 'sort_order' => 0, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now()]);
    expect(visibleNodeIds($sf))->not->toContain($diver);

    // (b) the product is visible on this storefront, but the placement row carries ANOTHER storefront_id
    $other = (int) DB::table('storefronts')->insertGetId(['code' => 'other2', 'name' => 'Other 2', 'locales' => '["en"]', 'default_locale' => 'en', 'currency' => 'EGP', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('storefront_product')->insert(['storefront_id' => $sf, 'product_id' => $productId, 'is_visible' => 1, 'is_featured' => 0, 'sort_order' => 0, 'slug' => "back-$productId", 'effective_price' => 1, 'effective_sale_price' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('storefront_category_product')->where('storefront_category_id', $diver)->update(['storefront_id' => $other]);
    expect(visibleNodeIds($sf))->not->toContain($diver);

    // control: the same placement stamped with the right storefront lights the node
    DB::table('storefront_category_product')->where('storefront_category_id', $diver)->update(['storefront_id' => $sf]);
    expect(visibleNodeIds($sf))->toContain($diver);
});
