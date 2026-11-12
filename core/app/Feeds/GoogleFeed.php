<?php

declare(strict_types=1);

namespace App\Feeds;

use App\Storefront\ContentIds;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WRITES a storefront's Google Merchant Center (Shopping) feed to a file (2026-11-11, D-9).
 * `feeds:google` runs it hourly; `GoogleFeedController` only ever SERVES the file — the Meta
 * feed's design, per storefront, behind its own token (`config/feeds.php`, section `google`).
 *
 * ── One builder of product data ────────────────────────────────────────────────────────────
 *
 * Every value comes from {@see MetaFeed::lines()} — the same id, title, description, price,
 * sale price (`catalogPrice()`), availability (the shop's stock rule), link, images, brand and
 * category path the Meta catalogue carries. This class only RENAMES them to Google's columns and
 * adds the one thing Meta's line has no column for: the manufacturer part number (`mpn`, the
 * product's SKU), with `identifier_exists = no` when there is none — Google's rule for products
 * without a GTIN. A second implementation of the shop's pricing or stock rule is exactly what this
 * avoids.
 *
 * Format: tab-separated values with a header row (Google's "TSV" upload). Every value is already
 * one line (`MetaFeed::text()`); a tab inside one would break the row, so tabs become spaces.
 * Google's limits are CHECKED and reported, never applied — the Meta feed's rule.
 */
final class GoogleFeed
{
    public const COLUMNS = [
        'id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability',
        'price', 'sale_price', 'brand', 'mpn', 'identifier_exists', 'condition', 'product_type',
    ];

    /** Google's documented limits, in characters. */
    public const LIMITS = ['title' => 150, 'description' => 5000, 'product_type' => 750, 'mpn' => 70];

    /** Google takes at most 10 additional images. */
    public const MAX_ADDITIONAL_IMAGES = 10;

    public function __construct(private readonly MetaFeed $meta) {}

    /** @return array{code: string, storefront_id: int, currency: string, token: string, domain: string, image_host: string, locale: string, exclude: list<int>}|null */
    public static function config(string $code): ?array
    {
        return MetaFeed::configFrom('google', $code);
    }

    /** @return list<string> */
    public static function configured(): array
    {
        $codes = [];
        foreach (array_keys((array) config('feeds.google', [])) as $code) {
            if (is_string($code) && self::config($code) !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @return list<string> storefronts whose entry HAS a token that cannot be used */
    public static function unusable(): array
    {
        $codes = [];
        foreach ((array) config('feeds.google', []) as $code => $c) {
            $token = is_array($c) ? ($c['token'] ?? null) : null;
            if (is_string($code) && is_string($token) && $token !== '' && self::config($code) === null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function path(string $code): string
    {
        return rtrim(config()->string('feeds.path'), '/\\').DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.'google.tsv';
    }

    /**
     * Generate the storefront's file — replaced only when its content changed (stable ETag), via a
     * temporary file and a rename, so a reader never sees half a file.
     *
     * @return array{products: int, bytes: int, changed: bool, ms: float, no_mpn: int, over_limit: list<array{id: int, field: string, length: int, limit: int}>, path: string}
     */
    public function generate(string $code, int $chunk = MetaFeed::CHUNK): array
    {
        $cfg = self::config($code);
        if ($cfg === null) {
            throw new RuntimeException("No Google feed is configured for storefront [{$code}].");
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

        $count = 0;
        $noMpn = 0;
        $over = [];
        try {
            fwrite($h, implode("\t", self::COLUMNS)."\n");
            $buffer = [];
            foreach ($this->meta->lines($cfg, $chunk) as $id => $line) {
                $buffer[$id] = $line;
                if (count($buffer) >= $chunk) {
                    $this->flush($h, $buffer, $count, $noMpn, $over, $cfg['storefront_id']);
                    $buffer = [];
                }
            }
            $this->flush($h, $buffer, $count, $noMpn, $over, $cfg['storefront_id']);
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

        return ['products' => $count, 'bytes' => (int) filesize($path), 'changed' => $changed, 'ms' => (hrtime(true) - $t0) / 1e6, 'no_mpn' => $noMpn, 'over_limit' => $over, 'path' => $path];
    }

    /**
     * Write a chunk of Meta lines as Google rows, with their SKUs read in ONE query.
     *
     * @param  resource  $h
     * @param  array<int, array<string, string>>  $lines
     * @param  list<array{id: int, field: string, length: int, limit: int}>  $over
     */
    private function flush($h, array $lines, int &$count, int &$noMpn, array &$over, int $storefrontId): void
    {
        if ($lines === []) {
            return;
        }
        $skus = [];
        foreach (DB::table('catalog_products')->whereIn('id', array_keys($lines))->get(['id', 'sku']) as $p) {
            $skus[Coerce::int($p->id)] = trim(Coerce::str($p->sku));
        }
        // Google's item id: the old Merchant Center's `gla_<WooCommerce id>` for an old Brand Fashion product (ContentIds)
        $google = ContentIds::google($storefrontId, array_map('intval', array_keys($lines)));
        foreach ($lines as $id => $line) {
            $row = self::row(['id' => $google[$id] ?? ($line['id'] ?? '')] + $line, $skus[$id] ?? '');
            if ($row['mpn'] === '') {
                $noMpn++;
            }
            foreach (self::LIMITS as $field => $limit) {
                $length = mb_strlen($row[$field]);
                if ($length > $limit) {
                    $over[] = ['id' => $id, 'field' => $field, 'length' => $length, 'limit' => $limit];
                }
            }
            fwrite($h, implode("\t", array_map(fn (string $v): string => str_replace(["\t", "\r", "\n"], ' ', $v), array_values($row)))."\n");
            $count++;
        }
    }

    /**
     * One Meta line in Google's columns.
     *
     * @param  array<string, string>  $line
     * @return array<string, string>
     */
    public static function row(array $line, string $sku): array
    {
        $images = array_values(array_filter(explode(',', $line['additional_image_link'] ?? ''), fn (string $u): bool => $u !== ''));

        return [
            'id' => $line['id'] ?? '',
            'title' => $line['title'] ?? '',
            'description' => $line['description'] ?? '',
            'link' => $line['link'] ?? '',
            'image_link' => $line['image_link'] ?? '',
            'additional_image_link' => implode(',', array_slice($images, 0, self::MAX_ADDITIONAL_IMAGES)),
            // Google's values are in_stock / out_of_stock; Meta's line says "in stock" / "out of stock".
            'availability' => ($line['availability'] ?? '') === 'in stock' ? 'in_stock' : 'out_of_stock',
            'price' => $line['price'] ?? '',
            'sale_price' => $line['sale_price'] ?? '',
            'brand' => $line['brand'] ?? '',
            'mpn' => $sku,
            'identifier_exists' => $sku === '' ? 'no' : '',
            'condition' => $line['condition'] ?? 'new',
            'product_type' => $line['product_type'] ?? '',
        ];
    }
}
