<?php

namespace App\Transform\Steps;

use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 19 — products.category_type_id / sub_type_id → storefront_category_product, **per ACTIVE
 * storefront**, key (storefront_category_id, product_id): the sub-type node row is_primary = 1,
 * the category-type node row is_primary = 0; products with neither → A-18 (unplaced).
 *
 * Each storefront is placed into ITS OWN nodes, resolved by the LEGACY ORIGIN KEY the mirrored
 * copy carries (`legacy_source`, `legacy_id`, `legacy_parent_id`) — never by node id, which
 * differs between storefronts by construction. So a product's primary category on Brand Fashion
 * is the copy-equivalent of its primary category on Watchizer, and the one-primary-per-storefront
 * database invariant (`scp_one_primary_unique`, M1d) holds on each of them independently.
 * Existing placements that no longer match legacy are NEVER deleted (additive
 * transform) — they are counted and listed as `stale_placements`.
 *
 * ONE PRIMARY PER PRODUCT (milestone audit 🔴-2, 2026-09-08). A product whose legacy sub
 * type changed used to keep the old primary row AND gain a new one, so the emitted
 * category depended on row order. The step now runs in two phases:
 *
 *   1. Read the legacy (product → desired primary node) map, then DEMOTE every existing
 *      primary row that disagrees with it (is_primary = 0; the row itself survives).
 *   2. Write the placements.
 *
 * The order matters twice over. `storefront_category_product` now carries a second unique
 * key (`scp_one_primary_unique`, M1d): if a stale primary were still flagged when the new
 * primary row is inserted, MariaDB's `INSERT … ON DUPLICATE KEY UPDATE` would match THAT
 * key and quietly update the wrong row. Demoting first leaves `scp_category_product_unique`
 * as the only reachable conflict.
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
        $ctx->eachStorefront(function (int $storefrontId, bool $primary) use ($ctx, $result): void {
            $this->syncStorefront($ctx, $result, $primary);
            $placed = $ctx->db->table('storefront_category_product')->where('storefront_id', $storefrontId)->count();
            $primaries = $ctx->db->table('storefront_category_product')->where('storefront_id', $storefrontId)->where('is_primary', 1)->count();
            $result->note(sprintf('storefront %d: %d placements, %d primary', $storefrontId, $placed, $primaries));
        });
    }

    /** One storefront's placements. `$ctx->storefrontId` already points at it. */
    private function syncStorefront(TransformContext $ctx, StepResult $result, bool $primary): void
    {
        $nodes = new CategoryNodes($ctx);
        /** @var array<string, int> */
        $nodeCache = [];
        $resolve = function (string $source, int $legacyId, ?int $legacyParentId) use ($ctx, $nodes, &$nodeCache): int {
            $key = "$source:$legacyId:".($legacyParentId ?? 'null');
            if (! isset($nodeCache[$key])) {
                $id = $source === 'category_type'
                    ? ($ctx->nodeId('category_types', $legacyId) ?? $nodes->find($source, $legacyId, null))
                    : ($ctx->nodeId('sub_types:'.$legacyParentId, $legacyId) ?? $nodes->find($source, $legacyId, $legacyParentId));
                if ($id === null) {
                    throw new RuntimeException("No storefront_categories node for $source $legacyId (parent ".($legacyParentId ?? 'NULL').') — run steps 15/16 first.');
                }
                $nodeCache[$key] = $id;
            }

            return $nodeCache[$key];
        };

        // Phase 1 — the primary each product SHOULD have, straight from legacy.
        /** @var array<int, int> product id => node id */
        $desiredPrimary = [];
        $ctx->chunkLegacy('products', ['id', 'category_type_id', 'sub_type_id'], function (Collection $rows) use ($resolve, &$desiredPrimary): void {
            foreach ($rows as $row) {
                $typeId = Row::nint($row, 'category_type_id');
                $subId = Row::nint($row, 'sub_type_id');
                if ($typeId !== null && $subId !== null) {
                    $desiredPrimary[Row::int($row, 'id')] = $resolve('sub_type', $subId, $typeId);
                }
            }
        });
        $this->demoteDisagreeingPrimaries($ctx, $result, $desiredPrimary);

        /** @var array<string, true> desired "category:product" */
        $desired = [];

        // Phase 2 — write the placements.
        $ctx->chunkLegacy('products', ['id', 'category_type_id', 'sub_type_id', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $resolve, &$desired, $primary): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                if ($primary) {
                    $result->read++;
                }
                $typeId = Row::nint($row, 'category_type_id');
                $subId = Row::nint($row, 'sub_type_id');
                if ($typeId === null && $subId === null) {
                    if ($primary) {
                        $result->count('unplaced');                      // A-18
                        $ctx->diff('A-18', 'products', $id, 'category_type_id=NULL sub_type_id=NULL', 'unplaced');
                    }

                    continue;
                }
                if ($typeId !== null) {
                    $node = $resolve('category_type', $typeId, null);
                    $out[] = $this->row($ctx, $node, $id, 0, $row);
                    $desired["$node:$id"] = true;
                }
                if ($subId !== null) {
                    if ($typeId === null) {
                        if ($primary) {
                            $result->count('sub_type_without_type');  // A-18 variant: cannot pair
                            $ctx->diff('A-18', 'products', $id, "sub_type_id=$subId category_type_id=NULL", 'sub type placement skipped (no pair)');
                        }
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

    /**
     * Clear is_primary on every row that is flagged primary but is not the product's desired
     * primary node (including products that lost their sub type entirely). The rows survive —
     * only the flag moves, so the transform stays additive.
     *
     * @param  array<int, int>  $desiredPrimary  product id => node id
     */
    private function demoteDisagreeingPrimaries(TransformContext $ctx, StepResult $result, array $desiredPrimary): void
    {
        $demote = [];
        $rows = $ctx->db->table('storefront_category_product')
            ->select(['id', 'product_id', 'storefront_category_id'])
            ->where('storefront_id', $ctx->storefrontId)
            ->where('is_primary', 1)
            ->orderBy('id')
            ->cursor();
        foreach ($rows as $row) {
            $productId = Row::int($row, 'product_id');
            $nodeId = Row::int($row, 'storefront_category_id');
            if (($desiredPrimary[$productId] ?? null) !== $nodeId) {
                $demote[] = Row::int($row, 'id');
                $ctx->diff('PRIMARY', 'storefront_category_product', $productId, "node $nodeId was is_primary = 1", 'demoted to 0 — the product\'s legacy sub type points elsewhere now (row kept)');
            }
        }
        foreach (array_chunk($demote, 500) as $chunk) {
            $ctx->db->table('storefront_category_product')->whereIn('id', $chunk)->update(['is_primary' => 0]);
        }
        if ($demote !== []) {
            $result->count('primary_demoted', count($demote));
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
