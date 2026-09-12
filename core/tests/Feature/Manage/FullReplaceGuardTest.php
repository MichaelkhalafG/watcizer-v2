<?php

use App\Support\FullReplace;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The two dashboard endpoints that REPLACE a whole record now refuse a caller that has not said so
 * (decision 2026-09-12, after the hazard cost real data).
 *
 * `PUT …/products/{id}` and `PUT /manage/lookups/{list}/{id}` store the payload as the WHOLE row:
 * a key absent from the payload is CLEARED. The screens depend on that — emptying a field is done
 * by submitting it empty — so the semantics are NOT changed. What changed is that the caller must
 * declare completeness, because a partial payload from a script or an importer deletes everything
 * it omits, silently, behind a 302 that reads as a successful save.
 *
 * The evidence these tests exist for: a five-field probe payload PUT at a live product took its
 * grade, watch specs, both descriptions, sale price and every storefront-1 placement with it, and a
 * lookup PUT without `extra.hex` left `catalog/meta` serving `color_value: null`.
 */

it('REFUSES a product save that has not declared the payload complete, and writes nothing', function () {
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: false);

    $before = T::row(DB::table('catalog_products')->where('id', $productId)
        ->first(['wa_code', 'brand_id', 'selling_price', 'grade_id', 'purchase_price']));
    $placementsBefore = T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->count());

    // Exactly the shape that destroyed a live product: the few fields a script happens to know.
    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/products/{$productId}", [
            'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
            'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
            'selling_price' => '999.00',
            'currency' => 'EGP',
            'is_active' => true,
            'title' => ['ar' => 'جزئي', 'en' => 'Partial'],
        ])
        ->assertSessionHasErrors('wa_code');

    // Nothing moved: not the price the payload DID carry, not the columns it omitted, not the
    // placements. A refusal that half-saved would be worse than no guard at all.
    $after = T::row(DB::table('catalog_products')->where('id', $productId)
        ->first(['wa_code', 'brand_id', 'selling_price', 'grade_id', 'purchase_price']));

    expect((array) $after)->toBe((array) $before)
        ->and(T::int(DB::table('storefront_category_product')
            ->where('storefront_id', 1)->where('product_id', $productId)->count()))->toBe($placementsBefore);
});

it('tells the caller what to do instead, in Arabic for the screen and in English for the script', function () {
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/products/{$productId}", ['wa_code' => 'x'])
        ->assertSessionHasErrors('wa_code');

    $message = T::err('wa_code');

    expect($message)->toMatch('/\p{Arabic}/u')
        // The developer half has to name the mechanism, the marker and the alternative — this
        // error is read by whoever wrote the script, not by an operator.
        ->and($message)->toContain('REPLACES the whole record')
        ->and($message)->toContain(FullReplace::MARKER.'=1')
        ->and($message)->toContain('partial-update path');
});

it('accepts the SCREEN payload, which carries the declaration', function () {
    // The other half: the guard must not break the form. This is the form's own shape.
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    $node = CatalogFixture::watchesRoot();
    CatalogFixture::place($productId, $node);
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/products/{$productId}", [
            '_complete' => 1,
            'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
            'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
            'selling_price' => '777.00',
            'currency' => 'EGP',
            'is_active' => true,
            'title' => ['ar' => 'مكتمل', 'en' => 'Complete'],
            'storefronts' => [
                '1' => [
                    'category_ids' => [$node],
                    'primary_category_id' => $node,
                    'is_visible' => false,
                    'is_featured' => false,
                    'sort_order' => 0,
                    'slug' => null,
                ],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(T::str(DB::table('catalog_products')->where('id', $productId)->value('selling_price')))->toBe('777.00');
});

it('REFUSES an undeclared lookup save, and keeps the extras it would have cleared', function () {
    // The exact shape that cleared a colour's hex: names only, no `extra`.
    $colour = T::row(DB::table('catalog_colors')->orderBy('id')->first(['id', 'hex']));
    $id = T::int($colour->id);
    $hexBefore = $colour->hex;

    expect($hexBefore)->not->toBeNull('the fixture colour must have a hex, or this proves nothing');

    actingAs(Staff::admin())
        ->put("/manage/lookups/colors/{$id}", ['name' => ['ar' => 'أحمر', 'en' => 'Red']])
        ->assertSessionHasErrors('name.ar');

    expect(DB::table('catalog_colors')->where('id', $id)->value('hex'))->toBe($hexBefore);
});

it('accepts the lookups screen payload, which carries the declaration and the extras', function () {
    $colour = T::row(DB::table('catalog_colors')->orderBy('id')->first(['id', 'hex']));
    $id = T::int($colour->id);
    $arBefore = DB::table('catalog_color_translations')->where('color_id', $id)->where('locale', 'ar')->value('name');

    actingAs(Staff::admin())
        ->put("/manage/lookups/colors/{$id}", [
            '_complete' => 1,
            'name' => ['ar' => 'أحمر مختبر', 'en' => 'Test red'],
            'extra' => ['hex' => T::str($colour->hex)],
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('catalog_colors')->where('id', $id)->value('hex'))->toBe($colour->hex)
        ->and(DB::table('catalog_color_translations')->where('color_id', $id)->where('locale', 'ar')->value('name'))
        ->toBe('أحمر مختبر')
        ->and($arBefore)->not->toBe('أحمر مختبر', 'the fixture must actually have changed the name');
});

it('treats only an explicit truthy declaration as a declaration', function () {
    // A marker that any value satisfies would be decoration. `0`, `''` and absence are all "no".
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false);

    foreach (['0', '', 'no', 'false'] as $value) {
        actingAs(Staff::admin())
            ->put("/manage/storefronts/1/products/{$productId}", ['_complete' => $value, 'wa_code' => 'x'])
            ->assertSessionHasErrors('wa_code');
    }
});
