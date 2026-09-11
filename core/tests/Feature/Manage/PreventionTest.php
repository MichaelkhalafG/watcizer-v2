<?php

use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;

/*
 * TASK 4.2 — "the UI must PREVENT mistakes, not warn about them."
 *
 * One test per rule, and each asserts BOTH halves, because either alone is a half-measure:
 *
 *   • the SERVER refuses (a disabled control is presentation, never the control);
 *   • the SCREEN says so before the click, with the reason and what to do instead.
 *
 * The team varies in skill and the least careful operator is the one this file is written for.
 * Nothing here is a matter of taste: every rule below is already enforced by the database, by the
 * storefront's rendering, or by a locked decision — the change is that it is now unreachable
 * rather than discouraged.
 */

/**
 * A valid product-edit payload for one storefront, so each test can break exactly one thing.
 *
 * @param  array<string, mixed>  $section
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function editPayload(int $productId, array $section = [], array $overrides = []): array
{
    $watches = CatalogFixture::watchesRoot();

    return array_merge([
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'منتج اختبار المنع', 'en' => 'Prevention test'],
        'storefronts' => [
            '1' => array_merge([
                'category_ids' => [$watches],
                'primary_category_id' => $watches,
                'is_visible' => false,
                'is_featured' => false,
                'sort_order' => 0,
                'slug' => null,
            ], $section),
        ],
    ], $overrides);
}

it('BLOCKS visibility without an Arabic title, on the server and on the control', function () {
    // AGENTS §2.17: translation fallback is OFF, so a product with no Arabic title renders an
    // empty name on an Arabic-first storefront. This is the locked decision, made unreachable.
    $productId = CatalogFixture::productWithoutArabic();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, ['is_visible' => true], [
            'title' => ['ar' => '', 'en' => 'No Arabic'],
        ]))
        ->assertSessionHasErrors();

    expect(Row::bool(T::one(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)), 'is_visible'))
        ->toBeFalse();

    // …and the screen already knew: `missing_arabic` names the field, which is what the switch's
    // disabled reason is built from.
    $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    expect(T::arr($props['missing_arabic']))->not->toBe([]);
});

it('BLOCKS visibility without an image', function () {
    // A listing card is an image and a price. Every one of the 464 live products has an image, so
    // the gate costs the team nothing and stops the one mistake it exists for.
    $productId = CatalogFixture::productWithoutImage();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, ['is_visible' => true]))
        ->assertSessionHasErrors('storefronts.1.is_visible');

    expect(Row::bool(T::one(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)), 'is_visible'))
        ->toBeFalse();
    expect(T::err('storefronts.1.is_visible'))->toContain('صورة');
});

it('BLOCKS visibility with no category on THAT storefront', function () {
    // "Visible" has to mean reachable. A product in no category appears in no listing and under no
    // menu item; the only way to it is a URL nobody has.
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, [
            'category_ids' => [],
            'primary_category_id' => null,
            'is_visible' => true,
        ]))
        ->assertSessionHasErrors('storefronts.1.is_visible');

    expect(T::err('storefronts.1.is_visible'))->toContain('تصنيف');
});

it('REFUSES a save with categories chosen and no primary among them', function () {
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false);
    $watches = CatalogFixture::watchesRoot();

    // Categories, no primary.
    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, [
            'category_ids' => [$watches],
            'primary_category_id' => null,
        ]))
        ->assertSessionHasErrors('storefronts.1.primary_category_id');

    // A primary that was never chosen — which the DB's one-primary key could only turn into an
    // error later, and which `place()` used to silently correct to the first id.
    $other = CatalogFixture::fashionRoot();
    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, [
            'category_ids' => [$watches],
            'primary_category_id' => $other,
        ]))
        ->assertSessionHasErrors('storefronts.1.primary_category_id');
});

it('REFUSES a CREATE with no category on the storefront that decides the family', function () {
    CatalogFixture::assumeSwitched();

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', [
        'wa_code' => 'prevent-'.bin2hex(random_bytes(4)),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '100.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'بلا تصنيف', 'en' => 'No category'],
        'storefronts' => ['1' => [
            'category_ids' => [], 'primary_category_id' => null,
            'is_visible' => false, 'is_featured' => false, 'sort_order' => 0, 'slug' => null,
        ]],
    ])->assertSessionHasErrors('storefronts.1.category_ids');

    expect(T::err('storefronts.1.category_ids'))->toContain('مواصفات');
});

it('REJECTS a negative price and a non-numeric one at the field', function () {
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId, visible: false);

    foreach (['-5.00', 'abc', ''] as $bad) {
        actingAs(Staff::dataEntry())
            ->put("/manage/storefronts/1/products/{$productId}", editPayload($productId, [], ['selling_price' => $bad]))
            ->assertSessionHasErrors('selling_price');
    }

    // And the price stayed what it was — a refused save writes nothing.
    expect(T::str(DB::table('catalog_products')->where('id', $productId)->value('selling_price')))->toBe('500.00');
});

it('REFUSES a duplicate slug inside one storefront, by name, instead of silently suffixing it', function () {
    /*
     * This one CHANGED behaviour, and the old behaviour is the point: `uniqueSlug()` appended `-2`
     * and saved. The operator typed `rolex-daytona`, got `rolex-daytona-2`, and found out by
     * reading the URL some time later. A silent correction is the same failure as a warning
     * nobody reads.
     *
     * A DERIVED slug (the field left empty) is still suffixed — there the suffix is the answer.
     */
    // A hand-typed slug is only possible post-switch (review 🟠-3), and the rule under test here
    // is the duplicate refusal, not the lock.
    CatalogFixture::assumeSwitched();

    $first = CatalogFixture::product();
    $second = CatalogFixture::product();
    CatalogFixture::place($first, CatalogFixture::watchesRoot());
    CatalogFixture::place($second, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($first, visible: false);
    CatalogFixture::onStorefront($second, visible: false);

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$first}", editPayload($first, ['slug' => 'taken-slug']))
        ->assertSessionHasNoErrors();

    actingAs(Staff::dataEntry())
        ->put("/manage/storefronts/1/products/{$second}", editPayload($second, ['slug' => 'taken-slug']))
        ->assertSessionHasErrors('storefronts.1.slug');

    expect(T::err('storefronts.1.slug'))->toContain('مستخدم بالفعل')
        // The second product did NOT quietly become `taken-slug-2`.
        ->and(T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $second)->value('slug')))
        ->not->toBe('taken-slug-2');
});

it('ALLOWS the same slug on a DIFFERENT storefront, because that is what the schema says', function () {
    // `sp_storefront_slug_unique` is (storefront_id, slug). Two sites are two domains; the same
    // product answering `/product/x` on both is the correct answer, not a collision.
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    $watches = CatalogFixture::watchesRoot();
    $bfNode = CatalogFixture::anyNodeOf(2);
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '500.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج', 'en' => 'Product'],
        'storefronts' => [
            '1' => ['category_ids' => [$watches], 'primary_category_id' => $watches, 'is_visible' => false, 'is_featured' => false, 'sort_order' => 0, 'slug' => 'shared-across-sites'],
            '2' => ['category_ids' => [$bfNode], 'primary_category_id' => $bfNode, 'is_visible' => false, 'is_featured' => false, 'sort_order' => 0, 'slug' => 'shared-across-sites'],
        ],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('slug')))->toBe('shared-across-sites')
        ->and(T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $productId)->value('slug')))->toBe('shared-across-sites');
});

it('REFUSES a variant that names neither a colour nor a size', function () {
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'بلا لون ولا مقاس',
        'is_active' => true,
    ])->assertSessionHasErrors('color_id');

    expect(DB::table('catalog_product_variants')->where('product_id', $productId)->count())->toBe(0);
    expect(T::err('color_id'))->toContain('لونًا أو مقاسًا');
});

it('SURFACES the delete refusal on the variant row instead of after the click', function () {
    // The three refusals are wave 3.5's and are already enforced (AGENTS §2.22). What task 4.2
    // adds is that the panel receives the reason per row, so the control is disabled and says why.
    // `synthetic()` opens each variant's stock through `InventoryService`, so every row already
    // has LEDGER MOVEMENTS — which is refusal number three (AGENTS §2.22) and the one that exists
    // because `inventory_movements.variant_id` is ON DELETE SET NULL. No order line needed: that
    // one is asserted in `VariantPanelTest`, and `order_items.product_id` points at the LEGACY
    // products table, so a catalogue-only fixture could not carry one anyway.
    $fixture = VariantFixture::synthetic();
    $productId = T::int($fixture['product']);

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    $rows = T::arr(T::arr($props['variants'])['rows']);

    $blocked = 0;
    foreach ($rows as $raw) {
        $row = T::arr($raw);
        if ($row['may_delete'] === false) {
            $blocked++;
            expect(T::str($row['delete_blocked_reason'] ?? ''))->not->toBe('', 'a refusal with no reason is a dead end');
        }
    }
    expect($blocked)->toBeGreaterThan(0);
});

it('never offers data-entry an action the server would refuse', function () {
    /*
     * "Any action data-entry cannot perform must be absent or disabled with a reason, never a 403
     * after the click." The navigation is the place this is decided, so it is asserted there: a
     * data-entry session is offered no item whose ability it does not hold.
     */
    $nav = Props::navItems(actingAs(Staff::dataEntry())->get('/manage')->assertOk());

    expect($nav)->not->toBe([]);
    foreach ($nav as $item) {
        $href = T::str(T::arr($item)['href'] ?? '');
        if ($href === '' || ! str_starts_with($href, '/manage')) {
            continue;
        }
        // Every offered link must actually open for this role. A 403 or a 404 here would be the
        // dashboard advertising a door it locks.
        actingAs(Staff::dataEntry())->get($href)->assertOk();
    }
});
