<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The product title as the SOURCE wrote it, made usable — once, before anything reads it.
 *
 * ── Two defects in the export, both measured on the real file (2026-09-14) ──────────────────
 *
 * **1. Glued words.** 60 titles run an ALL-CAPS brand straight into the next word:
 * `EMPORIO ARMANIMen's Stainless Steel Analog Watch`, `ALBAWomen's Analog Watch`. That is not
 * cosmetic — `BrandResolver` matches brand names on whole words, and `ARMANIMen's` contains no
 * word `ARMANI`, so **55 of those 60 products imported with the brand `Generic`** while their real
 * brand sat in the title all along. Twenty-two were Emporio Armani watches.
 *
 * **2. Raw HTML entities.** 13 titles carry `&amp;` literally — `Cap inspired By DOLCE&amp;GABBANA`
 * — because the export encodes for HTML and we store text. Left alone it reaches the customer
 * exactly like that.
 *
 * ── Why this is a reader concern and not a writer one ───────────────────────────────────────
 *
 * The title is used three times: to find the brand, to make the Arabic translation, and to be
 * stored. Fixing it at any one of those leaves the other two reading the broken version — the
 * brand fix alone would still have shown `DOLCE&amp;GABBANA` on the shop, and decoding at write
 * time would still have matched the brand against a glued word. So it is normalised once, at the
 * point the row is read, and everything downstream sees the same corrected string.
 *
 * ── The un-gluing rule, and why it is narrow ────────────────────────────────────────────────
 *
 * A run of two or more capitals immediately followed by a capital-then-lowercase: `ARMANIMen` →
 * `ARMANI Men`. It is deliberately blind to what the words ARE, because a brand list would not
 * have helped — `USBCable` and `XLShirt` are the same defect and neither is a brand.
 *
 * What it must not do is break a word that is legitimately written that way. `IPhone` does not
 * match (one capital before the lowercase run), `MK4222` does not (no lowercase follows), and an
 * ordinary `Emporio Armani Men's` is already spaced and untouched. Asserted in
 * `tests/Feature/Import/SourceTitleTest.php`, including the leave-alone cases.
 */
final class SourceTitle
{
    /** `&amp;amp;` exists in the wild; decode until it stops changing, but never forever. */
    private const MAX_DECODE_PASSES = 3;

    /** The whole normalisation, in the order the two fixes have to happen. */
    public static function clean(string $raw): string
    {
        // Entities first: a decoded `&amp;` can expose a glued word behind it, never the reverse.
        $text = self::decode($raw);
        $text = self::unglue($text);

        // Collapse the runs of whitespace the source is also full of, so `Maserati  Watch` — two
        // spaces, in the real file — does not travel into the catalogue.
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    /** `DOLCE&amp;GABBANA` → `DOLCE&GABBANA`. */
    private static function decode(string $text): string
    {
        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }

        return $text;
    }

    /** `EMPORIO ARMANIMen's` → `EMPORIO ARMANI Men's`. */
    private static function unglue(string $text): string
    {
        $spaced = preg_replace('/([A-Z]{2,})([A-Z][a-z])/u', '$1 $2', $text);

        return $spaced ?? $text;
    }
}
