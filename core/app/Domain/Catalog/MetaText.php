<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Product meta titles and descriptions, made fit for a search result (S-AR stage 2, 2026-10-01).
 *
 * About half the English `meta_title`s end in junk copied from a marketplace listing: " | Select…",
 * or a tail cut off mid-word — " | Se…", " | Ch...", " | ..." (measured 2026-10-01: 317 of 698
 * visible products end in "| Select…", ~60 more in a truncated tail). The rule: the LAST " | "
 * segment goes when it ends in an ellipsis ("…" or "..."), because a segment that stops in an
 * ellipsis is a cut-off, never words somebody chose. A tail that ends in a word (" | Diver") stays.
 *
 * `catalog:clean-meta-titles` applies this to the stored rows once; the storefront read applies it
 * again, so junk typed later still never reaches a search result.
 */
final class MetaText
{
    public static function title(?string $raw): ?string
    {
        $t = self::squash($raw);
        if ($t === null) {
            return null;
        }
        // `[^|]*` after the pipe means it is always the LAST pipe; the part before may be empty.
        while (preg_match('/^(.*?)\s*\|\s*[^|]*(?:…|\.\.\.)\s*$/u', $t, $m) === 1) {
            $t = rtrim($m[1], " \t—–-|");
        }

        return $t === '' ? null : $t;
    }

    /**
     * A title fit to SHOW: cleaned, and not cut off. 215 stored titles (2026-10-01) end mid-word in
     * "..", "..." or "…" with no pipe before it ("… Blue Dial Silver ...") — copied already truncated.
     * The data keeps them (the command only removes the pipe junk); the page falls back to the
     * product's own full title instead of showing a cut-off.
     */
    public static function usableTitle(?string $raw): ?string
    {
        $t = self::title($raw);

        return $t === null || preg_match('/(?:\.{2,}|…)$/u', $t) === 1 ? null : $t;
    }

    public static function description(?string $raw): ?string
    {
        return self::squash($raw);
    }

    /** One line, single spaces, no tags; null when nothing is left. */
    private static function squash(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw)));

        return $t === '' ? null : $t;
    }
}
