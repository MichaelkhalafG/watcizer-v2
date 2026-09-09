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
        // Latest transform movement per (product, bucket), by id. Reading the whole reason set in
        // id order and overwriting means the last write per key IS the latest row.
        /** @var array<string, int> "product:bucket" => quantity_after */
        $latest = [];
        $rows = $ctx->db->table('inventory_movements')->select(['id', 'product_id', 'bucket', 'quantity_after'])->where('reason', 'transform')->orderBy('id')->cursor();
        foreach ($rows as $row) {
            $latest[Row::int($row, 'product_id').':'.Row::str($row, 'bucket')] = Row::int($row, 'quantity_after');
        }

        $ctx->chunkLegacy('products', ['id', 'stock', 'market_stock', 'updated_at'], function (Collection $products) use ($ctx, $result, $latest): void {
            $inserts = [];
            foreach ($products as $p) {
                $id = Row::int($p, 'id');
                $result->read++;
                foreach (['express' => Row::int($p, 'stock'), 'market' => Row::nint($p, 'market_stock') ?? 0] as $bucket => $qty) {
                    $current = $latest["$id:$bucket"] ?? null;
                    if ($current === $qty) {
                        $result->writes->unchanged++;

                        continue;
                    }
                    $opening = $current === null;
                    $inserts[] = [
                        'product_id' => $id,
                        'variant_id' => null,
                        'bucket' => $bucket,
                        'quantity_delta' => $opening ? $qty : $qty - $current,
                        'quantity_after' => $qty,
                        'reason' => 'transform',
                        'reference_type' => 'legacy:products',
                        'reference_id' => $id,
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'storefront_id' => null,
                        'external_ref' => null,
                        'note' => $opening ? 'transform baseline' : 'transform re-baseline',
                        'created_at' => Row::nstr($p, 'updated_at'),
                    ];
                    if (! $opening) {
                        $result->count("baseline_moved:$bucket");
                    }
                }
            }
            $result->writes->inserted += $ctx->writer->insert('inventory_movements', self::COLUMNS, $inserts);
        });
    }
}
