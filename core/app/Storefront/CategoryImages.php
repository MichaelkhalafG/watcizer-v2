<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Transform\Row;
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

        return self::picturesOf(self::rollup($storefrontId, array_keys($idPaths))['best']);
    }

    /**
     * The cover image of each node's winning product (node id → product id, from rollup()['best']).
     *
     * @param  array<int, int>  $winners
     * @return array<int, array<string, mixed>>
     */
    public static function picturesOf(array $winners): array
    {
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
     * Per node of the storefront's tree, over its WHOLE subtree (the node and every descendant, by the materialised
     * id path): how many distinct visible + active products are placed there (`counts`), and which product with a
     * photo represents it (`best`: in stock first, then the newest, then the highest id — the order the window query
     * used).
     *
     * Rewritten 2026-11-12 (production incident): the old form joined every category to every other with
     * `node.path LIKE CONCAT(c.path, '%')` — no index can serve it — and took 150 s+ per copy on 9,746 products while
     * every request that missed the cache started its own copy. Now: ONE indexed query for the storefront's
     * placements (category → product, with the product's stock, age and whether it has a photo), and each placement
     * is credited to the ancestors in its node's path (`/4/76/77/` → 4, 76, 77) in PHP. Same results.
     *
     * @param  list<int>  $nodeIds  the nodes asked about
     * @return array{counts: array<int, int>, best: array<int, int>}
     */
    public static function rollup(int $storefrontId, array $nodeIds): array
    {
        $wanted = array_fill_keys($nodeIds, true);
        if ($wanted === []) {
            return ['counts' => [], 'best' => []];
        }
        /** @var array<int, list<int>> $ancestors every node of the tree → the ids on its path, itself included */
        $ancestors = [];
        foreach (DB::table('storefront_categories')->where('storefront_id', $storefrontId)->get(['id', 'path']) as $raw) {
            $ids = [];
            foreach (explode('/', trim(Row::str($raw, 'path'), '/')) as $segment) {
                if ($segment !== '' && ctype_digit($segment) && isset($wanted[(int) $segment])) {
                    $ids[] = (int) $segment;
                }
            }
            $ancestors[Row::int($raw, 'id')] = $ids;
        }

        $members = [];
        $best = [];
        $rank = [];
        $placements = DB::table('storefront_category_product as scp')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->whereIn('scp.storefront_category_id', array_keys($ancestors))
            ->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
            ->selectRaw('scp.storefront_category_id AS node_id, p.id AS product_id, p.in_stock AS in_stock, p.created_at AS created_at, '
                .'EXISTS (SELECT 1 FROM catalog_product_images i WHERE i.product_id = p.id) AS has_image')
            ->cursor();
        foreach ($placements as $raw) {
            $node = Row::int($raw, 'node_id');
            $product = Row::int($raw, 'product_id');
            $hasImage = Row::bool($raw, 'has_image');
            // in stock FIRST, then newest, then the highest id: a sold-out product represents a category only when
            // nothing in it can be bought (a NULL creation date sorts last, as it did in the window query)
            $key = [Row::bool($raw, 'in_stock') ? 1 : 0, Row::nstr($raw, 'created_at') ?? '', $product];
            foreach ($ancestors[$node] ?? [] as $a) {
                $members[$a][$product] = true;
                if ($hasImage && (! isset($rank[$a]) || $key > $rank[$a])) {
                    $rank[$a] = $key;
                    $best[$a] = $product;
                }
            }
        }
        $counts = [];
        foreach ($members as $a => $products) {
            $counts[$a] = count($products);
        }

        return ['counts' => $counts, 'best' => $best];
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
