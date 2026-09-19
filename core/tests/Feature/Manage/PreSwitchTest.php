<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\PreSwitch;
use App\Transform\Row;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The pre-switch block (developer decision 2026-09-11 on wave 4B's flag 1).
 *
 * Every other 4B test calls `CatalogFixture::assumeSwitched()`, because its subject is the world
 * AFTER the switch. **This file is the one that does not**, so the refusals are proved with the
 * flag in the state production ships with — false — through the real endpoints.
 *
 * ── What is actually being prevented, stated accurately ──────────────────────────────────────
 *
 * Not "switch night fails". Switch night is `core:drop-clean` → `migrate` → `core:transform`, and
 * the transform reconciles AFTER rebuilding from legacy, so a dashboard-authored row is already
 * gone when the reconciliation runs. The reconciliation is a property of a FRESH REBUILD, not of a
 * live database — it is emphatic about that, asserting `storefront_product[visible] = all
 * products visible` and the slug plan on every row, so merely hiding a product would "break" it
 * too.
 *
 * What is prevented is the loss the developer named first: **an afternoon of typing that the next
 * rebuild deletes.** A created row vanishes; an edited row is only reverted. That is the line.
 *
 * ── The gates became CAVEATS on 2026-09-18 (item 5) — and this file did not shrink ───────
 *
 * The developer asked for every gated feature open and exercised: *"Keep the explanatory notices,
 * but make the actions work."* `config('transform.pre_switch_gates')` now defaults to `warn`, so
 * the refusals below would all fail as written.
 *
 * They were NOT deleted, and that is the point of this note. A refusal nobody tests is a refusal
 * nobody can safely turn back on, and `CORE_PRE_SWITCH_GATES=enforce` is exactly what somebody
 * will reach for the first time a rebuild eats an afternoon of work. So every refusal test now
 * pins the mode it is about — `enforce` — through the `enforcing()` helper below, and each is
 * joined by its opposite: the same endpoint, in `warn`, succeeding and carrying the caveat.
 *
 * The two modes are one line apart in config and one line apart here.
 */

/**
 * Put the refusals back for the duration of ONE test.
 *
 * `config()` writes to the in-memory repository, and nothing persists it, so this is scoped to the
 * test the same way `PreSwitch::allowing()` is scoped to a callable.
 */
function enforcing(): void
{
    config(['transform.pre_switch_gates' => 'enforce']);
}

it('ships with the flag OFF, so forgetting it refuses rather than loses work', function () {
    // The fail-safe direction, asserted rather than assumed.
    expect(PreSwitch::completed())->toBeFalse()
        ->and(config('transform.write_switch_completed'))->toBeFalse();

    // And `.env.example` must both DECLARE it and declare it false, so a new machine starts
    // pre-switch and a reader learns the flag exists. Parsed with Dotenv rather than grepped,
    // because a substring test passes on a commented-out line and on `=TRUE` alike — the same
    // trap wave 4A's `.env.example` assertion fell into.
    $values = Dotenv::createArrayBacked(base_path(), '.env.example')->load();
    expect(array_key_exists('CORE_WRITE_SWITCH_COMPLETED', $values))
        ->toBeTrue('.env.example must declare CORE_WRITE_SWITCH_COMPLETED; a flag nobody can see is a flag nobody flips')
        ->and(T::str($values['CORE_WRITE_SWITCH_COMPLETED']))
        ->toBe('false', '.env.example must ship the flag OFF: a new machine has to start pre-switch');
});

it('REFUSES a new product, and writes nothing', function () {
    enforcing();
    $before = DB::table('catalog_products')->count();

    // An OTHERWISE VALID payload, on purpose: the refusal being proved is the pre-switch block,
    // so the request must not be able to fail for a duller reason (a missing category, say).
    $watches = CatalogFixture::watchesRoot();
    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', [
        'wa_code' => 'pre-switch-'.bin2hex(random_bytes(4)),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '100.00', 'purchase_price' => '0.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج ممنوع', 'en' => 'Blocked product'],
        'category_ids' => [$watches], 'primary_category_id' => $watches,
        'is_visible' => false, 'is_featured' => false,
    ])->assertSessionHasErrors('pre_switch');

    expect(DB::table('catalog_products')->count())->toBe($before);
    expect(T::err('pre_switch'))->toContain('الإضافة موقوفة حاليًا')
        /*
         * The message still has to say what to do instead, or it is a dead end — that half of this
         * assertion is unchanged and is worth keeping. What changed on 2026-09-18 is the answer:
         * it used to send the operator to the old dashboard, which is the pre-switch instruction
         * the team has now been told to stop following. A paused control sends them to a person.
         */
        ->and(T::err('pre_switch'))->toContain('المدير');
});

it('REFUSES a new variant, even on a dashboard-created product', function () {
    enforcing();
    // The product itself is inserted by the fixture (not through the writer), so this isolates
    // the variant door: before the switch, no variant row may be created at all — legacy
    // `product_variants` is empty, so the rebuild deletes it and re-levels its ledger rows.
    $productId = CatalogFixture::product();
    $before = DB::table('catalog_product_variants')->count();

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        // Otherwise valid — a size and a label — so the only reason this can fail is the
        // pre-switch block, which is what the test is about.
        'label' => 'ممنوع', 'size_id' => T::int(DB::table('catalog_sizes')->orderBy('id')->value('id')),
        'is_active' => true,
    ])->assertSessionHasErrors('variants');

    expect(DB::table('catalog_product_variants')->count())->toBe($before);
    expect(T::err('variants'))->toContain('الإضافة موقوفة حاليًا');
});

it('REFUSES a new category, and writes nothing', function () {
    enforcing();
    $before = DB::table('storefront_categories')->count();

    /*
     * As an ADMIN, and that changed on 2026-09-18 (item 6).
     *
     * This test used to act as data-entry, because the calendar rule was the only one on this
     * route. Creating a category is now admin-only as well, so a data-entry request is refused by
     * the route's `can:edit-category-tree` before it ever reaches `PreSwitch` — a 403, not a
     * validation error, and this test would have passed for the wrong reason if it had been made
     * to accept either.
     *
     * The two rules are checked in that order on purpose: the one that will never change for this
     * person answers first. `SlugAndTreeGrantTest` owns the 403 half.
     */
    actingAs(Staff::admin())->post('/manage/storefronts/1/categories', [
        'name' => ['ar' => 'تصنيف ممنوع', 'en' => 'Blocked category'],
    ])->assertSessionHasErrors('tree');

    expect(DB::table('storefront_categories')->count())->toBe($before);
    expect(T::err('tree'))->toContain('الإضافة موقوفة حاليًا');
});

it('REFUSES a new row in every one of the twelve lookup lists', function () {
    enforcing();
    foreach (array_keys(LookupWriter::all()) as $list) {
        $def = LookupWriter::definition($list);
        $before = DB::table($def['master'])->count();

        $extra = [];
        foreach ($def['extra'] as $column => $field) {
            $extra[$column] = match ($field['type'] ?? 'string') {
                'boolean' => true,
                'integer' => 1,
                'hex' => '#123456',
                default => 'x'.bin2hex(random_bytes(3)),
            };
        }

        actingAs(Staff::admin())->post("/manage/lookups/{$list}", [
            'name' => ['ar' => 'ممنوع', 'en' => 'Blocked'.bin2hex(random_bytes(3))],
            'extra' => $extra,
        ])->assertSessionHasErrors('name.ar');

        expect(DB::table($def['master'])->count())->toBe($before, "list [{$list}] gained a row before the switch");
    }
});

/*
 * ── The other half: what stays OPEN, because training has to be possible ────────────────────
 */

it('still lets the team EDIT an existing product, which is the training the decision keeps open', function () {
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $waCode = T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code'));

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        '_complete' => 1,
        'wa_code' => $waCode,
        'brand_id' => T::int(DB::table('catalog_products')->where('id', $productId)->value('brand_id')),
        'selling_price' => '4444.00', 'purchase_price' => '0.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'عنوان تدريبي', 'en' => 'Training title'],
        // Full REPLACE on a product the fixture placed as visible: the descriptions have to
        // travel or the writer refuses, which is the guard added on 2026-09-18 doing its job.
        'short_description' => ['ar' => 'وصف مختصر', 'en' => 'Short description'],
        'long_description' => ['ar' => 'وصف تفصيلي', 'en' => 'Long description'],
        'is_visible' => false, 'is_featured' => false,
    ])->assertSessionHasNoErrors();

    expect((float) T::str(DB::table('catalog_products')->where('id', $productId)->value('selling_price')))->toBe(4444.0);
});

it('still lets the team rename and MOVE an existing category', function () {
    // Renaming and moving are edits of rows legacy owns: the rebuild reverts them, it does not
    // delete a node the team made. Both stay open.
    $watches = CatalogFixture::watchesRoot();

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/categories/{$watches}", [
        'name' => ['ar' => 'ساعات (تدريب)', 'en' => 'Watches'],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $watches)->where('locale', 'ar')->value('name')))->toBe('ساعات (تدريب)');
});

it('still lets the team place, hide, feature and sort — but NOT re-slug (review 🟠-3)', function () {
    enforcing();
    /*
     * This test used to assert that the slug could be changed pre-switch, and that was the
     * dishonest part of the screen: the field promised a permanent 301, and the next rebuild
     * deletes both the hand-typed slug and the redirect written for it. The developer's call was
     * to make the screen honest by disabling the field, so the rule under test is now: everything
     * else on this screen keeps working, and the slug is refused BY NAME with a reason.
     */
    $productId = T::int(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
        ->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));
    $watches = CatalogFixture::watchesRoot();

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => true,
        'sort_order' => 77,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
    ])->assertSessionHasNoErrors();

    $row = T::row(DB::table('storefront_product')->where('product_id', $productId)->where('storefront_id', 1)
        ->first(['is_visible', 'is_featured', 'sort_order']));

    expect(Row::bool($row, 'is_featured'))->toBeTrue()
        ->and(Row::int($row, 'sort_order'))->toBe(77);

    // The slug: refused, on the slug field, in Arabic, and the stored value is untouched.
    $before = T::str(DB::table('storefront_product')
        ->where('product_id', $productId)->where('storefront_id', 1)->value('slug'));

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => true,
        'sort_order' => 77,
        'slug' => 'training-slug-'.bin2hex(random_bytes(3)),
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
    ])->assertSessionHasErrors('slug');

    expect(T::err('slug'))->toMatch('/\p{Arabic}/u')
        ->and(T::str(DB::table('storefront_product')
            ->where('product_id', $productId)->where('storefront_id', 1)->value('slug')))->toBe($before);

    // After the switch the same request is accepted — one flag, and this is the screen it opens.
    config()->set('transform.write_switch_completed', true);
    $wanted = 'post-switch-slug-'.bin2hex(random_bytes(3));
    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => true,
        'sort_order' => 77,
        'slug' => $wanted,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')
        ->where('product_id', $productId)->where('storefront_id', 1)->value('slug')))->toBe($wanted);
});

it('still lets the team add and reorder IMAGES on an existing product', function () {
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));
    $waCode = T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code'));

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/products/{$productId}", [
        '_complete' => 1,
        'wa_code' => $waCode,
        'brand_id' => T::int(DB::table('catalog_products')->where('id', $productId)->value('brand_id')),
        'selling_price' => '500.00', 'purchase_price' => '0.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'صور تدريبية', 'en' => 'Training images'],
        // Full REPLACE on a product the fixture placed as visible: the descriptions have to
        // travel or the writer refuses, which is the guard added on 2026-09-18 doing its job.
        'short_description' => ['ar' => 'وصف مختصر', 'en' => 'Short description'],
        'long_description' => ['ar' => 'وصف تفصيلي', 'en' => 'Long description'],
        'images' => [
            ['path' => 'Product_image/train-a.webp', 'is_cover' => true],
            ['path' => 'Product_image/train-b.webp', 'is_cover' => false],
        ],
        'is_visible' => false, 'is_featured' => false,
    ])->assertSessionHasNoErrors();

    expect(DB::table('catalog_product_images')->where('product_id', $productId)->count())->toBe(2);
});

/*
 * ── The list is the documentation, so the list has to be true ───────────────────────────────
 */

it('names only transform-output tables, because that is the whole premise', function () {
    // If a table in the list were NOT dropped by the rebuild, blocking its creation would be
    // pointless friction; if a table the UI creates were MISSING from the list, the block would
    // have a hole. Both directions matter, and the first is checkable here.
    expect(PreSwitch::nonTransformTables())->toBe([], 'PreSwitch names a table that the rebuild does not drop');

    foreach (array_keys(PreSwitch::tableMap()) as $table) {
        expect(CoreChecksumCommand::CLEAN_TABLES)->toContain($table);
    }
});

it('covers every table the 4B writers can insert into', function () {
    // The other direction, kept honest by hand rather than by magic: these are the transform-output
    // tables a 4B screen can create a row in, and every one of them must appear in the list with a
    // disposition. A new write path that forgets to declare itself throws (PreSwitch::definition).
    $writable = [
        'catalog_products', 'catalog_product_translations', 'catalog_product_search',
        'catalog_product_watch_specs', 'catalog_product_images', 'catalog_product_variants',
        'storefront_categories', 'storefront_category_translations',
        'storefront_category_product', 'storefront_product', 'storefront_redirects',
        'catalog_brands', 'catalog_colors', 'catalog_sizes', 'catalog_materials', 'catalog_shapes',
        'catalog_movement_types', 'catalog_closure_types', 'catalog_display_types', 'catalog_units',
        'catalog_genders', 'catalog_features', 'catalog_grades',
    ];

    $missing = array_values(array_diff($writable, array_keys(PreSwitch::tableMap())));
    expect($missing)->toBe([], 'a table a 4B screen writes is not declared in PreSwitch::CREATIONS');

    /*
     * …including the three pure pivots (review 🟡-6). They used to be left OUT on the grounds that
     * a pivot row is not an entity of its own — which is true, and which is exactly why they are
     * ALLOWED — but leaving them out made `nonTransformTables()` pass for the wrong reason: a
     * table the product form writes was simply undeclared, so "the declared list is complete" was
     * not a claim anybody had checked. Declared and allowed says the same thing and can be tested.
     */
    foreach (['catalog_product_feature', 'catalog_product_gender', 'catalog_product_color'] as $pivot) {
        expect(PreSwitch::tableMap())->toHaveKey($pivot);
        expect(PreSwitch::tableMap()[$pivot]['blocked'])->toBeFalse("[{$pivot}] is a set on an existing product, not an entity");
    }
});

it('flips as ONE flag, not per action and not on a date', function () {
    enforcing();
    // Four actions blocked, six allowed, one switch (the sixth is `attributes`, declared for
    // review 🟡-6). The decision was explicit that it must not be a date guess: the switch happens
    // when it happens.
    $blocked = [];
    $allowed = [];
    $reasons = [];
    foreach (PreSwitch::CREATIONS as $action => $definition) {
        $definition['blocked'] ? $blocked[] = $action : $allowed[] = $action;
        $reasons[] = $definition['why'];
    }

    // Every action carries its OWN reason. "is not empty" would be a claim about a literal in a
    // `const` — PHPStan answers that at analysis time, which makes it not a test. What a test can
    // catch is the mistake that actually happens here: a new action added by copying a neighbour
    // and inheriting its explanation, so the screen tells the team the wrong thing about it.
    expect(array_values(array_unique($reasons)))->toHaveCount(count(PreSwitch::CREATIONS));

    expect($blocked)->toBe(['product', 'variant', 'category', 'lookup'])
        ->and($allowed)->toBe(['product_image', 'placement', 'storefront_product', 'watch_specs', 'redirect', 'attributes']);

    // One flag: flipping it opens every blocked action at once.
    foreach ($blocked as $action) {
        expect(PreSwitch::allows($action))->toBeFalse();
    }
    config()->set('transform.write_switch_completed', true);
    foreach (array_keys(PreSwitch::CREATIONS) as $action) {
        expect(PreSwitch::allows($action))->toBeTrue("[{$action}] is still refused after the switch");
    }
});

it('opens the real endpoints once the flag flips', function () {
    // The other direction end to end: the same POST that was refused above succeeds afterwards,
    // so the block is the flag and not a dead route.
    config()->set('transform.write_switch_completed', true);

    // ADMIN since item 6: creating a node is admin-only whatever the flag says, so a data-entry
    // request here would be a 403 and would prove nothing about the flag.
    actingAs(Staff::admin())->post('/manage/storefronts/1/categories', [
        'name' => ['ar' => 'تصنيف بعد التحويل', 'en' => 'After the switch'],
    ])->assertSessionHasNoErrors();

    expect(DB::table('storefront_category_translations')->where('name', 'تصنيف بعد التحويل')->exists())->toBeTrue();
});

/*
 * ── The screens say it, so nobody discovers it by clicking ──────────────────────────────────
 */

it('tells every screen whether its create control works, and why not', function () {
    enforcing();
    $entry = Staff::dataEntry();

    $product = Props::of(actingAs($entry)->get('/manage/storefronts/1/products')->assertOk());
    $form = Props::of(actingAs($entry)->get('/manage/storefronts/1/products/create')->assertOk());
    $tree = Props::of(actingAs($entry)->get('/manage/storefronts/1/categories')->assertOk());
    $lookups = Props::of(actingAs($entry)->get('/manage/lookups/brands')->assertOk());

    foreach (['products list' => $product, 'product form' => $form, 'category tree' => $tree, 'lookups' => $lookups] as $name => $props) {
        // `toHaveKey($key, $value)` takes an expected VALUE as its second argument, not a message
        // — the same trap as `toContain`, and the third time this codebase has hit it. Spelled out.
        expect(array_key_exists('pre_switch', $props))
            ->toBeTrue("{$name} does not tell the screen about the block");

        /** @var array<string, mixed> $state */
        $state = $props['pre_switch'];
        expect($state['blocked'])->toBeTrue("{$name} thinks creation is allowed")
            ->and($state['write_switch_completed'])->toBeFalse()
            ->and(T::str($state['message']))->toContain('الإضافة موقوفة حاليًا');
    }

    // The product form also carries the VARIANT state, because that panel has its own control.
    /** @var array<string, mixed> $variantState */
    $variantState = $form['pre_switch_variant'];
    expect($variantState['blocked'])->toBeTrue();
});

/*
 * ══ The other mode (item 5, 2026-09-18) ════════════════════════════════════════
 *
 * Everything above proves the refusals still work when somebody asks for them. Everything below
 * proves the shipped default actually opens the doors — which is the whole of what was asked for,
 * and is the half that a config default alone does not demonstrate.
 *
 * These tests do NOT call `enforcing()`, so they run in the mode a fresh checkout runs in.
 */

it('ships with the gates set to WARN, which is the decision, not an accident', function () {
    expect(PreSwitch::enforced())->toBeFalse()
        ->and(config('transform.pre_switch_gates'))->toBe('warn');

    // …and `.env.example` declares the variable, so a reader learns the switch exists and can put
    // the refusals back without reading the source. Same reasoning as the flag test at the top:
    // parsed with Dotenv, because a substring test passes on a commented-out line.
    $values = Dotenv::createArrayBacked(base_path(), '.env.example')->load();
    expect(array_key_exists('CORE_PRE_SWITCH_GATES', $values))
        ->toBeTrue('.env.example must declare CORE_PRE_SWITCH_GATES; a switch nobody can see is one nobody flips');
});

it('CREATES a product before the switch, which is the thing that was asked for', function () {
    $watches = CatalogFixture::watchesRoot();
    $code = 'item5-'.bin2hex(random_bytes(4));

    // The SAME payload the refusal test above posts, so the only difference between the two tests
    // is the mode — which is the thing being proved.
    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/products', [
        'wa_code' => $code,
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '100.00', 'purchase_price' => '0.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج اختبار بند 5', 'en' => 'Item 5 probe'],
        'category_ids' => [$watches], 'primary_category_id' => $watches,
        'is_visible' => false, 'is_featured' => false,
    ])->assertSessionHasNoErrors();

    // The row exists — and the transaction the test runs in removes it again, so the catalogue is
    // exactly as it was. That is the same bargain the caveat offers the operator: the work is real
    // until the rebuild, and then it is not.
    expect(DB::table('catalog_products')->where('wa_code', $code)->count())->toBe(1);
});

it('CHANGES a slug before the switch, with the 301 caveat instead of a locked field', function () {
    $row = T::one(
        DB::table('storefront_product')->where('storefront_id', 1)->whereNotNull('slug')->orderBy('product_id')
    );
    $productId = Row::int($row, 'product_id');
    $was = Row::str($row, 'slug');
    $watches = CatalogFixture::watchesRoot();

    /*
     * The WHOLE placement payload, not just the slug. This endpoint is a full replace — a request
     * that names only one field is refused for the fields it left out, which is a different
     * refusal from the one under test and would have made this test pass for the wrong reason.
     */
    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        'slug' => $was.'-item5',
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->value('slug')))
        ->toBe($was.'-item5');
});

it('says NOTHING about the rebuild on a screen whose controls work', function () {
    /*
     * This test used to assert the opposite, and the reversal is the point.
     *
     * Item 5 opened the gates and kept a caveat beside every opened control, on the reasoning that
     * opening a gate and removing its sentence leaves the operator doing careful work that a
     * rebuild will take. That reasoning was sound while the team was rehearsing. It stopped being
     * sound on 2026-09-18, when the developer said the team is working as though the switch has
     * happened:
     *
     *   *"the gates were opened but the warnings stayed, and they're now telling the team not to do
     *   the exact thing I want them doing."*
     *
     * So a screen whose control works now says nothing at all, and this asserts the silence —
     * because a caveat quietly coming back is exactly the kind of regression that looks like a
     * helpful addition in a diff.
     */
    actingAs(Staff::admin());

    $screens = [
        'products list' => '/manage/storefronts/1/products',
        'lookups' => '/manage/lookups/brands',
        'categories' => '/manage/storefronts/1/categories',
    ];

    foreach ($screens as $name => $url) {
        $props = Props::of(actingAs(Staff::admin())->get($url)->assertOk());
        $state = T::arr($props['pre_switch'] ?? null);

        expect($state['blocked'] ?? null)->toBeFalse("{$name} still refuses");
        expect($state['caveat'] ?? null)->toBeNull("{$name} is warning about the rebuild again");
        expect($state['message'] ?? null)->toBeNull("{$name} carries a refusal on an open control");

        // …and the screen-wide banner is gone from the payload entirely, not merely empty.
        expect(array_key_exists('pre_switch_notice', $props))
            ->toBeFalse("{$name} still ships the pre-switch banner");
    }
});

it('opens the SECONDARY storefront tree, and still says it is a mirror', function () {
    $second = T::int(
        DB::table('storefronts')->where('is_active', true)->where('id', '<>', 1)->orderBy('id')->value('id')
    );

    expect($second)->toBeGreaterThan(0, 'there is no second storefront — this test proves nothing');

    // The policy object first: the mirror is still running, and the edit is allowed anyway.
    expect(PreSwitch::syncsSecondaryTrees())->toBeTrue()
        ->and(PreSwitch::mayEditTree($second))->toBeTrue();

    /*
     * The ONE note that survived the 2026-09-18 sweep, and the only `caveat` left anywhere.
     *
     * It survived because it is not about a rebuild — it is about two dashboards writing the same
     * tree right now. It must also not name any of our machinery, which is the other half of the
     * instruction, so that is asserted too.
     */
    $state = PreSwitch::treeState($second);
    $caveat = T::str($state['caveat']);

    expect($state['blocked'])->toBeFalse()
        ->and($caveat)->not->toBe('')
        ->and($state['message'])->toBeNull();

    foreach (['ليلة التحويل', 'إعادة البناء', 'core:', 'migrate'] as $forbidden) {
        expect(str_contains($caveat, $forbidden))
            ->toBeFalse("the tree note says “{$forbidden}” — operator copy names no machinery");
    }
});

it('keeps the CONVERSION guard refusing, because that one is not about lost typing', function () {
    /*
     * The line item 5 does not cross, asserted so nobody assumes "every gate opened".
     *
     * `ConversionGuard` reads `write_switch_completed`, not this switch, and it is a different
     * class of risk: legacy writes `products.quantity` directly, a converted product's stock lives
     * in the ledger, and opening that before legacy stops writing corrupts stock. Lost typing is
     * recoverable by typing it again. Lost stock figures are not.
     */
    expect(ConversionGuard::writeSwitchCompleted())->toBeFalse()
        ->and(PreSwitch::completed())->toBeFalse()
        ->and(PreSwitch::enforced())->toBeFalse();
});
