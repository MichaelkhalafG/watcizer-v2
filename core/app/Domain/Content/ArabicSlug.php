<?php

// i18n-exempt-file: matching data, never rendered - the Arabic letters and digits an article address is normalised with

declare(strict_types=1);

namespace App\Domain\Content;

use Illuminate\Support\Facades\DB;

/**
 * An Arabic article's address (SEO Phase 3, 2026-11-14; the brief's BUSINESS FACTS 9): /ar/blog/ساعات-تومي-هيلفيغر-رجالي.
 * Arabic letters and digits (Latin letters and digits kept as they are, lower-cased), no diacritics or tatweel, words
 * joined by hyphens, at most MAX characters cut at a word. Stored as the letters; canonical, hreflang, the sitemaps and
 * IndexNow percent-encode it (rawurlencode). STABLE ONCE PUBLISHED: BlogWriter only fills it while an article has never
 * been published, or when the team types one.
 */
final class ArabicSlug
{
    public const MAX = 80;

    public static function make(string $text): string
    {
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);           // diacritics, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا']);                          // one alef
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $s = mb_strtolower($s);
        // letters only — Arabic punctuation (، ؟ ؛) is in the Arabic block too, and is a separator here
        $s = (string) preg_replace('/[^\x{0621}-\x{063A}\x{0641}-\x{064A}\x{0671}-\x{06D3}a-z0-9]+/u', '-', $s);
        $s = trim((string) preg_replace('/-+/', '-', $s), '-');
        if (mb_strlen($s) > self::MAX) {
            $cut = mb_substr($s, 0, self::MAX);
            $dash = mb_strrpos($cut, '-');
            $s = rtrim($dash !== false && $dash > 30 ? mb_substr($cut, 0, $dash) : $cut, '-');
        }

        return $s;
    }

    /** `make()`, made unique within the storefront (-2, -3… on a clash with another article). */
    public static function unique(int $storefrontId, string $text, ?int $ignoreId = null): string
    {
        $base = self::make($text);
        if ($base === '') {
            return '';
        }
        $slug = $base;
        for ($n = 2; DB::table('core_blogs')->where('storefront_id', $storefrontId)->where('slug_ar', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
