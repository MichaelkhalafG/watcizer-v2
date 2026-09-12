<?php

use App\Domain\Catalog\CategoryTreeWriter;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * REVIEW 🟠-1 — a category id in the product payload was never checked against the storefront it
 * was submitted under, and that single omission had two faces:
 *
 *   1. **A stale node 500s.** Operator A opens the product form; operator B deletes an empty
 *      dashboard-created category; operator A saves. The id no longer exists, so the placement
 *      write hit a foreign-key failure instead of a field error.
 *   2. **A crafted payload reaches ANOTHER storefront's tree.** `storefronts[1][category_ids]`
 *      carrying a storefront-2 node id was accepted by validation; `place()` then filtered the id
 *      out as "not a node of this storefront" and, seeing an empty set, DELETED every existing
 *      storefront-1 placement for that product. A write scoped to storefront 1 silently wiped
 *      storefront 1's own data because the attacker named storefront 2.
 *
 * Both close with the same rule: every submitted category id must exist IN THE STOREFRONT OF THE
 * SECTION IT WAS SUBMITTED UNDER. The rules are generated per section key rather than with a
 * `storefronts.*` wildcard, because the wildcard cannot know which storefront id to scope to —
 * which is exactly why the check was missing in the first place.
 */

/**
 * A complete product payload with one storefront section, so a test can break exactly one id.
 *
 * @param  array<string, mixed>  $section
 * @return array<string, mixed>
 */
function scopePayload(int $productId, array $section, int $storefrontId = 1): array
{
    return [
        // The screen's full-replace declaration (App\Support\FullReplace).
        '_complete' => 1,
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'منتج نطاق', 'en' => 'Scope product'],
        'storefronts' => [
            (string) $storefrontId => array_merge([
                'category_ids' => [],
                'primary_category_id' => null,
                'is_visible' => false,
                'is_featured' => false,
                'sort_order' => 0,
                'slug' => null,
            ], $section),
        ],
    ];
}

it('REFUSES a category id that was deleted while the form was open, with a field error', function () {
    // Symptom 1, as the reviewer staged it: two operators, one form, one delete in between.
    CatalogFixture::assumeSwitched();
    $keep = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $doomed = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Doomed', 'محكوم');

    $productId = CatalogFixture::product('fashion');
    CatalogFixture::place($productId, $keep['id']);
    CatalogFixture::onStorefront($productId, visible: false);

    // Operator B deletes the empty, dashboard-created node — which is permitted.
    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$doomed['id']}")->assertSessionHasNoErrors();
    expect(DB::table('storefront_categories')->where('id', $doomed['id'])->exists())->toBeFalse();

    // Operator A saves the form they opened before the delete.
    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", scopePayload($productId, [
            'category_ids' => [$keep['id'], $doomed['id']],
            'primary_category_id' => $doomed['id'],
        ]))
        // A FIELD error, not a 500 and not a silent partial write.
        ->assertSessionHasErrors(['storefronts.1.category_ids.1', 'storefronts.1.primary_category_id']);

    // And the product still sits where it did: a refused save writes nothing.
    expect(T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->count()))->toBe(1)
        ->and(T::int(DB::table('storefront_category_product')
            ->where('storefront_id', 1)->where('product_id', $productId)->value('storefront_category_id')))
        ->toBe($keep['id']);
});

it('REFUSES another storefront’s node in this storefront’s section, and keeps the placements', function () {
    /*
     * Symptom 2, and the assertion that matters is the SECOND one: before the fix this request
     * returned a redirect (success) and left the product placed NOWHERE on storefront 1.
     */
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $foreign = CatalogFixture::anyNodeOf(Storefront::BRAND_FASHION_ID);

    $productId = CatalogFixture::product('watch');
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId, visible: false);

    expect(T::int(DB::table('storefront_categories')->where('id', $foreign)->value('storefront_id')))
        ->toBe(Storefront::BRAND_FASHION_ID, 'the fixture must hand us a node of the OTHER storefront');

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", scopePayload($productId, [
            'category_ids' => [$foreign],
            'primary_category_id' => $foreign,
        ]))
        ->assertSessionHasErrors(['storefronts.1.category_ids.0', 'storefronts.1.primary_category_id']);

    // The placement that existed is untouched — this is the data-loss half of the finding.
    $rows = T::many(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId));
    expect($rows)->toHaveCount(1)
        ->and(Row::int($rows[0], 'storefront_category_id'))->toBe($watches);

    // …and nothing was written into the other storefront either.
    expect(DB::table('storefront_category_product')
        ->where('storefront_id', Storefront::BRAND_FASHION_ID)->where('product_id', $productId)
        ->where('storefront_category_id', $foreign)->exists())->toBeFalse();
});

it('REFUSES a stale node on the CREATE path too, not only on save', function () {
    /*
     * The reviewer's stale-form probe drove BOTH endpoints, and so does this: an operator who
     * leaves the "new product" form open across somebody else's delete is the same race, and a
     * create has no existing row to fall back on — before the fix it reached family derivation
     * and 500'd there.
     */
    CatalogFixture::assumeSwitched();
    $keep = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $doomed = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Doomed', 'محكوم');

    actingAs(Staff::admin())->delete("/manage/storefronts/1/categories/{$doomed['id']}")->assertSessionHasNoErrors();

    $before = T::int(DB::table('catalog_products')->count());

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', [
        'wa_code' => 'stale-create-'.bin2hex(random_bytes(4)),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'منتج جديد', 'en' => 'New product'],
        'storefronts' => [
            '1' => [
                'category_ids' => [$keep['id'], $doomed['id']],
                'primary_category_id' => $doomed['id'],
                'is_visible' => false,
                'is_featured' => false,
                'sort_order' => 0,
                'slug' => null,
            ],
        ],
    ])->assertSessionHasErrors(['storefronts.1.category_ids.1', 'storefronts.1.primary_category_id']);

    // A refused create writes NO product at all — not a half-made row for the operator to find.
    expect(T::int(DB::table('catalog_products')->count()))->toBe($before);
});

it('accepts each storefront’s OWN nodes in the same submit', function () {
    // The rule must scope per SECTION, not per request: one submit carries storefront 1's nodes in
    // section 1 and storefront 2's in section 2, and both are legitimate.
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $brandNode = CatalogFixture::anyNodeOf(Storefront::BRAND_FASHION_ID);

    $productId = CatalogFixture::product('watch');
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        '_complete' => 1,
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج', 'en' => 'Product'],
        'storefronts' => [
            '1' => ['category_ids' => [$watches], 'primary_category_id' => $watches, 'is_visible' => false, 'is_featured' => false, 'sort_order' => 0, 'slug' => null],
            '2' => ['category_ids' => [$brandNode], 'primary_category_id' => $brandNode, 'is_visible' => false, 'is_featured' => false, 'sort_order' => 0, 'slug' => null],
        ],
    ])->assertSessionHasNoErrors();

    expect(DB::table('storefront_category_product')->where('storefront_id', 1)->where('product_id', $productId)->where('storefront_category_id', $watches)->exists())->toBeTrue()
        ->and(DB::table('storefront_category_product')->where('storefront_id', 2)->where('product_id', $productId)->where('storefront_category_id', $brandNode)->exists())->toBeTrue();
});

it('REFUSES a node that never existed, on the placement screen too', function () {
    // The same rule on the other write path: `PlacementController::update()` takes the same two
    // keys, and it had the same hole.
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: false);

    $ghost = T::int(DB::table('storefront_categories')->max('id')) + 5000;

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/placement/{$productId}", [
            'is_visible' => false, 'is_featured' => false,
            'category_ids' => [$ghost], 'primary_category_id' => $ghost,
        ])
        ->assertSessionHasErrors(['category_ids.0', 'primary_category_id']);

    expect(T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->count()))->toBe(1);
});

it('REFUSES a foreign node on the placement screen and keeps the placements', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $foreign = CatalogFixture::anyNodeOf(Storefront::BRAND_FASHION_ID);

    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/placement/{$productId}", [
            'is_visible' => false, 'is_featured' => false,
            'category_ids' => [$foreign], 'primary_category_id' => $foreign,
        ])
        ->assertSessionHasErrors(['category_ids.0', 'primary_category_id']);

    expect(T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->value('storefront_category_id')))
        ->toBe($watches);
});

it('still lets a category be created, moved and used in the same session', function () {
    // A scoping rule that refused legitimate ids would be worse than the hole it closes, so the
    // ordinary path is asserted right beside the two refusals.
    CatalogFixture::assumeSwitched();
    $root = CatalogFixture::root('Scope root', 'جذر النطاق');
    $child = CatalogFixture::child($root['id'], 'Scope child', 'فرع النطاق');

    $productId = CatalogFixture::product('fashion');
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", scopePayload($productId, [
            'category_ids' => [$root['id'], $child['id']],
            'primary_category_id' => $child['id'],
        ]))
        ->assertSessionHasNoErrors();

    // …and after a MOVE the same ids stay valid, because the rule is about the storefront and not
    // about the position in the tree.
    app(CategoryTreeWriter::class)->move(1, $child['id'], null);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", scopePayload($productId, [
            'category_ids' => [$child['id']],
            'primary_category_id' => $child['id'],
        ]))
        ->assertSessionHasNoErrors();

    expect(T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->count()))->toBe(1);
});
