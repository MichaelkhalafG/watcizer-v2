<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 20 — products.stock / market_stock → inventory_movements: one `transform` row per
 * product per bucket (quantity_delta = quantity_after = stock, actor system) — the ledger
 * baseline (study §2.9.2 step 20, §4). inventory_movements has no natural unique key, so
 * the existing baseline row (reason = transform, product, bucket) is looked up and only
 * re-written when the legacy quantity moved; created_at is the product's legacy updated_at
 * so a re-run against unchanged data is byte-identical.
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
        /** @var array<string, array{id: int, after: int}> "product:bucket" */
        $existing = [];
        $rows = $ctx->db->table('inventory_movements')->select(['id', 'product_id', 'bucket', 'quantity_after'])->where('reason', 'transform')->orderBy('id')->cursor();
        foreach ($rows as $row) {
            $existing[Row::int($row, 'product_id').':'.Row::str($row, 'bucket')] = ['id' => Row::int($row, 'id'), 'after' => Row::int($row, 'quantity_after')];
        }

        $ctx->chunkLegacy('products', ['id', 'stock', 'market_stock', 'updated_at'], function (Collection $products) use ($ctx, $result, $existing): void {
            $inserts = [];
            foreach ($products as $p) {
                $id = Row::int($p, 'id');
                $result->read++;
                foreach (['express' => Row::int($p, 'stock'), 'market' => Row::nint($p, 'market_stock') ?? 0] as $bucket => $qty) {
                    $current = $existing["$id:$bucket"] ?? null;
                    if ($current === null) {
                        $inserts[] = [
                            'product_id' => $id,
                            'variant_id' => null,
                            'bucket' => $bucket,
                            'quantity_delta' => $qty,
                            'quantity_after' => $qty,
                            'reason' => 'transform',
                            'reference_type' => 'legacy:products',
                            'reference_id' => $id,
                            'actor_type' => 'system',
                            'actor_id' => null,
                            'storefront_id' => null,
                            'external_ref' => null,
                            'note' => 'transform baseline',
                            'created_at' => Row::nstr($p, 'updated_at'),
                        ];
                    } elseif ($current['after'] !== $qty) {
                        $ctx->writer->updateById('inventory_movements', $current['id'], ['quantity_delta', 'quantity_after'], ['quantity_delta' => $qty, 'quantity_after' => $qty]);
                        $result->writes->updated++;
                        $result->count("baseline_moved:$bucket");
                    } else {
                        $result->writes->unchanged++;
                    }
                }
            }
            $result->writes->inserted += $ctx->writer->insert('inventory_movements', self::COLUMNS, $inserts);
        });
    }
}
