<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * inventory:verify — the nightly reconciliation the study asks for (§4.2), extended to variants
 * by wave 3.5.
 *
 * Four invariants, each catching a different way the ledger can stop being true:
 *
 *  1. **Variant ledger = variant column.** `Σ quantity_delta` for a variant equals that variant's
 *     `stock_express` / `stock_market`. This is the authoritative check for a product that has
 *     variants.
 *  2. **Product ledger = product column, for products with NO variants.** Wave 3's original check,
 *     unchanged, now scoped to the products it still applies to.
 *  3. **Aggregate.** For a product WITH variants, `catalog_products.stock_*` equals the SUM of its
 *     variants' columns — and no product-level movement exists for it at all. The second half
 *     matters as much as the first: a product-level movement against a variant product is how the
 *     aggregate would start drifting, and it is refused at write time, so finding one here means
 *     something wrote around the service.
 *  4. **`in_stock`.** For a variant product the flag means "some ACTIVE variant has stock", which
 *     no quantity check would catch: deactivating the only stocked variant moves no units and
 *     writes no movement, but does change whether the product is orderable.
 *
 * In production, where StockWriteGuard is not armed, this command IS the net.
 *
 *   --fix   append a `reason = adjustment` movement that re-bases the LEDGER onto the column, at
 *           whichever level drifted. Never edits an existing row; the drift stays in the history.
 *           It corrects invariants 1 and 2 only — an aggregate or a flag disagreement (3 and 4) is
 *           a catalog problem, not a ledger one, and is reported for a human.
 */
final class InventoryVerifyCommand extends Command
{
    protected $signature = 'inventory:verify {--fix : append an adjustment movement for each drifted ledger} {--json}';

    protected $description = 'Assert the stock ledger, the variant columns, the product aggregate and in_stock all agree';

    public function handle(InventoryService $inventory): int
    {
        /** @var array<string, int> $variantLedger  "variantId:bucket" => Σ delta */
        $variantLedger = [];
        foreach (DB::table('inventory_movements')->selectRaw('variant_id, bucket, SUM(quantity_delta) AS d')
            ->whereNotNull('variant_id')->groupBy('variant_id', 'bucket')->cursor() as $row) {
            $variantLedger[Row::int($row, 'variant_id').':'.Row::str($row, 'bucket')] = (int) (Row::nfloat($row, 'd') ?? 0.0);
        }

        /** @var array<string, int> $productLedger  "productId:bucket" => Σ delta of PRODUCT-level rows */
        $productLedger = [];
        foreach (DB::table('inventory_movements')->selectRaw('product_id, bucket, SUM(quantity_delta) AS d')
            ->whereNull('variant_id')->groupBy('product_id', 'bucket')->cursor() as $row) {
            $productLedger[Row::int($row, 'product_id').':'.Row::str($row, 'bucket')] = (int) (Row::nfloat($row, 'd') ?? 0.0);
        }

        /** @var array<int, array{express: int, market: int, active_in_stock: bool, count: int}> $variantsByProduct */
        $variantsByProduct = [];
        /** @var list<array{product_id: int, variant_id: int|null, bucket: string, column: int, ledger: int}> $drift */
        $drift = [];
        $checked = 0;

        foreach (DB::table('catalog_product_variants')->select(['id', 'product_id', 'stock_express', 'stock_market', 'is_active'])->orderBy('id')->cursor() as $variant) {
            $variantId = Row::int($variant, 'id');
            $productId = Row::int($variant, 'product_id');
            $express = Row::int($variant, 'stock_express');
            $market = Row::int($variant, 'stock_market');
            $active = Row::bool($variant, 'is_active');

            $seen = $variantsByProduct[$productId] ?? ['express' => 0, 'market' => 0, 'active_in_stock' => false, 'count' => 0];
            $variantsByProduct[$productId] = [
                'express' => $seen['express'] + $express,
                'market' => $seen['market'] + $market,
                'active_in_stock' => $seen['active_in_stock'] || ($active && ($express > 0 || $market > 0)),
                'count' => $seen['count'] + 1,
            ];

            // invariant 1
            foreach (['express' => $express, 'market' => $market] as $bucket => $column) {
                $checked++;
                $ledger = $variantLedger["$variantId:$bucket"] ?? 0;
                if ($column !== $ledger) {
                    $drift[] = ['product_id' => $productId, 'variant_id' => $variantId, 'bucket' => $bucket, 'column' => $column, 'ledger' => $ledger];
                }
            }
        }

        /** @var list<array{product_id: int, kind: string, expected: string, actual: string}> $mismatch */
        $mismatch = [];

        foreach (DB::table('catalog_products')->select(['id', 'stock_express', 'stock_market', 'in_stock'])->orderBy('id')->cursor() as $product) {
            $productId = Row::int($product, 'id');
            $variants = $variantsByProduct[$productId] ?? null;

            if ($variants === null) {
                // invariant 2 — a product with no variants, exactly wave 3's check
                foreach (InventoryService::columns() as $bucket => $column) {
                    $checked++;
                    $value = Row::int($product, $column);
                    $ledger = $productLedger["$productId:$bucket"] ?? 0;
                    if ($value !== $ledger) {
                        $drift[] = ['product_id' => $productId, 'variant_id' => null, 'bucket' => $bucket, 'column' => $value, 'ledger' => $ledger];
                    }
                }

                continue;
            }

            // invariant 3 — the aggregate, and the absence of product-level movements
            foreach (['express', 'market'] as $bucket) {
                $checked++;
                $column = Row::int($product, InventoryService::columns()[$bucket]);
                if ($column !== $variants[$bucket]) {
                    $mismatch[] = ['product_id' => $productId, 'kind' => "aggregate:{$bucket}", 'expected' => (string) $variants[$bucket], 'actual' => (string) $column];
                }
                if (($productLedger["$productId:$bucket"] ?? 0) !== 0) {
                    $mismatch[] = ['product_id' => $productId, 'kind' => "product-level movement on a variant product:{$bucket}", 'expected' => 'none', 'actual' => 'present'];
                }
            }

            // invariant 4 — in_stock means "some ACTIVE variant has stock"
            $checked++;
            $flag = Row::bool($product, 'in_stock');
            if ($flag !== $variants['active_in_stock']) {
                $mismatch[] = ['product_id' => $productId, 'kind' => 'in_stock', 'expected' => $variants['active_in_stock'] ? '1' : '0', 'actual' => $flag ? '1' : '0'];
            }
        }

        // A movement whose product or variant no longer exists cannot be reconciled at all.
        $orphanProducts = DB::table('inventory_movements as im')
            ->leftJoin('catalog_products as cp', 'cp.id', '=', 'im.product_id')->whereNull('cp.id')->count();
        $orphanVariants = DB::table('inventory_movements as im')
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'im.variant_id')
            ->whereNotNull('im.variant_id')->whereNull('v.id')->count();
        $orphans = $orphanProducts + $orphanVariants;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'checked' => $checked, 'drift' => $drift, 'mismatch' => $mismatch,
                'orphan_movements' => ['product' => $orphanProducts, 'variant' => $orphanVariants],
            ], JSON_PRETTY_PRINT));

            return $drift === [] && $mismatch === [] && $orphans === 0 ? self::SUCCESS : self::FAILURE;
        }

        $variantCount = DB::table('catalog_product_variants')->count();
        $this->info("inventory:verify — {$checked} checks over ".DB::table('catalog_products')->count()." product(s) and {$variantCount} variant(s)");

        if ($orphans > 0) {
            $this->error("{$orphanProducts} movement(s) reference a product that no longer exists; {$orphanVariants} reference a missing variant.");
        }
        if ($mismatch !== []) {
            $this->error(count($mismatch).' aggregate/flag mismatch(es) — a catalog problem, not a ledger one; --fix does NOT touch these:');
            $this->table(['product', 'what', 'expected', 'actual'], array_map(fn (array $m): array => [$m['product_id'], $m['kind'], $m['expected'], $m['actual']], $mismatch));
        }
        if ($drift === []) {
            if ($mismatch === [] && $orphans === 0) {
                $this->info('Ledger, variant columns, product aggregate and in_stock all agree.');
            }

            return $mismatch === [] && $orphans === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['product', 'variant', 'bucket', 'column', 'ledger'], array_map(
            fn (array $d): array => [$d['product_id'], $d['variant_id'] ?? '—', $d['bucket'], $d['column'], $d['ledger']],
            $drift
        ));

        if (! (bool) $this->option('fix')) {
            $this->error(count($drift).' ledger(s) drifted. Re-run with --fix to append re-basing adjustments.');

            return self::FAILURE;
        }

        foreach ($drift as $d) {
            // The column is what the storefront sold against, so the column wins and the LEDGER is
            // corrected to it — by appending a movement, never by editing one, and never by moving
            // the column.
            $inventory->rebase(StockTarget::fromLine($d['product_id'], $d['variant_id']), $d['bucket'], 'inventory:verify re-base');
        }
        $this->info(count($drift).' adjustment movement(s) appended.');

        return $mismatch === [] && $orphans === 0 ? self::SUCCESS : self::FAILURE;
    }
}
