<?php

use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The category tree is shown in the order the team PUT it (item 3, developer 2026-09-18).
 *
 * ── "The up/down reorder buttons don't work" ────────────────────────────────────────────────
 *
 * They worked. Every click wrote `sort_order` correctly, the activity log recorded it, and the
 * STOREFRONT honoured it — `Storefront\CategoryTree` has always ordered by `depth, sort_order, id`,
 * so the customer-facing menu moved. The dashboard did not.
 *
 * The dashboard ordered by `path`, and `path` is an ID path: `/1/10/`, `/1/11/`, `/1/12/`. Sorted
 * as a string that is ascending by id and cannot be changed by any button. The operator clicked,
 * the page came back identical, and the only evidence anything had happened was on the live site.
 *
 * ── Why the old tests did not catch it ──────────────────────────────────────────────────────
 *
 * They asserted the COLUMN. `reorder` returned the number of rows moved and `sort_order` held the
 * new value, so every assertion passed while the screen was wrong. The first test below asserts
 * what the operator actually sees: the order of the `nodes` prop, AFTER a reorder, through the real
 * endpoint.
 *
 * ── Which of these three would actually have caught it ─────────────────────────────
 *
 * Checked, not assumed: the fix was reverted and all three were run against the old ordering. Only
 * the FIRST went red. The other two passed — on this catalogue each level's id order happens to
 * match its sort order, so ordering by an id path looks correct.
 *
 * That is worth knowing and not worth hiding. The first test earns its place because it CREATES the
 * condition it checks: it reorders, then reads the screen, instead of hoping the data contains a
 * level that is out of order. The other two are invariants the tree view in item 2 is built on —
 * every node present, every child after its parent — and they are the ones that will matter the day
 * somebody rewrites the arrangement.
 */

it('shows a reordered level in its new order, not in id order', function () {
    CatalogFixture::assumeSwitched();

    $storefront = 1;
    $nodes = T::arr(Props::of(actingAs(Staff::admin())->get("/manage/storefronts/{$storefront}/categories")->assertOk())['nodes'] ?? null);

    // A level with at least two nodes — the root's children on a real tree.
    $byParent = [];
    foreach ($nodes as $node) {
        $row = T::arr($node);
        $byParent[T::str($row['parent_id'] ?? 'root')][] = T::int($row['id'] ?? null);
    }

    $parent = null;
    $siblings = [];
    foreach ($byParent as $key => $ids) {
        if (count($ids) >= 2) {
            // 'root' is the key a NULL parent gets above; (int) 'root' is 0, and 0 is not a
            // parent id — the endpoint refuses it with 'must be at least 1'.
            $parent = $key === 'root' ? null : (int) $key;
            $siblings = $ids;
            break;
        }
    }

    expect($siblings)->not->toBe([], 'no level has two nodes — this test proves nothing');

    // Swap the first two, through the endpoint the buttons call.
    $wanted = $siblings;
    [$wanted[0], $wanted[1]] = [$wanted[1], $wanted[0]];

    actingAs(Staff::admin())->post("/manage/storefronts/{$storefront}/categories/reorder", [
        'parent_id' => $parent,
        'ids' => $wanted,
    ])->assertSessionHasNoErrors();

    // …and the SCREEN reflects it. This is the assertion the defect could not have survived.
    $after = T::arr(Props::of(actingAs(Staff::admin())->get("/manage/storefronts/{$storefront}/categories"))['nodes'] ?? null);

    $seen = [];
    foreach ($after as $node) {
        $row = T::arr($node);
        $key = T::str($row['parent_id'] ?? 'root');
        if ($key === ($parent === null ? 'root' : (string) $parent)) {
            $seen[] = T::int($row['id'] ?? null);
        }
    }

    expect($seen)->toBe($wanted, 'the screen still shows the old order');
});

it('keeps every node in the list, and every child after its own parent', function () {
    CatalogFixture::assumeSwitched();

    $nodes = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/2/categories')->assertOk())['nodes'] ?? null);

    $total = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->count());
    expect(count($nodes))->toBe($total, 'the arrangement lost or duplicated a node');

    /*
     * Depth-first means a child is never printed before its parent. A screen that indents by
     * `depth` (item 2) draws nonsense the moment that stops being true — a node appears indented
     * under whatever happened to precede it.
     *
     * An ORPHAN is allowed at the END: a node whose parent is missing is a real thing to fix, and
     * appending it is how this screen shows it rather than silently dropping the row.
     */
    $position = [];
    foreach ($nodes as $index => $node) {
        $position[T::int(T::arr($node)['id'] ?? null)] = $index;
    }

    $wrong = [];
    foreach ($nodes as $index => $node) {
        $row = T::arr($node);
        $parent = $row['parent_id'] ?? null;
        if ($parent === null) {
            continue;
        }
        $parentId = T::int($parent);
        if (! array_key_exists($parentId, $position)) {
            // An orphan — its parent is not on this storefront at all.
            continue;
        }
        if ($position[$parentId] > $index) {
            $wrong[] = T::int($row['id'] ?? null).' appears before its parent '.$parentId;
        }
    }

    expect($wrong)->toBe([]);
});

it('sorts each level by sort_order, with the id only as a tie-break', function () {
    CatalogFixture::assumeSwitched();

    $nodes = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/2/categories')->assertOk())['nodes'] ?? null);

    // Walk the list and check each level is non-decreasing in (sort_order, id). Written as a scan
    // rather than a re-sort so a failure names the pair that is out of order.
    $lastOfLevel = [];
    $wrong = [];
    foreach ($nodes as $node) {
        $row = T::arr($node);
        $key = T::str($row['parent_id'] ?? 'root');
        $pair = [T::int($row['sort_order'] ?? null), T::int($row['id'] ?? null)];

        if (isset($lastOfLevel[$key]) && $lastOfLevel[$key] > $pair) {
            $wrong[] = 'level '.$key.': '.implode(',', $lastOfLevel[$key]).' came before '.implode(',', $pair);
        }
        $lastOfLevel[$key] = $pair;
    }

    expect($wrong)->toBe([]);
});

/*
 * ── What a BRANCH holds (item 2, 2026-09-18) ────────────────────────────────────────────────
 *
 * The screen counted products per NODE. On this tree almost everything hangs off leaves, so a
 * section like "ساعات" showed 0 products while thousands sat underneath it.
 *
 * That was confusing on its own and it produced a flat contradiction: the "empty — hidden
 * automatically" badge read the node's own count, while "in the menu" reads the §3.3 rule, which
 * looks at the whole branch. A parent full of products displayed both at once. Folding a branch
 * would have left the row claiming to be empty as the only one on screen.
 *
 * ── And then it counted the wrong thing (D-4, browser walkthrough 2026-09-18) ────────────────
 *
 * The first version SUMMED each node's own count up the tree. A product may be filed under
 * several categories in the same branch — a watch under both `ساعات غوص` and `كرونوغراف` — so
 * every such product was counted once per placement. On storefront 2 the badge read
 * `7823 في الفرع` against a catalogue of 7,713: a branch holding more products than exist.
 *
 * The test below was complicit: it recomputed the SAME sum in the other direction, so both halves
 * agreed on the wrong answer. It now rebuilds the sets from the placement rows, which is a
 * genuinely independent check rather than a second copy of the implementation.
 */

it('counts DISTINCT products in a branch, never placement rows', function () {
    $nodes = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/2/categories')->assertOk())['nodes'] ?? null);

    $subtree = [];
    $parentOf = [];
    foreach ($nodes as $node) {
        $row = T::arr($node);
        $id = T::int($row['id'] ?? null);
        $subtree[$id] = T::int($row['products_subtree'] ?? null);
        $parentOf[$id] = $row['parent_id'] === null ? null : T::int($row['parent_id']);
    }

    /*
     * Rebuilt from the placement rows themselves, with the same "live and visible" predicate the
     * screen uses. Every product is pushed into the SET of each ancestor, so filing one product
     * under two sibling categories adds it to the parent once — which is the whole point.
     */
    $sets = array_fill_keys(array_keys($subtree), []);
    foreach (
        DB::table('storefront_category_product as scp')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', 2);
            })
            ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
            ->where('scp.storefront_id', 2)
            ->where('sp.is_visible', true)
            ->where('cp.is_active', true)
            ->whereNull('cp.deleted_at')
            ->distinct()
            ->get(['scp.storefront_category_id as node', 'scp.product_id as product_id']) as $raw
    ) {
        $row = Row::cast($raw);
        $node = Row::int($row, 'node');
        $product = Row::int($row, 'product_id');
        for ($at = $node; $at !== null; $at = $parentOf[$at] ?? null) {
            if (! array_key_exists($at, $sets)) {
                break;
            }
            $sets[$at][$product] = true;
        }
    }

    $wrong = [];
    foreach ($sets as $id => $products) {
        if (($subtree[$id] ?? -1) !== count($products)) {
            $wrong[] = "node {$id}: screen says ".($subtree[$id] ?? 'missing').', the branch holds '.count($products);
        }
    }

    expect($wrong)->toBe([]);
});

it('never reports a branch holding more products than the catalogue has', function () {
    /*
     * The cheap, blunt check that would have caught D-4 on the day it shipped. It needs no
     * knowledge of the tree at all: a number labelled "products" cannot exceed the number of
     * products, and `7823 في الفرع` on a 7,713-product catalogue was visible to anyone reading
     * the screen.
     */
    $nodes = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/2/categories')->assertOk())['nodes'] ?? null);
    $catalogue = DB::table('catalog_products')->whereNull('deleted_at')->count();

    $impossible = [];
    foreach ($nodes as $node) {
        $row = T::arr($node);
        foreach (['products', 'products_subtree', 'products_any_subtree'] as $key) {
            if (T::int($row[$key] ?? null) > $catalogue) {
                $impossible[] = 'node '.T::int($row['id'] ?? null).": {$key} = ".T::int($row[$key] ?? null);
            }
        }
    }

    expect($impossible)->toBe([], "A node claims to hold more products than the catalogue's {$catalogue}.");
});

it('never says a category is EMPTY while also saying it is in the menu', function () {
    /*
     * The contradiction, asserted directly. `in_menu` is the §3.3 rule's own answer and looks at
     * the branch; the empty badge now reads `products_subtree`. If those two ever disagree again,
     * this is the test that says so — in the words the operator would use, not in column names.
     */
    $both = [];
    foreach ([1, 2] as $storefront) {
        $nodes = T::arr(Props::of(actingAs(Staff::admin())->get("/manage/storefronts/{$storefront}/categories")->assertOk())['nodes'] ?? null);

        foreach ($nodes as $node) {
            $row = T::arr($node);
            if (($row['in_menu'] ?? false) === true && T::int($row['products_subtree'] ?? null) === 0) {
                $both[] = "storefront {$storefront}, node ".T::int($row['id'] ?? null);
            }
        }
    }

    expect($both)->toBe([], 'a node claims to be both in the menu and empty');
});

/*
 * ── The primary-category rule, asserted rather than asserted-on-screen (item 4, 2026-09-18) ──
 *
 * The developer asked for this in writing "because I had to ask", and the product form now states
 * it. A sentence on a screen is a claim, and a claim nobody checks is how a screen ends up lying
 * politely. Each clause of that sentence is checked here against the code and the data:
 *
 *   • a product may sit in SEVERAL categories, and many do;
 *   • exactly one of them is primary, enforced by `PlacementWriter` clearing the previous one;
 *   • the primary is the breadcrumb — `Storefront\ProductDetail` builds it from the primary node
 *     and from nothing else.
 */

it('lets a product sit in several categories with exactly one primary, catalogue-wide', function () {
    $broken = [];

    foreach ([1, 2] as $storefront) {
        $rows = DB::table('storefront_category_product')
            ->where('storefront_id', $storefront)
            ->selectRaw('product_id, COUNT(*) AS nodes, COALESCE(SUM(is_primary), 0) AS primaries')
            ->groupBy('product_id')
            ->get();

        expect($rows->count())->toBeGreaterThan(0, "storefront {$storefront} has no placements");

        $inSeveral = 0;
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            if (Row::int($row, 'primaries') > 1) {
                $broken[] = "storefront {$storefront}, product ".Row::int($row, 'product_id').': '
                    .Row::int($row, 'primaries').' primaries';
            }
            if (Row::int($row, 'nodes') > 1) {
                $inSeveral++;
            }
        }

        // …and the first half of the sentence is true of the real catalogue, not just permitted.
        expect($inSeveral)->toBeGreaterThan(0, "no product on storefront {$storefront} is in more than one category — the sentence claims something the data does not show");
    }

    expect($broken)->toBe([], 'the one-primary rule is broken somewhere');
});

it('moves the primary rather than adding one, which is what makes the rule hold', function () {
    CatalogFixture::assumeSwitched();

    $productId = T::int(DB::table('catalog_products as cp')
        ->join('storefront_category_product as scp', 'scp.product_id', '=', 'cp.id')
        ->where('scp.storefront_id', 1)->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));

    // Two nodes on storefront 1, so there is somewhere for the primary to move TO.
    $nodes = DB::table('storefront_categories')->where('storefront_id', 1)->orderBy('id')->limit(2)->pluck('id')
        ->map(fn (mixed $id): int => T::int($id))->all();

    expect(count($nodes))->toBe(2, 'storefront 1 has fewer than two categories');

    foreach ($nodes as $primary) {
        actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
            'is_visible' => true,
            'is_featured' => false,
            'sort_order' => 0,
            'category_ids' => $nodes,
            'primary_category_id' => $primary,
        ])->assertSessionHasNoErrors();

        $marked = DB::table('storefront_category_product')
            ->where('storefront_id', 1)->where('product_id', $productId)->where('is_primary', true)
            ->pluck('storefront_category_id')->map(fn (mixed $id): int => T::int($id))->all();

        // Exactly one, and it is the one just chosen — not two, which is what would happen if the
        // writer set the new primary without clearing the old.
        expect($marked)->toBe([$primary]);
    }
});
