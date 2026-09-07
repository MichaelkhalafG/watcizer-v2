<?php

namespace App\Storefront;

/**
 * Media URL builder for the v2 shapes: `{src, srcset[], width, height, alt}` (§3.5.1, §5.4).
 * Renditions are emitted when the image row carries them; today the transform leaves them NULL.
 */
final class ImageUrl
{
    public static function src(string $path): string
    {
        $base = rtrim(config()->string('storefront.asset_base'), '/');

        return $base.'/Uploads_Images/'.ltrim($path, '/');
    }

    /**
     * @param  array<mixed>|null  $renditions
     * @return array{src: string, srcset: list<array{src: string, width: int, format: string}>, width: int|null, height: int|null, alt: string|null}
     */
    public static function object(string $path, ?int $width, ?int $height, ?string $alt, ?array $renditions): array
    {
        $srcset = [];
        if ($renditions !== null) {
            foreach ($renditions as $format => $sizes) {
                if (! is_array($sizes)) {
                    continue;
                }
                foreach ($sizes as $w => $file) {
                    if (is_string($file) && is_numeric($w)) {
                        $srcset[] = ['src' => self::src($file), 'width' => (int) $w, 'format' => (string) $format];
                    }
                }
            }
        }

        return ['src' => self::src($path), 'srcset' => $srcset, 'width' => $width, 'height' => $height, 'alt' => $alt];
    }
}
