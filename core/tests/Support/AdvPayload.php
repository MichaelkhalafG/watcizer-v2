<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The payload shape the ADVERSARIAL probes post (ported 2026-09-11 from the wave-4B review).
 *
 * It is deliberately the FLAT shape — `category_ids` / `is_visible` / `slug` at the top level,
 * with no `storefronts[<id>]` wrapper — because that is what the reviewer drove the endpoints
 * with, and `ProductController::foldFlatStorefront()` is what turns it into the per-storefront
 * shape. Keeping these probes flat therefore keeps the fold itself covered: if someone deletes it
 * as dead code, these tests go red instead of the console paths quietly breaking.
 */
final class AdvPayload
{
    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    public static function product(int $node, array $over = [], string $prefix = 'adv'): array
    {
        return array_merge([
            'wa_code' => $prefix.'-'.bin2hex(random_bytes(5)),
            'sku' => null,
            'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
            'selling_price' => '1200.00',
            'purchase_price' => '800.00',
            'sale_price' => null,
            'currency' => 'EGP',
            'low_stock_threshold' => 5,
            'is_active' => true,
            'title' => ['ar' => 'منتج عدائي', 'en' => 'Adversarial product'],
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
        ], $over);
    }

    /** The id of the most recently inserted product — what a create-then-inspect probe needs. */
    public static function newestProductId(): int
    {
        return T::int(DB::table('catalog_products')->orderByDesc('id')->value('id'));
    }

    /** The stored family of a product. */
    public static function family(int $productId): string
    {
        return T::str(DB::table('catalog_products')->where('id', $productId)->value('family'));
    }

    /** The stored specs JSON, or null when the family keeps its specs in a typed table. */
    public static function specs(int $productId): ?string
    {
        $value = DB::table('catalog_products')->where('id', $productId)->value('specs');

        return is_string($value) ? $value : null;
    }

    /**
     * Decoded specs JSON.
     *
     * @return array<string, mixed>
     */
    public static function specsArray(int $productId): array
    {
        $decoded = json_decode(self::specs($productId) ?? '{}', true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** An image row inserted behind the writer's back — for probes about the VISIBILITY gate. */
    public static function addImage(int $productId): void
    {
        DB::table('catalog_product_images')->insert([
            'product_id' => $productId,
            'path' => 'probe/img-'.bin2hex(random_bytes(4)).'.webp',
            'is_cover' => 1,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function isVisible(int $productId, int $storefrontId = 1): bool
    {
        return (bool) DB::table('storefront_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)->value('is_visible');
    }

    public static function placements(int $productId, int $storefrontId = 1): int
    {
        return T::int(DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)->count());
    }

    /** The slug stored for a product on a storefront (`''` when there is no row). */
    public static function slug(int $productId, int $storefrontId = 1): string
    {
        $value = DB::table('storefront_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)->value('slug');

        return is_string($value) ? $value : '';
    }
}
