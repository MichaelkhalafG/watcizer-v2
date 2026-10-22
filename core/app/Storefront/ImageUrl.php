<?php

namespace App\Storefront;

/**
 * Media URL builder for the v2 shapes: `{src, srcset[], width, height, alt}` (§3.5.1, §5.4).
 *
 * ── The srcset was empty on every row until 2026-10-22, for two reasons that hid each other ────
 *
 * `catalog_product_images.renditions` holds what `ImagePipeline::write()` recorded, keyed the way it
 * writes: `{"160": {"avif": "<file>-160.avif", "webp": "<file>-160.webp"}, "320": {…}}` — WIDTH first,
 * then format. This class read it the other way round (format first, the shape the migration's
 * comment sketched before the pipeline existed), so `is_numeric($w)` was asked of "avif" and every
 * rendition was dropped: 8,607 of 11,565 rows carried a full ladder and the API answered `srcset: []`
 * for all of them. Had the loop matched, the second fault would have shown: a recorded rendition is a
 * BARE filename (`basename()`), so the URL came out without its folder — `…/Uploads_Images/<file>`
 * instead of `…/Uploads_Images/Product/<file>` — a 404 per photo.
 *
 * Both shapes are read now (the one written, and the documented one in case a hand-made row ever
 * uses it), and a bare rendition name is placed beside its master. Nothing is ever DERIVED from the
 * naming convention: a width appears in the srcset only when the row records a file for it, and the
 * pipeline records a file only after checking it has bytes. The record is the truth; a guessed URL
 * that 404s is a broken photo on a live shop.
 */
final class ImageUrl
{
    /** The formats a rendition may be in, and the order a `<picture>` should offer them. */
    public const FORMATS = ['avif', 'webp'];

    /**
     * @param  string|null  $base  an EXPLICIT host to name, for a caller that knows which one it must use —
     *                             a compat payload names {@see StorefrontUrls::compatAssetBase()}, which is
     *                             not the v2 host (COMPAT-ASSET-HOST, 2026-10-14). Null keeps the rule below.
     */
    public static function src(string $path, ?string $base = null): string
    {
        if ($base !== null && $base !== '') {
            return rtrim($base, '/').'/Uploads_Images/'.ltrim($path, '/');
        }
        // A v2 request names ITS storefront's image host (ResolveStorefront sets this, and clears it
        // when the request ends); everything else — the dashboard, e-mails — keeps the .env value.
        $request = config('storefront.request_asset_base');
        $host = rtrim(is_string($request) && $request !== '' ? $request : config()->string('storefront.asset_base'), '/');

        return $host.'/Uploads_Images/'.ltrim($path, '/');
    }

    /**
     * The same URL, for a STOREFRONT named by id rather than by the request (2026-10-14): the
     * dashboard listing storefront 2's banners, a compat payload. The default storefront keeps
     * exactly {@see self::src()} — the request's host when one is set, else the .env value — so
     * nothing moves for the live shop; any other storefront gets its own
     * {@see StorefrontUrls::assetBase()}. Before this, every such URL named Watchizer's host.
     */
    public static function srcFor(int $storefrontId, string $path): string
    {
        if ($storefrontId === config()->integer('compat.storefront_id')) {
            return self::src($path);
        }

        return StorefrontUrls::assetBase($storefrontId).'/Uploads_Images/'.ltrim($path, '/');
    }

    /**
     * @param  array<mixed>|null  $renditions
     * @param  string|null  $base  see {@see self::src()} — an explicit image host for a caller that needs one
     * @return array{src: string, srcset: list<array{src: string, width: int, format: string}>, width: int|null, height: int|null, alt: string|null}
     */
    public static function object(string $path, ?int $width, ?int $height, ?string $alt, ?array $renditions, ?string $base = null): array
    {
        return ['src' => self::src($path, $base), 'srcset' => self::srcset($path, $renditions, $base), 'width' => $width, 'height' => $height, 'alt' => $alt];
    }

    /**
     * The recorded renditions of a master, as `{src, width, format}` entries, smallest first.
     *
     * Reads the pipeline's shape (`width => {format => file}`) and the documented one
     * (`format => {width => file}`); an entry is kept only when its width is a positive number, its
     * format is one of {@see self::FORMATS} and its file is a non-empty string. A bare filename is placed
     * in the MASTER's folder — the pipeline writes a rendition beside its master and records the name
     * only — while a value that already carries a folder is used as recorded.
     *
     * @param  array<mixed>|null  $renditions  the decoded `catalog_product_images.renditions`
     * @return list<array{src: string, width: int, format: string}>
     */
    public static function srcset(string $masterPath, ?array $renditions, ?string $base = null): array
    {
        if ($renditions === null || $renditions === []) {
            return [];
        }
        $folder = str_contains($masterPath, '/') ? substr($masterPath, 0, (int) strrpos($masterPath, '/')) : '';

        $out = [];
        foreach ($renditions as $outer => $inner) {
            if (! is_array($inner)) {
                continue;
            }
            foreach ($inner as $innerKey => $file) {
                if (! is_string($file) || trim($file) === '') {
                    continue;
                }
                if (is_numeric($outer)) {
                    // the writer's shape: width => {format => file}
                    $width = (int) $outer;
                    $format = (string) $innerKey;
                } elseif (is_numeric($innerKey)) {
                    // the documented shape: format => {width => file}
                    $width = (int) $innerKey;
                    $format = (string) $outer;
                } else {
                    continue;
                }
                if ($width <= 0 || ! in_array($format, self::FORMATS, true)) {
                    continue;
                }
                $file = ltrim(trim($file), '/');
                $relative = str_contains($file, '/') || $folder === '' ? $file : $folder.'/'.$file;
                $out[] = ['src' => self::src($relative, $base), 'width' => $width, 'format' => $format];
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['width'], $a['format']] <=> [$b['width'], $b['format']]);

        return $out;
    }
}
