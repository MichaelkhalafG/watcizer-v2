<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Support\ArabicSearch;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The product form: creating, editing, and the rules that make a save refuse.
 *
 * The family-derivation half lives in FamilySpecsTest; this file is everything else the form
 * writes — translations, the spec block, images, pivots, the price contract, the search index, and
 * the per-storefront half.
 */

/**
 * A complete, valid payload. Individual tests override one key to prove one rule.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        '_complete' => 1,   // the screen's full-replace declaration (App\Support\FullReplace)
        'wa_code' => '4b-form-'.bin2hex(random_bytes(4)),
        'sku' => null,
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '1200.00',
        'purchase_price' => '800.00',
        'sale_price' => null,
        'currency' => 'EGP',
        'low_stock_threshold' => 5,
        'is_active' => true,
        'title' => ['ar' => 'ساعة اختبار', 'en' => 'Test watch'],
        'short_description' => ['ar' => 'وصف', 'en' => 'Description'],
        // Sent because this payload is a full REPLACE and several of these tests edit a
        // VISIBLE product: omitting it would blank the description and the writer refuses
        // that outright ({@see ProductWriter::assertVisibleProductStaysComplete()}).
        'long_description' => ['ar' => 'وصف تفصيلي', 'en' => 'Long description'],
        'specs' => [],
        'images' => [],
        'feature_ids' => [],
        'gender_ids' => [],
        'colors' => [],
        // A category by default, because since task 4.2 a CREATE must be placed on the storefront
        // that decides the family: a product with no category has no family, and a spec block
        // picked by the configured default is not a decision anybody made. Tests that care about
        // a different family override both keys.
        'category_ids' => [CatalogFixture::watchesRoot()],
        'primary_category_id' => CatalogFixture::watchesRoot(),
        'is_visible' => false,
        'is_featured' => false,
        'sort_order' => 0,
        'slug' => null,
    ], $overrides);
}

it('creates a product with both locales, a category and a spec block', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();
    $shapeId = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));
    $unitId = T::int(DB::table('catalog_units')->orderBy('id')->value('id'));

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', productPayload([
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        'specs' => [
            'case_size' => '42.5',
            'case_size_unit_id' => $unitId,
            'case_shape_id' => $shapeId,
            'watch_box' => true,
        ],
    ]))->assertRedirect();

    $product = T::one(DB::table('catalog_products')->orderByDesc('id'));
    $productId = Row::int($product, 'id');

    expect(Row::str($product, 'family'))->toBe('watch')
        // Stock starts at zero and is untouched by the form — it moves only through the ledger.
        ->and(Row::int($product, 'stock_express'))->toBe(0)
        ->and(Row::bool($product, 'in_stock'))->toBeFalse();

    $specs = T::one(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId));
    expect((float) Row::money($specs, 'case_size'))->toBe(42.5)
        ->and(Row::int($specs, 'case_size_unit_id'))->toBe($unitId)
        ->and(Row::int($specs, 'case_shape_id'))->toBe($shapeId)
        ->and(Row::bool($specs, 'watch_box'))->toBeTrue();

    $translations = DB::table('catalog_product_translations')->where('product_id', $productId)->pluck('title', 'locale');
    expect($translations['ar'])->toBe('ساعة اختبار')
        ->and($translations['en'])->toBe('Test watch');

    // The placement row and the category link were both written, in that order.
    expect(DB::table('storefront_product')->where('product_id', $productId)->where('storefront_id', 1)->exists())->toBeTrue()
        ->and(T::int(DB::table('storefront_category_product')->where('product_id', $productId)->where('is_primary', true)->value('storefront_category_id')))
        ->toBe($watches);
});

it('refuses a product with no Arabic title', function () {
    CatalogFixture::assumeSwitched();
    // Fallback is OFF (AGENTS §2.17): a product without Arabic renders with holes on an
    // Arabic-first storefront, so the form will not create one.
    actingAs(Staff::dataEntry())
        ->post('/manage/storefronts/1/products', productPayload(['title' => ['ar' => '', 'en' => 'English only']]))
        ->assertSessionHasErrors('title.ar');
});

it('refuses to make a product visible while its Arabic is missing', function () {
    $productId = CatalogFixture::productWithoutArabic();
    CatalogFixture::onStorefront($productId, visible: false);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => true,
        'is_featured' => false,
    ])->assertSessionHasErrors('is_visible');

    expect(T::int(DB::table('storefront_product')->where('product_id', $productId)->value('is_visible')) === 1)->toBeFalse();

    // The error NAMES what is missing, because "cannot publish" without a reason is a dead end —
    // and the two shapes get two different sentences, which is the point: a product with NO
    // Arabic row at all is a different problem from one whose title is blank.
    expect(T::err('is_visible'))->toContain('صف الترجمة العربية بالكامل');

    // …the blank-title shape, which is what a half-filled form actually produces.
    $blankTitle = CatalogFixture::product();
    DB::table('catalog_product_translations')->where('product_id', $blankTitle)->where('locale', 'ar')->update(['title' => '   ']);
    CatalogFixture::onStorefront($blankTitle, visible: false);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$blankTitle}", [
        'is_visible' => true,
        'is_featured' => false,
    ])->assertSessionHasErrors('is_visible');

    expect(T::err('is_visible'))->toContain('العنوان');
});

it('stores a sale price only when it is a real discount', function () {
    CatalogFixture::assumeSwitched();
    // The price/total contract the frontend and the cart both enforce: a sale price counts only
    // when 0 < sale < selling. Anything else is stored as NULL rather than as a number that would
    // make the checkout reject the order total.
    $cases = [
        ['selling' => '1000.00', 'sale' => '800.00', 'stored' => true],
        ['selling' => '1000.00', 'sale' => '1000.00', 'stored' => false],
        ['selling' => '1000.00', 'sale' => '1200.00', 'stored' => false],
        ['selling' => '1000.00', 'sale' => '0', 'stored' => false],
    ];

    foreach ($cases as $case) {
        actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', productPayload([
            'selling_price' => $case['selling'],
            'sale_price' => $case['sale'],
        ]))->assertRedirect();

        $product = T::row(DB::table('catalog_products')->orderByDesc('id')->first(['sale_price']));
        $case['stored']
            ? expect(Row::nmoney($product, 'sale_price'))->not->toBeNull("sale {$case['sale']} of {$case['selling']} should be stored")
            : expect(Row::nmoney($product, 'sale_price'))->toBeNull("sale {$case['sale']} of {$case['selling']} must not be stored");
    }
});

it('mirrors a price change onto every storefront row', function () {
    // `storefront_product.effective_price` is a maintained mirror of the catalog price while the
    // override gate is off (AGENTS §2.4). Forgetting to refresh it is how a storefront keeps
    // selling at yesterday's price — the transform's step 18 writes it, and so must an edit.
    $productId = CatalogFixture::product(selling: 500.0);
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'selling_price' => '999.00',
        'sale_price' => '899.00',
    ]))->assertRedirect();

    $row = T::row(DB::table('storefront_product')->where('product_id', $productId)->first(['effective_price', 'effective_sale_price']));
    expect((float) Row::money($row, 'effective_price'))->toBe(999.0)
        ->and((float) Row::money($row, 'effective_sale_price'))->toBe(899.0);
});

it('writes images in the payload order with exactly one cover', function () {
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'images' => [
            ['path' => 'Product_image/a.webp', 'is_cover' => false],
            ['path' => 'Product_image/b.webp', 'is_cover' => true],
            ['path' => 'Product_image/c.webp', 'is_cover' => false],
        ],
    ]))->assertRedirect();

    $images = DB::table('catalog_product_images')->where('product_id', $productId)->orderBy('sort')
        ->get(['path', 'is_cover', 'sort'])
        ->map(fn (object $row): stdClass => Row::cast($row))
        ->all();

    expect($images)->toHaveCount(3)
        ->and(Row::str($images[0], 'path'))->toBe('Product_image/a.webp')
        ->and(Row::int($images[0], 'sort'))->toBe(0)
        ->and(Row::str($images[1], 'path'))->toBe('Product_image/b.webp')
        ->and(Row::bool($images[1], 'is_cover'))->toBeTrue()
        // Exactly one cover: the read layer picks it with `ORDER BY is_cover DESC, sort`, and two
        // covers make the choice arbitrary between two requests.
        ->and(DB::table('catalog_product_images')->where('product_id', $productId)->where('is_cover', true)->count())->toBe(1);
});

it('makes the first image the cover when the payload names none', function () {
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'images' => [['path' => 'Product_image/only.webp', 'is_cover' => false]],
    ]))->assertRedirect();

    expect(T::int(DB::table('catalog_product_images')->where('product_id', $productId)->value('is_cover')) === 1)->toBeTrue();
});

it('removes an image ROW without touching the shared file tree', function () {
    // `media:prune` is the only thing that deletes from the shared tree, and it is dry-run by
    // default for exactly this reason: the legacy application serves the same directory.
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'images' => [['path' => 'Product_image/x.webp', 'is_cover' => true], ['path' => 'Product_image/y.webp', 'is_cover' => false]],
    ]))->assertRedirect();
    expect(DB::table('catalog_product_images')->where('product_id', $productId)->count())->toBe(2);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'images' => [['path' => 'Product_image/x.webp', 'is_cover' => true]],
    ]))->assertRedirect();

    expect(DB::table('catalog_product_images')->where('product_id', $productId)->count())->toBe(1)
        ->and(DB::table('catalog_product_images')->where('product_id', $productId)->value('path'))->toBe('Product_image/x.webp');
});

it('replaces the attribute pivots rather than appending to them', function () {
    $productId = CatalogFixture::product();
    $features = DB::table('catalog_features')->orderBy('id')->limit(2)->pluck('id')->all();
    $colorId = T::int(DB::table('catalog_colors')->orderBy('id')->value('id'));

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'feature_ids' => $features,
        'colors' => [
            ['color_id' => $colorId, 'role' => 'main'],
            // The same colour in two roles is legal: the pivot's key is (product, colour, role).
            ['color_id' => $colorId, 'role' => 'dial'],
        ],
    ]))->assertRedirect();

    expect(DB::table('catalog_product_feature')->where('product_id', $productId)->count())->toBe(count($features))
        ->and(DB::table('catalog_product_color')->where('product_id', $productId)->count())->toBe(2);

    // A second save with fewer features must REPLACE, not append.
    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'feature_ids' => [$features[0]],
        'colors' => [],
    ]))->assertRedirect();

    expect(DB::table('catalog_product_feature')->where('product_id', $productId)->count())->toBe(1)
        ->and(DB::table('catalog_product_color')->where('product_id', $productId)->count())->toBe(0);
});

it('deletes the watch spec row when a product leaves the watch family', function () {
    // `catalog_product_watch_specs` is keyed by product id, so a stale row would keep answering
    // the detail endpoint's watch block for a bag.
    $watches = CatalogFixture::watchesRoot();
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product('watch');
    CatalogFixture::place($productId, $watches);
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        'specs' => ['case_size' => '40'],
    ]))->assertRedirect();
    expect(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->exists())->toBeTrue();

    // Move it to Fashion: the family changes and the watch row must go with it.
    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'category_ids' => [$fashion],
        'primary_category_id' => $fashion,
        'specs' => [],
    ]))->assertRedirect();

    expect(DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->exists())->toBeFalse()
        ->and(T::str(DB::table('catalog_products')->where('id', $productId)->value('family')))->not->toBe('watch');
});

it('maintains the search index on every save, because step 21 cannot see this row', function () {
    // The transform's step 21 builds `catalog_product_search` from LEGACY names. A product created
    // here would answer no search at all, and an edited one would keep answering by its old words,
    // unless the dashboard maintains the index itself (App\Domain\Catalog\ProductIndexer).
    //
    // Exercised through the WRITER and not only the endpoint, and that is not redundancy: a
    // sensitivity pass showed that deleting `ProductWriter::update()`'s own re-index left the HTTP
    // test green, because `PlacementWriter::place()` re-indexes too (a category name is part of
    // the searchable body) and the controller always calls it. The writer's contract is
    // independently "a save leaves the index correct" — it is called by commands and importers
    // too — so it has to be checked independently.
    $productId = CatalogFixture::product();

    app(ProductWriter::class)->update($productId, [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '1200.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'كلمة فريدة', 'en' => 'Unique Indexable Title'],
        'search_keywords' => 'extra keyword',
    ], null);

    $bodies = DB::table('catalog_product_search')->where('product_id', $productId)->pluck('body', 'locale');

    /*
     * The Arabic expectation goes through the NORMALISER (A-UX-1, 2026-09-17), because the index
     * body is a folded search key rather than the title: `كلمة فريدة` is stored as `كلمه فريده`.
     *
     * Written as a call rather than as the folded literal on purpose — the literal would say the
     * right thing and explain nothing, and the next person to read it would not know whether the
     * odd spelling was the point or a typo. The English assertions are untouched, which is itself
     * part of the contract: the fold leaves Latin text byte-identical.
     */
    expect($bodies)->toHaveCount(2)
        ->and(T::str($bodies['en']))->toContain('Unique Indexable Title')
        ->and(T::str($bodies['en']))->toContain('extra keyword')
        ->and(T::str($bodies['ar']))->toContain(ArabicSearch::normalise('كلمة فريدة'));

    // …and again through the endpoint, which is the path the team takes.
    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'title' => ['ar' => 'عنوان ثانٍ', 'en' => 'Second Indexable Title'],
    ]))->assertRedirect();

    expect(T::str(DB::table('catalog_product_search')->where('product_id', $productId)->where('locale', 'en')->value('body')))
        ->toContain('Second Indexable Title');
});

it('rejects a duplicate wa_code with a field error, not a 500', function () {
    CatalogFixture::assumeSwitched();
    $existing = T::str(DB::table('catalog_products')->orderBy('id')->value('wa_code'));

    actingAs(Staff::dataEntry())
        ->post('/manage/storefronts/1/products', productPayload(['wa_code' => $existing]))
        ->assertSessionHasErrors('wa_code');
});

it('archives instead of deleting, and can restore', function () {
    // Order lines, the ledger and the id map all point at a product row, and §2.9.6 rule 2 is
    // that this application does not delete catalogue history.
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'archive', 'ids' => [$productId],
    ])->assertRedirect();

    $row = T::row(DB::table('catalog_products')->where('id', $productId)->first(['deleted_at', 'is_active']));
    expect(Row::nstr($row, 'deleted_at'))->not->toBeNull()
        ->and(Row::bool($row, 'is_active'))->toBeFalse();

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'restore', 'ids' => [$productId],
    ])->assertRedirect();

    expect(DB::table('catalog_products')->where('id', $productId)->value('deleted_at'))->toBeNull();
});

it('does not blank the columns a bulk action did not mention', function () {
    // `ProductWriter::update()` writes a FULL column list, so a partial payload would clear what
    // it omitted. The bulk path reads the row back and re-submits it for exactly that reason —
    // this is the test that would catch a bulk "activate" that also erased every price.
    $productId = CatalogFixture::product(selling: 777.0);
    DB::table('catalog_products')->where('id', $productId)->update([
        'model_number' => 'KEEP-ME', 'purchase_price' => '123.00', 'is_active' => false,
    ]);

    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'activate', 'ids' => [$productId],
    ])->assertRedirect();

    $row = T::one(DB::table('catalog_products')->where('id', $productId));
    expect(Row::bool($row, 'is_active'))->toBeTrue()
        ->and(Row::str($row, 'model_number'))->toBe('KEEP-ME')
        ->and((float) Row::money($row, 'purchase_price'))->toBe(123.0)
        ->and((float) Row::money($row, 'selling_price'))->toBe(777.0);
});

it('ships the create form with every block and lookup it might need', function () {
    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/products/create')->assertOk());

    expect($props['product'])->toBeNull()
        ->and($props['blocks'])->toBeArray()
        ->and($props['blocks'])->toHaveKey('watch')
        ->and($props['lookups'])->toBeArray()
        ->and($props['lookups'])->toHaveKey('colors')
        ->and($props['lookups'])->toHaveKey('units')
        ->and($props['categories'])->toBeArray()
        /*
         * And NO pre-switch banner. This used to assert the opposite — that the create screen
         * carries a notice saying the next rebuild replaces anything typed here. The developer
         * removed every one of those on 2026-09-18 because the team is now working as though the
         * switch has happened, so the prop is gone from the payload rather than merely empty.
         */
        ->and(array_key_exists('pre_switch_notice', $props))->toBeFalse();
});

// ── what a product save records (B4, 2026-09-20) ──────────────────────────────────────────────

/*
 * The live review's repro: edit any product's Arabic description, press Save, and the activity log
 * grew TWO rows one second apart — both saying `is_active: 0 ← ` with a blank right-hand side, and
 * neither mentioning the description. One PUT on the wire, two rows, and not one word about what
 * the operator actually changed.
 *
 * Three causes, fixed as one change because fixing any of them alone makes it worse:
 *
 *   1. `ActivityLog::same()` read the database's `0` as different from the payload's `false`, so
 *      every boolean column at 0 logged a phantom change on every save.
 *   2. `ProductController::saveStorefrontSide()` calls the writer a second time to re-derive the
 *      family. That call diffs to NOTHING once (1) is right, and `record()` already drops an
 *      UPDATE with an empty diff — so the duplicate disappears rather than needing its own guard.
 *   3. `ProductWriter::update()` audited `catalog_products` alone, and recorded BEFORE the
 *      translations, specs, pivots and images were written. Fix (1) without (3) and a
 *      description-only edit logs nothing at all: wrong becomes silent.
 */

it('records ONE row for one save, and names what actually changed', function () {
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();

    $productId = CatalogFixture::product();
    $payload = productPayload();

    // A first save, so the row is in a known state and the snapshot has something to diff against.
    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", $payload)
        ->assertSessionHasNoErrors();

    $mark = T::int(DB::table('core_activity_log')->max('id') ?? 0);

    // The review's exact repro: change the Arabic description and nothing else.
    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'long_description' => ['ar' => 'وصف تفصيلي جديد تمامًا', 'en' => 'Long description'],
    ]))->assertSessionHasNoErrors();

    $rows = T::many(DB::table('core_activity_log')->where('id', '>', $mark)
        ->where('subject_type', 'catalog_products')->where('subject_id', $productId));

    // ONE row. Two was the bug, and it came from the phantom diff rather than from a second request.
    expect($rows)->toHaveCount(1, 'one save must leave one row');

    $changes = T::arr(json_decode(T::str(Row::nstr(Row::cast($rows[0]), 'changes')), true));

    // …and it names the DESCRIPTION, which is the whole point and what neither old row mentioned.
    expect($changes)->toHaveKey('long_description_ar');

    $description = T::arr($changes['long_description_ar']);
    expect(T::str($description['to'] ?? null))->toContain('جديد تمامًا');

    // …and it does NOT carry the phantom: `is_active` did not move, so it must not appear.
    expect($changes)->not->toHaveKey('is_active');
});

it('logs nothing at all for a save that changed nothing', function () {
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();

    $productId = CatalogFixture::product();
    $payload = productPayload();

    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", $payload)->assertSessionHasNoErrors();

    $settled = productPayload([
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
    ]);
    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", $settled)->assertSessionHasNoErrors();

    $mark = T::int(DB::table('core_activity_log')->max('id') ?? 0);

    // The same payload a third time: nothing moved, so nothing is recorded. This is the assertion
    // that would catch the phantom coming back — it produced a row on EVERY save, including this one.
    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", $settled)->assertSessionHasNoErrors();

    expect(DB::table('core_activity_log')->where('id', '>', $mark)
        ->where('subject_type', 'catalog_products')->count())->toBe(0);
});

it('treats a database 0 and a payload false as the same value', function () {
    /*
     * The unit behind cause 1, pinned on its own so the reason survives the screen it was found on.
     * `is_numeric(false)` is FALSE, so the pair used to fall through to `'0' === ''`.
     *
     * The loose-comparison shortcut is deliberately NOT what fixes it: `'abc' == false` is true in
     * PHP, and an audit trail that calls a title change "no change" is the failure this prevents.
     */
    expect(ActivityLog::diff(['is_active' => 0], ['is_active' => false]))->toBe([]);
    expect(ActivityLog::diff(['is_active' => 1], ['is_active' => true]))->toBe([]);
    expect(ActivityLog::diff(['is_active' => '0'], ['is_active' => false]))->toBe([]);

    // …and a real change still reads as one, in both directions.
    expect(ActivityLog::diff(['is_active' => 0], ['is_active' => true]))->toHaveKey('is_active');
    expect(ActivityLog::diff(['is_active' => 1], ['is_active' => false]))->toHaveKey('is_active');

    // …and a non-numeric string is not quietly equal to false, which loose comparison would make it.
    expect(ActivityLog::diff(['title' => 'abc'], ['title' => false]))->toHaveKey('title');
});

it('leaves the specification block ALONE when a payload does not mention it', function () {
    /*
     * `writeSpecs()` was the one writer not guarded by `array_key_exists`, where `writePivots()`
     * always has been. `SpecBlocks::watchColumns()` maps an absent key to a full set of nulls, so
     * any caller omitting `specs` silently blanked TEN columns — case size, the materials, the
     * movement, the water resistance — on a product that was merely being re-saved.
     *
     * It had never bitten because the only caller is the form and `currentPayload()` happens to
     * carry the block. It surfaced when the audit snapshot grew to cover the spec table (B4) and a
     * "changed nothing" save reported ten columns going to null.
     *
     * The guard is on the WRITER, so an importer or a CLI command nobody has written yet is covered
     * by construction rather than by remembering.
     */
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();

    $productId = CatalogFixture::product();
    $shapeId = T::int(DB::table('catalog_shapes')->orderBy('id')->value('id'));

    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", productPayload([
        'specs' => ['case_size' => '42.00', 'shape_id' => $shapeId],
    ]))->assertSessionHasNoErrors();

    $before = DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->first();
    expect($before)->not->toBeNull();

    // A partial payload — everything except `specs`, exactly what a script would send.
    app(ProductWriter::class)->update(
        $productId,
        array_diff_key(productPayload([
            'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        ]), ['specs' => true]),
        T::int($admin->getAuthIdentifier()),
    );

    $after = DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->first();

    expect($after)->toEqual($before, 'a payload that never mentioned the specs changed them');

    // …and `specs => []` still CLEARS, because that is present and unambiguous — the same contract
    // `feature_ids` and `colors` already have.
    app(ProductWriter::class)->update(
        $productId,
        productPayload([
            'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
            'specs' => [],
        ]),
        T::int($admin->getAuthIdentifier()),
    );

    $cleared = DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->first();
    expect($cleared)->not->toBeNull()
        ->and(Row::nstr(Row::cast(T::row($cleared)), 'case_size'))->toBeNull();
});
