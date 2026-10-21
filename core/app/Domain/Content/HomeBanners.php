<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Storefront\ImageUrl;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The home banners a storefront is showing, as ONE list both API paths serve (the developer's addendum,
 * 2026-10-20).
 *
 * ── Why this is not inside either endpoint ──────────────────────────────────────────────────────
 *
 * The same hero component now runs in BOTH storefront apps, and the two apps read two different
 * endpoints: Brand Fashion reads v2 (`/api/v2/{sf}/home`, {@see \App\Storefront\StorefrontHome}) and
 * Watchizer reads compat (`catalog/home`, {@see \App\Compat\CompatHome}) — its live path, which is not
 * being moved for this. One component cannot read two shapes, so the shape is built once, here, and both
 * endpoints hand it out. v2 adds its own `category` ref on top for the build that is still in flight.
 *
 * ── `href` is resolved on the SERVER ────────────────────────────────────────────────────────────
 *
 * A banner points at a URL, a category or a product, and the hero does not know this taxonomy. Worse,
 * the obvious client-side guess is wrong: a category node's `path` is a SLUG PATH (`women/bags`) while
 * the category route is ONE segment (`/category/[category]`), so `/category/{path}` 404s for anything
 * below depth 1 — which is what the first cut of the Brand Fashion hero did. The server knows the tree,
 * so the server answers the question, in URLs both apps route:
 *
 *   url      → the link as typed (already checked to be `https://…` or `/…` when it was saved)
 *   category → `/category/{slug}`   — the node's own slug, the single segment the route takes
 *   product  → `/products/{id}`     — `catalog_products.id` IS the legacy `products.id`, and
 *                                     `products/[id]` is a byte-identical twin route in both apps
 *   none     → `/listing`
 *
 * `alt` is the destination category's name per locale (`{"en": …, "ar": …}`), or null when the banner
 * has no category — there is no alt-text field on a banner, and a decorative image with an invented
 * description is worse for a screen reader than an empty one.
 */
final class HomeBanners
{
    /**
     * Every active in-window home banner for a storefront, in the dashboard's order.
     *
     * `device` rides on each row and THE FILTERING IS THE CLIENT'S, never this list's: the CDN in front of
     * both API hosts keeps one copy per URL and ignores `Vary`, so a payload narrowed by user-agent here
     * would be served to the wrong device out of a cached copy (V2-CDN-PRIVATE, 2026-10-19).
     *
     * @param  string|null  $assetBase  the image host to name. v2 passes null and keeps the request's own host;
     *                                    compat passes {@see \App\Storefront\StorefrontUrls::compatAssetBase()},
     *                                    because a compat payload's image host is NOT the v2 one and the live
     *                                    shop's URLs must not move a byte (COMPAT-ASSET-HOST, 2026-10-14).
     * @return list<array<string, mixed>>
     */
    public static function list(int $storefrontId, ?string $assetBase = null): array
    {
        $rows = DB::table('storefront_banners as b')
            ->leftJoin('storefront_categories as c', 'c.id', '=', 'b.storefront_category_id')
            ->where('b.storefront_id', $storefrontId)
            ->where('b.placement', 'home')
            ->where('b.is_active', 1)
            ->where(fn (Builder $q) => $q->whereNull('b.starts_at')->orWhere('b.starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('b.ends_at')->orWhere('b.ends_at', '>=', now()))
            ->orderBy('b.sort_order')->orderBy('b.id')
            ->select(['b.id', 'b.device', 'b.image_path', 'b.link_url', 'b.product_id', 'b.storefront_category_id', 'c.slug as category_slug'])
            ->get();

        $categoryIds = [];
        foreach ($rows as $row) {
            $id = Row::nint($row, 'storefront_category_id');
            if ($id !== null) {
                $categoryIds[$id] = true;
            }
        }
        $names = $categoryIds === [] ? [] : self::names(array_keys($categoryIds));

        $out = [];
        foreach ($rows as $row) {
            $categoryId = Row::nint($row, 'storefront_category_id');
            $out[] = [
                'id' => Row::int($row, 'id'),
                'device' => BannerWriter::device(Row::nstr($row, 'device')),
                'image' => ImageUrl::object(BannerImage::path(Row::str($row, 'image_path')), null, null, null, null, $assetBase),
                'href' => self::href(Row::nstr($row, 'link_url'), Row::nstr($row, 'category_slug'), Row::nint($row, 'product_id')),
                'alt' => $categoryId === null ? null : ($names[$categoryId] ?? null),
                // kept because the v2 payload has carried them since it was written; the hero reads
                // `href` and `alt` and never these
                'link_url' => Row::nstr($row, 'link_url'),
                'product_id' => Row::nint($row, 'product_id'),
                'category_id' => $categoryId,
            ];
        }

        return $out;
    }

    /** Where a banner sends a shopper — see the class note for the four rules. */
    private static function href(?string $linkUrl, ?string $categorySlug, ?int $productId): string
    {
        $url = trim((string) $linkUrl);
        if ($url !== '') {
            return $url;
        }
        $slug = trim((string) $categorySlug);
        if ($slug !== '') {
            return '/category/'.$slug;
        }
        if ($productId !== null) {
            return '/products/'.$productId;
        }

        return '/listing';
    }

    /**
     * Category names per locale, for the referenced nodes only.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    private static function names(array $ids): array
    {
        $out = [];
        foreach (DB::table('storefront_category_translations')
            ->select(['storefront_category_id', 'locale', 'name'])
            ->whereIn('storefront_category_id', $ids)->orderBy('id')->get() as $t) {
            $out[Row::int($t, 'storefront_category_id')][Row::str($t, 'locale')] = Row::str($t, 'name');
        }

        return $out;
    }
}
