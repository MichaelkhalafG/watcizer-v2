<?php

declare(strict_types=1);

namespace App\Compat;

use App\Domain\Catalog\SearchExposure;
use App\Domain\Seo\LandingPages;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 *  - the trust pages, `/about-us`, `/contact-us`, `/privacy-policy`, `/terms-and-conditions`, and `/faq` (2026-10-30)
 *    (TRUST_PAGES). They were 404s and left out until the storefront built them (2026-10-02);
 *    `LocaleSitemapTest` holds that each one listed is a page the storefront has;
 *  - `/blogs` and every `/blog/{slug}` — only once the storefront HAS a published article (B9: an
 *    empty /blogs is not listed), with the article's publish date as lastmod.
 * Images: the product's cover, on the host the pages use (`compat.sitemap_image_host`), titled in
 * the sitemap's language.
 *
 * PRODUCT URLS FOLLOW THE SHOP'S OWN ROUTE (2026-11-14, SEO Phase 1). Watchizer's product page resolves the
 * English-title slug of the listing index (above) and keeps it. Every OTHER storefront's app routes products by
 * its own `storefront_product.slug` (v2 `products/{slug}`): Brand Fashion's sitemap used to print the title slug
 * too and ~4,000 of its 8,415 product URLs per language answered 404 (measured live 2026-11-13: 76 of 160; on the
 * scratch copy 3,781 of 9,273). For those storefronts the products are every product v2 answers for —
 * `ProductCards::visibleOn`, the same rule as the product page — each under its own slug, once.
 *
 * CACHED, GUARDED (2026-11-14): a urlset over the growing catalogue costs seconds to build (Brand Fashion 3.4 s
 * on the scratch copy) and was built on EVERY request. It is one `compat_locale_sitemap` entry per storefront
 * and language, built under the storefront's build lock with the last good copy served meanwhile
 * (`StorefrontCache::rememberGuarded`), and built before anyone asks by `catalog:warm` (`warm()`).
 */
final class CompatLocaleSitemap
{
    public const LOCALES = ['en', 'ar'];

    /** The storefront's trust pages (2026-10-02), English paths; the warranty, shipping and returns pages since 2026-11-14. */
    /** The article archives (SEO Phase 3): listed from this many articles; the storefront's topics (src/lib/blogTopics.js TOPICS). */
    public const ARCHIVE_MIN = 3;

    public const ARCHIVE_TOPICS = ['buying-guide', 'comparison', 'how-to', 'care', 'style', 'gifts', 'technology', 'brand', 'explainer'];

    public const TRUST_PAGES = ['/about-us', '/contact-us', '/privacy-policy', '/terms-and-conditions', '/faq', '/warranty', '/shipping', '/returns', '/team', '/editorial-policy'];

    /**
     * The two campaign pages (the design pass, 2026-11-05). They are PERMANENT pages — up, and indexable, between
     * campaigns; only their products change — so they are listed like any other fixed page of the shop.
     */
    public const CAMPAIGN_PAGES = ['/hot-deals', '/flash-sale'];

    public function __construct(
        private readonly CompatListing $listing,
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly CompatProducts $products,
        private readonly CompatBlogs $blogs,
        private readonly int $storefrontId,
        private readonly StorefrontCache $cache,
    ) {}

    /** The shop's address, read once per object: `url()` names it four times per URL (2.2 ms a URL uncached). */
    private ?string $domain = null;

    public function index(): string
    {
        $domain = $this->domain();
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

        $current = Cache::get($this->cache->key($this->storefrontId, 'compat_locale_sitemap', $locale));
        if (is_string($current)) {
            return $current;
        }
        // A REQUEST NEVER REBUILDS A SITEMAP THAT HAS A LAST GOOD COPY (the 2026-11-12 lesson: anything heavy runs from the
        // CLI). After a flush the last good copy is served and catalog:warm — every 5 minutes — builds the new one. Only a
        // shop whose sitemap was never built builds it here, once, under the guard.
        $last = Cache::get(StorefrontCache::lastGoodKey($this->storefrontId, 'compat_locale_sitemap', $locale));
        if (is_string($last)) {
            return $last;
        }

        return $this->built($locale);
    }

    /**
     * Build this storefront's urlsets that are not cached (catalog:warm, every 5 minutes): a cached one costs nothing,
     * a cold one is built here instead of in Google's request. Language => ms it took.
     *
     * @return array<string, int>
     */
    public function warm(): array
    {
        $took = [];
        foreach (self::LOCALES as $locale) {
            $t = hrtime(true);
            $this->built($locale);
            $took[$locale] = (int) round((hrtime(true) - $t) / 1e6);
        }

        return $took;
    }

    /** The current urlset, built under the storefront's build lock when it is not cached. */
    private function built(string $locale): string
    {
        /** @var string */
        return $this->cache->rememberGuarded($this->storefrontId, 'compat_locale_sitemap', $locale, config()->integer('storefront.ttl.sitemap'),
            fn (): string => $this->build($locale));
    }

    /**
     * Forget a storefront's cached urlsets (both languages) — an article was saved, published or deleted. The last
     * good copies stay, so the next request is served at once while one request rebuilds.
     */
    public static function forget(int $storefrontId): void
    {
        $cache = app(StorefrontCache::class);
        foreach (self::LOCALES as $locale) {
            Cache::forget($cache->key($storefrontId, 'compat_locale_sitemap', $locale));
        }
    }

    /**
     * True when this storefront's app routes a product by its own `storefront_product.slug` (v2), false for the
     * default storefront (Watchizer), whose product page resolves the English-title slug of the listing index.
     */
    public function routesByStorefrontSlug(): bool
    {
        return $this->storefrontId !== config()->integer('compat.storefront_id');
    }

    private function build(string $locale): string
    {
        $urls = [];
        $entries = $this->listing->entries();
        // Products kept from search engines (2026-11-14, SearchExposure: the switch, else the rule) are on sale but in
        // no sitemap, and do not count towards a brand's or a category's thin-listing minimum: a page whose indexable
        // products fall under it leaves the sitemap like any thin page. A Rolex the rule matched is `restricted`, not
        // hidden: it stays, and counts.
        $hidden = array_flip(self::hiddenIds());
        $entries = array_values(array_filter($entries, fn (array $e): bool => ! isset($hidden[$e['id']])));
        // REAL DATES (2026-11-14, SEO Phase 1): a page that lists products changed when the newest of them did (the
        // product or its placement on this storefront) or when its own page text was saved; the trust and campaign
        // pages are written in code and carry no date rather than an invented one.
        $stamps = $this->stamps();
        $texts = $this->textStamps();
        $all = self::newest($stamps, array_map(fn (array $e): int => $e['id'], $entries));
        foreach (['/', '/listing'] as $path) {
            $urls[] = $this->url($locale, $path, $all);
        }
        foreach ([...self::TRUST_PAGES, ...self::CAMPAIGN_PAGES] as $path) {
            $urls[] = $this->url($locale, $path);
        }
        // the Original watches collection (Michael, 2026-11-14), once it is not thin — as its page decides
        $original = SearchExposure::onlyOriginalWatches(ProductCards::visibleOn(
            DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $this->storefrontId))
            ->selectRaw('COUNT(*) AS n, MAX(GREATEST(COALESCE(p.updated_at, sp.updated_at), COALESCE(sp.updated_at, p.updated_at))) AS m')->first();
        if ($original !== null && Row::int($original, 'n') >= config()->integer('compat.thin_listing_min', 3)) {
            $urls[] = $this->url($locale, '/'.SearchExposure::ORIGINAL_WATCHES['slug'], LegacyJson::atom(Row::nstr($original, 'm')));
        }

        $imageHost = CompatSitemap::imageHost($this->storefrontId);   // W7: this storefront's own API host, or no images
        // Visible products per facet value; a page with fewer than `compat.thin_listing_min` is thin and left out
        // (2026-11-14 — it was "at least one"), as the shop marks it noindex.
        $used = ['brands' => [], 'categories' => [], 'subTypes' => [], 'grades' => []];
        $members = $used;                                     // facet => value => the product ids it lists
        foreach ($entries as $e) {
            foreach (array_keys($used) as $facet) {
                if ($e[$facet] !== null) {
                    $used[$facet][$e[$facet]] = ($used[$facet][$e[$facet]] ?? 0) + 1;
                    $members[$facet][$e[$facet]][] = $e['id'];
                }
            }
        }
        $brandSlugs = DB::table('catalog_brands')->pluck('slug', 'id')->all();
        $dated = fn (string $facet, int $value, ?string $text): ?string => self::later(self::newest($stamps, $members[$facet][$value] ?? []), $text);
        $min = config()->integer('compat.thin_listing_min', 3);
        foreach (array_keys($used) as $facet) {
            $used[$facet] = array_filter($used[$facet], fn (int $n): bool => $n >= $min);
        }
        array_push($urls, ...($this->routesByStorefrontSlug()
            ? $this->storefrontSlugProducts($locale, $imageHost)
            : $this->titleSlugProducts($locale, $entries, $imageHost)));

        foreach ($this->categories->categoryTypes() as $node) {
            $legacy = CompatCategories::legacyIdOf($node);
            if (isset($used['categories'][$legacy])) {
                $slug = LegacySlug::make($this->categories->name($node, 'en') ?? '');
                $this->facet($urls, $locale, '/category/', $slug, $dated('categories', $legacy, $texts['category'][Val::str($node, 'path')] ?? null));
            }
        }
        foreach ($this->names->ids('brands') as $id) {
            if (isset($used['brands'][$id])) {
                $slug = LegacySlug::make($this->names->name('brands', $id, 'en') ?? '');
                $this->facet($urls, $locale, '/brand/', $slug, $dated('brands', $id, $texts['brand'][Coerce::str($brandSlugs[$id] ?? '')] ?? null));
            }
        }
        // LANDING PAGES (2026-11-14, SEO Phase 2): only those search engines may index — their copy passed the quality
        // gate in both languages and they hold 6+ LIVE products. No date: the brand's newest change may be outside them.
        foreach (app(LandingPages::class)->all($this->storefrontId) as $landing) {
            if ($landing['indexable']) {
                $urls[] = $this->url($locale, $landing['path']);
            }
        }
        foreach ($this->categories->subTypes() as $node) {
            $legacy = CompatCategories::legacyIdOf($node);
            if (isset($used['subTypes'][$legacy])) {
                $slug = LegacySlug::make($this->categories->name($node, 'en') ?? '');
                $this->facet($urls, $locale, '/subtypes/', $slug, $dated('subTypes', $legacy, $texts['category'][Val::str($node, 'path')] ?? null));
            }
        }
        foreach ($this->names->ids('grades') as $id) {
            if (isset($used['grades'][$id])) {
                $slug = LegacySlug::make($this->names->name('grades', $id, 'en') ?? '');
                $this->facet($urls, $locale, '/grade/', $slug, $dated('grades', $id, null));
            }
        }

        $articles = $this->blogs->list();
        if ($articles !== []) {
            // the newest article's date (the list is newest first)
            $newest = '';
            foreach ($articles as $article) {
                $newest = strtotime($article['modified_at']) > strtotime($newest ?: '1970-01-01') ? $article['modified_at'] : $newest;
            }
            $urls[] = $this->url($locale, '/blogs', LegacyJson::atom($newest));
            // SEO Phase 3 (2026-11-14): lastmod = the article's last REAL change (modified_at, the publication date until
            // then); the Arabic address is its Arabic slug, percent-encoded, when it has one
            foreach ($articles as $article) {
                $ar = $article['slug_ar'] !== null && $article['slug_ar'] !== '' ? '/blog/'.rawurlencode($article['slug_ar']) : null;
                $urls[] = $this->url($locale, '/blog/'.$article['slug'], LegacyJson::atom($article['modified_at']), '', $ar);
            }
            // the archives (SEO Phase 3): a brand's or a topic's articles, listed from ARCHIVE_MIN articles — below that the
            // storefront marks the page noindex (src/lib/blogTopics.js MIN_INDEXABLE, the same number)
            $groups = [];
            foreach ($articles as $article) {
                if ($article['brand'] !== null) {
                    $groups['/blogs/brand/'.$article['brand']['slug']][] = $article['modified_at'];
                }
                if ($article['topic'] !== null && in_array($article['topic'], self::ARCHIVE_TOPICS, true)) {
                    $groups['/blogs/topic/'.$article['topic']][] = $article['modified_at'];
                }
            }
            foreach ($groups as $path => $dates) {
                if (count($dates) >= self::ARCHIVE_MIN) {
                    usort($dates, fn (string $a, string $b): int => strtotime($b) <=> strtotime($a));
                    $urls[] = $this->url($locale, $path, LegacyJson::atom($dates[0]));
                }
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n"
            .implode("\n", $urls)."\n</urlset>";
    }

    /**
     * Watchizer's products: the listing index's English-title slugs, twins once (unchanged since S-AR stage 3).
     *
     * @param  list<array{id: int, slug: string}>  $entries
     * @return list<string>
     */
    private function titleSlugProducts(string $locale, array $entries, ?string $imageHost): array
    {
        $rows = [];
        foreach ($this->products->query()->get() as $row) {
            $rows[Row::int($row, 'id')] = $row;
        }
        $titles = $this->products->translations(array_map(fn (array $e): int => $e['id'], $entries));
        $seen = [];
        $urls = [];
        foreach ($entries as $e) {
            if ($e['slug'] === '' || isset($seen[$e['slug']])) {
                continue;                                     // a twin: its URL is already listed
            }
            $seen[$e['slug']] = true;
            $row = $rows[$e['id']] ?? null;
            $cover = $row === null ? null : Row::nstr($row, 'cover_path');
            $urls[] = $this->url($locale, '/product/'.$e['slug'], $row === null ? null : LegacyJson::atom(Row::nstr($row, 'updated_at')),
                $this->image($imageHost, $cover, $titles[$e['id']] ?? [], $locale));
        }

        return $urls;
    }

    /**
     * Every product this storefront's own product page answers for (`ProductCards::visibleOn`), under its
     * `storefront_product.slug`, in id order. Two queries over the storefront's placements — the products, then their
     * covers — and no sub-select per row (AGENTS §2.24).
     *
     * @return list<string>
     */
    private function storefrontSlugProducts(string $locale, ?string $imageHost): array
    {
        $rows = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $this->storefrontId)
            ->where('sp.slug', '<>', '')
            // never one kept from search engines (2026-11-14, SearchExposure)
            ->whereRaw('COALESCE('.SearchExposure::effectiveSql('p').", '') <> ?", [SearchExposure::HIDDEN])
            ->orderBy('sp.product_id')
            ->get(['sp.product_id', 'sp.slug', 'p.updated_at']);
        $covers = [];
        $coverRows = DB::table('catalog_product_images as ci')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'ci.product_id')->where('sp.storefront_id', '=', $this->storefrontId)->where('sp.is_visible', '=', 1);
            })
            ->where('ci.is_cover', 1)
            ->orderBy('ci.product_id')->orderBy('ci.sort')->orderBy('ci.id')
            ->get(['ci.product_id', 'ci.path']);
        foreach ($coverRows as $c) {
            $covers[Row::int($c, 'product_id')] ??= Row::nstr($c, 'path');   // the first cover, as the product page shows it
        }
        $ids = array_values(array_map(fn (object $r): int => Row::int($r, 'product_id'), $rows->all()));
        $titles = $this->products->translations($ids);
        $urls = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = Row::int($r, 'product_id');
            $slug = Row::str($r, 'slug');
            if (isset($seen[$slug])) {
                continue;                                     // a slug is unique per storefront; never list one twice
            }
            $seen[$slug] = true;
            $urls[] = $this->url($locale, '/product/'.rawurlencode($slug), LegacyJson::atom(Row::nstr($r, 'updated_at')),
                $this->image($imageHost, $covers[$id] ?? null, $titles[$id] ?? [], $locale));
        }

        return $urls;
    }

    /**
     * The products kept from search engines (2026-11-14, SearchExposure: the switch, else the rule).
     *
     * @return list<int>
     */
    private static function hiddenIds(): array
    {
        return array_values(array_map(
            fn (mixed $id): int => Coerce::int($id),
            DB::table('catalog_products as p')->whereNull('p.deleted_at')
                ->whereRaw(SearchExposure::effectiveSql('p').' = ?', [SearchExposure::HIDDEN])->pluck('p.id')->all(),
        ));
    }

    /**
     * A product's <image:image>: its cover on the storefront's image host, titled in the sitemap's language.
     *
     * @param  array<string, array<string, mixed>>  $titles  locale => translation row
     */
    private function image(?string $imageHost, ?string $coverPath, array $titles, string $locale): string
    {
        $file = LegacyJson::legacyImage($coverPath, 'Product');
        if ($imageHost === null || $file === null || $file === '') {
            return '';
        }
        $title = self::str($titles[$locale]['title'] ?? null) ?? self::str($titles['en']['title'] ?? null);

        return "\n    <image:image><image:loc>".htmlspecialchars($imageHost.'/Uploads_Images/'.(str_contains($file, '/') ? $file : 'Product/'.$file)).'</image:loc>'
            .($title !== null ? '<image:title>'.htmlspecialchars($title).'</image:title>' : '').'</image:image>';
    }

    /** @param  list<string>  $urls */
    private function facet(array &$urls, string $locale, string $prefix, string $slug, ?string $lastmod): void
    {
        if ($slug !== '') {
            $urls[] = $this->url($locale, $prefix.$slug, $lastmod);
        }
    }

    /**
     * When each product last changed for this storefront: the later of the product's and its placement's updated_at.
     * One query over the storefront's placements.
     *
     * @return array<int, string> product id => 'Y-m-d H:i:s'
     */
    private function stamps(): array
    {
        $out = [];
        foreach (DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $this->storefrontId)->whereNull('p.deleted_at')
            ->selectRaw('sp.product_id AS id, GREATEST(COALESCE(p.updated_at, sp.updated_at), COALESCE(sp.updated_at, p.updated_at)) AS m')
            ->get() as $r) {
            $m = Row::nstr($r, 'm');
            if ($m !== null) {
                $out[Row::int($r, 'id')] = $m;
            }
        }

        return $out;
    }

    /**
     * When each brand / category page text was last saved on this storefront (either language), by its key: a brand's
     * catalogue slug, a category's path ("watches", "watches/gmt").
     *
     * @return array{brand: array<string, string>, category: array<string, string>}
     */
    private function textStamps(): array
    {
        $out = ['brand' => [], 'category' => []];
        foreach (DB::table('core_seo_texts')->where('storefront_id', $this->storefrontId)->whereIn('subject_type', ['brand', 'category'])
            ->groupBy('subject_type', 'subject_key')
            ->selectRaw('subject_type, subject_key, MAX(updated_at) AS m')->get() as $r) {
            $m = Row::nstr($r, 'm');
            if ($m !== null) {
                $out[Row::str($r, 'subject_type') === 'brand' ? 'brand' : 'category'][Row::str($r, 'subject_key')] = $m;
            }
        }

        return $out;
    }

    /**
     * The newest stamp among products, as the sitemap's lastmod.
     *
     * @param  array<int, string>  $stamps
     * @param  list<int>  $ids
     */
    private static function newest(array $stamps, array $ids): ?string
    {
        $max = null;
        foreach ($ids as $id) {
            if (isset($stamps[$id]) && ($max === null || $stamps[$id] > $max)) {
                $max = $stamps[$id];
            }
        }

        return $max === null ? null : LegacyJson::atom($max);
    }

    /** The later of two dates (an atom string or a 'Y-m-d H:i:s' one), as an atom string. */
    private static function later(?string $a, ?string $b): ?string
    {
        $a = $a === null ? null : LegacyJson::atom($a);
        $b = $b === null ? null : LegacyJson::atom($b);
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return strtotime($a) >= strtotime($b) ? $a : $b;
    }

    /** A page's URL in `$locale`, with its alternates. `$path` is the English (bare) path. */
    private function url(string $locale, string $path, ?string $lastmod = null, string $image = '', ?string $arPath = null): string
    {
        $out = "  <url>\n    <loc>".htmlspecialchars($this->href($path, $locale, $arPath)).'</loc>'
            .($lastmod !== null ? "\n    <lastmod>{$lastmod}</lastmod>" : '');
        foreach ([...self::LOCALES, 'x-default'] as $lang) {
            $out .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"{$lang}\" href=\"".htmlspecialchars($this->href($path, $lang === 'x-default' ? 'en' : $lang, $arPath)).'"/>';
        }

        return $out.$image."\n  </url>";
    }

    /** The shop URL of an English path in a language: English bare, Arabic under /ar (its own path when it has one). */
    private function href(string $path, string $locale, ?string $arPath = null): string
    {
        $domain = $this->domain();
        if ($locale !== 'ar') {
            return $domain.$path;
        }
        $path = $arPath ?? $path;

        return $domain.($path === '/' ? '/ar' : '/ar'.$path);
    }

    /**
     * The SHOP this sitemap is for (2026-10-22). The default storefront keeps `compat.sitemap_domain`, byte for
     * byte what Google has always been given; every other storefront names its own site — Brand Fashion's
     * sitemap used to list all 1,059 URLs on watchizereg.com, because this read one config value for every
     * shop (the backlog item). `StorefrontUrls::frontend()` is where the shop's own address is decided.
     */
    private function domain(): string
    {
        return $this->domain ??= $this->storefrontId === config()->integer('compat.storefront_id')
            ? rtrim(config()->string('compat.sitemap_domain'), '/')
            : StorefrontUrls::frontend($this->storefrontId);
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
