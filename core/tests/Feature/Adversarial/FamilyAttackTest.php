<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\AdvPayload;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * PORTED 2026-09-11 from the wave-4B adversarial review (axis 1 — "can anything except the
 * category decide the family?"). The reviewer's probes lived outside the repo and PRINTED their
 * findings; here they are assertions, so the next change to the family rule has to answer them.
 *
 * Two of them ended in `assertTrue(true)` because they were DISCOVERING behaviour — the foreign
 * node and the non-existent node. Both turned out to be defects (review 🟠-1: a foreign id wiped
 * the storefront's placements, a stale id 500'd), so they are asserted here as the refusals the
 * fix introduced rather than as prints.
 *
 * The rule under attack is AGENTS §2.21: the CATEGORY decides the family, never the spec keys.
 */

it('lets the CATEGORY decide even when the spec keys contradict it', function () {
    CatalogFixture::assumeSwitched();
    $perfumes = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Perfumes', 'عطور');

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($perfumes['id'], [
        // `bag_type` and `width_cm` are the BAG family's keys. A resolver that reads the payload
        // would answer `bag`; the category says perfume, and the category is the rule.
        'specs' => ['bag_type' => 'tote', 'width_cm' => '12.5', 'perfume_volume_ml' => 100],
    ]))->assertRedirect();

    $id = AdvPayload::newestProductId();
    $specs = AdvPayload::specsArray($id);

    expect(AdvPayload::family($id))->toBe('perfume')
        ->and($specs)->not->toHaveKey('bag_type')
        ->and($specs)->not->toHaveKey('width_cm')
        ->and($specs['perfume_volume_ml'] ?? null)->toBe(100);
});

it('discards the watch spec ROW when the category moves a product out of the watch family', function () {
    CatalogFixture::assumeSwitched();
    $watchNode = CatalogFixture::watchesRoot();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $shape = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($watchNode, [
        'specs' => ['case_size' => '42.0', 'case_shape_id' => $shape, 'watch_box' => true],
    ]))->assertRedirect();
    $id = AdvPayload::newestProductId();

    expect(AdvPayload::family($id))->toBe('watch')
        ->and(DB::table('catalog_product_watch_specs')->where('product_id', $id)->exists())->toBeTrue();

    // The move, still posting the OLD family's fields — the shape of a real edit, where the form
    // state carries what the operator typed before they changed the category.
    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($bags['id'], [
        'specs' => ['case_size' => '42.0', 'case_shape_id' => $shape, 'watch_box' => true, 'bag_type' => 'tote'],
    ]))->assertRedirect();

    $specs = AdvPayload::specsArray($id);

    expect(AdvPayload::family($id))->toBe('bag')
        ->and(DB::table('catalog_product_watch_specs')->where('product_id', $id)->exists())
        ->toBeFalse('the watch spec row outlived the family — an orphan the storefront would read')
        ->and($specs['bag_type'] ?? null)->toBe('tote')
        ->and($specs)->not->toHaveKey('case_size');
});

it('survives two category changes in one session, keeping no keys from either previous family', function () {
    CatalogFixture::assumeSwitched();
    $fashion = CatalogFixture::fashionRoot();
    $bags = CatalogFixture::child($fashion, 'Bags', 'حقائب');
    $wallets = CatalogFixture::child($fashion, 'Wallets', 'محافظ');
    $watch = CatalogFixture::watchesRoot();

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags['id'], [
        'specs' => ['bag_type' => 'tote', 'width_cm' => '20'],
    ]))->assertRedirect();
    $id = AdvPayload::newestProductId();
    expect(AdvPayload::family($id))->toBe('bag');

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($wallets['id'], [
        'specs' => ['wallet_card_slots' => 8, 'width_cm' => '10'],
    ]))->assertRedirect();
    expect(AdvPayload::family($id))->toBe('wallet');

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($watch, [
        'specs' => ['case_size' => '40'],
    ]))->assertRedirect();

    expect(AdvPayload::family($id))->toBe('watch')
        // A watch keeps its specs in a typed table, so the JSON column must be NULL — not left
        // holding the wallet keys, which is how a stale block reappears on a screen.
        ->and(AdvPayload::specs($id))->toBeNull()
        ->and(DB::table('catalog_product_watch_specs')->where('product_id', $id)->exists())->toBeTrue();
});

it('falls back to the CONFIGURED default for a root the config does not know', function () {
    CatalogFixture::assumeSwitched();
    $shoes = CatalogFixture::root('Shoes', 'أحذية');
    $default = T::str(config('transform.family.default'));

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($shoes['id'], [
        'specs' => ['bag_type' => 'sneaker'],
    ]))->assertRedirect();

    expect(AdvPayload::family(AdvPayload::newestProductId()))->toBe($default);
});

it('REFUSES a storefront-2 node submitted on the storefront-1 product route (review 🟠-1)', function () {
    /*
     * The reviewer's A1.5 printed what happened; what happened was data loss. The foreign id
     * passed validation, `place()` filtered it out as not-of-this-storefront, the desired set came
     * out EMPTY, and every storefront-1 placement of the product was deleted. Asserted here as the
     * refusal, with the surviving placement as the half that matters.
     */
    CatalogFixture::assumeSwitched();
    $bags1 = CatalogFixture::child(CatalogFixture::fashionRoot(1), 'Bags', 'حقائب', 1);
    $perfume2 = CatalogFixture::child(CatalogFixture::fashionRoot(2), 'Perfumes', 'عطور', 2);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags1['id'], [
        'specs' => ['bag_type' => 'tote'],
    ]))->assertRedirect();
    $id = AdvPayload::newestProductId();

    expect(AdvPayload::family($id))->toBe('bag')
        ->and(AdvPayload::placements($id))->toBe(1);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($perfume2['id'], [
        'specs' => ['perfume_volume_ml' => 50],
    ]))->assertSessionHasErrors(['storefronts.1.category_ids.0', 'storefronts.1.primary_category_id']);

    expect(AdvPayload::placements($id))->toBe(1, 'the refused write must not have emptied the placement set')
        ->and(AdvPayload::placements($id, 2))->toBe(0)
        ->and(AdvPayload::family($id))->toBe('bag', 'and the family may not have moved either');
});

it('REFUSES a node id that does not exist at all, with a field error and no 500 (review 🟠-1)', function () {
    // A1.6. Before the fix the id reached `FamilyForCategory`, which threw
    // "Category node N does not exist" — a 500 on the operator's screen.
    CatalogFixture::assumeSwitched();
    $bags1 = CatalogFixture::child(CatalogFixture::fashionRoot(1), 'Bags', 'حقائب', 1);
    actingAs(Staff::admin())->post('/manage/storefronts/1/products', AdvPayload::product($bags1['id']))->assertRedirect();
    $id = AdvPayload::newestProductId();

    $ghost = T::int(DB::table('storefront_categories')->max('id')) + 99999;

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$id}", AdvPayload::product($ghost))
        ->assertSessionHasErrors(['storefronts.1.category_ids.0', 'storefronts.1.primary_category_id']);

    expect(AdvPayload::family($id))->toBe('bag')
        ->and(AdvPayload::placements($id))->toBe(1);
});
