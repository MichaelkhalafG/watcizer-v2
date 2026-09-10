<?php

namespace Tests\Support;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Support\Facades\DB;

/**
 * Wave 3.5 fixtures: products that sell through variants.
 *
 * Legacy `product_variants` is empty, so every variant test builds its own. Two shapes, because
 * they answer different questions:
 *
 *  - `synthetic()` — a brand-new catalog-only product. Fine for anything that stays inside
 *    `catalog_*` and `inventory_movements`.
 *  - `converted()` — a REAL product (one that exists in legacy `products` too) turned into a
 *    variant product. Needed by anything that writes an order line, because `order_items.product_id`
 *    still carries a foreign key to legacy `products` until M2 runs.
 *
 * The conversion in `converted()` is done by hand precisely because NOTHING IN THE SYSTEM DOES IT:
 * turning a non-variant product into a variant one means zeroing its product-level columns,
 * retiring its product-level ledger rows and opening the variants, and wave 3.5 provides no
 * operation for that. It is on the flag list for wave 4's dashboard, where such an edit belongs.
 *
 * Every write goes through `StockWriteGuard::allow()` — inserting a row that CARRIES stock columns
 * is a stock write, and the guard is right to say so.
 */
final class VariantFixture
{
    /** @return array{product: int, variants: list<int>} */
    public static function synthetic(int $count = 2, int $express = 5, int $market = 3): array
    {
        $brandId = T::int(DB::table('catalog_brands')->orderBy('id')->value('id'));
        $productId = (int) StockWriteGuard::allow(fn () => DB::table('catalog_products')->insertGetId([
            'family' => 'fashion', 'brand_id' => $brandId,
            'wa_code' => 'variant-test-'.bin2hex(random_bytes(5)),
            'selling_price' => '500.00', 'currency' => 'EGP',
            'stock_express' => 0, 'stock_market' => 0, 'in_stock' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $variants = self::addVariants($productId, $count, 'Size');
        $service = app(InventoryService::class);
        foreach ($variants as $variantId) {
            $service->set(StockTarget::variant($productId, $variantId), 'express', $express, 'manual');
            $service->set(StockTarget::variant($productId, $variantId), 'market', $market, 'manual');
        }

        return ['product' => $productId, 'variants' => $variants];
    }

    /**
     * A real, storefront-1 product converted to sell through variants.
     *
     * @return array{product: int, variants: list<int>}
     */
    public static function converted(int $count = 1, int $express = 9, ?int $productId = null): array
    {
        $productId ??= T::int(DB::table('catalog_products as cp')
            ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
            ->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));

        StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $productId)
            ->update(['stock_express' => 0, 'stock_market' => 0, 'in_stock' => 0]));
        DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->delete();

        $variants = self::addVariants($productId, $count, 'Converted size');
        foreach ($variants as $variantId) {
            app(InventoryService::class)->set(StockTarget::variant($productId, $variantId), 'express', $express, 'manual');
        }

        return ['product' => $productId, 'variants' => $variants];
    }

    /** An order with one line naming a variant. `ZZ` order numbers mark probe rows. */
    public static function order(int $productId, int $variantId, int $quantity): int
    {
        $addressId = T::int(DB::table('addresses')->orderBy('id')->value('id'));
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => null, 'address_id' => $addressId,
            'total_price_for_order' => '0.00', 'payment_method' => 'cash',
            'order_number' => 'ZZ'.random_int(100000, 999999), 'status' => 'processing',
            'guest_name' => 'variant-test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'variant_id' => $variantId,
            'offer_id' => null, 'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
            'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** @return list<int> */
    private static function addVariants(int $productId, int $count, string $labelPrefix): array
    {
        $variants = [];
        for ($i = 1; $i <= $count; $i++) {
            $variants[] = (int) StockWriteGuard::allow(fn () => DB::table('catalog_product_variants')->insertGetId([
                'product_id' => $productId, 'sku' => null, 'label' => "{$labelPrefix} {$i}",
                'price_delta' => '0.00', 'stock_express' => 0, 'stock_market' => 0,
                'is_active' => 1, 'sort' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]));
        }

        return $variants;
    }
}
