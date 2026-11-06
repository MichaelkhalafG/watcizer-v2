<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Compat\CompatHome;
use App\Storefront\ImageUrl;
use App\Storefront\StorefrontHome;
use App\Storefront\StorefrontUrls;
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
 * endpoints: Brand Fashion reads v2 (`/api/v2/{sf}/home`, {@see StorefrontHome}) and
 * Watchizer reads compat (`catalog/home`, {@see CompatHome}) — its live path, which is not
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
     *                                  compat passes {@see StorefrontUrls::compatAssetBase()},
     *                                  because a compat payload's image host is NOT the v2 one and the live
     *                                  shop's URLs must not move a byte (COMPAT-ASSET-HOST, 2026-10-14).
     * @return list<array<string, mixed>>
     */
    public static function list(int $storefrontId, ?string $assetBase = null): array
    {
        return self::rows($storefrontId, BannerWriter::HOME, $assetBase);
    }

    /**
     * The two SIDE slots beside the large hero (the twelve updates, Part 3.1, 2026-11-05): `[top, bottom]`,
     * each the slot's banner or null.
     *
     * A slot is a placement of its own (`home_side_1`, `home_side_2`), managed on the banners screen apart
     * from the hero's slides — they are not slides. A slot shows ONE banner: the first active, in-window row
     * of its placement in the dashboard's order, so the team can schedule its successor without a gap. Both
     * null — every storefront that never fills them — and the hero renders full width exactly as it did.
     * The same shape as a hero slide, so the storefront draws both with one component.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public static function side(int $storefrontId, ?string $assetBase = null): array
    {
        return [
            self::slot(self::rows($storefrontId, BannerWriter::SIDE[0], $assetBase)),
            self::slot(self::rows($storefrontId, BannerWriter::SIDE[1], $assetBase)),
        ];
    }

    /**
     * ONE slot from its active banners (the review of 2026-11-05: a side slot may have a picture per device). The
     * slot IS its first banner for a computer (`both` or `desktop`) — or, with none, its first banner at all — and it
     * carries `mobile_image`, the picture of its first `mobile` banner, when it has one of each: the page then
     * draws that one on a phone. A slot with a single picture goes out exactly as it did before (no extra key).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private static function slot(array $rows): ?array
    {
        $wide = null;
        $phone = null;
        foreach ($rows as $row) {
            if (($row['device'] ?? null) === 'mobile') {
                $phone ??= $row;
            } else {
                $wide ??= $row;
            }
        }
        if ($wide === null) {
            return $phone;
        }

        return $phone === null ? $wide : $wide + ['mobile_image' => $phone['image']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(int $storefrontId, string $placement, ?string $assetBase): array
    {
        $rows = DB::table('storefront_banners as b')
            ->leftJoin('storefront_categories as c', 'c.id', '=', 'b.storefront_category_id')
            ->where('b.storefront_id', $storefrontId)
            ->where('b.placement', $placement)
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
