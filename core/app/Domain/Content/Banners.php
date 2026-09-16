<?php

declare(strict_types=1);

namespace App\Domain\Content;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Reading banners — one query shape, used by the list and by anything that needs the same columns.
 *
 * The joins are what make the list readable: a banner's DESTINATION is a product title or a
 * category name, and looking either up per row would be the "per row, never per page" mistake the
 * product list already learned (AGENTS §2.24). Both come back in the list query.
 */
final class Banners
{
    /** The list query for one storefront, destination names included. */
    public static function query(int $storefrontId): Builder
    {
        return DB::table('storefront_banners as b')
            ->leftJoin('catalog_product_translations as pt', function (JoinClause $join): void {
                $join->on('pt.product_id', '=', 'b.product_id')->where('pt.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as ct', function (JoinClause $join): void {
                $join->on('ct.storefront_category_id', '=', 'b.storefront_category_id')->where('ct.locale', '=', 'ar');
            })
            ->where('b.storefront_id', $storefrontId)
            ->where('b.placement', BannerWriter::PLACEMENTS[0])
            ->select([
                'b.id', 'b.image_path', 'b.link_url', 'b.product_id', 'b.storefront_category_id',
                'b.sort_order', 'b.is_active', 'b.starts_at', 'b.ends_at',
                'pt.title as product_title', 'ct.name as category_name',
            ]);
    }

    public static function exists(int $storefrontId, int $bannerId): bool
    {
        return DB::table('storefront_banners')
            ->where('id', $bannerId)
            ->where('storefront_id', $storefrontId)
            ->where('placement', BannerWriter::PLACEMENTS[0])
            ->exists();
    }
}
