<?php

namespace App\Transform\Steps;

use App\Domain\Catalog\PreSwitch;
use App\Support\LegacySlug;
use App\Transform\CategoryNodes;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;

/**
 * Step 15 — category_types → storefront_categories depth 1, key
 * (storefront, 'category_type', id, NULL). slug = slugify(en) = today's `/category/[slug]` param
 * (A-14 audits any difference).
 *
 * Runs once per storefront the tree sync covers ({@see PreSwitch::syncsSecondaryTrees()}): the
 * primary always, every other active storefront only while the write-switch flag is FALSE. Each
 * storefront gets its OWN nodes with their own ids, paths and slugs — never shared rows — so
 * deleting a Brand Fashion category can never touch Watchizer's.
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
        $names = $ctx->legacyTranslations('category_type_translations', 'category_type_id', 'category_type_name');

        $ctx->eachStorefront(function (int $storefrontId, bool $primary) use ($ctx, $result, $names): void {
            if (! $primary && ! PreSwitch::syncsSecondaryTrees()) {
                $result->count('tree_sync_skipped_post_switch');
                $result->note("storefront {$storefrontId}: tree sync OFF (write-switch completed) — its tree is the team's own now");

                return;
            }
            $this->syncStorefront($ctx, $result, $names, $storefrontId, $primary);
        });
    }

    /**
     * @param  array<int, array<string, string>>  $names
     */
    private function syncStorefront(TransformContext $ctx, StepResult $result, array $names, int $storefrontId, bool $primary): void
    {
        $nodes = new CategoryNodes($ctx);
        $nodes->prime();

        $position = 0;
        foreach ($ctx->legacy->table('category_types')->select(['id', 'image', 'created_at', 'updated_at'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            if ($primary) {
                $result->read++;
            }
            $position++;
            $en = trim($names[$id]['en'] ?? '');
            $ar = trim($names[$id]['ar'] ?? '');
            if ($ar === '') {
                $ar = $en;
                if ($primary) {
                    $result->count('ar_copied_from_en');
                }
            }
            if ($en === '') {
                $en = $ar !== '' ? $ar : "Category type $id";
                if ($primary) {
                    $result->count('en_missing');
                }
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

            $ctx->rememberNode('category_types', $id, $node['id']);
            if ($primary && $node['slug'] !== LegacySlug::orId($en, $id)) {
                $result->count('slug_deviated');
                $result->note("category_type $id slug [{$node['slug']}] differs from slugify(en) — see A-14");
            }
        }
        if (! $primary) {
            $result->note("storefront {$storefrontId}: depth-1 nodes mirrored");
        }
    }
}
