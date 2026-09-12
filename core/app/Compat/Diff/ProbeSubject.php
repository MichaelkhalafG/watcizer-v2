<?php

declare(strict_types=1);

namespace App\Compat\Diff;

use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Everything `compat:edit-probe` must be able to put back, read ONCE before it writes anything.
 *
 * A typed object rather than an array, for two reasons that are the same reason: the probe restores
 * live catalogue rows, and a restore built from `mixed` is a restore nobody can check. Every field
 * here is the value as it was found, and the probe's writes are this object with one field changed.
 */
final class ProbeSubject
{
    /**
     * @param  list<int>  $categoryIds  the product's category nodes on storefront 1, as found
     */
    public function __construct(
        public readonly int $productId,
        public readonly bool $isVisible,
        public readonly bool $isFeatured,
        public readonly int $sortOrder,
        public readonly string $slug,
        public readonly array $categoryIds,
        public readonly ?int $primaryCategoryId,
        public readonly int $colourId,
        public readonly string $colourAr,
        public readonly string $colourEn,
        public readonly ?string $colourHex,
        public readonly int $nodeId,
        public readonly string $nodeAr,
        public readonly string $nodeEn,
    ) {}

    /** Null when the product has no `storefront_product` row on storefront 1 — nothing to probe. */
    public static function read(int $productId): ?self
    {
        $row = DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)
            ->first(['is_visible', 'is_featured', 'sort_order', 'slug']);
        if ($row === null) {
            return null;
        }

        $categoryIds = [];
        $primary = null;
        foreach (
            DB::table('storefront_category_product')
                ->where('storefront_id', 1)->where('product_id', $productId)
                ->orderBy('storefront_category_id')
                ->get(['storefront_category_id', 'is_primary']) as $placement
        ) {
            $node = Row::int(Row::cast($placement), 'storefront_category_id');
            $categoryIds[] = $node;
            if (Row::bool(Row::cast($placement), 'is_primary')) {
                $primary = $node;
            }
        }

        // The two narrow content-edit subjects: one lookup row and one category node of the PRIMARY
        // tree, with their current names so the probe can put them back.
        $colour = DB::table('catalog_colors as c')
            ->leftJoin('catalog_color_translations as ar', function (JoinClause $join): void {
                $join->on('ar.color_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('catalog_color_translations as en', function (JoinClause $join): void {
                $join->on('en.color_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->orderBy('c.id')->first(['c.id', 'c.hex', 'ar.name as name_ar', 'en.name as name_en']);

        $treeNode = DB::table('storefront_categories as sc')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $join): void {
                $join->on('ar.storefront_category_id', '=', 'sc.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $join): void {
                $join->on('en.storefront_category_id', '=', 'sc.id')->where('en.locale', '=', 'en');
            })
            ->where('sc.storefront_id', 1)->where('sc.depth', 2)
            ->orderBy('sc.id')->first(['sc.id', 'ar.name as name_ar', 'en.name as name_en']);

        return new self(
            productId: $productId,
            isVisible: Row::bool(Row::cast($row), 'is_visible'),
            isFeatured: Row::bool(Row::cast($row), 'is_featured'),
            sortOrder: Row::int(Row::cast($row), 'sort_order'),
            slug: Row::nstr(Row::cast($row), 'slug') ?? '',
            categoryIds: $categoryIds,
            primaryCategoryId: $primary,
            colourId: $colour === null ? 0 : Row::int(Row::cast($colour), 'id'),
            colourAr: $colour === null ? '' : (Row::nstr(Row::cast($colour), 'name_ar') ?? ''),
            colourEn: $colour === null ? '' : (Row::nstr(Row::cast($colour), 'name_en') ?? ''),
            colourHex: $colour === null ? null : Row::nstr(Row::cast($colour), 'hex'),
            nodeId: $treeNode === null ? 0 : Row::int(Row::cast($treeNode), 'id'),
            nodeAr: $treeNode === null ? '' : (Row::nstr(Row::cast($treeNode), 'name_ar') ?? ''),
            nodeEn: $treeNode === null ? '' : (Row::nstr(Row::cast($treeNode), 'name_en') ?? ''),
        );
    }

    /**
     * The category ids as the form submits them.
     *
     * @return list<string>
     */
    public function categoryIdsAsStrings(): array
    {
        return array_map(fn (int $id): string => (string) $id, $this->categoryIds);
    }
}
