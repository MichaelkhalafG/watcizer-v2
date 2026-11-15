<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Compat\CompatLocaleSitemap;
use App\Domain\Catalog\SearchExposure;
use App\Domain\Seo\LandingPages;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * Does a shop have this page? (the quality gate, 2026-11-14 — "links resolve"). A site path, with or without its /ar,
 * query and fragment dropped. Known pages: the home, /listing, /blogs, the trust and campaign pages, Original watches,
 * a brand with LIVE products, a landing page, a category or sub-category, a PUBLISHED article of the shop, a product the
 * shop shows. Read for a CLI run: each storefront's sets are built once per process.
 */
final class SitePaths
{
    /** @var array<int, array<string, array<string, true>>> */
    private static array $sets = [];

    public static function exists(int $storefrontId, string $href): bool
    {
        $path = (string) preg_replace('/[?#].*$/', '', $href);
        $path = $path === '/ar' ? '/' : (str_starts_with($path, '/ar/') ? substr($path, 3) : $path);
        $path = rtrim($path, '/') ?: '/';
        if (in_array($path, ['/', '/listing', '/blogs', '/'.SearchExposure::ORIGINAL_WATCHES['slug'], ...CompatLocaleSitemap::TRUST_PAGES, ...CompatLocaleSitemap::CAMPAIGN_PAGES], true)) {
            return true;
        }
        $parts = explode('/', ltrim($path, '/'));
        $sets = self::sets($storefrontId);

        return match ($parts[0]) {
            'brand' => count($parts) === 2 ? isset($sets['brand'][$parts[1]]) : app(LandingPages::class)->find($storefrontId, implode('/', array_slice($parts, 1))) !== null,
            'category' => count($parts) === 2 && isset($sets['category'][rawurldecode($parts[1])]),
            'subtypes' => count($parts) === 2 && isset($sets['category'][rawurldecode($parts[1])]),
            'blog' => count($parts) === 2 && isset($sets['blog'][rawurldecode($parts[1])]),
            'product' => count($parts) === 2 && isset($sets['product'][rawurldecode($parts[1])]),
            default => false,
        };
    }

    /** Forget the per-process sets (tests, and a long run that writes articles). */
    public static function forget(): void
    {
        self::$sets = [];
    }

    /** @return array<string, array<string, true>> */
    private static function sets(int $storefrontId): array
    {
        if (isset(self::$sets[$storefrontId])) {
            return self::$sets[$storefrontId];
        }
        $out = ['brand' => [], 'category' => [], 'blog' => [], 'product' => []];
        $live = DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at');
        foreach ((clone $live)->join('catalog_brands as b', 'b.id', '=', 'p.brand_id')->distinct()->pluck('b.slug') as $slug) {
            $out['brand'][Coerce::str($slug)] = true;
        }
        foreach (DB::table('storefront_categories')->where('storefront_id', $storefrontId)->where('is_active', 1)->pluck('slug') as $slug) {
            $out['category'][Coerce::str($slug)] = true;
        }
        foreach (DB::table('core_blogs')->where('storefront_id', $storefrontId)->whereNotNull('published_at')->pluck('slug') as $slug) {
            $out['blog'][Coerce::str($slug)] = true;
        }
        // a product: the shop's own slug, and (Watchizer's product page) the English title's slug
        foreach ((clone $live)->leftJoin('catalog_product_translations as t', function (\Illuminate\Database\Query\JoinClause $j): void {
            $j->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'en');
        })->get(['sp.slug', 't.title']) as $r) {
            $out['product'][Row::str($r, 'slug')] = true;
            $title = Row::nstr($r, 'title');
            if ($title !== null) {
                $out['product'][LegacySlug::make($title)] = true;
            }
        }

        return self::$sets[$storefrontId] = $out;
    }
}
