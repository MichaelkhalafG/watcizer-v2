<?php

/**
 * ✅ SEO: Dynamic XML Sitemap
 *
 * مكانه: routes/web.php → Route::get('/sitemap.xml', [SitemapController::class, 'index']);
 * أو ضيفه في: app/Http/Controllers/SitemapController.php
 *
 * بيولد sitemap ديناميكي بيشمل:
 *   - الصفحات الثابتة
 *   - كل المنتجات (عربي + إنجليزي)
 *   - كل البراندات
 *   - كل التصنيفات
 *   - كل المدونات
 */

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Brand;
use App\Models\CategoryType;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    const DOMAIN = 'https://watchizereg.com';

    public function index()
    {
        $sitemap = Cache::remember('sitemap_xml', now()->addHours(6), function () {
            return $this->generateSitemap();
        });

        return response($sitemap, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function generateSitemap(): string
    {
        $urls = [];

        // ── Static pages ──────────────────────────────────────────────────────
        $staticPages = [
            ['loc' => '/',                   'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => '/products',           'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => '/category/Watches',   'priority' => '0.9', 'changefreq' => 'daily'],
            ['loc' => '/category/Fashion',   'priority' => '0.8', 'changefreq' => 'weekly'],
            ['loc' => '/offers',             'priority' => '0.8', 'changefreq' => 'daily'],
            ['loc' => '/blogs',              'priority' => '0.7', 'changefreq' => 'weekly'],
            ['loc' => '/about-us',           'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => '/contact-us',         'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => '/privacy-policy',     'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => '/terms-and-conditions','priority'=> '0.3', 'changefreq' => 'yearly'],
        ];

        foreach ($staticPages as $page) {
            $urls[] = $this->makeUrl(self::DOMAIN . $page['loc'], $page['priority'], $page['changefreq']);
        }

        // ── Products ──────────────────────────────────────────────────────────
        // ✅ جيب كل المنتجات النشطة مع الترجمات
        $products = Product::with('translations')
            ->where('active', 1)
            ->select(['id', 'image', 'updated_at'])
            ->get();

        foreach ($products as $product) {
            $enTitle = $product->translations->where('locale', 'en')->first()?->product_title;
            $arTitle = $product->translations->where('locale', 'ar')->first()?->product_title;

            if (!$enTitle) continue;

            $imageXml = '';
            if ($product->image) {
                $imgUrl   = 'https://dash.watchizereg.com/Uploads_Images/Product/' . $product->image;
                // ✅ Image alt in both languages for better image SEO
                $imageXml = "
        <image:image>
            <image:loc>" . htmlspecialchars($imgUrl) . "</image:loc>
            <image:title>" . htmlspecialchars($enTitle . ($arTitle ? ' | ' . $arTitle : '')) . "</image:title>
            <image:caption>" . htmlspecialchars($enTitle . ' - Watchizer Egypt') . "</image:caption>
        </image:image>";
            }

            // English URL
            $urls[] = $this->makeUrl(
                self::DOMAIN . '/product/' . rawurlencode($enTitle),
                '0.8',
                'weekly',
                $product->updated_at?->toAtomString(),
                $imageXml
            );

            // ✅ Arabic alternate (same URL but signal via hreflang in page)
            // We add the AR title as alternate if different
            if ($arTitle && $arTitle !== $enTitle) {
                $urls[] = $this->makeUrl(
                    self::DOMAIN . '/product/' . rawurlencode($arTitle),
                    '0.7',
                    'weekly',
                    $product->updated_at?->toAtomString()
                );
            }
        }

        // ── Brands ────────────────────────────────────────────────────────────
        $brands = \App\Models\Brand::with('translations')->get();
        foreach ($brands as $brand) {
            $enName = $brand->translations->where('locale', 'en')->first()?->brand_name
                   ?? $brand->brand_name;
            if (!$enName) continue;
            $urls[] = $this->makeUrl(
                self::DOMAIN . '/brand/' . rawurlencode($enName),
                '0.7',
                'weekly'
            );
        }

        // ── Category Types ────────────────────────────────────────────────────
        $categoryTypes = CategoryType::with('translations')->get();
        foreach ($categoryTypes as $cat) {
            $enName = $cat->translations->where('locale', 'en')->first()?->category_type_name
                   ?? $cat->category_type_name;
            if (!$enName) continue;
            $urls[] = $this->makeUrl(
                self::DOMAIN . '/category/' . rawurlencode($enName),
                '0.7',
                'weekly'
            );
        }

        // ── Blogs ─────────────────────────────────────────────────────────────
        $blogs = Blog::with(['translations', 'images'])->get();
        foreach ($blogs as $blog) {
            $enTitle = $blog->translations->where('locale', 'en')->first()?->title;
            if (!$enTitle) continue;

            $imageXml = '';
            if ($blog->image) {
                $imgUrl   = 'https://dash.watchizereg.com/Uploads_Images/Blog/' . $blog->image;
                $imageXml = "
        <image:image>
            <image:loc>" . htmlspecialchars($imgUrl) . "</image:loc>
            <image:title>" . htmlspecialchars($enTitle . ' - Watchizer Blog') . "</image:title>
        </image:image>";
            }

            $urls[] = $this->makeUrl(
                self::DOMAIN . '/blog/' . rawurlencode($enTitle),
                '0.6',
                'monthly',
                $blog->updated_at?->toAtomString(),
                $imageXml
            );
        }

        // ── Build XML ─────────────────────────────────────────────────────────
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' . "\n";
        $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        $xml .= implode("\n", $urls);
        $xml .= "\n</urlset>";

        return $xml;
    }

    private function makeUrl(string $loc, string $priority, string $changefreq, ?string $lastmod = null, string $imageXml = ''): string
    {
        $lastmodXml = $lastmod ? "\n        <lastmod>{$lastmod}</lastmod>" : '';
        return "    <url>
        <loc>" . htmlspecialchars($loc) . "</loc>{$lastmodXml}
        <changefreq>{$changefreq}</changefreq>
        <priority>{$priority}</priority>{$imageXml}
    </url>";
    }
}