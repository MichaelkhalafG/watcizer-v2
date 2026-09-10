<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 20 — products.stock / market_stock → inventory_movements: the ledger baseline
 * (study §2.9.2 step 20, §4).
 *
 * **APPEND-ONLY** (wave 3, milestone-audit finding). The first version of this step UPDATED the
 * baseline row in place when the legacy quantity had moved since the last additive run, which
 * made `inventory_movements` a mutable snapshot instead of a ledger — the one thing §4 says it
 * must never be, because `Σ quantity_delta = current column` is the invariant `inventory:verify`
 * relies on and an in-place edit silently breaks it.
 *
 * Now: a product/bucket with no transform movement gets its opening row (delta = after = the
 * legacy quantity); one whose latest transform movement disagrees with legacy gets a NEW
 * correction row carrying the difference; one that agrees is left alone. So a re-run against
 * unchanged data still writes nothing, and the deltas of every run telescope to the current
 * quantity.
 *
 * `created_at` is the product's legacy `updated_at`, so a rebuild from the same dump produces a
 * byte-identical table.
 */
final class Step20InventoryBaseline implements Step
{
    /** @var list<string> */
    private const COLUMNS = [
        'product_id', 'variant_id', 'bucket', 'quantity_delta', 'quantity_after', 'reason', 'reference_type',
        'reference_id', 'actor_type', 'actor_id', 'storefront_id', 'external_ref', 'note', 'created_at',
    ];

    // Wave 3.5: a product that sells through variants gets ONE baseline per variant per bucket and
    // NONE at product level — the level the ledger is authoritative at. A product without variants
    // is untouched by that change and still gets exactly the two rows it always did.

    public function number(): int
    {
        return 20;
    }

    public function name(): string
    {
        return 'inventory_baseline';
    }

    public function target(): string
    {
        return 'inventory_movements (reason = transform)';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        // Latest transform movement per target, by id. Reading the whole reason set in id order
        // and overwriting means the last write per key IS the latest row. The key names the LEVEL,
        // so a product baseline and a variant baseline can never be mistaken for each other.
        /** @var array<string, int> "p:{id}:{bucket}" | "v:{id}:{bucket}" => quantity_after */
        $latest = [];
        $rows = $ctx->db->table('inventory_movements')->select(['id', 'product_id', 'variant_id', 'bucket', 'quantity_after'])->where('reason', 'transform')->orderBy('id')->cursor();
        foreach ($rows as $row) {
            $variantId = Row::nint($row, 'variant_id');
            $key = $variantId === null ? 'p:'.Row::int($row, 'product_id') : 'v:'.$variantId;
            $latest[$key.':'.Row::str($row, 'bucket')] = Row::int($row, 'quantity_after');
        }

        // Which products sell through variants, and what each variant holds. Wave 3.5: for those
        // products the VARIANT is authoritative, so the baseline is written per variant and the
        // product's own columns are the aggregate step 13 already wrote.
        /** @var array<int, list<array{id: int, express: int, market: int}>> $variantsByProduct */
        $variantsByProduct = [];
        foreach ($ctx->db->table('catalog_product_variants')->select(['id', 'product_id', 'stock_express', 'stock_market'])->orderBy('id')->cursor() as $row) {
            $variantsByProduct[Row::int($row, 'product_id')][] = [
                'id' => Row::int($row, 'id'),
                'express' => Row::int($row, 'stock_express'),
                'market' => Row::int($row, 'stock_market'),
            ];
        }

        $ctx->chunkLegacy('products', ['id', 'stock', 'market_stock', 'updated_at'], function (Collection $products) use ($ctx, $result, $latest, $variantsByProduct): void {
            $inserts = [];
            foreach ($products as $p) {
                $id = Row::int($p, 'id');
                $result->read++;
                $stamp = Row::nstr($p, 'updated_at');

                $targets = [];
                if (isset($variantsByProduct[$id])) {
                    foreach ($variantsByProduct[$id] as $variant) {
                        $targets[] = ['key' => 'v:'.$variant['id'], 'variant_id' => $variant['id'], 'express' => $variant['express'], 'market' => $variant['market']];
                    }
                } else {
                    $targets[] = ['key' => 'p:'.$id, 'variant_id' => null, 'express' => Row::int($p, 'stock'), 'market' => Row::nint($p, 'market_stock') ?? 0];
                }

                foreach ($targets as $target) {
                    foreach (['express' => $target['express'], 'market' => $target['market']] as $bucket => $qty) {
                        $current = $latest[$target['key'].':'.$bucket] ?? null;
                        if ($current === $qty) {
                            $result->writes->unchanged++;

                            continue;
                        }
                        $opening = $current === null;
                        $inserts[] = [
                            'product_id' => $id,
                            'variant_id' => $target['variant_id'],
                            'bucket' => $bucket,
                            'quantity_delta' => $opening ? $qty : $qty - $current,
                            'quantity_after' => $qty,
                            'reason' => 'transform',
                            'reference_type' => $target['variant_id'] === null ? 'legacy:products' : 'legacy:product_variants',
                            'reference_id' => $target['variant_id'] ?? $id,
                            'actor_type' => 'system',
                            'actor_id' => null,
                            'storefront_id' => null,
                            'external_ref' => null,
                            'note' => $opening ? 'transform baseline' : 'transform re-baseline',
                            'created_at' => $stamp,
                        ];
                        if (! $opening) {
                            $result->count("baseline_moved:$bucket");
                        }
                    }
                }
            }
            $result->writes->inserted += $ctx->writer->insert('inventory_movements', self::COLUMNS, $inserts);
        });
    }
}
