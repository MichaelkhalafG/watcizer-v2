<?php

namespace App\Transform\Steps;

use App\Support\LegacySlug;
use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use RuntimeException;

/**
 * Step 16 — one depth-2 node per DISTINCT (category_type_id, sub_type_id) pair found on
 * products, key (1, 'sub_type', sub_type_id, category_type_id), parent = the step-15 node.
 * Sub types with no products are mirrored under the category type "used by most other
 * sub types" unless config transform.orphan_sub_type_parents overrides them (A-15).
 * slug = slugify(en); collision → "-{parent slug}", then "-{sub type id}".
 */
final class Step16SubTypes implements Step
{
    public function number(): int
    {
        return 16;
    }

    public function name(): string
    {
        return 'sub_types';
    }

    public function target(): string
    {
        return 'storefront_categories (depth 2), storefront_category_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $nodes = new CategoryNodes($ctx);
        $nodes->prime();
        $names = $ctx->legacyTranslations('sub_type_translations', 'sub_type_id', 'sub_type_name');
        $typeNames = $ctx->legacyTranslations('category_type_translations', 'category_type_id', 'category_type_name');

        /** @var array<int, array<int, int>> type id => sub type id => product count */
        $pairs = [];
        $pairRows = $ctx->legacy->table('products')
            ->selectRaw('category_type_id, sub_type_id, COUNT(*) AS n')
            ->whereNotNull('category_type_id')->whereNotNull('sub_type_id')
            ->groupBy('category_type_id', 'sub_type_id')
            ->orderBy('category_type_id')->orderBy('sub_type_id')
            ->get();
        foreach ($pairRows as $row) {
            $pairs[Row::int($row, 'category_type_id')][Row::int($row, 'sub_type_id')] = Row::int($row, 'n');
        }

        // Majority rule for orphans: the type with the most DISTINCT sub types (tie → lowest id).
        $majorityType = null;
        $best = -1;
        foreach ($pairs as $typeId => $subs) {
            if (count($subs) > $best) {
                $best = count($subs);
                $majorityType = $typeId;
            }
        }
        $overrides = $ctx->configIntMap('orphan_sub_type_parents');

        /** @var array<int, array{image: string|null, created_at: string|null, updated_at: string|null}> */
        $subTypes = [];
        foreach ($ctx->legacy->table('sub_types')->select(['id', 'image', 'created_at', 'updated_at'])->orderBy('id')->get() as $row) {
            $subTypes[Row::int($row, 'id')] = ['image' => Row::nstr($row, 'image'), 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
            $result->read++;
        }

        // Desired nodes: every product pair, then orphans.
        /** @var list<array{type: int, sub: int, orphan: bool}> */
        $desired = [];
        $placed = [];
        foreach ($pairs as $typeId => $subs) {
            foreach ($subs as $subId => $n) {
                if (! isset($subTypes[$subId])) {
                    throw new RuntimeException("products reference sub_type_id $subId which does not exist (A-24 should have caught this).");
                }
                $desired[] = ['type' => $typeId, 'sub' => $subId, 'orphan' => false];
                $placed[$subId] = true;
                if ($n === 1) {
                    $result->count('pair_used_once');                // A-15 (informational)
                }
            }
        }
        foreach ($subTypes as $subId => $_) {
            if (isset($placed[$subId])) {
                continue;
            }
            $typeId = $overrides[$subId] ?? $majorityType;
            if ($typeId === null) {
                throw new RuntimeException("Cannot place orphan sub_type $subId: no product pairs exist to derive a majority type and no override is configured.");
            }
            if (! isset($typeNames[$typeId])) {
                throw new RuntimeException("Orphan sub_type $subId: override/majority category_type_id $typeId does not exist.");
            }
            $desired[] = ['type' => $typeId, 'sub' => $subId, 'orphan' => true];
            $result->count(isset($overrides[$subId]) ? 'orphan_placed_by_override' : 'orphan_placed_by_majority_rule');
        }

        usort($desired, fn (array $a, array $b) => [$a['type'], $a['sub']] <=> [$b['type'], $b['sub']]);

        $position = [];
        foreach ($desired as $d) {
            $typeId = $d['type'];
            $subId = $d['sub'];
            $parentId = $ctx->idMap->get('category_types', $typeId, 'storefront_categories') ?? $nodes->find('category_type', $typeId, null);
            if ($parentId === null) {
                throw new RuntimeException("Depth-1 node for category_type $typeId missing — run step 15 first.");
            }
            $parentSlug = LegacySlug::orId(trim($typeNames[$typeId]['en'] ?? ''), $typeId);
            $position[$typeId] = ($position[$typeId] ?? 0) + 1;

            $en = trim($names[$subId]['en'] ?? '');
            $ar = trim($names[$subId]['ar'] ?? '');
            if ($ar === '') {
                $ar = $en;
                $result->count('ar_copied_from_en');
            }
            if ($en === '') {
                $en = $ar !== '' ? $ar : "Sub type $subId";
                $result->count('en_missing');
            }
            $image = $subTypes[$subId]['image'];
            $slug = LegacySlug::orId($en, $subId);

            $node = $nodes->ensure([
                'source' => 'sub_type',
                'legacy_id' => $subId,
                'legacy_parent_id' => $typeId,
                'parent_id' => $parentId,
                'slug' => $slug,
                'slug_fallbacks' => [$slug.'-'.$parentSlug],
                'image_path' => ($image !== null && trim($image) !== '') ? 'Sub_type/'.$image : null,
                'is_active' => 1,
                'show_in_menu' => 1,
                'sort_order' => $position[$typeId],
                'created_at' => $subTypes[$subId]['created_at'],
                'updated_at' => $subTypes[$subId]['updated_at'],
            ], ['en' => $en, 'ar' => $ar], $result);

            $ctx->idMap->remember('sub_types:'.$typeId, $subId, 'storefront_categories', $node['id']);
            if ($d['orphan']) {
                $result->note(sprintf('orphan sub_type %d [%s] mirrored under category_type %d [%s] (%s)', $subId, $en, $typeId, trim($typeNames[$typeId]['en'] ?? ''), isset($overrides[$subId]) ? 'override' : 'majority rule'));
            }
        }
    }
}
