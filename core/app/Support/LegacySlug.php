<?php

namespace App\Support;

/**
 * Exact mirror of the legacy slug function used for every public Watchizer URL:
 * Frontend-next `src/utils/slugs.js` toSlug() and the backend
 * `SitemapController::slugify()` (which documents itself as mirroring slugs.js).
 *
 * lowercase · strip ' ’ ` · strip & · keep [a-z0-9 whitespace -] · whitespace→- ·
 * collapse - · trim -. Anything else (Arabic, accents, punctuation) is dropped, so
 * an Arabic-only name yields '' — callers fall back to the numeric id exactly as
 * the legacy code does (`slugify($enTitle) ?: (string) $product->id`).
 *
 * Do NOT "improve" this (e.g. transliteration): the transform derives
 * storefront_product.slug and category slugs with it so no live URL changes
 * (study §0.2-9, §2.9.2 steps 15/18, audit A-14/A-17).
 */
final class LegacySlug
{
    public static function make(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace("/['’`]/u", '', $s);
        $s = str_replace('&', '', $s);
        $s = (string) preg_replace('/[^a-z0-9\s-]/', '', $s);
        $s = (string) preg_replace('/\s+/', '-', $s);
        $s = (string) preg_replace('/-+/', '-', $s);

        return trim($s, '-');
    }

    /** Slug or the id as string — the legacy fallback for names that slugify to ''. */
    public static function orId(string $name, int $id): string
    {
        $slug = self::make($name);

        return $slug !== '' ? $slug : (string) $id;
    }
}
