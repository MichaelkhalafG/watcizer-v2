<?php

// i18n-exempt-file: matching data, never rendered - the Arabic-Indic digits read as Western ones

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * Word counting and comparison for the quality gate (2026-11-14), the same for English and Arabic: a word is a run of
 * letters or digits; Arabic diacritics and tatweel are ignored; Arabic-Indic digits read as Western ones.
 */
final class Words
{
    public static function count(string $text): int
    {
        return count(self::list($text));
    }

    /** @return list<string> the words, lower-cased, diacritics and Markdown marks dropped */
    public static function list(string $text): array
    {
        $t = self::westernDigits($text);
        $t = (string) preg_replace('/\{\{[^}]*\}\}|\{facts\}/u', ' ', $t);           // data blocks and tokens are not prose
        $t = (string) preg_replace('/\]\([^)]*\)/u', ']', $t);                         // a link's address is not prose
        $t = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);  // diacritics, tatweel
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', mb_strtolower($t), $m);

        return $m[0];
    }

    /** Arabic-Indic digits and separators as Western ones: ٤٬٣٥٠ → 4,350, ١٫٥ → 1.5. */
    public static function westernDigits(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7',
            '٨' => '8', '٩' => '9', '٬' => ',', '٫' => '.', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5',
            '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /**
     * The text's 5-word shingles, as a set (keys).
     *
     * @return array<string, true>
     */
    public static function shingles(string $text, int $size = 5): array
    {
        $w = self::list($text);
        $out = [];
        for ($i = 0, $n = count($w) - $size; $i <= $n; $i++) {
            $out[implode(' ', array_slice($w, $i, $size))] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));

        return $inter / (count($a) + count($b) - $inter);
    }
}
