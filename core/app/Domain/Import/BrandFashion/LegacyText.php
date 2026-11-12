<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

/**
 * Turns the old Brand Fashion WordPress text into ours (2026-11-11, the legacy catalogue import).
 *
 * Pure functions, no database: every rule here is something the import report counts, and a test
 * pins each one against the real shapes measured in the WordPress dump (`bf-legacy.sql`, 9 Nov):
 *
 *   • 34 titles carry HTML entities (`Dolce &amp; Gabbana`, `Men&#8217;s`) — decoded, twice when
 *     the source double-encoded (`&amp;#8217;`);
 *   • 9 rows are WordPress "duplicate product" copies (`… Copy`, slug `…-copy`) — the suffix is
 *     removed; whether the row is a real duplicate is decided by SKU/name later, not here;
 *   • descriptions are one short paragraph, 252 of them with HTML and 3 with shortcodes — stored
 *     as PLAIN TEXT with newlines between paragraphs, which is what every description in core
 *     already is (0 of 9,172 hold a tag) and what both storefronts render;
 *   • only 9 of 7,782 products have a short description at all, and core will not show a product
 *     without one — so the short text is DERIVED from the product's own long text (its first
 *     sentence), never written fresh. The import reports how many were derived.
 */
final class LegacyText
{
    /** Short descriptions are cut here, at a sentence end when there is one. */
    public const SHORT_MAX = 200;

    /**
     * First lines that are headings, not text — measured on the dump: "Highlights" 641, "Overview"
     * 303, and their Arabic forms (يسلط الضوء 325, ملخص 165, أبرز الميزات 63, نظرة عامة 20).
     */
    public const HEADINGS = ['highlights', 'overview', 'يسلط الضوء', 'ملخص', 'أبرز الميزات', 'نظرة عامة'];

    /** HTML entities, NBSPs and runs of whitespace → one clean line. */
    public static function line(?string $value): string
    {
        $text = self::decode($value ?? '');
        $text = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * A product title: one clean line, the WordPress copy marker removed, wrapping quotes removed
     * (`"GUESS Women's Leather Shoes"` is how one product was typed).
     */
    public static function title(?string $value): string
    {
        $title = self::line($value);
        $title = preg_replace('/[\s\-–(]*\bcopy\b\)?\s*$/iu', '', $title) ?? $title;
        $title = trim($title);
        if (preg_match('/^"(.+)"$/u', $title, $m) === 1) {
            $title = trim($m[1]);
        }

        return $title;
    }

    /** Was the title or slug a WordPress "duplicate product" copy? */
    public static function wasCopy(string $title, string $slug): bool
    {
        return preg_match('/\bcopy\b\)?\s*$/iu', self::line($title)) === 1
            || preg_match('/-copy(-\d+)?$/', $slug) === 1;
    }

    /** The slug without WordPress's `-copy` / `-copy-2` tail. */
    public static function slug(string $slug): string
    {
        $slug = strtolower(trim(rawurldecode($slug)));

        return preg_replace('/-copy(-\d+)?$/', '', $slug) ?? $slug;
    }

    /**
     * Descriptive HTML → plain text: shortcodes dropped, block ends and `<br>` become line breaks,
     * every tag stripped, entities decoded, blank lines collapsed to one.
     */
    public static function paragraphs(?string $html): string
    {
        $text = $html ?? '';
        if (trim($text) === '') {
            return '';
        }

        // [vc_row …], [caption]…[/caption] — page-builder shortcodes are not text.
        $text = preg_replace('/\[\/?[a-z_][^\]]*\]/i', '', $text) ?? $text;
        $text = preg_replace('/<\s*(script|style)\b.*?<\s*\/\s*\1\s*>/is', '', $text) ?? $text;
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\s*\/\s*(p|div|li|h[1-6]|tr|ul|ol|table|blockquote)\s*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\s*li\b[^>]*>/i', '• ', $text) ?? $text;
        $text = strip_tags($text);
        $text = self::decode($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $clean = trim(preg_replace('/[ \t\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $line) ?? $line);
            $lines[] = $clean;
        }
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Did the source hold markup we had to strip (reported, so the count is visible)? */
    public static function hadMarkup(?string $html): bool
    {
        return $html !== null && preg_match('/<[a-z\/!]|\[[a-z_\/][^\]]*\]/i', $html) === 1;
    }

    /**
     * The short description, derived from the long one: its first sentence if that fits, else the
     * text cut at the last word boundary before {@see SHORT_MAX} with an ellipsis. Arabic and Latin
     * sentence ends both count. Empty in, empty out.
     */
    public static function shortFrom(string $long): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $long)), fn (string $l): bool => $l !== ''));
        // A lone heading line says nothing on a card: "Highlights" opened 1,078 short texts.
        if (count($lines) > 1 && in_array(mb_strtolower($lines[0]), self::HEADINGS, true)) {
            array_shift($lines);
        }
        // Lines are joined as sentences: the old descriptions are bullet lines with no full stops.
        $lines = array_map(fn (string $l): string => preg_match('/[.!?؟۔:،,;…]$/u', $l) === 1 ? $l : $l.'.', $lines);
        $first = implode(' ', $lines);
        if ($first === '') {
            return '';
        }

        if (preg_match('/^(.{20,'.self::SHORT_MAX.'}?[.!?؟۔])(\s|$)/u', $first, $m) === 1) {
            return trim($m[1]);
        }
        if (mb_strlen($first) <= self::SHORT_MAX) {
            return $first;
        }

        $cut = mb_substr($first, 0, self::SHORT_MAX);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 40) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, ' ,،;:-–').'…';
    }

    /** SKU identity: trimmed, inner whitespace collapsed, upper-case. Null when there is none. */
    public static function skuKey(?string $sku): ?string
    {
        $key = strtoupper(self::line($sku));

        return $key === '' ? null : $key;
    }

    /**
     * Name identity, for products with no SKU: decoded, lower-case, the copy marker gone, every
     * run of non-letters/digits one space. "Men’s" and "Men's" are the same name.
     */
    public static function nameKey(?string $name): string
    {
        $text = mb_strtolower(self::title($name));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Decode entities, twice when the source encoded them twice (`&amp;#8217;`). */
    private static function decode(string $value): string
    {
        $once = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $once) === 1) {
            $once = html_entity_decode($once, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $once;
    }
}
