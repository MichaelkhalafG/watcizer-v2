<?php

namespace App\Storefront;

use App\Domain\Media\CutoutMask;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The background masks of the cover photos of some products, for a slider whose photos blend into its colour
 * (the developer's review, 2026-11-05, item 32 — see {@see CutoutMask} for what a mask is and why).
 *
 * A mask is made ONCE per photo and kept on the photo's row (`catalog_product_images.cut_mask`). A slider's
 * products change by themselves (the newest, the best value), so nobody can make the masks ahead of time for
 * "the products on the slider": the first payload that needs a mask that is not there MAKES it — at most
 * {@see self::LAZY_MAX} photos or {@see self::LAZY_SECONDS} seconds in one request, the rest on the next —
 * and `media:cut-masks` can make them ahead for a whole catalogue. A photo without a mask (none yet, no
 * studio background, its file not on this disk) is simply drawn as it is: the storefront never hides one.
 *
 * It can never fail a page: before the migration has run the columns are not there, and any other fault while
 * making a mask is reported and that photo goes without.
 */
final class CutMasks
{
    /** Masks made inside one storefront request, at most (about 0.12 s each, measured on real covers). */
    public const LAZY_MAX = 12;

    public const LAZY_SECONDS = 2.5;

    /**
     * A photo is decoded whole to be examined (measured: +12 MB at the peak for a 1200 px master). It is not examined
     * when the request has less than this left under PHP's memory limit — that photo waits for a lighter request.
     */
    private const MEMORY_ROOM = 36 * 1048576;

    /**
     * @param  list<int>  $productIds
     * @return array<int, string> product id => the mask of its COVER photo, as a `data:` URL
     */
    public static function forProducts(array $productIds, bool $make = true): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $rows = DB::table('catalog_product_images')
                ->select(['id', 'product_id', 'path', 'cut_mask', 'cut_mask_at'])
                ->whereIn('product_id', $productIds)
                // the cover, by the same rule the cards use (ProductCards::images)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get();
        } catch (QueryException) {
            return [];                                  // the columns are not there yet (code before its migration)
        }

        $out = [];
        $seen = [];
        $left = self::LAZY_MAX;
        $deadline = microtime(true) + self::LAZY_SECONDS;
        foreach ($rows as $row) {
            $productId = Row::int($row, 'product_id');
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;

            $mask = Row::nstr($row, 'cut_mask');
            if ($mask === null && Row::nstr($row, 'cut_mask_at') === null && $make && $left > 0 && microtime(true) < $deadline) {
                $left--;
                $mask = self::make(Row::int($row, 'id'), Row::str($row, 'path'));
            }
            if ($mask !== null && $mask !== '') {
                $out[$productId] = 'data:image/png;base64,'.$mask;
            }
        }

        return $out;
    }

    /**
     * Examine one photo and record the answer on its row. Returns the mask (base64 PNG), or null when the photo
     * has none — no studio background, or its file is not on this disk (that one is NOT recorded as examined:
     * the file may arrive).
     */
    public static function make(int $imageId, string $path): ?string
    {
        try {
            $file = self::file($path);
            if ($file === null || ! self::hasRoom()) {
                return null;
            }
            $made = CutoutMask::fromFile($file);
            $mask = $made === null ? null : base64_encode($made['png']);
            DB::table('catalog_product_images')->where('id', $imageId)->update(['cut_mask' => $mask, 'cut_mask_at' => now()]);

            return $mask;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Is there room under PHP's memory limit to decode one more photo? (No limit = yes.) */
    private static function hasRoom(): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };

        return $bytes - memory_get_usage(true) >= self::MEMORY_ROOM;
    }

    /** The photo's file on this disk, or null when it is not there (or the stored path is not a plain one). */
    public static function file(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        $root = config()->string('media.root');
        $root = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:/', $root) === 1 ? $root : base_path($root);
        $file = rtrim($root, '/\\').'/'.$path;

        return is_file($file) ? $file : null;
    }
}
