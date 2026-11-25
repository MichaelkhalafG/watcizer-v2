<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Compat\CompatCart;
use App\Storefront\ImageUrl;
use App\Storefront\StorefrontUrls;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * Products as an e-mail shows them (2026-11-24, the abandoned-cart e-mail and the campaigns): both titles, TODAY'S price
 * on the shop (its sale price when valid), the cover image, and the link on the shop's own site by the shop's own slug.
 * Only products the shop shows (visible, active, not deleted); a product gone since is simply left out.
 */
final class EmailProducts
{
    /**
     * @param  list<int>  $ids  in the order to show
     * @return list<array{id: int, name_en: string, name_ar: string, price: float, image: string|null, url: string}>
     */
    public static function cards(int $storefrontId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $host = StorefrontUrls::frontend($storefrontId);
        $rows = DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->whereIn('sp.product_id', $ids)->where('sp.is_visible', 1)
            ->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->get(['sp.product_id', 'sp.slug', 'sp.effective_price', 'sp.effective_sale_price'])->keyBy('product_id');
        $titles = [];
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', $ids)->get(['product_id', 'locale', 'title']) as $t) {
            $row = Row::cast($t);
            $titles[Row::int($row, 'product_id')][Row::str($row, 'locale')] = (string) Row::nstr($row, 'title');
        }
        $covers = [];
        foreach (DB::table('catalog_product_images')->whereIn('product_id', $ids)->orderByDesc('is_cover')->orderBy('sort')->get(['product_id', 'path']) as $img) {
            $row = Row::cast($img);
            $covers[Row::int($row, 'product_id')] ??= Row::str($row, 'path');
        }
        $out = [];
        foreach ($ids as $id) {
            $raw = $rows->get($id);
            if (! is_object($raw)) {
                continue;
            }
            $p = Row::cast($raw);
            $en = $titles[$id]['en'] ?? '';
            $ar = $titles[$id]['ar'] ?? '';
            $out[] = [
                'id' => $id,
                'name_en' => $en !== '' ? $en : $ar,
                'name_ar' => $ar !== '' ? $ar : $en,
                'price' => CompatCart::catalogPrice(Row::str($p, 'effective_price'), Row::nstr($p, 'effective_sale_price')),
                'image' => isset($covers[$id]) ? ImageUrl::src($covers[$id]) : null,
                'url' => $host.'/product/'.Row::str($p, 'slug'),
            ];
        }

        return $out;
    }
}
