<?php

namespace App\Console\Commands\Concerns;

use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A throwaway `catalog_products` row for a concurrency probe to fight over.
 *
 * Every probe builds its own product rather than borrowing a real one. That is not fastidiousness:
 * a probe that commits and releases stock on a real row leaves that row's `updated_at` moved, and
 * the next transform then reports an update it should not have to make — which is exactly the
 * false signal that would teach a reviewer to ignore the transform's "zero net changes" line.
 *
 * The row is inactive, placed on no storefront and referenced by nothing, so it is invisible to
 * every read path and safe to hard-delete on the way out.
 */
trait CreatesProbeProduct
{
    /** @param  string  $ref  a `probe:`-prefixed marker, used as the unique `wa_code` */
    protected function createProbeProduct(string $ref): int
    {
        $brandId = DB::table('catalog_brands')->orderBy('id')->value('id');
        if ($brandId === null) {
            throw new RuntimeException('No catalog_brands row to hang a probe product on — run core:transform first.');
        }

        return StockWriteGuard::allow(fn (): int => (int) DB::table('catalog_products')->insertGetId([
            'family' => 'watch',
            'brand_id' => (int) (is_numeric($brandId) ? $brandId : 0),
            'wa_code' => $ref,
            'selling_price' => '0.00',
            'currency' => 'EGP',
            'stock_express' => 0,
            'stock_market' => 0,
            'in_stock' => 0,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * N variants on a probe product, each opened at zero.
     *
     * It lives here rather than in the probe command because inserting a row that CARRIES stock
     * columns is a stock write, and StockWriteGuard is right to say so — it caught this on the
     * first run. The write window belongs to the fixture helper, which keeps the census of files
     * allowed to open it at the same four.
     *
     * @return list<int>
     */
    protected function createProbeVariants(int $productId, int $count): array
    {
        return StockWriteGuard::allow(function () use ($productId, $count): array {
            $ids = [];
            for ($i = 1; $i <= $count; $i++) {
                $ids[] = (int) DB::table('catalog_product_variants')->insertGetId([
                    'product_id' => $productId,
                    'sku' => null,
                    'label' => "probe size {$i}",
                    'price_delta' => '0.00',
                    'stock_express' => 0,
                    'stock_market' => 0,
                    'is_active' => 1,
                    'sort' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $ids;
        });
    }

    protected function deleteProbeProduct(int $productId): void
    {
        StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $productId)->delete());
    }
}
