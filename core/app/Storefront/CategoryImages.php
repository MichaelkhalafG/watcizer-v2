<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The picture a category shows when it has none of its own (the twelve updates, Part 5, 2026-11-05).
 *
 * ── The rule ──────────────────────────────────────────────────────────────────────────────────
 *
 * A category or sub-type with no image of its own shows the cover photo of its MOST RECENTLY ADDED
 * product. The team can delete the picture from the dashboard, and that means "show nothing" — the
 * `image_hidden` flag — never "fall back again".
 *
 * ── The two things the brief asked to settle ──────────────────────────────────────────────────
 *
 * **"Most recent" is `catalog_products.created_at`** — when the product was added to the catalogue —
 * newest first, ties broken by the higher id. Not `updated_at`: correcting a typo in an old product
 * would then change the picture of its category. Not the date it was placed in the category: nothing
 * records one.
 *
 * **A product a shopper cannot OPEN never represents a category; one he can BUY comes first.** The
 * candidates are products visible on THIS storefront, active, not deleted, with a cover photo — the newest
 * one that is IN STOCK. So when the product whose photo is showing is later hidden, archived or sold out,
 * the next most recent in-stock product takes its place the next time the tree is built — every product,
 * placement and visibility write flushes the tree, and its cache lasts an hour at most.
 *
 * WHEN THE WHOLE CATEGORY IS SOLD OUT (the developer's review, 2026-11-05: Electronics, 72 products and
 * none in stock, drew letters for every tile) the newest product of it represents it anyway: a category
 * that has products a shopper can open is a page worth a picture, and a letter in a tile reads as a
 * broken image. Only a category with no pictured product at all shows none.
 *
 * The products of the whole SUBTREE count: "Watches" is represented by the newest watch, whichever
 * sub-type it sits in.
 */
final class CategoryImages
{
    /**
     * The fallback image of each given node that has a candidate — node id → the same image object a
     * product card carries (src, srcset from the recorded renditions, width, height, alt).
     *
     * ONE query for all of them: every candidate product is joined to each of its ancestors among the
     * given nodes and ranked per node, newest first; a second query reads the winners' cover rows.
     *
     * @param  array<int, string>  $idPaths  node id → its materialised id path (`/3/33/`)
     * @return array<int, array<string, mixed>>
     */
    public static function fallbacks(int $storefrontId, array $idPaths): array
    {
        if ($idPaths === []) {
            return [];
        }

        $ranked = DB::table('storefront_categories as c')
            ->join('storefront_categories as node', function (JoinClause $join): void {
                $join->on('node.storefront_id', '=', 'c.storefront_id')->on('node.path', 'like', DB::raw("CONCAT(c.path, '%')"));
            })
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'node.id')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('c.storefront_id', $storefrontId)->whereIn('c.id', array_keys($idPaths))
            ->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'))
            // in stock FIRST, then newest: a sold-out product represents a category only when nothing in it can be bought
            ->selectRaw('c.id AS node_id, p.id AS product_id, ROW_NUMBER() OVER (PARTITION BY c.id ORDER BY p.in_stock DESC, p.created_at DESC, p.id DESC) AS rn');

        $winners = [];
        foreach (DB::query()->fromSub($ranked, 'r')->where('r.rn', 1)->get(['r.node_id', 'r.product_id']) as $raw) {
            $winners[Row::int($raw, 'node_id')] = Row::int($raw, 'product_id');
        }
        if ($winners === []) {
            return [];
        }

        $covers = self::covers(array_values(array_unique($winners)));
        $out = [];
        foreach ($winners as $nodeId => $productId) {
            if (isset($covers[$productId])) {
                $out[$nodeId] = $covers[$productId];
            }
        }

        return $out;
    }

    /**
     * The cover photo of each product, as a card's image object — the cover row, else the first by sort.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public static function covers(array $productIds): array
    {
        $out = [];
        if ($productIds === []) {
            return $out;
        }
        foreach (
            DB::table('catalog_product_images')
                ->select(['product_id', 'path', 'width', 'height', 'alt_en', 'alt_ar', 'renditions'])
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get() as $raw
        ) {
            $productId = Row::int($raw, 'product_id');
            if (! isset($out[$productId])) {
                $out[$productId] = ProductCards::image(Row::cast($raw));
            }
        }

        return $out;
    }
}
