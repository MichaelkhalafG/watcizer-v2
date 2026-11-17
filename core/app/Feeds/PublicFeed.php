<?php

declare(strict_types=1);

namespace App\Feeds;

use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Catalog\SearchExposure;
use App\Domain\Inventory\InventoryService;
use App\Storefront\ImageUrl;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE PUBLIC PRODUCT FEED (2026-11-17, the 2026-11-16 comparison's item 6). WRITES one file per storefront,
 * `{feeds.path}/{code}/products.json`; `feeds:public` runs it hourly, and `GET /api/v2/{code}/feed` only SERVES the file
 * (each shop serves it at /feeds/products.json, linked from its /llms.txt). Read-only to the world: no token, no price
 * nobody may see — only what the shop's own pages show.
 *
 * What an assistant can read from it, per product: its titles (English and Arabic), its brand, its family, its page in
 * both languages (EXACTLY the sitemap's addresses — CompatLocaleSitemap::productPaths), its price and the price a buyer
 * pays now (the sale price only when 0 < sale < price — checkout's rule), whether it is in stock, its photo, when it last
 * changed. Never a product kept from search engines, nor a restricted one (a matched Rolex): neither is in /llms.txt or
 * any feed. Written whole every run through a temporary file and a rename, so a reader never sees half a file.
 */
final class PublicFeed
{
    public const CHUNK = 500;

    public static function path(string $code): string
    {
        return rtrim(config()->string('feeds.path'), '/\\').DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.'products.json';
    }

    /**
     * @return array{products: int, bytes: int, path: string}
     */
    public function generate(string $code): array
    {
        $sf = Coerce::int(DB::table('storefronts')->where('code', $code)->where('is_active', 1)->value('id'));
        if ($sf === 0) {
            throw new RuntimeException("no active storefront {$code}");
        }
        $services = new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned($sf));
        $paths = $services->localeSitemap->productPaths();
        // never a restricted product (the sitemap keeps a matched Rolex; the feeds and llms.txt do not)
        $restricted = array_flip(array_map(fn (mixed $v): int => Coerce::int($v), DB::table('catalog_products as p')->whereNull('p.deleted_at')
            ->whereRaw(SearchExposure::effectiveSql('p').' = ?', [SearchExposure::RESTRICTED])->pluck('p.id')->all()));
        $ids = array_values(array_filter(array_keys($paths), fn (int $id): bool => ! isset($restricted[$id])));
        $site = rtrim(StorefrontUrls::frontend($sf), '/');
        $base = $sf === config()->integer('compat.storefront_id') ? null : StorefrontUrls::assetBase($sf);
        $brands = [];
        foreach (DB::table('catalog_brand_translations')->where('locale', 'en')->get(['brand_id', 'name']) as $b) {
            $brands[Row::int($b, 'brand_id')] = trim(Row::str($b, 'name'));
        }

        $items = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('catalog_products as p')->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $sf))
                ->whereIn('p.id', $chunk)->get(['p.id', 'p.family', 'p.brand_id', 'p.in_stock', 'p.updated_at', 'sp.effective_price', 'sp.effective_sale_price', 'sp.updated_at as sp_updated']);
            $titles = [];
            foreach (DB::table('catalog_product_translations')->whereIn('product_id', $chunk)->whereIn('locale', ['en', 'ar'])->get(['product_id', 'locale', 'title']) as $t) {
                $titles[Row::int($t, 'product_id')][Row::str($t, 'locale')] = trim(Row::nstr($t, 'title') ?? '');
            }
            $covers = [];
            foreach (DB::table('catalog_product_images')->whereIn('product_id', $chunk)->whereNull('variant_id')
                ->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->get(['product_id', 'path']) as $c) {
                $covers[Row::int($c, 'product_id')] ??= Row::str($c, 'path');
            }
            $byId = [];
            foreach ($rows as $r) {
                $byId[Row::int($r, 'id')] = $r;
            }
            foreach ($chunk as $id) {
                $r = $byId[$id] ?? null;
                if ($r === null) {
                    continue;
                }
                $price = Coerce::float(Row::str($r, 'effective_price'));
                $sale = Row::nfloat($r, 'effective_sale_price');
                $pays = $sale !== null && $sale > 0 && $sale < $price ? $sale : $price;
                $path = $paths[$id];
                $cover = $covers[$id] ?? null;
                $updated = max((string) Row::nstr($r, 'updated_at'), (string) Row::nstr($r, 'sp_updated'));
                $items[] = [
                    'id' => $id,
                    'title' => ['en' => $titles[$id]['en'] ?? '', 'ar' => $titles[$id]['ar'] ?? ''],
                    'brand' => $brands[Row::int($r, 'brand_id')] ?? null,
                    'family' => Row::nstr($r, 'family'),
                    'url' => ['en' => $site.$path, 'ar' => $site.'/ar'.$path],
                    'price' => round($price, 2),
                    'price_now' => round($pays, 2),
                    'currency' => 'EGP',
                    'in_stock' => Row::int($r, 'in_stock') === 1,
                    'image' => $cover === null ? null : ($base === null ? ImageUrl::src($cover) : ImageUrl::src($cover, $base)),
                    'updated_at' => $updated === '' ? null : substr($updated, 0, 10),
                ];
            }
        }

        $file = self::path($code);
        $doc = [
            'shop' => StorefrontUrls::nameOf($sf),
            'site' => $site,
            'about' => 'Every product this shop sells and shows to search engines: its name in English and Arabic, brand, page, price in EGP (price_now is what a buyer pays today), stock and photo. Updated every hour.',
            'generated_at' => now()->utc()->toIso8601String(),
            'count' => count($items),
            'products' => $items,
        ];
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
        $tmp = $file.'.tmp';
        file_put_contents($tmp, $json);
        rename($tmp, $file);

        return ['products' => count($items), 'bytes' => strlen($json), 'path' => $file];
    }
}
