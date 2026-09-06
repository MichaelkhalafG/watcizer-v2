<?php

namespace App\Transform\Steps;

use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use RuntimeException;

/**
 * Step 17 — the dormant `categories` tree (+category_translations) → storefront_categories
 * under a hidden root `legacy-tree` (is_active = 0, show_in_menu = 0). Preserved so nothing
 * is lost; no product is placed there (none is linked in legacy).
 */
final class Step17LegacyCategories implements Step
{
    public function number(): int
    {
        return 17;
    }

    public function name(): string
    {
        return 'legacy_categories';
    }

    public function target(): string
    {
        return 'storefront_categories (legacy-tree), storefront_category_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $nodes = new CategoryNodes($ctx);
        $nodes->prime();
        $rootCfg = $ctx->configArray('legacy_tree_root');
        $rootSlug = is_string($rootCfg['slug'] ?? null) ? $rootCfg['slug'] : 'legacy-tree';

        $root = $nodes->ensure([
            'source' => 'category_root',
            'legacy_id' => 0,
            'legacy_parent_id' => null,
            'parent_id' => null,
            'slug' => $rootSlug,
            'slug_fallbacks' => [$rootSlug.'-hidden'],
            'image_path' => null,
            'is_active' => 0,
            'show_in_menu' => 0,
            'sort_order' => 9999,
            'created_at' => null,
            'updated_at' => null,
        ], [
            'en' => is_string($rootCfg['name_en'] ?? null) ? $rootCfg['name_en'] : 'Legacy category tree',
            'ar' => is_string($rootCfg['name_ar'] ?? null) ? $rootCfg['name_ar'] : 'Legacy category tree',
        ], $result);

        $names = $ctx->legacyTranslations('category_translations', 'category_id', 'name');
        $descriptions = $ctx->legacyTranslations('category_translations', 'category_id', 'description');

        /** @var array<int, int> legacy category id => node id */
        $nodeIds = [];
        $rows = $ctx->legacy->table('categories')
            ->select(['id', 'parent_id', 'level', 'slug', 'image', 'is_active', 'sort_order', 'created_at', 'updated_at'])
            ->orderBy('level')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $result->read++;
            $legacyParent = Row::nint($row, 'parent_id');
            $parentNode = $legacyParent === null ? $root['id'] : ($nodeIds[$legacyParent] ?? null);
            if ($parentNode === null) {
                throw new RuntimeException("categories#$id: parent $legacyParent not processed yet (level ordering broken).");
            }
            $en = trim($names[$id]['en'] ?? '');
            $ar = trim($names[$id]['ar'] ?? '');
            if ($ar === '') {
                $ar = $en;
                $result->count('ar_copied_from_en');
            }
            if ($en === '') {
                $en = $ar !== '' ? $ar : "Category $id";
                $result->count('en_missing');
            }
            $image = Row::nstr($row, 'image');
            $slug = trim(Row::str($row, 'slug'));
            if ($slug === '') {
                $slug = "category-$id";
            }

            $node = $nodes->ensure([
                'source' => 'category',
                'legacy_id' => $id,
                'legacy_parent_id' => null,
                'parent_id' => $parentNode,
                'slug' => $slug,
                'slug_fallbacks' => [$slug.'-legacy'],
                'image_path' => ($image !== null && trim($image) !== '') ? 'Category/'.$image : null,
                'is_active' => Row::bool($row, 'is_active') ? 1 : 0,
                'show_in_menu' => 0,
                'sort_order' => Row::int($row, 'sort_order'),
                'created_at' => Row::nstr($row, 'created_at'),
                'updated_at' => Row::nstr($row, 'updated_at'),
            ], ['en' => $en, 'ar' => $ar, 'description_en' => $descriptions[$id]['en'] ?? null, 'description_ar' => $descriptions[$id]['ar'] ?? null], $result);

            $nodeIds[$id] = $node['id'];
            $ctx->idMap->remember('categories', $id, 'storefront_categories', $node['id']);
        }
        $result->note("hidden root [{$root['slug']}] id {$root['id']}");
    }
}
