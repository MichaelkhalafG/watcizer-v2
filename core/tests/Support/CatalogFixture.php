<?php

namespace Tests\Support;

use App\Domain\Catalog\CategoryTreeWriter;
use App\Domain\Inventory\StockWriteGuard;
use App\Models\Storefront\StorefrontCategory;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Wave-4B fixtures: category nodes, products and placements built the way the DASHBOARD builds
 * them.
 *
 * Everything here goes through the real writers wherever one exists — `CategoryTreeWriter` for a
 * node, `InventoryService` for a quantity — because a fixture that writes `path` by hand would
 * make the tree tests pass against data the application could never produce. The two exceptions
 * are raw inserts of `catalog_products` rows (there is no factory, by design: the transform owns
 * that table's shape) and they go through `StockWriteGuard::allow()` because inserting a row that
 * CARRIES stock columns is a stock write and the guard is right to say so.
 *
 * Companion to {@see VariantFixture}, which covers wave 3.5's shapes.
 */
final class CatalogFixture
{
    /** Storefront 1 is Watchizer by construction (deterministic ids, study §2.9.3). */
    public const STOREFRONT = 1;

    /**
     * Build state as if the write-switch had happened.
     *
     * `App\Domain\Catalog\PreSwitch` refuses every dashboard CREATION until the switch, because
     * the rebuild would delete the row (developer decision 2026-09-11). Most 4B tests are about
     * the world AFTER that — a tree move, a family derivation, the one-primary invariant — and
     * cannot have a subject without a catalogue to move around.
     *
     * So they say so, out loud, at the top of the test. It is a config value, so it dies with the
     * test; and it is not a bypass, because `PreSwitchTest` drives the same endpoints with the flag
     * in its DEFAULT state and asserts every refusal. A guard proved in one file and assumed away
     * in the others is still proved.
     */
    public static function assumeSwitched(): void
    {
        config()->set('transform.write_switch_completed', true);
    }

    /**
     * A root category with an EN name — the level the family resolver reads.
     *
     * @return array{id: int, slug: string}
     */
    public static function root(string $nameEn, string $nameAr = 'تصنيف اختبار', int $storefrontId = self::STOREFRONT): array
    {
        self::assumeSwitched();
        $node = app(CategoryTreeWriter::class)->create($storefrontId, null, ['ar' => $nameAr, 'en' => $nameEn]);

        return ['id' => $node->id, 'slug' => Coerce::str($node->getAttribute('slug'))];
    }

    /**
     * A child of a node — the level the resolver reads as the legacy "sub type".
     *
     * @return array{id: int, slug: string}
     */
    public static function child(int $parentId, string $nameEn, string $nameAr = 'فرع اختبار', int $storefrontId = self::STOREFRONT): array
    {
        self::assumeSwitched();
        $node = app(CategoryTreeWriter::class)->create($storefrontId, $parentId, ['ar' => $nameAr, 'en' => $nameEn]);

        return ['id' => $node->id, 'slug' => Coerce::str($node->getAttribute('slug'))];
    }

    /** The node the transform created for legacy `category_type` 1 (`Watches`), by its legacy key. */
    public static function watchesRoot(int $storefrontId = self::STOREFRONT): int
    {
        return T::int(DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('legacy_source', 'category_type')
            ->where('legacy_id', 1)
            ->value('id'));
    }

    /** The node for legacy `category_type` 2 (`Fashion`). */
    public static function fashionRoot(int $storefrontId = self::STOREFRONT): int
    {
        return T::int(DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('legacy_source', 'category_type')
            ->where('legacy_id', 2)
            ->value('id'));
    }

    /**
     * A catalog-only product (no legacy row), the safe shape for anything that does not write an
     * order line. Returns its id.
     */
    public static function product(string $family = 'fashion', float $selling = 500.0, bool $active = true): int
    {
        $brandId = T::int(DB::table('catalog_brands')->orderBy('id')->value('id'));

        $id = (int) StockWriteGuard::allow(fn () => DB::table('catalog_products')->insertGetId([
            'family' => $family,
            'brand_id' => $brandId,
            'wa_code' => '4b-test-'.bin2hex(random_bytes(5)),
            'selling_price' => number_format($selling, 2, '.', ''),
            'purchase_price' => '0.00',
            'currency' => 'EGP',
            'stock_express' => 0,
            'stock_market' => 0,
            'in_stock' => 0,
            'low_stock_threshold' => 5,
            'is_active' => $active ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        DB::table('catalog_product_translations')->insert([
            ['product_id' => $id, 'locale' => 'ar', 'title' => 'منتج اختبار 4B'],
            ['product_id' => $id, 'locale' => 'en', 'title' => 'Wave 4B test product'],
        ]);

        /*
         * One image, because since 2026-09-11 a product with NO image may not be made visible
         * (task 4.2, `PlacementWriter::assertHasImage()`), and a fixture that cannot be published
         * is not the shape of anything in this catalogue: all 464 live products have an image.
         * The gate itself is proved with a product whose image is deliberately removed.
         */
        DB::table('catalog_product_images')->insert([
            'product_id' => $id,
            'path' => 'Product/4b-fixture.webp',
            'is_cover' => 1,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** A product with NO image — the shape the visibility gate must refuse (task 4.2). */
    public static function productWithoutImage(): int
    {
        $id = self::product();
        DB::table('catalog_product_images')->where('product_id', $id)->delete();

        return $id;
    }

    /** A product with NO Arabic translation — the shape the visibility gate must refuse. */
    public static function productWithoutArabic(): int
    {
        $id = self::product();
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->delete();

        return $id;
    }

    /** Place a product in a node, optionally as the primary. */
    public static function place(int $productId, int $nodeId, bool $primary = true, int $storefrontId = self::STOREFRONT): void
    {
        DB::table('storefront_category_product')->insert([
            'storefront_id' => $storefrontId,
            'storefront_category_id' => $nodeId,
            'product_id' => $productId,
            'sort_order' => 0,
            'is_primary' => $primary ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Give a product a row on a storefront, so the placement screen can see it.
     *
     * This writes the ROW ONLY and places nothing — deliberately, so that a test which needs an
     * unplaced product still gets one. Note that since 2026-09-11 the WRITER refuses to make a
     * product visible on a storefront it is not placed on (task 4.2,
     * `PlacementWriter::assertPlacedSomewhere()`), so a test that drives the placement SCREEN with
     * `visible: true` has to call `place()` as well. Inserting the row directly, as here, bypasses
     * the writer on purpose: that is how the "the gate refuses this state" cases are built.
     */
    public static function onStorefront(int $productId, bool $visible = true, int $storefrontId = self::STOREFRONT): void
    {
        DB::table('storefront_product')->insert([
            'storefront_id' => $storefrontId,
            'product_id' => $productId,
            'is_visible' => $visible ? 1 : 0,
            'is_featured' => 0,
            'sort_order' => 0,
            'slug' => '4b-test-'.$productId,
            'price_override' => null,
            'sale_price_override' => null,
            'effective_price' => '500.00',
            'effective_sale_price' => null,
            'published_at' => $visible ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A SECOND storefront, for the scope tests. Brand Fashion (id 2) may or may not exist on a
     * given machine, so it is created when missing and returned either way.
     */
    public static function secondStorefront(): int
    {
        $existing = DB::table('storefronts')->where('id', '!=', self::STOREFRONT)->orderBy('id')->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('storefronts')->insertGetId([
            'code' => 'scope-test',
            'name' => 'Scope test storefront',
            'domain' => null,
            'locales' => json_encode(['ar', 'en']),
            'default_locale' => 'ar',
            'currency' => 'EGP',
            'is_active' => 1,
            'settings' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A node of the given storefront, whatever it is — for "another storefront's row" tests. */
    public static function anyNodeOf(int $storefrontId): int
    {
        $existing = DB::table('storefront_categories')->where('storefront_id', $storefrontId)->orderBy('id')->value('id');
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        self::assumeSwitched();

        return app(CategoryTreeWriter::class)
            ->create($storefrontId, null, ['ar' => 'جذر متجر آخر', 'en' => 'Other storefront root'])
            ->id;
    }

    /** Re-read a node as a model, for assertions about `path`/`depth`. */
    public static function node(int $nodeId): StorefrontCategory
    {
        $node = StorefrontCategory::query()->find($nodeId);
        Assert::assertInstanceOf(StorefrontCategory::class, $node, "node {$nodeId} vanished");

        return $node;
    }
}
