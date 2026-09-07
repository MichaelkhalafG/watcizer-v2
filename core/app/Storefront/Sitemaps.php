<?php

namespace App\Storefront;

use App\Support\Val;
use App\Transform\Row;

/**
 * Per-storefront, per-locale sitemaps (CLEAN_CORE_STUDY §6.4): the index lists
 * `sitemaps/{locale}/products-{n}.xml` (5 000 URLs each), `categories.xml`, `brands.xml` and
 * `static.xml` for every storefront locale. Entries: only visible + published + active products;
 * `lastmod` = greatest of product / placement / cover updated_at; `<image:image>` with the cover;
 * `xhtml:link` alternates for both locales. Chunks are cached 6 h and flushed with the listings.
 *
 * URL design (§6.1/§6.2): the default locale is unprefixed, the other under `/{locale}/`;
 * products `/product/{slug}`, depth-1 categories `/category/{slug}`, deeper `/c/{path}`, brands `/brand/{slug}`.
 */
final class Sitemaps
{
    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly Lookups $lookups,
        private readonly CategoryTree $tree,
        private readonly ProductCards $cards,
    ) {}

    public function host(): string
    {
        $domain = $this->ctx->storefront->domain;

        return is_string($domain) && $domain !== '' ? 'https://'.$domain : rtrim(config()->string('app.url'), '/');
    }

    public function prefix(string $locale): string
    {
        return $locale === $this->ctx->defaultLocale() ? '' : '/'.$locale;
    }

    public function index(string $apiBase): string
    {
        $chunk = config()->integer('storefront.sitemap.chunk');
        $count = $this->cards->base()->count();
        $chunks = max(1, (int) ceil($count / $chunk));
        $lastmod = $this->cards->base()->selectRaw('MAX(GREATEST(p.updated_at, sp.updated_at)) AS m')->value('m');
        $lastmod = is_string($lastmod) ? ProductCards::ts($lastmod) : null;

        $entries = [];
        foreach ($this->ctx->locales() as $locale) {
            for ($n = 1; $n <= $chunks; $n++) {
                $entries[] = self::sitemapEntry("{$apiBase}/sitemaps/{$locale}/products-{$n}.xml", $lastmod);
            }
            $entries[] = self::sitemapEntry("{$apiBase}/sitemaps/{$locale}/categories.xml", $lastmod);
            $entries[] = self::sitemapEntry("{$apiBase}/sitemaps/{$locale}/brands.xml", null);
            $entries[] = self::sitemapEntry("{$apiBase}/sitemaps/{$locale}/static.xml", null);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".implode("\n", $entries)."\n</sitemapindex>";
    }

    /** @return string|null null when the file name is unknown or the chunk is out of range */
    public function chunk(string $locale, string $file): ?string
    {
        if (! in_array($locale, $this->ctx->locales(), true)) {
            return null;
        }
        $key = "{$locale}:{$file}";

        /** @var string|null */
        return $this->cache->remember($this->ctx->id(), 'sitemap', $key, config()->integer('storefront.ttl.sitemap'), function () use ($locale, $file): ?string {
            if (preg_match('/^products-(\d+)$/', $file, $m) === 1) {
                return $this->products($locale, (int) $m[1]);
            }

            return match ($file) {
                'categories' => $this->categories($locale),
                'brands' => $this->brands($locale),
                'static' => $this->urlset([$this->url($this->host().$this->prefix($locale).'/', null, $this->alternates('/'), '')]),
                default => null,
            };
        });
    }

    private function products(string $locale, int $n): ?string
    {
        $chunk = config()->integer('storefront.sitemap.chunk');
        $rows = $this->cards->base()
            ->select(['sp.product_id', 'sp.slug', 'p.updated_at AS p_updated', 'sp.updated_at AS sp_updated'])
            ->selectRaw('(SELECT ci.path FROM catalog_product_images ci WHERE ci.product_id = p.id AND ci.is_cover = 1 ORDER BY ci.sort, ci.id LIMIT 1) AS cover_path')
            ->selectRaw('(SELECT MAX(ci.updated_at) FROM catalog_product_images ci WHERE ci.product_id = p.id AND ci.is_cover = 1) AS cover_updated')
            ->orderBy('sp.product_id')
            ->forPage($n, $chunk)
            ->get();
        if ($rows->isEmpty() && $n > 1) {
            return null;
        }
        $ids = ProductListing::idsOf($rows);
        $titles = $this->cards->translations($ids, ['title']);
        $urls = [];
        foreach ($rows as $r) {
            $id = Row::int($r, 'product_id');
            $path = '/product/'.Row::str($r, 'slug');
            $stamps = array_filter([Row::nstr($r, 'p_updated'), Row::nstr($r, 'sp_updated'), Row::nstr($r, 'cover_updated')], fn (?string $s) => $s !== null);
            $lastmod = $stamps === [] ? null : ProductCards::ts(max($stamps));
            $image = '';
            $cover = Row::nstr($r, 'cover_path');
            if ($cover !== null) {
                $title = $titles[$id]['title'][$locale] ?? ($titles[$id]['title'][$this->ctx->defaultLocale()] ?? null);
                $image = "\n    <image:image><image:loc>".htmlspecialchars(ImageUrl::src($cover)).'</image:loc>'.($title !== null ? '<image:title>'.htmlspecialchars($title).'</image:title>' : '').'</image:image>';
            }
            $urls[] = $this->url($this->host().$this->prefix($locale).$path, $lastmod, $this->alternates($path), $image);
        }

        return $this->urlset($urls);
    }

    private function categories(string $locale): string
    {
        $urls = [];
        foreach ($this->tree->data()['nodes'] as $node) {
            $path = self::categoryPath($node);
            $updated = $node['updated_at'];
            $urls[] = $this->url($this->host().$this->prefix($locale).$path, is_string($updated) ? $updated : null, $this->alternates($path), '');
        }

        return $this->urlset($urls);
    }

    private function brands(string $locale): string
    {
        $urls = [];
        foreach ($this->lookups->list('brands') as $brand) {
            if (($brand['is_active'] ?? true) !== true) {
                continue;
            }
            $path = '/brand/'.Val::str($brand, 'slug');
            $urls[] = $this->url($this->host().$this->prefix($locale).$path, null, $this->alternates($path), '');
        }

        return $this->urlset($urls);
    }

    /** @param  array<string, mixed>  $node */
    public static function categoryPath(array $node): string
    {
        return Val::int($node, 'depth') === 1 ? '/category/'.Val::str($node, 'slug') : '/c/'.Val::str($node, 'path');
    }

    private function alternates(string $path): string
    {
        $out = '';
        foreach ($this->ctx->locales() as $locale) {
            $out .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"{$locale}\" href=\"".htmlspecialchars($this->host().$this->prefix($locale).$path).'"/>';
        }
        $out .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"".htmlspecialchars($this->host().$this->prefix($this->ctx->defaultLocale()).$path).'"/>';

        return $out;
    }

    private function url(string $loc, ?string $lastmod, string $alternates, string $image): string
    {
        return '  <url>'."\n    <loc>".htmlspecialchars($loc).'</loc>'.($lastmod !== null ? "\n    <lastmod>{$lastmod}</lastmod>" : '').$alternates.$image."\n  </url>";
    }

    /** @param  list<string>  $urls */
    private function urlset(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n".implode("\n", $urls)."\n</urlset>";
    }

    private static function sitemapEntry(string $loc, ?string $lastmod): string
    {
        return '  <sitemap><loc>'.htmlspecialchars($loc).'</loc>'.($lastmod !== null ? "<lastmod>{$lastmod}</lastmod>" : '').'</sitemap>';
    }
}
