<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Models\Storefront\StorefrontRedirect;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * THE OLD WORDPRESS SHOP'S IMAGE ADDRESSES (2026-11-24, Search Console's 404s on Brand Fashion).
 *
 * The legacy map (`bf:legacy-redirects`) holds one row per ORIGINAL image the import copied: `/wp-content/uploads/<file>`
 * → the master core made from it. WordPress also served, and Google still asks for, the COPIES it made of each original:
 * the sized ones (`photo-300x300.jpg`), the big-image copy (`photo-scaled.jpg`), the edited one (`photo-e1712345678.jpg`)
 * and a WebP twin beside it (`photo.jpg.webp`). None of them is in the map, so all of them answered 404.
 *
 * A copy belongs to exactly one original — the product is clear — so it is sent where its original goes. A path whose
 * original is not in the map (an image the import never copied: a banner, a logo, a product that is gone) stays a 404;
 * nothing is guessed. Read at lookup time, so there is no data step and nothing to undo.
 */
final class OldImages
{
    public const PREFIX = '/wp-content/uploads/';

    /**
     * The originals a WordPress image address may be a copy of, nearest first, never the address itself.
     *
     * @return list<string>
     */
    public static function originals(string $path): array
    {
        if (! str_starts_with($path, self::PREFIX)) {
            return [];
        }
        $out = [];
        $current = $path;
        // a WebP twin written beside the original: photo.jpg.webp → photo.jpg
        if (preg_match('/^(.+\.(?:jpe?g|png|gif))\.webp$/i', $current, $m) === 1) {
            $current = $m[1];
            $out[] = $current;
        }
        if (preg_match('/^(.+?)((?:-\d{1,5}x\d{1,5})|(?:-scaled)|(?:-e\d{9,13}))+(\.[a-z0-9]{2,5})$/i', $current, $m) === 1) {
            $bare = $m[1];
            $ext = $m[3];
            $out[] = $bare.$ext;
            // a sized copy of a WebP twin's original may also have been asked as .webp: the jpg/png original first
            foreach (['.jpg', '.jpeg', '.png', '.webp'] as $other) {
                if (strcasecmp($other, $ext) !== 0) {
                    $out[] = $bare.$other;
                }
            }
        }

        return array_values(array_unique(array_filter($out, fn (string $p): bool => $p !== $path)));
    }

    /**
     * The map's row for the original of this copy, or null.
     *
     * @return array{id: int, to: string, status: int, original: string}|null
     */
    public static function resolve(int $storefrontId, string $path): ?array
    {
        foreach (self::originals($path) as $original) {
            $raw = DB::table('storefront_redirects')
                ->where('storefront_id', $storefrontId)
                ->where('from_hash', StorefrontRedirect::hashPath($original))
                ->first(['id', 'to_path', 'status']);
            if ($raw !== null) {
                $row = Row::cast($raw);

                return ['id' => Row::int($row, 'id'), 'to' => Row::str($row, 'to_path'), 'status' => Row::int($row, 'status'), 'original' => $original];
            }
        }

        return null;
    }
}
