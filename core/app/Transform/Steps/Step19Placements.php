<?php

namespace App\Transform\Steps;

use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 19 — products.category_type_id / sub_type_id → storefront_category_product, key
 * (storefront_category_id, product_id): the sub-type node row is_primary = 1, the
 * category-type node row is_primary = 0; products with neither → A-18 (unplaced).
 * Existing placements that no longer match legacy are NEVER deleted (additive
 * transform) — they are counted and listed as `stale_placements`.
 */
final class Step19Placements implements Step
{
    /** @var list<string> */
    private const COLUMNS = ['storefront_id', 'storefront_category_id', 'product_id', 'sort_order', 'is_primary', 'created_at', 'updated_at'];

    public function number(): int
    {
        return 19;
    }

    public function name(): string
    {
        return 'placements';
    }

    public function target(): string
    {
        return 'storefront_category_product';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $nodes = new CategoryNodes($ctx);
        /** @var array<string, int> */
        $nodeCache = [];
        $resolve = function (string $source, int $legacyId, ?int $legacyParentId) use ($ctx, $nodes, &$nodeCache): int {
            $key = "$source:$legacyId:".($legacyParentId ?? 'null');
            if (! isset($nodeCache[$key])) {
                $id = $source === 'category_type'
                    ? ($ctx->idMap->get('category_types', $legacyId, 'storefront_categories') ?? $nodes->find($source, $legacyId, null))
                    : ($ctx->idMap->get('sub_types:'.$legacyParentId, $legacyId, 'storefront_categories') ?? $nodes->find($source, $legacyId, $legacyParentId));
                if ($id === null) {
                    throw new RuntimeException("No storefront_categories node for $source $legacyId (parent ".($legacyParentId ?? 'NULL').') — run steps 15/16 first.');
                }
                $nodeCache[$key] = $id;
            }

            return $nodeCache[$key];
        };

        /** @var array<string, true> desired "category:product" */
        $desired = [];

        $ctx->chunkLegacy('products', ['id', 'category_type_id', 'sub_type_id', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $resolve, &$desired): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $result->read++;
                $typeId = Row::nint($row, 'category_type_id');
                $subId = Row::nint($row, 'sub_type_id');
                if ($typeId === null && $subId === null) {
                    $result->count('unplaced');                          // A-18
                    $ctx->diff('A-18', 'products', $id, 'category_type_id=NULL sub_type_id=NULL', 'unplaced');

                    continue;
                }
                if ($typeId !== null) {
                    $node = $resolve('category_type', $typeId, null);
                    $out[] = $this->row($ctx, $node, $id, 0, $row);
                    $desired["$node:$id"] = true;
                }
                if ($subId !== null) {
                    if ($typeId === null) {
                        $result->count('sub_type_without_type');       // A-18 variant: cannot pair
                        $ctx->diff('A-18', 'products', $id, "sub_type_id=$subId category_type_id=NULL", 'sub type placement skipped (no pair)');
                    } else {
                        $node = $resolve('sub_type', $subId, $typeId);
                        $out[] = $this->row($ctx, $node, $id, 1, $row);
                        $desired["$node:$id"] = true;
                    }
                }
            }
            $result->writes->add($ctx->writer->upsert('storefront_category_product', self::COLUMNS, ['storefront_category_id', 'product_id'], ['is_primary', 'updated_at'], $out));
        });

        $stale = 0;
        $existing = $ctx->db->table('storefront_category_product')->select(['storefront_category_id', 'product_id'])->where('storefront_id', $ctx->storefrontId)->orderBy('id')->cursor();
        foreach ($existing as $row) {
            $key = Row::int($row, 'storefront_category_id').':'.Row::int($row, 'product_id');
            if (! isset($desired[$key])) {
                $stale++;
                $ctx->diff('STALE', 'storefront_category_product', $key, 'placement not in legacy any more', 'kept (additive transform never deletes)');
            }
        }
        if ($stale > 0) {
            $result->count('stale_placements', $stale);
        }
    }

    /** @return array<string, mixed> */
    private function row(TransformContext $ctx, int $nodeId, int $productId, int $primary, \stdClass $row): array
    {
        return [
            'storefront_id' => $ctx->storefrontId,
            'storefront_category_id' => $nodeId,
            'product_id' => $productId,
            'sort_order' => 0,
            'is_primary' => $primary,
            'created_at' => Row::nstr($row, 'created_at'),
            'updated_at' => Row::nstr($row, 'updated_at'),
        ];
    }
}
