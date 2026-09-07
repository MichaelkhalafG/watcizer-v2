<?php

namespace App\Compat;

use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * The legacy `SitemapController::generateSitemap()` reproduced byte-for-byte from the clean
 * tables (products, brands, category types, sub types, grades) and the legacy content table
 * `blogs` (read-only). Same static pages, same string assembly, same slug rule: the product URL
 * is slugify(EN title) — twins therefore share a URL exactly as they do today (§6.1).
 */
final class CompatSitemap
{
    public function __construct(
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly CompatProducts $products,
    ) {}

    public function xml(): string
    {
        $domain = config()->string('compat.sitemap_domain');
        $imageHost = config()->string('compat.sitemap_image_host');
        $urls = [];

        foreach ([
            ['/', '1.0', 'daily'], ['/products', '0.9', 'daily'], ['/category/Watches', '0.9', 'daily'],
            ['/category/Fashion', '0.8', 'weekly'], ['/offers', '0.8', 'daily'], ['/blogs', '0.7', 'weekly'],
            ['/about-us', '0.5', 'monthly'], ['/contact-us', '0.5', 'monthly'], ['/privacy-policy', '0.3', 'yearly'],
            ['/terms-and-conditions', '0.3', 'yearly'],
        ] as [$loc, $priority, $freq]) {
            $urls[] = self::url($domain.$loc, $priority, $freq);
        }

        $rows = $this->products->query()->orderBy('p.id')->get();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $translations = $this->products->translations($ids);
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $enTitle = self::nstr($translations[$id]['en']['title'] ?? null);
            $arTitle = self::nstr($translations[$id]['ar']['title'] ?? null);
            if ($enTitle === null || $enTitle === '') {
                continue;
            }
            $imageXml = '';
            $file = LegacyJson::basename(Row::nstr($row, 'cover_path'));
            if ($file !== null && $file !== '') {
                $imgUrl = $imageHost.'/Uploads_Images/Product/'.$file;
                $imageXml = '
        <image:image>
            <image:loc>'.htmlspecialchars($imgUrl).'</image:loc>
            <image:title>'.htmlspecialchars($enTitle.($arTitle !== null && $arTitle !== '' ? ' | '.$arTitle : '')).'</image:title>
            <image:caption>'.htmlspecialchars($enTitle.' - Watchizer Egypt').'</image:caption>
        </image:image>';
            }
            $slug = LegacySlug::make($enTitle);
            $slug = $slug !== '' ? $slug : (string) $id;
            $urls[] = self::url($domain.'/product/'.$slug, '0.8', 'weekly', LegacyJson::atom(Row::nstr($row, 'updated_at')), $imageXml);
        }

        foreach ($this->names->ids('brands') as $brandId) {
            $enName = $this->names->name('brands', $brandId, 'en');
            if ($enName === null || $enName === '') {
                continue;
            }
            $urls[] = self::url($domain.'/brand/'.rawurlencode($enName), '0.7', 'weekly');
        }

        foreach ($this->categories->categoryTypes() as $node) {
            $enName = $this->categories->name($node, 'en');
            if ($enName === null || $enName === '') {
                continue;
            }
            $urls[] = self::url($domain.'/category/'.rawurlencode($enName), '0.7', 'weekly');
        }

        foreach ($this->categories->subTypes() as $node) {
            $enName = $this->categories->name($node, 'en');
            if ($enName === null || $enName === '') {
                continue;
            }
            $slug = LegacySlug::make($enName);
            if ($slug === '') {
                continue;
            }
            $urls[] = self::url($domain.'/subtypes/'.$slug, '0.7', 'weekly');
        }

        foreach ($this->names->ids('grades') as $gradeId) {
            $enName = $this->names->name('grades', $gradeId, 'en');
            if ($enName === null || $enName === '') {
                continue;
            }
            $slug = LegacySlug::make($enName);
            if ($slug === '') {
                continue;
            }
            $urls[] = self::url($domain.'/grade/'.$slug, '0.7', 'weekly');
        }

        $blogTitles = [];
        foreach (DB::connection('legacy')->table('blog_translations')->select(['blog_id', 'title'])->where('locale', 'en')->orderBy('id')->get() as $t) {
            $blogTitles[Row::int($t, 'blog_id')] ??= Row::nstr($t, 'title');
        }
        foreach (DB::connection('legacy')->table('blogs')->select(['id', 'image', 'updated_at'])->orderBy('id')->get() as $blog) {
            $enTitle = $blogTitles[Row::int($blog, 'id')] ?? null;
            if ($enTitle === null || $enTitle === '') {
                continue;
            }
            $imageXml = '';
            $image = Row::nstr($blog, 'image');
            if ($image !== null && $image !== '') {
                $imgUrl = $imageHost.'/Uploads_Images/Blog/'.$image;
                $imageXml = '
        <image:image>
            <image:loc>'.htmlspecialchars($imgUrl).'</image:loc>
            <image:title>'.htmlspecialchars($enTitle.' - Watchizer Blog').'</image:title>
        </image:image>';
            }
            $urls[] = self::url($domain.'/blog/'.rawurlencode($enTitle), '0.6', 'monthly', LegacyJson::atom(Row::nstr($blog, 'updated_at')), $imageXml);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'."\n";
        $xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"'."\n";
        $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        $xml .= implode("\n", $urls);
        $xml .= "\n</urlset>";

        return $xml;
    }

    private static function url(string $loc, string $priority, string $changefreq, ?string $lastmod = null, string $imageXml = ''): string
    {
        $lastmodXml = $lastmod !== null ? "\n        <lastmod>{$lastmod}</lastmod>" : '';

        return '    <url>
        <loc>'.htmlspecialchars($loc)."</loc>{$lastmodXml}
        <changefreq>{$changefreq}</changefreq>
        <priority>{$priority}</priority>{$imageXml}
    </url>";
    }

    private static function nstr(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
