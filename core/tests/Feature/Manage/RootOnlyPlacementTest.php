<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * REHEARSAL #3 (2026-09-12) — five live products arrived with a `category_type_id` and no
 * `sub_type_id`. The transform places exactly those on the ROOT node, with `is_primary = 0`
 * (audit A-18: "placed at depth 1 only"), and the storefront serves them: the PDP answers 200 and
 * the breadcrumb has one step. That is a legitimate state, not a defect.
 *
 * What was wrong is that NOTHING said so. The products list rendered them like any other placed
 * product, so nobody could see which products sit at the root of a site with no sub-category, and
 * the product form prefills that root as the "primary category" — so an ordinary save would
 * quietly give the product a primary it does not have.
 *
 * These tests hold the visibility, not the behaviour: the state stays allowed, and the screens
 * name it.
 */

/** A product placed on the ROOT only, with no primary — the transform's own shape for A-18. */
function rootOnlyProduct(int $storefrontId = 1): int
{
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false, storefrontId: $storefrontId);

    $root = T::int(DB::table('storefront_categories')
        ->where('storefront_id', $storefrontId)->where('depth', 1)->orderBy('id')->value('id'));

    DB::table('storefront_category_product')->insert([
        'storefront_id' => $storefrontId,
        'storefront_category_id' => $root,
        'product_id' => $productId,
        'sort_order' => 0,
        'is_primary' => 0,          // ← the whole point: a placement with NO primary
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $productId;
}

it('marks a root-only product in the products list, and an ordinary one not at all', function () {
    CatalogFixture::assumeSwitched();
    $rootOnly = rootOnlyProduct();

    $ordinary = CatalogFixture::product();
    CatalogFixture::onStorefront($ordinary, visible: false);
    CatalogFixture::place($ordinary, CatalogFixture::watchesRoot());

    $response = actingAs(Staff::admin())->get('/manage/storefronts/1/products?q='.urlencode(T::str(
        DB::table('catalog_products')->where('id', $rootOnly)->value('wa_code')
    )));
    $response->assertOk();

    $rows = Props::rows(Props::table($response));
    $states = [];
    foreach ($rows as $row) {
        $states[T::int($row['id'])] = T::str($row['placement']);
    }

    expect($states)->toHaveKey($rootOnly)
        ->and($states[$rootOnly])->toBe('root_only');

    // …and the ordinary product is NOT flagged, or the badge would say nothing.
    $second = actingAs(Staff::admin())->get('/manage/storefronts/1/products?q='.urlencode(T::str(
        DB::table('catalog_products')->where('id', $ordinary)->value('wa_code')
    )));
    $second->assertOk();
    foreach (Props::rows(Props::table($second)) as $row) {
        if (T::int($row['id']) === $ordinary) {
            expect(T::str($row['placement']))->toBe('placed');
        }
    }
});

it('marks a product with NO category at all separately from a root-only one', function () {
    // Two different problems: "in no listing at all" and "in the section but under no sub-category".
    // One badge for both would tell the operator to do the wrong thing.
    CatalogFixture::assumeSwitched();
    $unplaced = CatalogFixture::product();
    CatalogFixture::onStorefront($unplaced, visible: false);

    $response = actingAs(Staff::admin())->get('/manage/storefronts/1/products?q='.urlencode(T::str(
        DB::table('catalog_products')->where('id', $unplaced)->value('wa_code')
    )));
    $response->assertOk();

    foreach (Props::rows(Props::table($response)) as $row) {
        if (T::int($row['id']) === $unplaced) {
            expect(T::str($row['placement']))->toBe('none');
        }
    }
});

it('tells the product form that this section has no sub-category today', function () {
    CatalogFixture::assumeSwitched();
    $productId = rootOnlyProduct();

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());

    $states = [];
    foreach (T::arr($props['sections']) as $section) {
        $row = T::arr($section);
        $states[T::int(T::arr($row['storefront'])['id'])] = T::str($row['placement_state']);
    }

    // Storefront 1 is where the root-only placement is; storefront 2 has no placement at all.
    expect($states[1] ?? null)->toBe('root_only')
        ->and($states[2] ?? null)->toBe('none');
});

it('keeps the root-only state SERVED and leaves the transform free to create it', function () {
    /*
     * The visibility must not turn into a refusal by accident. The state is allowed: the writer
     * accepts a save that places a product on a root node, and the list keeps showing the product.
     * (Whether it should become a prevention rule is the developer's call — it is deliberately
     * only surfaced for now.)
     */
    CatalogFixture::assumeSwitched();
    $productId = rootOnlyProduct();
    $root = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->where('depth', 1)->orderBy('id')->value('id'));

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/placement/{$productId}", [
            'is_visible' => false,
            'is_featured' => false,
            'category_ids' => [$root],
            'primary_category_id' => $root,
        ])
        ->assertSessionHasNoErrors();

    expect(T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->count()))->toBe(1);
});
