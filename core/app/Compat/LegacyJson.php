<?php

namespace App\Compat;

use Illuminate\Support\Carbon;

/**
 * Value formatting that reproduces what the legacy Eloquent models emit through
 * response()->json(): ISO-8601 UTC timestamps with microseconds, the idempotent image-URL
 * builder of ProductResource/ProductListResource, and atom timestamps for the sitemap.
 */
final class LegacyJson
{
    /** Eloquent `created_at`/`updated_at` serialisation: parsed in the app timezone, emitted as UTC "Z". */
    public static function ts(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }
        // Memoised (2026-09-28): `Carbon::parse` was most of the time a page of cards took — two
        // per product and two per gallery image — and the same stamps recur (imports share them).
        // Same input, same zone, same answer; the memo is dropped before it can grow large.
        $key = config()->string('app.timezone').'|'.$dbValue;
        if (! isset(self::$ts[$key])) {
            if (count(self::$ts) >= 50000) {
                self::$ts = [];
            }
            self::$ts[$key] = Carbon::parse($dbValue, config()->string('app.timezone'))->toJSON();
        }

        return self::$ts[$key];
    }

    /** @var array<string, string> zone|db value => `ts()` */
    private static array $ts = [];

    /** `Carbon::toIso8601String()` as used for rating rows: local offset form. */
    public static function iso8601(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }

        return Carbon::parse($dbValue, config()->string('app.timezone'))->toIso8601String();
    }

    /** `Carbon::toAtomString()` as used by the legacy sitemap `lastmod`. */
    public static function atom(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }

        return Carbon::parse($dbValue, config()->string('app.timezone'))->toAtomString();
    }

    /**
     * An image's stored path, in the form the legacy payload carries it.
     *
     * ── THE RULE: the stored path is the only truth about where an image lives ───────────────
     *
     * Legacy kept ONE folder per image type — every product cover in `Product/`, every gallery
     * image in `Product_image/`, every brand logo in `Brand/`, every sub-type image in `Sub_type/` —
     * so its payloads carried a bare filename and every reader (the storefront's `getImageUrl`,
     * `imageUrl()` below, the sitemap) put the type's folder back. Compat relied on that and used
     * `basename()`.
     *
     * The dashboard broke the assumption. A cover is now one of the gallery images, so a cover
     * uploaded after the cutover lives in `Product_image/`; category images are written to
     * `Category_type/`. Stripping the folder and letting a reader re-add the type's folder sent
     * every new cover to `Product/` — 404 on the listing, the product page and the image sitemap
     * (found 2026-09-26, the day after the team started uploading).
     *
     * So the folder is dropped ONLY when it is the one legacy used for this field — the only case
     * in which a reader re-adding it lands on the same file, and the case that keeps every
     * legacy-origin row byte-identical to what the legacy host emitted. Anything else keeps its
     * folder, and every reader already passes `folder/file` through unchanged. **Do not
     * reintroduce a bare `basename()` on an image path**: the day one more image type moves
     * folder, it silently breaks every image of that type.
     *
     * @param  string  $legacyFolder  the folder legacy used for THIS field, and the one its readers re-add
     */
    public static function legacyImage(?string $path, string $legacyFolder): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $path = ltrim($path, '/');
        $pos = strrpos($path, '/');
        if ($pos === false) {
            return $path;
        }

        return substr($path, 0, $pos) === $legacyFolder ? substr($path, $pos + 1) : $path;
    }

    /**
     * ProductResource::$imgUrl — a bare filename gets the folder, a value with a folder segment
     * is used under Uploads_Images as-is, an absolute URL passes through.
     */
    public static function imageUrl(?string $file, string $folder = 'Product'): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $file) === 1) {
            return $file;
        }
        $base = rtrim(config()->string('compat.asset_base'), '/');
        $file = ltrim($file, '/');
        if (str_contains($file, '/')) {
            return $base.'/Uploads_Images/'.$file;
        }

        return $base.'/Uploads_Images/'.$folder.'/'.$file;
    }

    /** decimal(8,2) formatting of a derived percentage, matching the legacy column's string form. */
    public static function decimal2(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
