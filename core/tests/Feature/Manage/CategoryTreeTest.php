<?php

use App\Domain\Catalog\CategoryTreeWriter;
use App\Models\Storefront\StorefrontCategory;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The category tree — `path`/`depth` maintenance, the refusals, and the screen's job of making the
 * dynamic visibility rule visible.
 *
 * `StorefrontCategory`'s docblock has promised since M1 that "`path`/`depth` are maintained by the
 * category service's moveTo(), never by hand". These tests are what makes that promise checkable:
 * a materialised path is what the read layer trusts (the menu visibility rule reads ancestors off
 * it, the family resolver reads the root off it), so a tree whose `parent_id` and `path` disagree
 * is invisible to one half of the application and visible to the other.
 */

/**
 * Every node of a storefront, keyed by id — for asserting a whole subtree at once.
 *
 * @return array<int, array{parent_id: int|null, depth: int, path: string}>
 */
function treeOf(int $storefrontId = 1): array
{
    $out = [];
    foreach (T::many(DB::table('storefront_categories')->where('storefront_id', $storefrontId)->select(['id', 'parent_id', 'depth', 'path'])) as $row) {
        $out[Row::int($row, 'id')] = [
            'parent_id' => Row::nint($row, 'parent_id'),
            'depth' => Row::int($row, 'depth'),
            'path' => Row::str($row, 'path'),
        ];
    }

    return $out;
}

/** The invariant: every node's path is its parent's path plus its own id, and depth matches. */
function assertTreeConsistent(int $storefrontId = 1): void
{
    $tree = treeOf($storefrontId);
    $broken = [];

    foreach ($tree as $id => $node) {
        $parentId = $node['parent_id'];
        $expectedPath = ($parentId === null ? '/' : $tree[$parentId]['path']).$id.'/';
        $expectedDepth = $parentId === null ? 1 : $tree[$parentId]['depth'] + 1;

        if ($node['path'] !== $expectedPath) {
            $broken[] = "node {$id}: path {$node['path']} should be {$expectedPath}";
        }
        if ($node['depth'] !== $expectedDepth) {
            $broken[] = "node {$id}: depth {$node['depth']} should be {$expectedDepth}";
        }
    }

    expect($broken)->toBe([], 'the tree is inconsistent between parent_id, path and depth');
}

it('starts from a consistent tree — the transform built one', function () {
    // If this fails, nothing below means anything: it is the baseline the whole file compares to.
    assertTreeConsistent();
    expect(treeOf())->not->toBe([]);
});

it('creates a node with the right path and depth, and no legacy key', function () {
    $root = CatalogFixture::root('Test Root', 'جذر اختبار');
    $child = CatalogFixture::child($root['id'], 'Test Child', 'فرع اختبار');

    $rootNode = CatalogFixture::node($root['id']);
    $childNode = CatalogFixture::node($child['id']);

    expect(T::str($rootNode->getAttribute('path')))->toBe('/'.$root['id'].'/')
        ->and($rootNode->depth)->toBe(1)
        ->and(T::str($childNode->getAttribute('path')))->toBe('/'.$root['id'].'/'.$child['id'].'/')
        ->and($childNode->depth)->toBe(2)
        // Dashboard-authored: no legacy key, so the transform will never claim, refresh or
        // re-parent it (§2.9.6 rule 3 keys on the legacy tuple).
        ->and($rootNode->legacy_source)->toBeNull()
        ->and($rootNode->legacy_id)->toBeNull();

    assertTreeConsistent();
});

it('moves a whole SUBTREE and rewrites every path and depth beneath it', function () {
    // Three levels, so the rewrite has to touch a grandchild — the case a one-level update misses.
    $a = CatalogFixture::root('Branch A', 'فرع أ');
    $b = CatalogFixture::root('Branch B', 'فرع ب');
    $mid = CatalogFixture::child($a['id'], 'Mid', 'وسط');
    $leaf = CatalogFixture::child($mid['id'], 'Leaf', 'ورقة');

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/categories/{$mid['id']}/move", ['parent_id' => $b['id']])
        ->assertSessionHasNoErrors();

    $midNode = CatalogFixture::node($mid['id']);
    $leafNode = CatalogFixture::node($leaf['id']);

    expect($midNode->parent_id)->toBe($b['id'])
        ->and(T::str($midNode->getAttribute('path')))->toBe('/'.$b['id'].'/'.$mid['id'].'/')
        ->and($midNode->depth)->toBe(2)
        // The grandchild moved with it, and its depth shifted by the same delta.
        ->and(T::str($leafNode->getAttribute('path')))->toBe('/'.$b['id'].'/'.$mid['id'].'/'.$leaf['id'].'/')
        ->and($leafNode->depth)->toBe(3);

    assertTreeConsistent();
});

it('moves a node to the ROOT and back', function () {
    $a = CatalogFixture::root('Home A', 'أ');
    $child = CatalogFixture::child($a['id'], 'Child', 'فرع');

    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$child['id']}/move", ['parent_id' => null])
        ->assertSessionHasNoErrors();

    $node = CatalogFixture::node($child['id']);
    expect($node->parent_id)->toBeNull()
        ->and(T::str($node->getAttribute('path')))->toBe('/'.$child['id'].'/')
        ->and($node->depth)->toBe(1);

    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$child['id']}/move", ['parent_id' => $a['id']])
        ->assertSessionHasNoErrors();

    expect(CatalogFixture::node($child['id'])->depth)->toBe(2);
    assertTreeConsistent();
});

it('REFUSES a move into the node own subtree — the cycle that would orphan the branch', function () {
    $root = CatalogFixture::root('Cycle root', 'جذر');
    $child = CatalogFixture::child($root['id'], 'Cycle child', 'فرع');
    $grandchild = CatalogFixture::child($child['id'], 'Cycle grandchild', 'حفيد');

    $before = treeOf();

    // Into a direct child…
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$root['id']}/move", ['parent_id' => $child['id']])
        ->assertSessionHasErrors('tree');
    // …and into a grandchild, which a one-hop check would miss. The path prefix decides both.
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$root['id']}/move", ['parent_id' => $grandchild['id']])
        ->assertSessionHasErrors('tree');
    // …and onto itself.
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$root['id']}/move", ['parent_id' => $root['id']])
        ->assertSessionHasErrors('tree');

    expect(treeOf())->toBe($before, 'a refused move must change nothing');
    assertTreeConsistent();
});

it('REFUSES a move across storefronts', function () {
    $other = CatalogFixture::secondStorefront();
    $foreign = CatalogFixture::anyNodeOf($other);
    $mine = CatalogFixture::root('Mine', 'لي');

    // Addressed through MY storefront, naming a node of another: "not there", not "not yours".
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$mine['id']}/move", ['parent_id' => $foreign])
        ->assertSessionHasErrors('tree');

    expect(CatalogFixture::node($mine['id'])->parent_id)->toBeNull();
});

it('REFUSES a move that would make the tree deeper than the cap', function () {
    // Build a chain to the cap, then try to hang it under another node.
    $chain = [];
    $parent = null;
    for ($i = 1; $i <= CategoryTreeWriter::MAX_DEPTH; $i++) {
        $node = $parent === null
            ? CatalogFixture::root("Deep {$i}", "عمق {$i}")
            : CatalogFixture::child($parent, "Deep {$i}", "عمق {$i}");
        $chain[] = $node['id'];
        $parent = $node['id'];
    }
    expect(CatalogFixture::node($parent)->depth)->toBe(CategoryTreeWriter::MAX_DEPTH);

    // A child of the deepest node is refused outright…
    $host = CatalogFixture::root('Host', 'مستضيف');
    expect(fn () => app(CategoryTreeWriter::class)->create(1, $parent, ['ar' => 'أعمق', 'en' => 'Too deep']))
        ->toThrow(RuntimeException::class);

    // …and moving the whole chain one level down is refused too, because the DEEPEST descendant
    // is what the cap has to be measured against, not the node being moved.
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$chain[0]}/move", ['parent_id' => $host['id']])
        ->assertSessionHasErrors('tree');

    assertTreeConsistent();
});

it('renames a node in both locales, and deletes an emptied English name rather than blanking it', function () {
    $node = CatalogFixture::root('Original', 'الأصلي');

    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$node['id']}", [
        'name' => ['ar' => 'المعدّل', 'en' => 'Renamed'],
    ])->assertSessionHasNoErrors();

    $names = DB::table('storefront_category_translations')->where('storefront_category_id', $node['id'])->pluck('name', 'locale');
    expect($names['ar'])->toBe('المعدّل')->and($names['en'])->toBe('Renamed');

    // An emptied EN name is DELETED, not stored blank: fallback is off, so a blank row and a
    // missing row look the same to a reader and completely different to the storefront.
    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$node['id']}", [
        'name' => ['ar' => 'المعدّل', 'en' => ''],
    ])->assertSessionHasNoErrors();

    expect(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node['id'])->where('locale', 'en')->exists())->toBeFalse();
});

it('refuses a node with no Arabic name', function () {
    actingAs(Staff::admin())->post('/manage/storefronts/1/categories', ['name' => ['ar' => '', 'en' => 'English only']])
        ->assertSessionHasErrors('name.ar');
});

it('leaves a 301 behind when a category slug changes', function () {
    $node = CatalogFixture::root('Slug Source', 'مصدر');
    $oldSlug = $node['slug'];

    actingAs(Staff::admin())->put("/manage/storefronts/1/categories/{$node['id']}", [
        'name' => ['ar' => 'مصدر', 'en' => 'Slug Source'],
        'slug' => 'slug-target',
    ])->assertSessionHasNoErrors();

    expect(T::str(CatalogFixture::node($node['id'])->getAttribute('slug')))->toBe('slug-target');

    $redirect = T::one(DB::table('storefront_redirects')
        ->where('storefront_id', 1)->where('from_path', '/category/'.$oldSlug));

    expect(Row::str($redirect, 'to_path'))->toBe('/category/slug-target')
        ->and(Row::int($redirect, 'status'))->toBe(301)
        ->and(Row::str($redirect, 'source'))->toBe('slug_change');
});

it('makes a colliding slug unique instead of hitting the unique index', function () {
    $first = CatalogFixture::root('Collide', 'أول');
    $second = CatalogFixture::root('Collide', 'ثانٍ');

    expect($second['slug'])->not->toBe($first['slug'])
        ->and($second['slug'])->toStartWith('collide');
});

it('REFUSES to delete a node with children, with products, or with a legacy source', function () {
    // All three are `ON DELETE CASCADE` underneath, which is exactly why the check is here: a
    // permitted delete would take the subtree and every placement in it silently.
    $root = CatalogFixture::root('Delete me', 'احذفني');
    $child = CatalogFixture::child($root['id'], 'Child', 'فرع');

    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$root['id']}")->assertSessionHasErrors('tree');
    expect(DB::table('storefront_categories')->where('id', $root['id'])->exists())->toBeTrue();

    // With a product in it.
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, $child['id']);
    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$child['id']}")->assertSessionHasErrors('tree');
    expect(DB::table('storefront_category_product')->where('product_id', $productId)->exists())->toBeTrue();

    // And a transform-created node is never deletable: the rebuild would bring it back, so
    // deleting it is a lie that lasts until the next rehearsal.
    $legacyNode = CatalogFixture::watchesRoot();
    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$legacyNode}")->assertSessionHasErrors('tree');
    expect(DB::table('storefront_categories')->where('id', $legacyNode)->exists())->toBeTrue();
});

it('deletes an empty, dashboard-authored leaf', function () {
    $node = CatalogFixture::root('Empty leaf', 'ورقة فارغة');

    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$node['id']}")->assertSessionHasNoErrors();

    expect(DB::table('storefront_categories')->where('id', $node['id'])->exists())->toBeFalse()
        ->and(DB::table('storefront_category_translations')->where('storefront_category_id', $node['id'])->exists())->toBeFalse();
});

it('reorders siblings without touching another parent level', function () {
    $root = CatalogFixture::root('Order root', 'جذر ترتيب');
    $one = CatalogFixture::child($root['id'], 'One', 'واحد');
    $two = CatalogFixture::child($root['id'], 'Two', 'اثنان');
    $three = CatalogFixture::child($root['id'], 'Three', 'ثلاثة');

    actingAs(Staff::admin())->post('/manage/storefronts/1/categories/reorder', [
        'parent_id' => $root['id'],
        'ids' => [$three['id'], $one['id'], $two['id']],
    ])->assertSessionHasNoErrors();

    $order = DB::table('storefront_categories')->where('parent_id', $root['id'])->orderBy('sort_order')->pluck('id')->all();
    expect(array_map(fn (mixed $v): int => T::int($v), $order))->toBe([$three['id'], $one['id'], $two['id']]);
});

it('shows the team the THREE reasons a node is out of the menu', function () {
    // The screen's whole job: menu visibility is dynamic (study §3.3) and a dashboard that showed
    // only the two switches would let the team fight the rule. "معطّل", "مستبعد من القائمة يدويًا"
    // and "لا يوجد منتج ظاهر فيه أو في فروعه" are three different problems with three different
    // fixes, so the screen names which one applies.
    $empty = CatalogFixture::root('Menu empty', 'فارغ');
    $hidden = CatalogFixture::root('Menu hidden', 'مستبعد');
    $off = CatalogFixture::root('Menu off', 'معطل');

    app(CategoryTreeWriter::class)->setFlags(1, $hidden['id'], null, false);
    app(CategoryTreeWriter::class)->setFlags(1, $off['id'], false, null);

    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/categories')->assertOk());
    /** @var list<array<string, mixed>> $nodes */
    $nodes = $props['nodes'];

    $byId = [];
    foreach ($nodes as $node) {
        $byId[T::int($node['id'])] = $node;
    }

    expect($byId[$empty['id']]['in_menu'])->toBeFalse()
        ->and(T::str($byId[$empty['id']]['in_menu_reason']))->toContain('لا يوجد منتج ظاهر')
        ->and($byId[$hidden['id']]['in_menu'])->toBeFalse()
        ->and(T::str($byId[$hidden['id']]['in_menu_reason']))->toContain('مستبعد من القائمة')
        ->and($byId[$off['id']]['in_menu'])->toBeFalse()
        ->and(T::str($byId[$off['id']]['in_menu_reason']))->toContain('معطّل');

    // …and the rule's own answer is read from the SAME query the read layer uses, so the screen
    // cannot disagree with the storefront.
    $ruleIds = StorefrontCategory::nodeIdsWithVisibleProducts(1);
    foreach ($nodes as $node) {
        $id = T::int($node['id']);
        $expected = $node['is_active'] === true && $node['show_in_menu'] === true && in_array($id, $ruleIds, true);
        expect($node['in_menu'])->toBe($expected, "node {$id}: the screen disagrees with scopeVisibleInMenu");
    }
});

it('shows the FAMILY each node would give a product, with the transform rule', function () {
    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/categories')->assertOk());
    /** @var list<array<string, mixed>> $nodes */
    $nodes = $props['nodes'];

    $watchesRoot = CatalogFixture::watchesRoot();
    foreach ($nodes as $node) {
        if (T::int($node['id']) === $watchesRoot) {
            expect($node['family'])->toBe('watch');
        }
    }
});

it('bumps the storefront cache version on every tree mutation', function () {
    // The menu, the tree endpoint and every cached category payload are derived from these rows.
    // A team that renames a category and cannot see it on the storefront will rename it again.
    $cache = app(StorefrontCache::class);
    $before = $cache->version(1);

    CatalogFixture::root('Cache bump', 'تحديث الذاكرة');

    expect($cache->version(1))->toBeGreaterThan($before);
});
