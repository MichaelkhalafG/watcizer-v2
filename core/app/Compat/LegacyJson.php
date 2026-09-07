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

        return Carbon::parse($dbValue, config()->string('app.timezone'))->toJSON();
    }

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

    public static function basename(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
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
