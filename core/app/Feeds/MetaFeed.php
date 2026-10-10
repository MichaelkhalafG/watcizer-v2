<?php

namespace App\Feeds;

use App\Compat\CompatCart;
use App\Compat\CompatCategories;
use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Compat\LegacyJson;
use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WRITES the storefront's Meta catalogue feed to a file (2026-10-10). `feeds:meta` runs it hourly;
 * `MetaFeedController` only ever SERVES the file. Nothing here runs on a shopper's or Meta's request.
 *
 * ── Never a whole-catalogue build ────────────────────────────────────────────────────────────────
 *
 * The ids, their order, the product-page slug and the category/brand ids come from the LISTING
 * INDEX — the lean, cached index the warm keeps (L8), so the link is exactly the URL the shop and the
 * sitemap use. The rest is read CHUNK by chunk with the builders the warm's card entries use
 * (`CompatCatalog::productRows` / `productImages`) and streamed to the file line by line; a chunk is
 * freed before the next. The output is in index order whatever the chunk size (a test holds it
 * byte-identical between chunk sizes).
 *
 * ── The data is the shop's own ───────────────────────────────────────────────────────────────────
 *
 *  - availability: express + market stock > 0 — the shop's rule (`CompatListing` inStock, the product
 *    page) — read live per product, never a blanket value;
 *  - price: the selling price; sale_price ONLY when `CompatCart::catalogPrice()` — the function
 *    checkout prices with — is below it;
 *  - images: absolute, on the storefront's image host, via `LegacyJson::imageUrl()`; the gallery in
 *    its own order, at most 20;
 *  - id: the product id — what the pixel sends as `content_ids` and CAPI's Purchase sends, so
 *    events keep matching catalogue items.
 *
 * Meta's field limits are CHECKED, never applied: a value over its limit is written whole and
 * reported (product, field, length, limit) so a person decides — nothing is cut silently.
 */
final class MetaFeed
{
    /** The CSV header, in this order. */
    public const COLUMNS = [
        'id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link',
        'image_link', 'additional_image_link', 'brand', 'product_type',
        'custom_label_0', 'custom_label_1', 'custom_label_2',
    ];

    /** Meta's documented limits, in characters. */
    public const LIMITS = [
        'title' => 200, 'description' => 9999, 'product_type' => 750,
        'custom_label_0' => 100, 'custom_label_1' => 100, 'custom_label_2' => 100,
    ];

    public const MAX_ADDITIONAL_IMAGES = 20;

    /** Products read per chunk — the warm's own size (`CompatListing::WARM_CHUNK`). */
    public const CHUNK = 500;

    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * The storefront's feed settings, or null when it has NO feed (no entry, an empty or malformed
     * token, no such active storefront). Null is final: there is no fallback to another storefront.
     *
     * @return array{code: string, storefront_id: int, currency: string, token: string, domain: string, image_host: string, locale: string, exclude: list<int>}|null
     */
    public static function config(string $code): ?array
    {
        $c = config("feeds.meta.{$code}");
        if (! is_array($c)) {
            return null;
        }
        $token = $c['token'] ?? null;
        $domain = $c['domain'] ?? null;
        $host = $c['image_host'] ?? null;
        $locale = $c['locale'] ?? null;
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1
            || ! is_string($domain) || $domain === '' || ! is_string($host) || $host === ''
            || ! is_string($locale) || $locale === '') {
            return null;
        }
        $row = DB::table('storefronts')->where('code', $code)->where('is_active', 1)->first(['id', 'currency']);
        if ($row === null || ! is_numeric($row->id)) {
            return null;
        }
        $exclude = [];
        foreach ((array) ($c['exclude'] ?? []) as $id) {
            if (is_numeric($id)) {
                $exclude[] = (int) $id;
            }
        }

        return [
            'code' => $code,
            'storefront_id' => (int) $row->id,
            'currency' => is_string($row->currency) && $row->currency !== '' ? $row->currency : 'EGP',
            'token' => $token,
            'domain' => rtrim($domain, '/'),
            'image_host' => rtrim($host, '/'),
            'locale' => $locale,
            'exclude' => $exclude,
        ];
    }

    /**
     * The storefront codes with a usable feed configuration.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $codes = [];
        foreach (array_keys((array) config('feeds.meta', [])) as $code) {
            if (is_string($code) && self::config($code) !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Storefronts whose entry HAS a token but cannot be used — a token of the wrong shape, no such
     * active storefront, a blank domain. Somebody meant to switch the feed on, so this is a fault to
     * report, unlike an entry with no token at all (the feed is simply off).
     *
     * @return list<string>
     */
    public static function unusable(): array
    {
        $codes = [];
        foreach ((array) config('feeds.meta', []) as $code => $c) {
            $token = is_array($c) ? ($c['token'] ?? null) : null;
            if (is_string($code) && is_string($token) && $token !== '' && self::config($code) === null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** Where the storefront's feed file lives. */
    public static function path(string $code): string
    {
        return rtrim(config()->string('feeds.path'), '/\\').DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.'meta.csv';
    }

    /**
     * Generate the storefront's feed. The file is replaced only when its content changed (so its
     * ETag stays stable and Meta skips an unchanged fetch), through a temporary file and a rename,
     * so a reader never sees half a file.
     *
     * @return array{products: int, bytes: int, changed: bool, ms: float, stock: array{express: int, market: int, out: int}, over_limit: list<array{id: int, field: string, length: int, limit: int}>, path: string}
     */
    public function generate(string $code, int $chunk = self::CHUNK): array
    {
        $cfg = self::config($code);
        if ($cfg === null) {
            throw new RuntimeException("No Meta feed is configured for storefront [{$code}].");
        }
        $t0 = hrtime(true);
        $path = self::path($code);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create '.dirname($path));
        }
        $tmp = $path.'.tmp-'.getmypid();
        $h = fopen($tmp, 'wb');
        if ($h === false) {
            throw new RuntimeException("Cannot write {$tmp}");
        }

        try {
            $result = $this->write($h, $cfg, max(1, $chunk));
        } finally {
            fclose($h);
        }

        $changed = ! is_file($path) || sha1_file($path) !== sha1_file($tmp);
        if ($changed) {
            if (! rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException("Cannot replace {$path}");
            }
        } else {
            unlink($tmp);
        }
        clearstatcache(true, $path);

        return $result + ['bytes' => (int) filesize($path), 'changed' => $changed, 'ms' => (hrtime(true) - $t0) / 1e6, 'path' => $path];
    }

    /**
     * @param  resource  $h
     * @param  array{code: string, storefront_id: int, currency: string, token: string, domain: string, image_host: string, locale: string, exclude: list<int>}  $cfg
     * @return array{products: int, stock: array{express: int, market: int, out: int}, over_limit: list<array{id: int, field: string, length: int, limit: int}>}
     */
    private function write($h, array $cfg, int $chunk): array
    {
        $svc = new CompatServices($this->cache, $this->inventory, CompatStorefront::pinned($cfg['storefront_id']));
        $locale = $cfg['locale'];

        // The index: ids in the shop's order, the product-page slug, the category and brand ids.
        $excluded = array_flip($cfg['exclude']);
        $index = [];
        foreach ($svc->listing->entries() as $e) {
            if (! isset($excluded[$e['id']])) {
                $index[$e['id']] = ['slug' => $e['slug'], 'brand' => $e['brands'], 'category' => $e['categories'], 'sub' => $e['subTypes']];
            }
        }

        $categoryNames = [];
        foreach ($svc->categories->categoryTypes() as $node) {
            $categoryNames[CompatCategories::legacyIdOf($node)] = $svc->categories->name($node, $locale);
        }
        $subNames = [];
        foreach ($svc->categories->subTypes() as $node) {
            $subNames[CompatCategories::legacyIdOf($node)] = $svc->categories->name($node, $locale);
        }

        fputcsv($h, self::COLUMNS, ',', '"', '');
        $count = 0;
        $express = 0;
        $market = 0;
        $out = 0;
        $over = [];
        foreach (array_chunk(array_keys($index), max(1, $chunk)) as $ids) {
            $rows = [];
            foreach ($svc->catalog->productRows($locale, $ids) as $row) {
                if (is_int($row['id'] ?? null)) {
                    $rows[$row['id']] = $row;
                }
            }
            // The gallery in its own order (sort, then id), as the product page shows it.
            $images = [];
            foreach ($svc->catalog->productImages($ids) as $img) {
                $pid = $img['product_id'] ?? null;
                $file = $img['image'] ?? null;
                if (is_int($pid) && is_string($file) && $file !== '') {
                    $images[] = [$pid, is_int($img['sort'] ?? null) ? $img['sort'] : 0, is_int($img['id'] ?? null) ? $img['id'] : 0, $file];
                }
            }
            usort($images, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $gallery = [];
            foreach ($images as [$pid, , , $file]) {
                $url = LegacyJson::imageUrl($file, 'Product_image', $cfg['image_host']);
                if ($url !== null && count($gallery[$pid] ?? []) < self::MAX_ADDITIONAL_IMAGES) {
                    $gallery[$pid][] = $url;
                }
            }

            foreach ($ids as $id) {                                    // index order, not query order
                $row = $rows[$id] ?? null;
                if ($row === null) {
                    continue;                                        // hidden since the index was built
                }
                $line = $this->line($id, $row, $index[$id], $gallery[$id] ?? [], $cfg, $categoryNames, $subNames, $svc);
                match ($line['custom_label_2']) {
                    'express' => $express++,
                    'market' => $market++,
                    default => $out++,
                };
                foreach (self::LIMITS as $field => $limit) {
                    $length = mb_strlen($line[$field]);
                    if ($length > $limit) {
                        $over[] = ['id' => $id, 'field' => $field, 'length' => $length, 'limit' => $limit];
                    }
                }
                fputcsv($h, array_values($line), ',', '"', '');
                $count++;
            }
            unset($rows, $gallery, $images);
        }

        return ['products' => $count, 'stock' => ['express' => $express, 'market' => $market, 'out' => $out], 'over_limit' => $over];
    }

    /**
     * One product's feed line, keyed by column.
     *
     * @param  array<array-key, mixed>  $row  a `productRows()` row
     * @param  array{slug: string, brand: ?int, category: ?int, sub: ?int}  $entry
     * @param  list<string>  $gallery
     * @param  array{code: string, storefront_id: int, currency: string, token: string, domain: string, image_host: string, locale: string, exclude: list<int>}  $cfg
     * @param  array<int, ?string>  $categoryNames
     * @param  array<int, ?string>  $subNames
     * @return array<string, string>
     */
    private function line(int $id, array $row, array $entry, array $gallery, array $cfg, array $categoryNames, array $subNames, CompatServices $svc): array
    {
        $title = self::text($row['product_title'] ?? null);
        $description = self::text($row['short_description'] ?? null);
        if ($description === '') {
            $description = self::text($row['long_description'] ?? null);
        }
        $selling = is_numeric($row['selling_price'] ?? null) ? (string) $row['selling_price'] : '0';
        $sale = is_numeric($row['sale_price_after_discount'] ?? null) ? (string) $row['sale_price_after_discount'] : null;
        $effective = CompatCart::catalogPrice($selling, $sale);
        $express = is_int($row['stock'] ?? null) ? $row['stock'] : 0;
        $market = is_int($row['market_stock'] ?? null) ? $row['market_stock'] : 0;
        $category = $entry['category'] === null ? null : ($categoryNames[$entry['category']] ?? null);
        $sub = $entry['sub'] === null ? null : ($subNames[$entry['sub']] ?? null);
        $brand = $entry['brand'] === null ? '' : (string) $svc->names->name('brands', $entry['brand'], $cfg['locale']);

        return [
            'id' => (string) $id,
            'title' => $title,
            'description' => $description !== '' ? $description : $title,
            'availability' => $express + $market > 0 ? 'in stock' : 'out of stock',
            'condition' => 'new',
            'price' => self::money((float) $selling, $cfg['currency']),
            'sale_price' => $effective < (float) $selling ? self::money($effective, $cfg['currency']) : '',
            'link' => $cfg['domain'].'/product/'.$entry['slug'],
            'image_link' => (string) LegacyJson::imageUrl(is_string($row['image'] ?? null) && $row['image'] !== '' ? $row['image'] : null, 'Product', $cfg['image_host']),
            'additional_image_link' => implode(',', $gallery),
            'brand' => $brand,
            // The shop's own category path; omitted, not a placeholder, when the product has none.
            'product_type' => implode(' > ', array_values(array_filter([$category, $sub], fn (?string $s): bool => $s !== null && $s !== ''))),
            'custom_label_0' => $brand,
            'custom_label_1' => (string) $category,
            // Which stock makes it buyable: express ships now; market only through the supplier.
            'custom_label_2' => $express > 0 ? 'express' : ($market > 0 ? 'market' : ''),
        ];
    }

    private static function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', '').' '.$currency;
    }

    /** Plain text on one line: tags stripped, entities decoded, whitespace collapsed. */
    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }
}
