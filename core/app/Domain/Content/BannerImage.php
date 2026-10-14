<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * The folder a banner image lives in, put back where the stored value lacks it (2026-10-14).
 *
 * Every image column stores `<folder>/<file>` relative to `Uploads_Images/` — product images,
 * brand logos, category images, blog covers all do, because their forms write
 * `${stored.folder}/${stored.file}`. The banner form alone wrote `stored.file`, so
 * `storefront_banners.image_path` holds a bare filename and `ImageUrl::src()`, a plain
 * concatenation, emitted `…/Uploads_Images/<file>` — a 404 on both API hosts while the file sat in
 * `Uploads_Images/Banner_home/` (banner 1363, found on the dashboard list and the storefront).
 *
 * The form now writes the folder; this puts it back for the rows written before, and the writer
 * applies it too, so a row never carries a bare name again. A value that already has a folder is
 * kept as it is — the same rule `LegacyJson::legacyImage()` applies to the legacy columns.
 */
final class BannerImage
{
    /** `config/media.php` type `banner` — the legacy folder the storefronts' image hosts serve. */
    public const FOLDER = 'Banner_home';

    public static function path(string $stored): string
    {
        $path = ltrim(trim($stored), '/');
        if ($path === '' || str_contains($path, '/')) {
            return $path;
        }

        return self::FOLDER.'/'.$path;
    }
}
