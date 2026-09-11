<?php

use App\Domain\Catalog\FamilyForCategory;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * REVIEW 🟠-2 — the categories screen derived each node's family by calling
 * `FamilyForCategory::forNode()` inside the row loop. That method costs TWO queries (the node's
 * path, then the English names), so the screen's query count was 2n + a constant: a tree of 300
 * nodes meant roughly 600 extra round trips, and every category the team added made the page
 * slower. `explainMany()` is the same rule, batched into two queries for the whole tree.
 *
 * MEASURED, by putting the loop back and running this file: the screen went from **103 queries
 * at 10 extra nodes to 683 at 300** — 2 per node, exactly as read. With `explainMany()` both
 * renders cost the same handful.
 *
 * The guard here is a SLOPE, not a number. Asserting "≤ 14 queries" would go stale the first time
 * a legitimate query is added to the screen; asserting that 300 more nodes cost no more queries
 * than 10 more nodes is the property that was actually broken, and it cannot be satisfied by an
 * accidental N+1 anywhere on the page — not only in the family derivation.
 */

/** Queries executed on the default connection while `$fn` runs. */
function treeQueries(Closure $fn): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $fn();
    $n = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    return $n;
}

/**
 * `$count` sibling children under one root, inserted in one statement.
 *
 * `$tag` keeps slugs unique across calls — `storefront_id + slug` is a unique key, so a second
 * batch under the same root cannot restart its numbering.
 */
function bulkNodes(int $rootId, int $count, string $tag = 'a', int $storefrontId = 1): void
{
    $root = CatalogFixture::node($rootId);
    $now = now();
    $nodes = [];
    for ($i = 0; $i < $count; $i++) {
        $nodes[] = [
            'storefront_id' => $storefrontId,
            'parent_id' => $rootId,
            'depth' => $root->depth + 1,
            'slug' => 'scale-'.$rootId.'-'.$tag.'-'.$i,
            // `path` is NOT NULL with no default and needs the id the insert is about to assign,
            // so it goes in as the writer's own placeholder and is corrected below.
            'path' => '/',
            'sort_order' => $i,
            'is_active' => true,
            'show_in_menu' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('storefront_categories')->insert($nodes);

    // `path` is materialised, and a node without a valid one would be SKIPPED by the family
    // batcher — which would make this test pass for the wrong reason. Each row gets the real
    // `/<root>/<id>/` path, exactly as `CategoryTreeWriter` writes it.
    $ids = T::many(DB::table('storefront_categories')
        ->where('parent_id', $rootId)->where('slug', 'like', 'scale-'.$rootId.'-'.$tag.'-%')
        ->select(['id']));
    foreach ($ids as $row) {
        $id = Row::int($row, 'id');
        DB::table('storefront_categories')->where('id', $id)->update(['path' => $root->path.$id.'/']);
    }

    // Every node must carry an EN name too: the family is derived from the root's and the node's
    // English names, so a nameless tree would exercise a cheaper path than the real screen.
    $translations = [];
    foreach ($ids as $row) {
        foreach (['ar' => 'فرع', 'en' => 'Branch'] as $locale => $name) {
            $translations[] = [
                'storefront_category_id' => Row::int($row, 'id'),
                'locale' => $locale,
                'name' => $name.' '.Row::int($row, 'id'),
            ];
        }
    }
    DB::table('storefront_category_translations')->insert($translations);
}

it('costs the same number of queries for 300 categories as for 10', function () {
    CatalogFixture::assumeSwitched();
    $root = CatalogFixture::watchesRoot();

    bulkNodes($root, 10, 'small');
    $admin = Staff::admin();

    // Warm whatever a first render memoises (config, the resolver's own reads), so the comparison
    // measures the tree and not the first-request cost.
    actingAs($admin)->get('/manage/storefronts/1/categories')->assertOk();

    $small = treeQueries(fn () => actingAs($admin)->get('/manage/storefronts/1/categories')->assertOk());

    bulkNodes($root, 290, 'big');
    expect(T::int(DB::table('storefront_categories')->where('storefront_id', 1)->count()))
        ->toBeGreaterThanOrEqual(300, 'the tree under test must really hold 300+ nodes');

    $large = treeQueries(fn () => actingAs($admin)->get('/manage/storefronts/1/categories')->assertOk());

    /*
     * O(1): 290 extra nodes may not add a single query. The tolerance is +2 rather than +0 so that
     * a future legitimate change (one more aggregate, say) does not read as a regression — the
     * failure this guards against is 580, and no per-node cost hides inside a margin of two.
     */
    expect($large)->toBeLessThanOrEqual($small + 2,
        "tree render went from {$small} queries at 10 nodes to {$large} at 300 — that is per-node work");
});

it('derives the family for every node of a big tree in two queries', function () {
    // The narrow assertion, straight on the collaborator: whatever the page costs, the FAMILY part
    // of it is two queries for any number of nodes.
    CatalogFixture::assumeSwitched();
    $root = CatalogFixture::watchesRoot();
    bulkNodes($root, 300, 'many');

    $ids = [];
    foreach (T::many(DB::table('storefront_categories')->where('storefront_id', 1)->select(['id'])) as $row) {
        $ids[] = Row::int($row, 'id');
    }
    expect(count($ids))->toBeGreaterThanOrEqual(300);

    $families = new FamilyForCategory;
    $resolved = [];
    $n = treeQueries(function () use ($families, $ids, &$resolved): void {
        $resolved = $families->explainMany($ids);
    });

    expect($n)->toBe(2, 'explainMany must stay at two queries regardless of node count')
        ->and(count($resolved))->toBe(count($ids), 'every node must come back with a family');

    // And it is the SAME answer the per-node resolver gives, on a sample — batching may not change
    // the rule, only the number of round trips.
    foreach (array_slice($ids, 0, 5) as $id) {
        expect($resolved[$id]['family'])->toBe($families->forNode($id));
    }
});

it('still answers for a second storefront’s tree without leaking the first', function () {
    // The batcher takes bare ids, so a scoping mistake in it would be invisible on a single
    // storefront. Both trees are asked for at once and each node must keep its own family.
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $foreign = CatalogFixture::anyNodeOf(Storefront::BRAND_FASHION_ID);

    $families = new FamilyForCategory;
    $both = $families->explainMany([$watches, $foreign]);

    expect($both[$watches]['family'])->toBe($families->forNode($watches))
        ->and($both[$foreign]['family'])->toBe($families->forNode($foreign));
});
