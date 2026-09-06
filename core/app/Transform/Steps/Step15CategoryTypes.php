<?php

namespace App\Transform\Steps;

use App\Support\LegacySlug;
use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;

/**
 * Step 15 — category_types → storefront_categories depth 1, key (1, 'category_type', id, NULL).
 * slug = slugify(en) = today's `/category/[slug]` param (A-14 audits any difference).
 */
final class Step15CategoryTypes implements Step
{
    public function number(): int
    {
        return 15;
    }

    public function name(): string
    {
        return 'category_types';
    }

    public function target(): string
    {
        return 'storefront_categories (depth 1), storefront_category_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $nodes = new CategoryNodes($ctx);
        $nodes->prime();
        $names = $ctx->legacyTranslations('category_type_translations', 'category_type_id', 'category_type_name');

        $position = 0;
        foreach ($ctx->legacy->table('category_types')->select(['id', 'image', 'created_at', 'updated_at'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $result->read++;
            $position++;
            $en = trim($names[$id]['en'] ?? '');
            $ar = trim($names[$id]['ar'] ?? '');
            if ($ar === '') {
                $ar = $en;
                $result->count('ar_copied_from_en');
            }
            if ($en === '') {
                $en = $ar !== '' ? $ar : "Category type $id";
                $result->count('en_missing');
            }
            $image = Row::nstr($row, 'image');

            $node = $nodes->ensure([
                'source' => 'category_type',
                'legacy_id' => $id,
                'legacy_parent_id' => null,
                'parent_id' => null,
                'slug' => LegacySlug::orId($en, $id),
                'slug_fallbacks' => [],
                'image_path' => ($image !== null && trim($image) !== '') ? 'Category_type/'.$image : null,
                'is_active' => 1,
                'show_in_menu' => 1,
                'sort_order' => $position,
                'created_at' => Row::nstr($row, 'created_at'),
                'updated_at' => Row::nstr($row, 'updated_at'),
            ], ['en' => $en, 'ar' => $ar], $result);

            $ctx->idMap->remember('category_types', $id, 'storefront_categories', $node['id']);
            if ($node['slug'] !== LegacySlug::orId($en, $id)) {
                $result->count('slug_deviated');
                $result->note("category_type $id slug [{$node['slug']}] differs from slugify(en) — see A-14");
            }
        }
    }
}
