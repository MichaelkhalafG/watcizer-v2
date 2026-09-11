<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvPayload;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * PORTED 2026-09-11 from the wave-4B adversarial review (axis 2 — "is each guarantee enforced
 * where a human acts, or only in the UI?"). Every probe below bypasses the screen and posts
 * directly, which is the only way to tell a guard from a disabled button.
 *
 * Three of the reviewer's probes printed rather than asserted; all three assert here:
 *   • the cross-storefront slug (allowed — uniqueness is per storefront, by schema),
 *   • the unslugifiable slug (now REFUSED — review 🟡-4, it used to become the product id),
 *   • the refused destroy (what the operator actually gets back).
 */

it('refuses to make a product VISIBLE with no image, by direct request', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    actingAs(Staff::admin())
        ->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], ['is_visible' => true]));

    expect(AdvPayload::isVisible($id))->toBeFalse('a product with no image was made VISIBLE by a direct request');
});

it('refuses to make a product VISIBLE with no Arabic title, by direct request', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();
    AdvPayload::addImage($id);

    // Strip the Arabic row behind the writer's back — the shape a legacy product with no `ar`
    // translation actually has.
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->delete();

    actingAs(Staff::admin())->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], [
        'is_visible' => true,
        'title' => ['ar' => '', 'en' => 'Prevention probe'],
    ]));

    expect(AdvPayload::isVisible($id))->toBeFalse('a product with no Arabic was made VISIBLE by a direct request');
});

it('refuses to make a product VISIBLE with no category on this storefront', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();
    AdvPayload::addImage($id);

    actingAs(Staff::admin())->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], [
        'is_visible' => true,
        'category_ids' => [],
        'primary_category_id' => null,
    ]));

    expect(AdvPayload::isVisible($id))->toBeFalse('an unplaced product was made VISIBLE by a direct request');
});

it('refuses a negative price and keeps the stored one', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    actingAs(Staff::admin())
        ->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], ['selling_price' => '-5.00']))
        ->assertStatus(422);

    expect(T::str(DB::table('catalog_products')->where('id', $id)->value('selling_price')))->toBe('1200.00');
});

it('ALLOWS the same slug on two storefronts, because uniqueness is per storefront', function () {
    // Printed by the reviewer, asserted here: `sp_storefront_slug_unique` is (storefront_id, slug),
    // and two sites are two domains. The same product answering `/product/x` on both is correct.
    CatalogFixture::assumeSwitched();
    $bags1 = CatalogFixture::child(CatalogFixture::fashionRoot(1), 'Bags', 'حقائب', 1);
    $bags2 = CatalogFixture::child(CatalogFixture::fashionRoot(2), 'Bags', 'حقائب', 2);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags1['id'], [
        'slug' => 'adv-cross-sf',
    ]))->assertRedirect();
    $id = AdvPayload::newestProductId();

    actingAs(Staff::admin())->putJson("/manage/storefronts/2/products/{$id}", AdvPayload::product($bags2['id'], [
        'slug' => 'adv-cross-sf',
    ]))->assertSessionHasNoErrors();

    expect(AdvPayload::slug($id, 1))->toBe('adv-cross-sf')
        ->and(AdvPayload::slug($id, 2))->toBe('adv-cross-sf');
});

it('REFUSES a slug that slugifies to nothing, instead of storing the product id (review 🟡-4)', function () {
    /*
     * The reviewer typed «ساعة رولكس» and then 🙂🙂🙂 and printed what was stored. What was stored
     * was the PRODUCT ID: `Str::slug()` returned an empty string and the writer fell back to the
     * id, so the operator got `/product/5312` and no message. Two of the three slug corrections
     * already refused by name; this one now refuses too.
     */
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();
    $before = AdvPayload::slug($id);

    foreach (['ساعة رولكس', '🙂🙂🙂'] as $requested) {
        actingAs(Staff::admin())
            ->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], ['slug' => $requested]))
            ->assertSessionHasErrors('storefronts.1.slug');

        expect(T::err('storefronts.1.slug'))->toMatch('/\p{Arabic}/u')
            ->and(AdvPayload::slug($id))->toBe($before, 'a refused slug changed the stored one')
            ->and(AdvPayload::slug($id))->not->toBe((string) $id, 'the slug silently became the product id');
    }
});

it('refuses a variant with neither colour nor size, in all three spellings of nothing', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    $rows = fn (): int => T::int(DB::table('catalog_product_variants')->where('product_id', $id)->count());
    $before = $rows();

    // Absent keys, explicit nulls, and empty strings — a dimensionless variant is not a variant,
    // and "" is the spelling an HTML form actually sends.
    foreach ([
        ['label' => 'no-dimension row', 'is_active' => true],
        ['label' => 'explicit nulls', 'color_id' => null, 'size_id' => null, 'is_active' => true],
        ['label' => 'empty strings', 'color_id' => '', 'size_id' => '', 'is_active' => true],
    ] as $payload) {
        actingAs(Staff::admin())->postJson("/manage/products/{$id}/variants", $payload)->assertStatus(422);
    }

    expect($rows())->toBe($before);
});

it('refuses to delete a variant that has ledger history, and one that still holds units', function () {
    CatalogFixture::assumeSwitched();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $sizeId = T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    // (a) history, zero units: given 5 then taken back to 0, so the row holds nothing and HAS a past.
    actingAs(Staff::admin())->postJson("/manage/products/{$id}/variants", [
        'label' => 'M', 'size_id' => $sizeId, 'is_active' => true,
    ])->assertRedirect();
    $withHistory = T::int(DB::table('catalog_product_variants')->where('product_id', $id)->orderByDesc('id')->value('id'));

    app(InventoryService::class)->set(StockTarget::variant($id, $withHistory), 'express', 5, 'adjustment');
    app(InventoryService::class)->set(StockTarget::variant($id, $withHistory), 'express', 0, 'adjustment');

    expect(T::int(DB::table('inventory_movements')->where('variant_id', $withHistory)->count()))
        ->toBeGreaterThan(0, 'the fixture must really have written ledger rows');

    actingAs(Staff::admin())->deleteJson("/manage/products/{$id}/variants/{$withHistory}");
    expect(DB::table('catalog_product_variants')->where('id', $withHistory)->exists())
        ->toBeTrue('a variant with ledger movements was DELETED (AGENTS §2.22)');

    // (b) units on hand.
    actingAs(Staff::admin())->postJson("/manage/products/{$id}/variants", [
        'label' => 'L', 'size_id' => $sizeId, 'is_active' => true, 'stock_express' => 7,
    ])->assertRedirect();
    $holdingUnits = T::int(DB::table('catalog_product_variants')->where('product_id', $id)->orderByDesc('id')->value('id'));

    actingAs(Staff::admin())->deleteJson("/manage/products/{$id}/variants/{$holdingUnits}");
    expect(DB::table('catalog_product_variants')->where('id', $holdingUnits)->exists())
        ->toBeTrue('a variant holding units was DELETED');
});

it('refuses data-entry the admin-only storefront screens, on the server', function () {
    $de = Staff::dataEntry();
    $before = T::str(DB::table('storefronts')->where('id', 1)->value('name'));

    $update = actingAs($de)->putJson('/manage/storefronts/1', [
        'name' => 'hijacked', 'code' => 'watchizer', 'is_active' => true,
    ]);

    expect($update->getStatusCode())->toBeIn([403, 404])
        ->and(T::str(DB::table('storefronts')->where('id', 1)->value('name')))->toBe($before);
});

it('gives a customer with a legacy SuperAdmin type no access at all', function () {
    // `users.type` is the LEGACY admin gate and means nothing here: access comes from
    // `core_user_roles`. A customer row carrying the legacy value must still be refused.
    $customer = Staff::customer();

    expect(actingAs($customer)->get('/manage')->getStatusCode())->not->toBe(200);
});

it('refuses a primary category outside the chosen set, and a missing primary', function () {
    CatalogFixture::assumeSwitched();
    $fashion = CatalogFixture::fashionRoot();
    $bags = CatalogFixture::child($fashion, 'Bags', 'حقائب');
    $wallets = CatalogFixture::child($fashion, 'Wallets', 'محافظ');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    actingAs(Staff::admin())->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], [
        'category_ids' => [$bags['id']],
        'primary_category_id' => $wallets['id'],
    ]))->assertStatus(422);

    actingAs(Staff::admin())->putJson("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], [
        'category_ids' => [$bags['id'], $wallets['id']],
        'primary_category_id' => null,
    ]))->assertStatus(422);
});

it('refuses to convert a LIVE product to variants before the write-switch', function () {
    // Deliberately NOT assumeSwitched(): this is the real, default state, and the guard is the
    // one that keeps the storefront's stock reads consistent through the transition.
    $liveId = T::int(DB::table('catalog_products')
        ->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->value('id'));
    $sizeId = T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));

    actingAs(Staff::admin())->postJson("/manage/products/{$liveId}/variants", [
        'label' => 'M', 'size_id' => $sizeId, 'is_active' => true,
    ]);

    expect(T::int(DB::table('catalog_product_variants')->where('product_id', $liveId)->count()))
        ->toBe(0, 'a LIVE product was converted to variants before the write-switch');
});

it('tells the operator WHY a destroy was refused, and changes nothing', function () {
    /*
     * The reviewer's destroy probe printed three cases. Asserted here, because "what comes back"
     * is the whole difference between a screen a non-technical operator can use and one they
     * cannot: a node that holds products must say so, and a legacy-sourced node must say that a
     * rebuild would bring it back.
     */
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();

    // (a) a node holding products, on its own storefront.
    $held = T::int(DB::table('storefront_category_product')->where('storefront_id', 1)->value('storefront_category_id'));
    actingAs($admin)->from('/manage/storefronts/1/categories')
        ->delete("/manage/storefronts/1/categories/{$held}")
        ->assertSessionHasErrors('tree');

    expect(T::err('tree'))->toMatch('/\p{Arabic}/u')
        ->and(DB::table('storefront_categories')->where('id', $held)->exists())->toBeTrue();

    // (b) a legacy-sourced node with no children and no products: still refused, because the
    // rebuild recreates it — the screen offers "disable" instead.
    $legacyEmpty = DB::table('storefront_categories')
        ->where('storefront_id', 1)->whereNotNull('legacy_source')
        ->whereNotIn('id', DB::table('storefront_category_product')->select('storefront_category_id'))
        ->whereNotIn('id', DB::table('storefront_categories')->whereNotNull('parent_id')->select('parent_id'))
        ->value('id');

    expect($legacyEmpty)->not->toBeNull('the catalogue must hold at least one empty legacy leaf, or this proves nothing');

    actingAs($admin)->from('/manage/storefronts/1/categories')
        ->delete('/manage/storefronts/1/categories/'.T::int($legacyEmpty))
        ->assertSessionHasErrors('tree');

    expect(DB::table('storefront_categories')->where('id', T::int($legacyEmpty))->exists())->toBeTrue()
        ->and(T::err('tree'))->toMatch('/\p{Arabic}/u');
});
