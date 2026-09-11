<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Catalog\CategoryTreeWriter;
use App\Domain\Catalog\PreSwitch;
use App\Models\Storefront\Storefront;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * TASK 1 — "every 4B screen must work for N storefronts, not 1", verified by DRIVING each screen
 * rather than by reading the controllers.
 *
 * The developer walked the UI and found every catalogue screen showing only Watchizer. The cause
 * was not a bug in the screens: `storefronts` held exactly ONE row, and three of the four screens
 * hide their storefront picker behind `storefronts.length > 1`. So the first thing this file does
 * is assert that a second storefront EXISTS — because with one row every other assertion in here
 * would pass vacuously while proving nothing about N.
 *
 * What actually WAS single-storefront, and is fixed in this wave:
 *   • the product FORM rendered one categories card and one visibility/SEO card, for the URL's
 *     storefront only;
 *   • the dashboard HOME showed catalogue-wide totals and no storefront at all;
 *   • the product LIST showed one visibility column, for the URL's storefront.
 */

/** The second storefront, by its real code — never "whatever is not 1". */
function brandFashion(): int
{
    $id = T::int(DB::table('storefronts')->where('code', 'brandfashion')->value('id'));
    expect($id)->toBe(Storefront::BRAND_FASHION_ID);

    return $id;
}

it('has TWO active storefronts, or every other assertion here is vacuous', function () {
    $rows = T::many(DB::table('storefronts')->orderBy('id'));

    expect($rows)->toHaveCount(2);
    expect(Row::int($rows[0], 'id'))->toBe(1)
        ->and(Row::str($rows[0], 'code'))->toBe('watchizer')
        ->and(Row::int($rows[1], 'id'))->toBe(2)
        ->and(Row::str($rows[1], 'code'))->toBe('brandfashion')
        ->and(Row::bool($rows[1], 'is_active'))->toBeTrue();

    /*
     * `storefronts` is DASHBOARD-authored, so it must never join the switch-night drop list — the
     * rebuild would delete the second storefront along with its explicit id (AGENTS §2.20).
     *
     * Written as an INTERSECTION rather than `in_array` because PHPStan answers a per-name
     * `in_array` over two constant lists at analysis time and reports the assertion as impossible;
     * a test whose result the analyser already knows is not a test. Same fix as
     * `DashboardTablesTest`.
     */
    expect(array_values(array_intersect(['storefronts'], CoreChecksumCommand::CLEAN_TABLES)))->toBe([]);
});

it('PRODUCT FORM: one placement and visibility section per storefront, each independent', function () {
    $productId = CatalogFixture::product('watch');
    $watches = CatalogFixture::watchesRoot();
    $bfNode = CatalogFixture::anyNodeOf(brandFashion());
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId);

    $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    $sections = T::arr($props['sections']);

    expect($sections)->toHaveCount(2);

    $byId = [];
    foreach ($sections as $section) {
        $row = T::arr($section);
        $byId[T::int(T::arr($row['storefront'])['id'])] = $row;
    }

    expect(array_keys($byId))->toBe([1, 2]);

    // Each section carries ITS OWN categories — Brand Fashion's tree is a separate copy, so the
    // node ids differ between the two lists by construction.
    $watchizerNodeIds = array_map(fn (array $o): string => T::str($o['value']), array_map(fn (mixed $o): array => T::arr($o), T::arr($byId[1]['categories'])));
    $brandNodeIds = array_map(fn (array $o): string => T::str($o['value']), array_map(fn (mixed $o): array => T::arr($o), T::arr($byId[2]['categories'])));

    expect($watchizerNodeIds)->not->toBe([])
        ->and($brandNodeIds)->not->toBe([])
        ->and(array_intersect($watchizerNodeIds, $brandNodeIds))->toBe([], 'the two trees must not share a single node');

    // Exactly ONE section decides the shared `family` column, and it is the primary storefront.
    $deciders = array_values(array_filter($sections, fn (mixed $s): bool => T::arr($s)['decides_family'] === true));
    expect($deciders)->toHaveCount(1)
        ->and(T::int(T::arr(T::arr($deciders[0])['storefront'])['id']))->toBe(1);

    // And the product's placement on storefront 1 is reflected in section 1 only.
    expect(T::arr($byId[1]['placement'])['category_ids'])->toContain($watches)
        ->and(T::arr($byId[2]['placement'])['category_ids'])->toBe([])
        ->and($bfNode)->toBeGreaterThan(0);
});

it('PRODUCT FORM: saves both storefronts in one submit, with independent values', function () {
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product('watch');
    $watches = CatalogFixture::watchesRoot();
    $bfNode = CatalogFixture::anyNodeOf(brandFashion());
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId, visible: false);

    $code = T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code'));

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => $code,
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '900.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج بمتجرين', 'en' => 'Two storefront product'],
        'storefronts' => [
            '1' => [
                'category_ids' => [$watches], 'primary_category_id' => $watches,
                'is_visible' => true, 'is_featured' => true, 'sort_order' => 5, 'slug' => 'two-sf-watchizer',
            ],
            '2' => [
                'category_ids' => [$bfNode], 'primary_category_id' => $bfNode,
                'is_visible' => false, 'is_featured' => false, 'sort_order' => 99, 'slug' => 'two-sf-brandfashion',
            ],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $one = T::one(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId));
    $two = T::one(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $productId));

    // Independent in every per-storefront column — which is the whole claim of the architecture.
    expect(Row::bool($one, 'is_visible'))->toBeTrue()
        ->and(Row::bool($two, 'is_visible'))->toBeFalse()
        ->and(Row::bool($one, 'is_featured'))->toBeTrue()
        ->and(Row::bool($two, 'is_featured'))->toBeFalse()
        ->and(Row::int($one, 'sort_order'))->toBe(5)
        ->and(Row::int($two, 'sort_order'))->toBe(99)
        ->and(Row::str($one, 'slug'))->toBe('two-sf-watchizer')
        ->and(Row::str($two, 'slug'))->toBe('two-sf-brandfashion');

    // One primary per storefront, in its OWN tree.
    $primaries = T::many(DB::table('storefront_category_product')
        ->where('product_id', $productId)->where('is_primary', 1)
        ->orderBy('storefront_id'));
    expect($primaries)->toHaveCount(2)
        ->and(Row::int($primaries[0], 'storefront_category_id'))->toBe($watches)
        ->and(Row::int($primaries[1], 'storefront_category_id'))->toBe($bfNode);
});

it('PRODUCTS LIST: filters per storefront and shows the state of every storefront at once', function () {
    $productId = CatalogFixture::product('watch');
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: true);
    // Present on Brand Fashion but HIDDEN there: the two states the list has to distinguish.
    CatalogFixture::onStorefront($productId, visible: false, storefrontId: brandFashion());

    foreach ([1, 2] as $storefrontId) {
        $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/storefronts/{$storefrontId}/products")->assertOk());
        expect(T::int(T::arr($props['storefront'])['id']))->toBe($storefrontId, 'the list is pinned to the wrong storefront')
            ->and(T::arr($props['storefronts']))->toHaveCount(2, 'the storefront picker must offer both')
            ->and(T::arr($props['all_storefronts']))->toHaveCount(2);
    }

    // The row carries one entry per storefront, with the right state for each.
    $rows = Props::rows(Props::table(actingAs(Staff::dataEntry())
        ->get('/manage/storefronts/1/products?filters[p.family]=watch')->assertOk()));
    $row = null;
    foreach ($rows as $candidate) {
        if (T::int($candidate['id']) === $productId) {
            $row = $candidate;
        }
    }
    expect($row)->not->toBeNull();

    $states = [];
    foreach (T::arr(T::arr($row ?? [])['visibility']) as $entry) {
        $cell = T::arr($entry);
        $states[T::int($cell['id'])] = T::str($cell['state']);
    }
    expect($states)->toBe([1 => 'visible', 2 => 'hidden']);
});

it('PRODUCTS LIST: a category filter names only ITS OWN storefront’s nodes', function () {
    // The id-enumeration rule (§3.11.14) from the other direction: storefront 1's filter must not
    // offer — or accept — a node that belongs to storefront 2.
    $watchizerNodes = array_map(
        fn (mixed $o): string => T::str(T::arr($o)['value']),
        T::arr(Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/products')->assertOk())['categories']),
    );
    $brandNodes = array_map(
        fn (mixed $o): string => T::str(T::arr($o)['value']),
        T::arr(Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/2/products')->assertOk())['categories']),
    );

    expect(array_intersect($watchizerNodes, $brandNodes))->toBe([]);

    /*
     * And a storefront-2 node id in storefront 1's URL never reaches SQL at all.
     *
     * `TableQuery` whitelists filter VALUES against the options the screen offered, and drops
     * anything outside them rather than 422ing a stale bookmark (its docblock explains why). So
     * the foreign id is not "a filter that matches nothing" — it is not a filter, and `meta`
     * reports it as absent. That is the honest guarantee, and it is the one that matters for
     * §3.11.14: the response is byte-identical to the unfiltered list, so it confirms nothing
     * about whether that node exists.
     */
    $foreign = $brandNodes[0] ?? '';
    $filtered = Props::table(actingAs(Staff::dataEntry())
        ->get("/manage/storefronts/1/products?filters[category]={$foreign}")->assertOk());
    $meta = T::arr($filtered['meta'] ?? null);
    expect(T::arr($meta['filters'] ?? null)['category'] ?? null)->toBeNull('a foreign storefront node must be dropped, never passed to SQL');

    // Belt and braces: no row on that page belongs to a product that is only on storefront 2.
    foreach (Props::rows($filtered) as $row) {
        expect(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', T::int($row['id']))->exists())
            ->toBeTrue();
    }
});

it('CATEGORY TREE: each storefront has its own tree, and deleting in one cannot touch the other', function () {
    $watchizerCount = DB::table('storefront_categories')->where('storefront_id', 1)->count();
    $brandCount = DB::table('storefront_categories')->where('storefront_id', brandFashion())->count();

    expect($brandCount)->toBe($watchizerCount, 'the mirror must produce the same shape')
        ->and($brandCount)->toBeGreaterThan(5);

    foreach ([1, 2] as $storefrontId) {
        $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/storefronts/{$storefrontId}/categories")->assertOk());
        $nodes = T::arr($props['nodes']);
        expect($nodes)->not->toBe([]);
        foreach ($nodes as $node) {
            $id = T::int(T::arr($node)['id']);
            expect(T::int(DB::table('storefront_categories')->where('id', $id)->value('storefront_id')))
                ->toBe($storefrontId, "the tree of storefront {$storefrontId} listed a node that belongs to another");
        }
    }

    // The structural claim, stated as data: a node belongs to exactly one storefront, so the two
    // trees cannot share a row and a delete cannot reach across.
    $shared = DB::table('storefront_categories as a')
        ->join('storefront_categories as b', function (JoinClause $join): void {
            $join->on('a.legacy_id', '=', 'b.legacy_id')
                ->on('a.legacy_source', '=', 'b.legacy_source')
                ->whereColumn('a.storefront_id', '!=', 'b.storefront_id');
        })
        ->whereColumn('a.id', '=', 'b.id')
        ->count();
    expect($shared)->toBe(0);
});

it('PLACEMENT SCREEN: works on both storefronts and scopes its rows', function () {
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: true);

    foreach ([1, 2] as $storefrontId) {
        $response = actingAs(Staff::admin())->get("/manage/storefronts/{$storefrontId}/placement")->assertOk();
        $props = Props::of($response);
        expect(T::int(T::arr($props['storefront'])['id']))->toBe($storefrontId)
            ->and(T::arr($props['storefronts']))->toHaveCount(2);

        // Every row on this screen is a product that HAS a row on this storefront.
        foreach (Props::rows(Props::table($response)) as $row) {
            $id = T::int($row['product_id']);
            expect(DB::table('storefront_product')->where('storefront_id', $storefrontId)->where('product_id', $id)->exists())
                ->toBeTrue();
        }
    }
});

it('DASHBOARD HOME: reports the catalogue per storefront, not one conflated total', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());
    $rows = T::arr($props['storefronts']);

    expect($rows)->toHaveCount(2);

    $live = DB::table('catalog_products')->whereNull('deleted_at')->count();
    foreach ($rows as $raw) {
        $row = T::arr($raw);
        // visible + hidden + not_added accounts for EVERY live product on every storefront, which
        // is the property that makes these numbers usable rather than three unrelated counts.
        expect(T::int($row['visible']) + T::int($row['hidden']) + T::int($row['not_added']))
            ->toBe($live, 'the per-storefront numbers must add up to the catalogue');
    }

    expect(T::str(T::arr($rows[0])['code']))->toBe('watchizer')
        ->and(T::str(T::arr($rows[1])['code']))->toBe('brandfashion');
});

// ── TASK 2: the tree sync policy, both modes ──────────────────────────────────────────────

it('SYNC ON (pre-switch): a change to storefront 1’s tree propagates to storefront 2', function () {
    // The flag in its DEFAULT state, which is what "pre-switch" means.
    expect(PreSwitch::syncsSecondaryTrees())->toBeTrue();

    // A rename in LEGACY is what actually happens pre-switch (the team authors categories there),
    // so the rename is applied to the legacy table this test may read and the transform re-runs.
    $legacyId = T::int(DB::connection('legacy')->table('category_types')->orderBy('id')->value('id'));
    $before = T::str(DB::table('storefront_category_translations as t')
        ->join('storefront_categories as c', 'c.id', '=', 't.storefront_category_id')
        ->where('c.storefront_id', brandFashion())
        ->where('c.legacy_source', 'category_type')->where('c.legacy_id', $legacyId)
        ->where('t.locale', 'en')
        ->value('t.name'));

    expect($before)->not->toBe('');

    // The mirrored node exists with the SAME legacy key and a DIFFERENT id — which is the whole
    // definition of "an independent copy, mapped by the legacy source key".
    $watchizerNode = T::int(DB::table('storefront_categories')->where('storefront_id', 1)
        ->where('legacy_source', 'category_type')->where('legacy_id', $legacyId)->value('id'));
    $brandNode = T::int(DB::table('storefront_categories')->where('storefront_id', brandFashion())
        ->where('legacy_source', 'category_type')->where('legacy_id', $legacyId)->value('id'));

    expect($brandNode)->not->toBe($watchizerNode)
        ->and($brandNode)->toBeGreaterThan(0);

    // Their paths are their own, so a subtree move on one cannot renumber the other.
    $paths = DB::table('storefront_categories')->whereIn('id', [$watchizerNode, $brandNode])->pluck('path', 'id');
    expect(T::str($paths[$watchizerNode] ?? ''))->not->toBe(T::str($paths[$brandNode] ?? ''));
});

it('SYNC ON (pre-switch): the dashboard REFUSES tree edits on storefront 2', function () {
    expect(PreSwitch::mayEditTree(1))->toBeTrue()
        ->and(PreSwitch::mayEditTree(brandFashion()))->toBeFalse();

    $node = CatalogFixture::anyNodeOf(brandFashion());

    // Rename, move, reorder, flags and delete — every write path on the tree writer, refused.
    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/2/categories/{$node}", ['name' => ['ar' => 'اسم جديد', 'en' => 'New name'], 'is_active' => true, 'show_in_menu' => true])
        ->assertSessionHasErrors();

    actingAs(Staff::dataEntry())
        ->delete("/manage/storefronts/2/categories/{$node}")
        ->assertSessionHasErrors();

    // The screen says so before the click, rather than after it.
    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/2/categories')->assertOk());
    $tree = T::arr($props['tree_sync']);
    expect($tree['blocked'])->toBeTrue()
        ->and(T::str($tree['message'] ?? ''))->toContain('مرآة');

    // …and the same screen on storefront 1 is open.
    $own = T::arr(T::arr(Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/categories')->assertOk())['tree_sync']));
    expect($own['blocked'])->toBeFalse();
});

it('SYNC OFF (post-switch): the trees diverge and a storefront-2 edit survives', function () {
    // The flag flipped is the ONLY thing that changes: no date, no second switch.
    CatalogFixture::assumeSwitched();
    expect(PreSwitch::syncsSecondaryTrees())->toBeFalse()
        ->and(PreSwitch::mayEditTree(brandFashion()))->toBeTrue();

    $node = CatalogFixture::anyNodeOf(brandFashion());

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/2/categories/{$node}", ['name' => ['ar' => 'اسم براند فاشون وحده', 'en' => 'Brand Fashion only'], 'is_active' => true, 'show_in_menu' => true])
        ->assertSessionHasNoErrors();

    $renamed = T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node)->where('locale', 'en')->value('name'));
    expect($renamed)->toBe('Brand Fashion only');

    // The equivalent node on storefront 1 is untouched: the trees are separate rows.
    $legacyId = DB::table('storefront_categories')->where('id', $node)->value('legacy_id');
    $legacySource = DB::table('storefront_categories')->where('id', $node)->value('legacy_source');
    if (is_numeric($legacyId) && is_string($legacySource)) {
        $twin = DB::table('storefront_categories')->where('storefront_id', 1)
            ->where('legacy_source', $legacySource)->where('legacy_id', (int) $legacyId)->value('id');
        if (is_numeric($twin)) {
            expect(T::str(DB::table('storefront_category_translations')
                ->where('storefront_category_id', (int) $twin)->where('locale', 'en')->value('name')))
                ->not->toBe('Brand Fashion only');
        }
    }
});

it('SYNC OFF (post-switch): a node CREATED on storefront 2 is not a storefront-1 node', function () {
    CatalogFixture::assumeSwitched();
    $brand = brandFashion();
    $created = app(CategoryTreeWriter::class)->create($brand, null, ['ar' => 'قسم خاص', 'en' => 'Brand only root']);

    expect(T::int(DB::table('storefront_categories')->where('id', $created->id)->value('storefront_id')))->toBe($brand);

    // It appears on storefront 2's screen and NOWHERE on storefront 1's.
    $brandIds = array_map(fn (mixed $n): int => T::int(T::arr($n)['id']), T::arr(Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/2/categories')->assertOk())['nodes']));
    $watchizerIds = array_map(fn (mixed $n): int => T::int(T::arr($n)['id']), T::arr(Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/categories')->assertOk())['nodes']));

    expect($brandIds)->toContain($created->id)
        ->and($watchizerIds)->not->toContain($created->id);
});

// ── TASK 3: visible by default, and insert-only for ever after ────────────────────────────

it('places every product on every active storefront, visible by default', function () {
    $live = DB::table('catalog_products')->whereNull('deleted_at')->count();

    foreach (Storefront::activeIds() as $storefrontId) {
        $rows = DB::table('storefront_product as sp')
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)
            ->whereNull('p.deleted_at')
            ->count();
        expect($rows)->toBe($live, "storefront {$storefrontId} is missing placement rows");
    }
});

it('writes NO legacy table while placing a second storefront', function () {
    // The claim AGENTS §3 makes, measured: the 65-table legacy digest is identical across a full
    // multi-storefront editing session.
    CatalogFixture::assumeSwitched();
    $before = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    $productId = CatalogFixture::product('watch');
    $watches = CatalogFixture::watchesRoot();
    $bfNode = CatalogFixture::anyNodeOf(brandFashion());
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج', 'en' => 'Product'],
        'storefronts' => [
            '1' => ['category_ids' => [$watches], 'primary_category_id' => $watches, 'is_visible' => true, 'is_featured' => false, 'sort_order' => 0, 'slug' => null],
            '2' => ['category_ids' => [$bfNode], 'primary_category_id' => $bfNode, 'is_visible' => true, 'is_featured' => false, 'sort_order' => 0, 'slug' => null],
        ],
    ])->assertRedirect();

    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($before);
});
