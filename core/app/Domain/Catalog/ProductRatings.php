<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Storefront\StorefrontCache;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * A customer's rating of a product (B1, 2026-10-01).
 *
 * Decisions: signed-in customers only, no purchase requirement, no moderation — a rating is on the
 * page as soon as it is saved. One rating per customer per product (the table's UNIQUE(user_id,
 * product_id)); a second submission REPLACES the first, comment included, and keeps its row id and
 * `created_at`.
 *
 * Stored in `product_ratings` — the table `all_product_rating` already reads — whose product key
 * points at `catalog_products` once `core:repoint-commerce-fks` has run (before, a product created on
 * the dashboard could not be rated). The product's `rating_avg` / `rating_count` are recomputed from
 * the table on every save; the product's own cached detail and card are forgotten (the listing's rating
 * sort and grid averages follow within minutes — K6); the legacy
 * `products.average_rate` is frozen and not written.
 */
final class ProductRatings
{
    public const COMMENT_MAX = 2000;

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * @return 'created'|'replaced'|'not_found'
     */
    public function rate(int $storefrontId, int $productId, int $userId, int $rating, ?string $comment): string
    {
        if (! self::visible($storefrontId, $productId)) {
            return 'not_found';
        }
        $comment = $comment === null ? null : trim($comment);
        $comment = $comment === '' ? null : $comment;
        $now = now()->format('Y-m-d H:i:s');

        $status = DB::transaction(function () use ($productId, $userId, $rating, $comment, $now): string {
            $existing = DB::table('product_ratings')->where('user_id', $userId)->where('product_id', $productId)->lockForUpdate()->value('id');
            if ($existing !== null) {
                DB::table('product_ratings')->where('id', $existing)->update(['rating' => $rating, 'comment' => $comment, 'updated_at' => $now]);
            } else {
                DB::table('product_ratings')->insert([
                    'user_id' => $userId, 'product_id' => $productId, 'rating' => $rating, 'comment' => $comment,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->recompute($productId);

            return $existing !== null ? 'replaced' : 'created';
        });

        $this->forget($productId);

        return $status;
    }

    /** `rating_avg` / `rating_count` from the table, as the transform computes them (A-22r). */
    private function recompute(int $productId): void
    {
        $ratings = DB::table('product_ratings')->where('product_id', $productId);
        $count = $ratings->count();
        $mean = $ratings->avg('rating');
        $avg = $count === 0 || ! is_numeric($mean) ? null : number_format(min(9.99, round((float) $mean, 2)), 2, '.', '');
        DB::table('catalog_products')->where('id', $productId)->update(['rating_avg' => $avg, 'rating_count' => $count]);
    }

    /**
     * Every storefront showing the product: forget THIS product's detail and card only (K6, developer
     * 2026-09-30). The product page shows the new average at once; listing grids catch up at the next
     * catalog:warm (5 min) or the cache TTL (10 min). It used to flush the storefront's whole catalogue
     * cache (and warm it) on every rating — at 5 ratings a minute from one address, a way to keep the
     * shop's caches cold.
     */
    private function forget(int $productId): void
    {
        foreach (DB::table('storefront_product')->where('product_id', $productId)->pluck('storefront_id') as $id) {
            if (is_numeric($id)) {
                $this->cache->forgetProduct((int) $id, $productId);
                $this->cache->forgetCard((int) $id, $productId);
            }
        }
    }

    private static function visible(int $storefrontId, int $productId): bool
    {
        return DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1);
            })
            ->where('p.id', $productId)->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->exists();
    }
}
