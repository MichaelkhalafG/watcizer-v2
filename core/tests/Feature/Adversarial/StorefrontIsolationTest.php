<?php

use App\Domain\Catalog\CategoryTreeWriter;
use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\ProductWriter;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvPayload;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * PORTED 2026-09-11 from the wave-4B adversarial review (axes 3 and 5 — "are the two storefronts
 * really separate, and is the pre-switch block a door or a decoration?").
 *
 * These probes matter more than their size suggests: with ONE storefront every claim about N
 * passes vacuously, which is exactly how round 1 of wave 4B shipped two single-storefront screens
 * while its tests were green. So the first assertion in this file is that storefront 2 exists and
 * has a tree of its own.
 */

it('gives the two storefronts trees that share no rows, paired only by the legacy key', function () {
    $nodeIds = fn (int $storefrontId): array => array_map(
        fn (mixed $v): int => T::int($v),
        DB::table('storefront_categories')->where('storefront_id', $storefrontId)->pluck('id')->all()
    );
    $sf1 = $nodeIds(1);
    $sf2 = $nodeIds(2);

    expect($sf2)->not->toBe([], 'storefront 2 must have a tree, or every claim about N below is vacuous')
        ->and(array_values(array_intersect($sf1, $sf2)))->toBe([], 'the two trees share a row');

    /*
     * The trees are INDEPENDENT COPIES: a node on storefront 2 is a different row with a different
     * id, path and slug. What ties the two together for placement equivalence is the legacy origin
     * key (`legacy_source` + `legacy_id`), and that pairing has to exist or the transform cannot
     * place a product "in the same category" on both.
     */
    $pairs = T::int(DB::table('storefront_categories as a')
        ->join('storefront_categories as b', function (JoinClause $join): void {
            $join->on('a.legacy_source', '=', 'b.legacy_source')->on('a.legacy_id', '=', 'b.legacy_id');
        })
        ->where('a.storefront_id', 1)->where('b.storefront_id', 2)
        ->whereNotNull('a.legacy_id')
        ->count());

    expect($pairs)->toBeGreaterThan(0, 'no node pairs by legacy key, so placement equivalence is unmapped');
});

it('hides a product on one storefront without touching the other', function () {
    CatalogFixture::assumeSwitched();
    $bags1 = CatalogFixture::child(CatalogFixture::fashionRoot(1), 'Bags', 'حقائب', 1);
    $bags2 = CatalogFixture::child(CatalogFixture::fashionRoot(2), 'Bags', 'حقائب', 2);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags1['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    // The image travels IN the payload, because a save REPLACES the image set.
    $image = [['path' => 'probe/x.webp', 'is_cover' => true]];

    actingAs(Staff::admin())->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags1['id'], [
        'is_visible' => true, 'images' => $image,
    ]))->assertRedirect();
    actingAs(Staff::admin())->putJson("/manage/storefronts/2/products/{$id}", AdvPayload::product($bags2['id'], [
        'is_visible' => true, 'images' => $image,
    ]))->assertRedirect();

    expect(AdvPayload::isVisible($id, 1))->toBeTrue()
        ->and(AdvPayload::isVisible($id, 2))->toBeTrue();

    // Hide on 2 only, through the placement screen.
    actingAs(Staff::admin())->putJson("/manage/storefronts/2/placement/{$id}", [
        'is_visible' => false, 'is_featured' => false, 'sort_order' => 0,
    ])->assertRedirect();

    expect(AdvPayload::isVisible($id, 1))->toBeTrue('hiding on storefront 2 changed storefront 1')
        ->and(AdvPayload::isVisible($id, 2))->toBeFalse();
});

it('refuses every storefront-1 category route pointed at a storefront-2 node', function () {
    CatalogFixture::assumeSwitched();
    $node2 = CatalogFixture::child(CatalogFixture::fashionRoot(2), 'Foreign', 'أجنبي', 2);
    $admin = Staff::admin();

    actingAs($admin)->putJson("/manage/storefronts/1/categories/{$node2['id']}", [
        'name' => ['ar' => 'مخترق', 'en' => 'Hijacked'], 'is_active' => true,
    ]);
    actingAs($admin)->deleteJson("/manage/storefronts/1/categories/{$node2['id']}");
    actingAs($admin)->putJson("/manage/storefronts/1/categories/{$node2['id']}/move", [
        'parent_id' => null, 'position' => 0,
    ]);

    // Nothing moved, nothing was renamed, and the node still belongs to storefront 2.
    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node2['id'])->where('locale', 'en')->value('name')))
        ->toBe('Foreign', 'a storefront-2 node was RENAMED through a storefront-1 route')
        ->and(DB::table('storefront_categories')->where('id', $node2['id'])->exists())
        ->toBeTrue('a storefront-2 node was DELETED through a storefront-1 route')
        ->and(T::int(DB::table('storefront_categories')->where('id', $node2['id'])->value('storefront_id')))
        ->toBe(2);
});

it('404s an unknown storefront in the URL, never 403 — the id-oracle rule (§3.11.14)', function () {
    // 403 would confirm the storefront exists and is merely forbidden; 404 says nothing. The same
    // answer must come back for "not there" and "not yours".
    expect(actingAs(Staff::admin())->get('/manage/storefronts/9999/products')->getStatusCode())->toBe(404);
});

it('holds the one-primary invariant per storefront across the whole catalogue', function () {
    // Not a fixture claim — the real rows. Two primaries for one product on one storefront is a
    // catalogue that cannot answer "which category owns this product" on a breadcrumb.
    $violations = DB::table('storefront_category_product')
        ->where('is_primary', true)
        ->selectRaw('storefront_id, product_id, count(*) c')
        ->groupBy('storefront_id', 'product_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($violations->count())->toBe(0);
});

it('refuses every CREATION door over HTTP with the flag in its default state', function () {
    expect((bool) config('transform.write_switch_completed'))->toBeFalse('the flag must default to false');
    $admin = Staff::admin();

    // 1. a product.
    $anyNode = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->orderBy('id')->value('id'));
    actingAs($admin)->postJson('/manage/storefronts/1/products', AdvPayload::product($anyNode))->assertStatus(422);

    // 2. a variant on a CATALOGUE-ONLY product, so `ConversionGuard` is not the thing refusing.
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product('fashion');
    config()->set('transform.write_switch_completed', false);
    $sizeId = T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));
    actingAs($admin)->postJson("/manage/products/{$productId}/variants", [
        'label' => 'M', 'size_id' => $sizeId, 'is_active' => true,
    ])->assertStatus(422);

    // 3. a category. 4. a lookup row.
    actingAs($admin)->postJson('/manage/storefronts/1/categories', [
        'name' => ['ar' => 'جديد', 'en' => 'Brand new'], 'parent_id' => null, 'is_active' => true,
    ])->assertStatus(422);
    actingAs($admin)->postJson('/manage/lookups/colors', [
        'name' => ['ar' => 'لون جديد', 'en' => 'Brand new colour'],
    ])->assertStatus(422);

    expect(T::int(DB::table('catalog_product_variants')->where('product_id', $productId)->count()))->toBe(0);
});

it('refuses the same three doors called DIRECTLY, with no request in sight', function () {
    /*
     * The console / importer door. A guard that lives in a controller is a guard a future
     * `php artisan import:something` walks straight past, so the block sits in the writers.
     */
    expect((bool) config('transform.write_switch_completed'))->toBeFalse();

    $outcome = [];
    foreach ([
        'product' => fn () => app(ProductWriter::class)->create([
            'wa_code' => 'importer-'.bin2hex(random_bytes(4)),
            'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
            'selling_price' => 100, 'currency' => 'EGP', 'is_active' => true,
            'title' => ['ar' => 'مستورد', 'en' => 'Imported'],
        ], null),
        'category' => fn () => app(CategoryTreeWriter::class)->create(1, null, ['ar' => 'ج', 'en' => 'Importer node']),
        'lookup' => fn () => app(LookupWriter::class)->create('colors', ['name' => ['ar' => 'ل', 'en' => 'Importer colour']]),
    ] as $name => $call) {
        try {
            $call();
            $outcome[$name] = 'ALLOWED';
        } catch (RuntimeException $e) {
            $outcome[$name] = 'refused';
        }
    }

    expect($outcome)->toBe(['product' => 'refused', 'category' => 'refused', 'lookup' => 'refused']);
});

it('refuses a SECONDARY tree edit pre-switch while the primary tree stays editable', function () {
    /*
     * The sync policy: pre-switch, storefront 2's tree is re-synced from legacy on every run, so an
     * edit there would be silently reverted — it is refused instead. Storefront 1's tree is the
     * one the team trains on and must keep working.
     */
    expect((bool) config('transform.write_switch_completed'))->toBeFalse();
    $admin = Staff::admin();

    $node2 = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->orderBy('id')->value('id'));
    $node1 = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->orderBy('id')->value('id'));

    actingAs($admin)->putJson("/manage/storefronts/2/categories/{$node2}", [
        'name' => ['ar' => 'معدل', 'en' => 'Edited on 2'], 'is_active' => true,
    ])->assertStatus(422);

    actingAs($admin)->putJson("/manage/storefronts/1/categories/{$node1}", [
        'name' => ['ar' => 'معدل', 'en' => 'Edited on 1'], 'is_active' => true,
    ]);

    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $node1)->where('locale', 'en')->value('name')))
        ->toBe('Edited on 1', 'the PRIMARY tree must stay editable pre-switch');
});

it('opens all four doors, and the secondary tree, when the flag flips', function () {
    // ONE flag. The refusals above are not four policies, and this is what proves it.
    $admin = Staff::admin();
    config()->set('transform.write_switch_completed', true);

    actingAs($admin)->postJson('/manage/storefronts/1/categories', [
        'name' => ['ar' => 'بعد التحويل', 'en' => 'After switch'], 'parent_id' => null, 'is_active' => true,
    ])->assertRedirect();

    actingAs($admin)->postJson('/manage/lookups/colors', [
        'name' => ['ar' => 'لون بعد', 'en' => 'After colour'],
    ])->assertRedirect();

    $node = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->orderByDesc('id')->value('id'));
    actingAs($admin)->postJson('/manage/storefronts/1/products', AdvPayload::product($node))->assertRedirect();

    $node2 = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->orderBy('id')->value('id'));
    actingAs($admin)->putJson("/manage/storefronts/2/categories/{$node2}", [
        'name' => ['ar' => 'معدل', 'en' => 'Edited on 2 after switch'], 'is_active' => true,
    ])->assertRedirect();
});

it('leaves NO category deletable anywhere while the flag is false', function () {
    /*
     * The reviewer's reachability sweep: try to delete EVERY node of BOTH storefronts and count
     * how many died. The answer has to be none — every node is either legacy-sourced (a rebuild
     * would bring it back) or holds children or products.
     *
     * It runs inside the test transaction, so a successful delete would be rolled back — but it
     * would also be a hole, and this is how it gets noticed.
     */
    expect((bool) config('transform.write_switch_completed'))->toBeFalse();
    $admin = Staff::admin();

    $deleted = [];
    foreach ([1, 2] as $storefrontId) {
        foreach (DB::table('storefront_categories')->where('storefront_id', $storefrontId)->pluck('id') as $raw) {
            $id = T::int($raw);
            actingAs($admin)->from("/manage/storefronts/{$storefrontId}/categories")
                ->delete("/manage/storefronts/{$storefrontId}/categories/{$id}");
            if (! DB::table('storefront_categories')->where('id', $id)->exists()) {
                $deleted[] = "sf{$storefrontId}:{$id}";
            }
        }
    }

    expect($deleted)->toBe([], 'a category was deletable with the write-switch flag false');
});
