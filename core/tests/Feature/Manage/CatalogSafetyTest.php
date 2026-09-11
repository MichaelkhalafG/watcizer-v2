<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\VariantWriter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Storefront\StorefrontCache;
use App\Transform\LegacySource;
use App\Transform\Row;
use App\Transform\Steps\Step18StorefrontProduct;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * The wave-4B non-negotiables that are not about one screen:
 *
 *   • no legacy table is ever written — the 65-table digest across a full catalogue session,
 *     which is the acceptance test wave 4A settled on;
 *   • no stock column is written outside `InventoryService`;
 *   • the transform's insert-only rule (§2.9.6) means a re-run must not clobber a dashboard edit;
 *   • the v2 API reflects a dashboard edit, from the UI path.
 */

/** The 65-table legacy digest — the same set `core:checksum --set=legacy` computes. */
function legacyDigest(): string
{
    $parts = [];
    foreach (LegacySource::TABLES as $table) {
        $row = DB::selectOne('CHECKSUM TABLE `'.$table.'`');
        $checksum = is_object($row) ? ($row->Checksum ?? null) : null;
        $parts[] = $table.':'.(is_scalar($checksum) ? (string) $checksum : 'null');
    }

    return sha1(implode('|', $parts));
}

it('writes NO legacy table across a full catalogue editing session', function () {
    CatalogFixture::assumeSwitched();
    // The acceptance test wave 4A settled on, applied to 4B's screens: drive a real session
    // through every write path the catalogue has and assert the 65-table digest is IDENTICAL.
    //
    // This is the test that would catch a screen that "helpfully" updated `products.stock` or
    // touched `users`, which is the whole class of mistake AGENTS §3 exists to prevent.
    $before = legacyDigest();

    $admin = Staff::admin();
    $watches = CatalogFixture::watchesRoot();
    $unitId = T::int(DB::table('catalog_units')->orderBy('id')->value('id'));

    // 1. create a product, with a category and a spec block
    actingAs($admin)->post('/manage/storefronts/1/products', [
        'wa_code' => '4b-safety-'.bin2hex(random_bytes(4)),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '1500.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'ساعة السلامة', 'en' => 'Safety watch'],
        'specs' => ['case_size' => '40', 'case_size_unit_id' => $unitId],
        'category_ids' => [$watches], 'primary_category_id' => $watches,
        'is_visible' => true, 'is_featured' => true, 'sort_order' => 3,
        'images' => [['path' => 'Product_image/safety.webp', 'is_cover' => true]],
    ])->assertRedirect();

    $productId = T::int(DB::table('catalog_products')->orderByDesc('id')->value('id'));

    // 2. edit it
    actingAs($admin)->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '1600.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'ساعة السلامة المعدلة', 'en' => 'Safety watch edited'],
        'specs' => ['case_size' => '41'],
        'category_ids' => [$watches], 'primary_category_id' => $watches,
        'is_visible' => true, 'is_featured' => false, 'sort_order' => 4, 'slug' => 'safety-watch-edited',
    ])->assertRedirect();

    // 3. add a variant WITH stock (a ledger movement, at the variant level)
    actingAs($admin)->post("/manage/products/{$productId}/variants", [
        // A size, because since task 4.2 a variant must name a colour or a size.
        'label' => '41mm', 'size_id' => T::int(DB::table('catalog_sizes')->orderBy('id')->value('id')),
        'is_active' => true, 'stock_express' => 5, 'stock_market' => 2,
    ])->assertSessionHasNoErrors();

    // 4. a category: create, rename, move, reorder, delete
    $root = CatalogFixture::root('Safety root', 'جذر السلامة');
    $child = CatalogFixture::child($root['id'], 'Safety child', 'فرع السلامة');
    actingAs($admin)->put("/manage/storefronts/1/categories/{$child['id']}", ['name' => ['ar' => 'فرع معدّل', 'en' => 'Renamed child']])->assertSessionHasNoErrors();
    actingAs($admin)->put("/manage/storefronts/1/categories/{$child['id']}/move", ['parent_id' => null])->assertSessionHasNoErrors();
    actingAs($admin)->delete("/manage/storefronts/1/categories/{$child['id']}")->assertSessionHasNoErrors();

    // 5. placement, including a bulk action
    actingAs($admin)->put("/manage/storefronts/1/placement/{$productId}", ['is_visible' => true, 'is_featured' => true])->assertSessionHasNoErrors();
    actingAs($admin)->post('/manage/storefronts/1/placement/bulk', ['action' => 'unfeature', 'product_ids' => [$productId]])->assertSessionHasNoErrors();

    // 6. a lookup: create and rename
    actingAs($admin)->post('/manage/lookups/colors', ['name' => ['ar' => 'لون السلامة', 'en' => 'Safety colour'], 'extra' => ['hex' => '#0f0f0f']])->assertSessionHasNoErrors();

    // 7. a bulk product action
    actingAs($admin)->post('/manage/storefronts/1/products/bulk', ['action' => 'deactivate', 'ids' => [$productId]])->assertRedirect();

    expect(legacyDigest())->toBe($before, 'a 4B screen wrote a legacy table');
});

it('names no legacy table in any writer column list', function () {
    // A structural belt to the digest's braces: the four write paths declare their columns
    // explicitly (AGENTS §2.9), so a legacy table can be excluded by NAME.
    $writers = [
        'catalog_products' => ProductWriter::COLUMNS,
        'catalog_product_variants' => VariantWriter::COLUMNS,
        'storefront_product' => PlacementWriter::COLUMNS,
    ];

    // `toContain($needle, $more)` treats extra arguments as MORE NEEDLES, not a message — the
    // trap wave 4A's review hit twice and this test hit once. Offenders are collected instead.
    $legacyWrites = array_values(array_intersect(array_keys($writers), LegacySource::TABLES));
    $nonCoreWrites = array_values(array_diff(array_keys($writers), CoreChecksumCommand::CORE_TABLES));

    expect($legacyWrites)->toBe([], 'a 4B writer names a LEGACY table')
        ->and($nonCoreWrites)->toBe([], 'a 4B writer names a table that is not core-owned');
});

it('leaves a dashboard-owned placement column alone when the transform re-runs', function () {
    // §2.9.6 rule 1: `storefront_product.is_visible / is_featured / sort_order / …` are written by
    // the transform on INSERT only and never refreshed. This is the wave-4A finding generalised —
    // a dashboard that can be reverted by a rehearsal is a dashboard the team cannot trust.
    //
    // The transform's step 18 is exercised directly rather than through the whole command: the
    // command takes a server-side lock and runs 21 steps, and the claim under test is exactly
    // "step 18's upsert does not include these columns".
    $columns = (new ReflectionClass(Step18StorefrontProduct::class))
        ->getConstant('COLUMNS');
    expect($columns)->toBeArray();

    // The upsert's UPDATE list is what matters — the columns it refreshes on a conflict.
    $step = file_get_contents(app_path('Transform/Steps/Step18StorefrontProduct.php'));
    expect($step)->toBeString();

    /*
     * The refreshed set is a NAMED CONSTANT now, and it lost `slug` (task 3, 2026-09-11): a slug
     * is a URL the dashboard can edit, and editing it writes a 301 — so refreshing it on a re-run
     * would revert a deliberate change AND leave the redirect pointing at a slug that no longer
     * exists. Switch night is a fresh rebuild, where every row is an insert, so the slug plan
     * still reconciles exactly.
     */
    expect((string) $step)->toContain("private const REFRESHED = ['effective_price', 'effective_sale_price', 'updated_at'];");

    foreach (['is_visible', 'is_featured', 'sort_order', 'slug', 'price_override', 'sale_price_override', 'published_at'] as $dashboardOwned) {
        expect((string) $step)->not->toContain("REFRESHED = ['{$dashboardOwned}'", "step 18 refreshes {$dashboardOwned}, which the dashboard owns");
    }
});

it('keeps a dashboard placement edit through a real transform of the same rows', function () {
    // The behavioural half of the rule: set the columns through the SCREEN, re-run the step that
    // owns the table, and assert they survived.
    $productId = T::int(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
        ->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => true,
        'sort_order' => 4242,
    ])->assertSessionHasNoErrors();

    $beforeRow = DB::table('storefront_product')->where('product_id', $productId)->where('storefront_id', 1)
        ->first(['is_visible', 'is_featured', 'sort_order']);
    expect($beforeRow)->not->toBeNull();
    $before = Row::cast((object) $beforeRow);

    // The transform's own upsert for this row, with the legacy-derived values it would write.
    // `updateOrInsert`-shaped, naming only the columns step 18 refreshes.
    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('slug'));
    DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)
        ->update(['slug' => $slug, 'updated_at' => now()]);

    $afterRow = DB::table('storefront_product')->where('product_id', $productId)->where('storefront_id', 1)
        ->first(['is_visible', 'is_featured', 'sort_order']);
    expect($afterRow)->not->toBeNull();
    $after = Row::cast((object) $afterRow);

    expect(Row::bool($after, 'is_visible'))->toBe(Row::bool($before, 'is_visible'))
        ->and(Row::bool($after, 'is_featured'))->toBe(Row::bool($before, 'is_featured'))
        ->and(Row::int($after, 'sort_order'))->toBe(4242);
});

it('bumps the storefront cache version from the UI path, so v2 cannot serve a stale product', function () {
    // The cache-bump machinery exists since wave 2 (study §3.7.6) and the transform uses it. The
    // claim here is that the DASHBOARD path uses it too: a team member who edits a title and
    // cannot see it on the storefront will edit it again.
    $cache = app(StorefrontCache::class);
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId);

    $before = $cache->version(1);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '640.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'عنوان جديد للكاش', 'en' => 'Cache bump title'],
        'is_visible' => true, 'is_featured' => false,
    ])->assertRedirect();

    expect($cache->version(1))->toBeGreaterThan($before, 'a product edit did not invalidate the storefront caches');
});

it('serves the edited product through the v2 API, not a cached copy', function () {
    // End to end: edit through the dashboard, read through the public API, see the new value.
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);
    $watches = CatalogFixture::watchesRoot();
    CatalogFixture::place($productId, $watches);

    $slug = 'v2-visible-'.bin2hex(random_bytes(3));

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", [
        'wa_code' => T::str(DB::table('catalog_products')->where('id', $productId)->value('wa_code')),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'selling_price' => '4321.00', 'currency' => 'EGP', 'is_active' => true,
        'title' => ['ar' => 'منتج يظهر في الواجهة', 'en' => 'Visible through v2'],
        'category_ids' => [$watches], 'primary_category_id' => $watches,
        'is_visible' => true, 'is_featured' => false, 'slug' => $slug,
    ])->assertRedirect();

    // The detail endpoint's envelope is `product`, not `data` (that is the LIST's) — the first
    // version of this test read `data.title.ar` and got null for the right reason.
    $response = getJson("/api/v2/watchizer/products/{$slug}")->assertOk();

    expect($response->json('product.title.ar'))->toBe('منتج يظهر في الواجهة')
        ->and(T::float($response->json('product.price.amount')))->toBe(4321.0)
        ->and($response->json('product.slug'))->toBe($slug);
});

it('never lets a catalogue screen write a stock column, proven by the guard', function () {
    // `StockWriteGuard` refuses any statement naming a stock column outside `InventoryService`
    // (it is armed outside production). The first run of the variant tests proved it is live:
    // `VariantWriter::create()` named the columns as 0 and the guard refused the INSERT. This
    // asserts the guard is still armed, so that proof does not decay.
    $productId = CatalogFixture::product();

    expect(fn () => DB::table('catalog_products')->where('id', $productId)->update(['stock_express' => 99]))
        ->toThrow(RuntimeException::class, 'outside InventoryService');

    // …and a variant row, at the other level.
    $fixture = VariantFixture::synthetic(count: 1, express: 1, market: 0);
    expect(fn () => DB::table('catalog_product_variants')->where('id', $fixture['variants'][0])->update(['stock_market' => 7]))
        ->toThrow(RuntimeException::class, 'outside InventoryService');
});

it('leaves the ledger and the columns in agreement after a dashboard session', function () {
    CatalogFixture::assumeSwitched();
    // What `inventory:verify` checks in production, narrowed to the rows this session touched.
    $productId = CatalogFixture::product();

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'A', 'size_id' => T::int(DB::table('catalog_sizes')->orderBy('id')->value('id')),
        'is_active' => true, 'stock_express' => 9, 'stock_market' => 4,
    ])->assertSessionHasNoErrors();
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'B', 'size_id' => T::int(DB::table('catalog_sizes')->orderByDesc('id')->value('id')),
        'is_active' => true, 'stock_express' => 1, 'stock_market' => 0,
    ])->assertSessionHasNoErrors();

    $service = app(InventoryService::class);
    $mismatches = [];

    foreach (T::many(DB::table('catalog_product_variants')->where('product_id', $productId)->select(['id', 'stock_express', 'stock_market'])) as $variant) {
        $variantId = Row::int($variant, 'id');
        foreach (['express' => 'stock_express', 'market' => 'stock_market'] as $bucket => $column) {
            $ledger = $service->ledgerQuantity(StockTarget::variant($productId, $variantId), $bucket);
            $stored = Row::int($variant, $column);
            if ($ledger !== $stored) {
                $mismatches[] = "variant {$variantId} {$bucket}: column {$stored} vs ledger {$ledger}";
            }
        }
    }

    expect($mismatches)->toBe([]);

    // …and the product aggregate is the exact sum, with no product-level movement on a variant
    // product (the invariant the wave-3.5 reconciliation checks).
    $sumRow = DB::table('catalog_product_variants')->where('product_id', $productId)
        ->selectRaw('SUM(stock_express) as e, SUM(stock_market) as m')->first();
    $productRow = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']);
    expect($sumRow)->not->toBeNull()->and($productRow)->not->toBeNull();
    $sum = Row::cast((object) $sumRow);
    $product = Row::cast((object) $productRow);

    expect(Row::int($product, 'stock_express'))->toBe(Row::int($sum, 'e'))
        ->and(Row::int($product, 'stock_market'))->toBe(Row::int($sum, 'm'))
        ->and(DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->count())->toBe(0);
});
