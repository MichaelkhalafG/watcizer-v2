<?php

use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * The permanent dynamic menu rule (CLEAN_CORE_STUDY §3.3, decided 2026-09-06): a category
 * node is visible iff it (or a descendant) holds a visible product. Exercised on the real
 * transform output inside the test transaction (rolled back afterwards).
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
function visibleNodeIds(): array
{
    return array_values(StorefrontCategory::query()->visibleInMenu(Storefront::WATCHIZER_ID)->orderBy('id')->pluck('id')->map(fn (mixed $v) => (int) (is_numeric($v) ? $v : 0))->all());
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
        ->and(count($visible))->toBe(2 + 7);      // 2 category types + the 7 (type, sub type) pairs with products

    // place one visible product in Diver → it appears, and Watches stays visible
    $productId = Row::int(legacyFirstVisibleProduct(), 'id');
    DB::table('storefront_category_product')->insert([
        'storefront_id' => $sf, 'storefront_category_id' => $diver, 'product_id' => $productId,
        'sort_order' => 0, 'is_primary' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(visibleNodeIds())->toContain($diver);

    // hide that product on the storefront → Diver disappears again, nothing else changes
    DB::table('storefront_product')->where('storefront_id', $sf)->where('product_id', $productId)->update(['is_visible' => 0]);
    $after = visibleNodeIds();
    expect($after)->not->toContain($diver)
        ->and($after)->toContain($watches);
});

function legacyFirstVisibleProduct(): stdClass
{
    $row = DB::table('storefront_product')->where('storefront_id', Storefront::WATCHIZER_ID)->where('is_visible', 1)->orderBy('product_id')->first(['product_id as id']);
    if (! $row instanceof stdClass) {
        throw new RuntimeException('no visible storefront product');
    }

    return $row;
}
