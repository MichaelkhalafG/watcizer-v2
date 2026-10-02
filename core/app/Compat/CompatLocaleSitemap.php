<?php

declare(strict_types=1);

namespace App\Compat;

use App\Support\LegacySlug;
use App\Transform\Row;

/**
 * The Watchizer storefront's sitemaps, one per language (S-AR stage 3, 2026-10-01):
 *
 *   /sitemaps/index.xml   → lists /sitemaps/en.xml and /sitemaps/ar.xml on the SHOP's domain
 *   /sitemaps/en.xml      → every page under its bare URL   (https://watchizereg.com/product/…)
 *   /sitemaps/ar.xml      → every page under its /ar URL     (https://watchizereg.com/ar/product/…)
 * Every <url> carries xhtml:link alternates for en, ar and x-default (English), matching the
 * pages' own hreflang tags.
 *
 * ONLY URLs the storefront answers 200 on, built from the same index the storefront routes with:
 *  - products: the listing index's slugs (the English-title slug; twins share a URL, so each slug
 *    once — 686 URLs for 698 products on 2026-10-01). The v2 sitemap's `storefront_product.slug`
 *    was not usable here: 25 of those carry an id suffix ("…-621") the storefront 404s;
 *  - `/category/`, `/brand/`, `/subtypes/`, `/grade/` + the slug of the English name, and only
 *    those with at least one visible product (the legacy sitemap listed empty brands);
 *  - `/` and `/listing`. The legacy sitemap's `/products` (a 404) and `/offers` (a redirect) are
 *    gone (S5, B9);
 *  - the four trust pages, `/about-us`, `/contact-us`, `/privacy-policy`, `/terms-and-conditions`
 *    (TRUST_PAGES). They were 404s and left out until the storefront built them (2026-10-02);
 *    `LocaleSitemapTest` holds that each one listed is a page the storefront has;
 *  - `/blogs` and every `/blog/{slug}` — only once the storefront HAS a published article (B9: an
 *    empty /blogs is not listed), with the article's publish date as lastmod.
 * Images: the product's cover, on the host the pages use (`compat.sitemap_image_host`), titled in
 * the sitemap's language.
 */
final class CompatLocaleSitemap
{
    public const LOCALES = ['en', 'ar'];

    /** The storefront's trust pages (2026-10-02), English paths. */
    public const TRUST_PAGES = ['/about-us', '/contact-us', '/privacy-policy', '/terms-and-conditions'];

    public function __construct(
        private readonly CompatListing $listing,
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly CompatProducts $products,
        private readonly CompatBlogs $blogs,
    ) {}

    public function index(): string
    {
        $domain = config()->string('compat.sitemap_domain');
        $entries = [];
        foreach (self::LOCALES as $locale) {
            $entries[] = '  <sitemap><loc>'.htmlspecialchars("{$domain}/sitemaps/{$locale}.xml").'</loc></sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".implode("\n", $entries)."\n</sitemapindex>";
    }

    /** Null for a locale the shop does not have. */
    public function urlset(string $locale): ?string
    {
        if (! in_array($locale, self::LOCALES, true)) {
            return null;
        }
        $urls = [];
        foreach (['/', '/listing', ...self::TRUST_PAGES] as $path) {
            $urls[] = $this->url($locale, $path);
        }

        $entries = $this->listing->entries();
        $rows = [];
        foreach ($this->products->query()->get() as $row) {
            $rows[Row::int($row, 'id')] = $row;
        }
        $titles = $this->products->translations(array_map(fn (array $e): int => $e['id'], $entries));
        $imageHost = config()->string('compat.sitemap_image_host');
        $seen = [];
        $used = ['brands' => [], 'categories' => [], 'subTypes' => [], 'grades' => []];
        foreach ($entries as $e) {
            foreach (array_keys($used) as $facet) {
                if ($e[$facet] !== null) {
                    $used[$facet][$e[$facet]] = true;
                }
            }
            if ($e['slug'] === '' || isset($seen[$e['slug']])) {
                continue;                                     // a twin: its URL is already listed
            }
            $seen[$e['slug']] = true;
            $row = $rows[$e['id']] ?? null;
            $image = '';
            $file = $row === null ? null : LegacyJson::legacyImage(Row::nstr($row, 'cover_path'), 'Product');
            if ($file !== null && $file !== '') {
                $title = self::str($titles[$e['id']][$locale]['title'] ?? null) ?? self::str($titles[$e['id']]['en']['title'] ?? null);
                $image = "\n    <image:image><image:loc>".htmlspecialchars($imageHost.'/Uploads_Images/'.(str_contains($file, '/') ? $file : 'Product/'.$file)).'</image:loc>'
                    .($title !== null ? '<image:title>'.htmlspecialchars($title).'</image:title>' : '').'</image:image>';
            }
            $lastmod = $row === null ? null : LegacyJson::atom(Row::nstr($row, 'updated_at'));
            $urls[] = $this->url($locale, '/product/'.$e['slug'], $lastmod, $image);
        }

        foreach ($this->categories->categoryTypes() as $node) {
            if (isset($used['categories'][CompatCategories::legacyIdOf($node)])) {
                $this->facet($urls, $locale, '/category/', $this->categories->name($node, 'en'));
            }
        }
        foreach ($this->names->ids('brands') as $id) {
            if (isset($used['brands'][$id])) {
                $this->facet($urls, $locale, '/brand/', $this->names->name('brands', $id, 'en'));
            }
        }
        foreach ($this->categories->subTypes() as $node) {
            if (isset($used['subTypes'][CompatCategories::legacyIdOf($node)])) {
                $this->facet($urls, $locale, '/subtypes/', $this->categories->name($node, 'en'));
            }
        }
        foreach ($this->names->ids('grades') as $id) {
            if (isset($used['grades'][$id])) {
                $this->facet($urls, $locale, '/grade/', $this->names->name('grades', $id, 'en'));
            }
        }

        $articles = $this->blogs->list();
        if ($articles !== []) {
            $urls[] = $this->url($locale, '/blogs');
            foreach ($articles as $article) {
                $urls[] = $this->url($locale, '/blog/'.$article['slug'], LegacyJson::atom($article['published_at']));
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            .implode("\n", $urls)."\n</urlset>";
    }

    /** @param  list<string>  $urls */
    private function facet(array &$urls, string $locale, string $prefix, ?string $englishName): void
    {
        $slug = LegacySlug::make($englishName ?? '');
        if ($slug !== '') {
            $urls[] = $this->url($locale, $prefix.$slug);
        }
    }

    /** A page's URL in `$locale`, with its alternates. `$path` is the English (bare) path. */
    private function url(string $locale, string $path, ?string $lastmod = null, string $image = ''): string
    {
        $out = "  <url>\n    <loc>".htmlspecialchars($this->href($path, $locale)).'</loc>'
            .($lastmod !== null ? "\n    <lastmod>{$lastmod}</lastmod>" : '');
        foreach ([...self::LOCALES, 'x-default'] as $lang) {
            $out .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"{$lang}\" href=\"".htmlspecialchars($this->href($path, $lang === 'x-default' ? 'en' : $lang)).'"/>';
        }

        return $out.$image."\n  </url>";
    }

    /** The shop URL of an English path in a language: English bare, Arabic under /ar. */
    private function href(string $path, string $locale): string
    {
        $domain = config()->string('compat.sitemap_domain');
        if ($locale !== 'ar') {
            return $domain.$path;
        }

        return $domain.($path === '/' ? '/ar' : '/ar'.$path);
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
