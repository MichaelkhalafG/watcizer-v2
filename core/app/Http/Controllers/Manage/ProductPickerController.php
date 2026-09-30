<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\ProductSearch;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Pick products by hand" — the search behind the dashboard's ProductPicker (2026-09-30): the
 * re-engagement e-mail's manual picks (R4) and the home page's custom rail.
 *
 *   GET …?q=hugo       up to 20 products PLACED on this storefront, through ProductSearch — the one
 *                      answer to "find me this product" every other screen gives (names in both
 *                      languages, code, SKU), not a second LIKE of its own
 *   GET …?ids=4,6,9    those products, in that order (the picks already chosen, to show them again)
 *
 * Registered once per screen, inside that screen's own permission group, so the picker never widens
 * who can read what: home rails (content) and re-engagement (storefront management).
 */
final class ProductPickerController
{
    public const LIMIT = 20;

    public function search(Request $request, Storefront $storefront): JsonResponse
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', Coerce::str($request->input('ids')))), fn (int $id): bool => $id > 0)));
        $term = trim(Coerce::str($request->input('q')));
        if ($ids === [] && mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $query = self::base($storefront->id);
        if ($ids !== []) {
            $query->whereIn('p.id', array_slice($ids, 0, 50));
        } else {
            ProductSearch::apply($query, $term, 't', 'te');
            $query->orderByDesc('sp.is_visible')->orderBy('p.id')->limit(self::LIMIT);
        }

        $hits = [];
        foreach ($query->get() as $raw) {
            $row = Row::cast($raw);
            $cover = Row::nstr($row, 'cover');
            $hits[Row::int($row, 'id')] = [
                'id' => Row::int($row, 'id'),
                'title' => Row::nstr($row, 'title_ar') ?? Row::nstr($row, 'title_en') ?? '#'.Row::int($row, 'id'),
                'title_en' => Row::nstr($row, 'title_en'),
                'code' => Row::nstr($row, 'wa_code'),
                'cover' => $cover === null ? null : ImageUrl::src($cover),
                'in_stock' => Row::int($row, 'stock_express') + Row::int($row, 'stock_market') > 0,
                'visible' => Row::bool($row, 'is_visible'),
            ];
        }
        // ?ids= answers in the order asked; a search in its own order.
        $data = $ids === [] ? array_values($hits) : array_values(array_filter(array_map(fn (int $id): ?array => $hits[$id] ?? null, $ids)));

        return response()->json(['data' => $data]);
    }

    private static function base(int $storefrontId): Builder
    {
        return DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->leftJoin('catalog_product_translations as t', function (JoinClause $j): void {
                $j->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            ->leftJoin('catalog_product_translations as te', function (JoinClause $j): void {
                $j->on('te.product_id', '=', 'p.id')->where('te.locale', '=', 'en');
            })
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->select(['p.id', 'p.wa_code', 'p.stock_express', 'p.stock_market', 'sp.is_visible', 't.title as title_ar', 'te.title as title_en'])
            ->selectRaw('(SELECT ci.path FROM catalog_product_images ci WHERE ci.product_id = p.id ORDER BY ci.is_cover DESC, ci.sort, ci.id LIMIT 1) AS cover');
    }
}
