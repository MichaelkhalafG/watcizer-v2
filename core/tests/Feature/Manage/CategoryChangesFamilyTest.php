<?php

use App\Domain\Catalog\FamilyForCategory;
use App\Domain\Catalog\SpecBlocks;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * TASK 4.1 — "changing the category on an existing product does not change which fields are
 * required/shown" (developer observation, 2026-09-11).
 *
 * This file is the reproduction and then the guard. It exists as its own file because the rule it
 * defends is AGENTS §2.21 — the family is derived from the CATEGORY, by the same rule the transform
 * uses — and the legacy dashboard had to be hotfixed for breaking exactly it. The failure mode is
 * not "a wrong label": it is a bag stored with `family = watch`, whose spec block the team can
 * never fill in and whose storefront card reads from the wrong table.
 *
 * Three separate things have to follow the category, and they are asserted separately because they
 * broke separately:
 *
 *   1. the FAMILY the server stores;
 *   2. the VALIDATION RULES the server applies (the block's fields, not the previous block's);
 *   3. what the BROWSER renders before any round trip — the form must not need a reload.
 */

/**
 * A complete, valid payload placed in ONE category — the form's own shape, written here rather
 * than shared with `ProductFormTest` because a Pest helper only exists while its file is loaded
 * and a test that passes only when run with its neighbours is not a test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function movePayload(int $node, array $overrides = []): array
{
    return array_merge([
        'wa_code' => '4b-fam-'.bin2hex(random_bytes(4)),
        'sku' => null,
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '1200.00',
        'purchase_price' => '800.00',
        'sale_price' => null,
        'currency' => 'EGP',
        'low_stock_threshold' => 5,
        'is_active' => true,
        'title' => ['ar' => 'منتج اختبار', 'en' => 'Family move test'],
        'short_description' => ['ar' => 'وصف', 'en' => 'Description'],
        'specs' => [],
        'images' => [],
        'feature_ids' => [],
        'gender_ids' => [],
        'colors' => [],
        'category_ids' => [$node],
        'primary_category_id' => $node,
        'is_visible' => false,
        'is_featured' => false,
        'sort_order' => 0,
        'slug' => null,
    ], $overrides);
}

/**
 * One category option out of the form's props, by node id.
 *
 * A loop rather than an id-keyed map on purpose: PHPStan cannot prove a `(string) $int` is a key
 * of an `array<string, …>`, and building a map to then assert it has a key is more machinery than
 * finding the row.
 *
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function categoryOption(array $props, int $nodeId): array
{
    foreach (T::arr($props['categories'] ?? null) as $option) {
        $row = T::arr($option);
        if (T::str($row['value'] ?? null) !== (string) $nodeId) {
            continue;
        }
        // Re-keyed as strings: `T::arr()` cannot promise the key type, and this function's
        // callers read named fields off it.
        $out = [];
        foreach ($row as $field => $value) {
            $out[(string) $field] = $value;
        }

        return $out;
    }

    expect(false)->toBeTrue("the form did not offer category {$nodeId}");

    return [];
}

/** The `catalog_products` row, fresh. */
function productById(int $id): stdClass
{
    return T::one(DB::table('catalog_products')->where('id', $id));
}

it('moves a product from the WATCH family to a fashion one, server-side', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $shapeId = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));

    // A watch, with a real watch spec row.
    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', movePayload($watches, [
        'wa_code' => '4b-move-'.bin2hex(random_bytes(4)),
        'specs' => ['case_size' => '42.5', 'case_shape_id' => $shapeId, 'watch_box' => true],
    ]))->assertRedirect();

    $id = Row::int(T::one(DB::table('catalog_products')->orderByDesc('id')), 'id');
    expect(Row::str(productById($id), 'family'))->toBe('watch')
        ->and(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $id)->exists())->toBeTrue();

    // Now the same product is moved to Bags, and the payload no longer carries watch specs.
    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$id}", movePayload($bags['id'], [
        'wa_code' => Row::str(productById($id), 'wa_code'),
        'specs' => ['bag_type' => 'tote', 'bag_compartments' => 3],
    ]))->assertRedirect();

    $moved = productById($id);
    expect(Row::str($moved, 'family'))->toBe('bag')
        // The watch row is GONE, not orphaned: it is keyed by product id, and a stale row would
        // keep answering for a product that is no longer a watch.
        ->and(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $id)->exists())->toBeFalse()
        ->and(Row::nstr($moved, 'specs'))->toContain('bag_type');
});

it('moves a product from a fashion family back to WATCH, and the block comes with it', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');
    $shapeId = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', movePayload($bags['id'], [
        'wa_code' => '4b-back-'.bin2hex(random_bytes(4)),
        'specs' => ['bag_type' => 'tote'],
    ]))->assertRedirect();
    $id = Row::int(T::one(DB::table('catalog_products')->orderByDesc('id')), 'id');
    expect(Row::str(productById($id), 'family'))->toBe('bag');

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$id}", movePayload($watches, [
        'wa_code' => Row::str(productById($id), 'wa_code'),
        'specs' => ['case_size' => '40', 'case_shape_id' => $shapeId],
    ]))->assertRedirect();

    $moved = productById($id);
    expect(Row::str($moved, 'family'))->toBe('watch')
        ->and(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $id)->exists())->toBeTrue()
        // The bag JSON is cleared rather than carried: the product has a different block now, and
        // keeping keys no form will ever show is how orphaned data starts.
        ->and(Row::nstr($moved, 'specs'))->toBeNull();
});

it('lets the CATEGORY win over spec keys left over from the old family', function () {
    /*
     * THE REAL DEFECT, and the reason this file exists.
     *
     * `FamilyResolver` reads three inputs IN ORDER: the root category name, then the *prefixes of
     * the specs JSON keys*, then the node's own name. That middle rule is a LEGACY-DATA heuristic —
     * step 6 uses it to classify imported products whose categories say nothing useful.
     *
     * In the dashboard it was actively harmful. When the team moved a bag to a PERFUME category,
     * the browser still held `bag_type` in the form state, the payload carried it, and the prefix
     * rule fired BEFORE the node's name was ever considered: the server stored `family = bag` for
     * a product the operator had just filed under Perfumes. The category did not decide. That is
     * AGENTS §2.21 broken by the same mechanism as the legacy bug it was written for — a second
     * input deciding what the category is supposed to decide.
     */
    CatalogFixture::assumeSwitched();
    $fashion = CatalogFixture::fashionRoot();
    $bags = CatalogFixture::child($fashion, 'Bags', 'حقائب');
    $perfumes = CatalogFixture::child($fashion, 'Perfumes', 'عطور');

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', movePayload($bags['id'], [
        'wa_code' => '4b-trap-'.bin2hex(random_bytes(4)),
        'specs' => ['bag_type' => 'tote', 'strap_length_cm' => 60],
    ]))->assertRedirect();
    $id = Row::int(T::one(DB::table('catalog_products')->orderByDesc('id')), 'id');
    expect(Row::str(productById($id), 'family'))->toBe('bag');

    // The move to Perfumes, with the bag keys STILL in the payload — exactly what the browser
    // sends when the operator changes the category and does not clear the old block by hand.
    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$id}", movePayload($perfumes['id'], [
        'wa_code' => Row::str(productById($id), 'wa_code'),
        'specs' => ['bag_type' => 'tote', 'strap_length_cm' => 60, 'perfume_volume_ml' => 100],
    ]))->assertRedirect();

    expect(Row::str(productById($id), 'family'))
        ->toBe('perfume', 'the chosen category must decide the family, not a leftover spec key');
});

it('validates the block the CATEGORY names, not the one the product used to have', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $fashion = CatalogFixture::fashionRoot();
    $perfumes = CatalogFixture::child($fashion, 'Perfumes', 'عطور');

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', movePayload($watches, [
        'wa_code' => '4b-rules-'.bin2hex(random_bytes(4)),
        'specs' => ['case_size' => '42'],
    ]))->assertRedirect();
    $id = Row::int(T::one(DB::table('catalog_products')->orderByDesc('id')), 'id');
    $code = Row::str(productById($id), 'wa_code');

    // Moving to Perfumes and sending a BAD perfume value must fail on the PERFUME rule. If the
    // server were still validating the watch block, this payload would sail through (no watch key
    // is present at all) and store nothing.
    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$id}", movePayload($perfumes['id'], [
        'wa_code' => $code,
        'specs' => ['perfume_volume_ml' => 'not-a-number'],
    ]))->assertSessionHasErrors('specs.perfume_volume_ml');

    // …and the same field, valid, is accepted and stored.
    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$id}", movePayload($perfumes['id'], [
        'wa_code' => $code,
        'specs' => ['perfume_volume_ml' => 100],
    ]))->assertRedirect();

    expect(Row::str(productById($id), 'family'))->toBe('perfume')
        ->and(Row::nstr(productById($id), 'specs'))->toContain('perfume_volume_ml');
});

it('ships the family of EVERY category to the browser, so the block follows without a reload', function () {
    /*
     * The client half. `SpecBlock` renders `blocks[explanation.family]`, and the form used to
     * compute `shownFamily` by spreading the server's answer and overwriting only `node_id` and
     * `reason` — so the REASON text changed when the category changed and the BLOCK did not. The
     * screen looked reactive and was not.
     *
     * The fix is not a second implementation of the rule in TypeScript (that is how two rules
     * drift, and the docblock that promised a "shallow mirror" never had one). The server resolves
     * the family for every node it offers and ships it with the option, so the browser LOOKS UP
     * what the server would decide instead of deciding anything.
     */
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    $response = actingAs(Staff::dataEntry())->get('/manage/storefronts/1/products/create')->assertOk();
    $props = Props::of($response);

    $watchOption = categoryOption($props, $watches);
    $bagOption = categoryOption($props, T::int($bags['id']));

    expect($watchOption['family'] ?? null)
        ->toBe('watch', 'every category option must carry the family the SERVER resolves for it')
        ->and($bagOption['family'] ?? null)
        ->toBe('bag', 'a fashion-root node named Bags is a bag, by the sub-type rule');

    // And the option carries the REASON too, so the screen can explain the block it just swapped
    // in without asking the server.
    expect(T::str($bagOption['family_reason'] ?? null))->not->toBe('');
});

it('agrees with the server for every node the form offers', function () {
    /*
     * The map the browser trusts is only useful if it is the same answer the save will reach. This
     * walks EVERY option on the form and compares it against `FamilyForCategory` directly — the
     * class the writer calls — so a future edit that computes the map some cheaper way is caught.
     */
    CatalogFixture::assumeSwitched();
    CatalogFixture::child(CatalogFixture::fashionRoot(), 'Wallets', 'محافظ');
    CatalogFixture::child(CatalogFixture::watchesRoot(), 'Chronograph', 'كرونوغراف');

    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/products/create')->assertOk());
    $families = app(FamilyForCategory::class);

    $checked = 0;
    $wrong = [];
    foreach (T::arr($props['categories']) as $option) {
        $row = T::arr($option);
        $id = (int) T::str($row['value']);
        $shipped = T::str($row['family'] ?? '');
        $server = $families->forNode($id);
        if ($shipped !== $server) {
            $wrong[] = "node {$id}: shipped [{$shipped}] but the server resolves [{$server}]";
        }
        $checked++;
    }

    expect($wrong)->toBe([])->and($checked)->toBeGreaterThan(3);
});

it('tells the operator which spec values the move will discard', function () {
    // The honest answer to "what happens to the specs I already filled in": they are DROPPED,
    // because the block they belong to is not on the product any more. That is the right
    // behaviour — a bag has no case diameter — but it must never be silent, so the form is given
    // the previous family and its fields and says so before the save.
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $shapeId = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', movePayload($watches, [
        'wa_code' => '4b-warn-'.bin2hex(random_bytes(4)),
        'specs' => ['case_size' => '42', 'case_shape_id' => $shapeId],
    ]))->assertRedirect();
    $id = Row::int(T::one(DB::table('catalog_products')->orderByDesc('id')), 'id');

    $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/storefronts/1/products/{$id}/edit")->assertOk());
    $family = T::arr($props['family']);

    expect($family['family'] ?? null)->toBe('watch')
        // The saved family, named as such: the form compares it with the family of whatever
        // category is chosen now and warns when they differ.
        ->and(T::str($family['saved_family'] ?? ''))->toBe('watch')
        ->and(T::arr($props['blocks']))->toHaveKey('watch');
});
