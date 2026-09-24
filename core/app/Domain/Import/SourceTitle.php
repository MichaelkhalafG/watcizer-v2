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

    /** The whole normalisation, in the order the fixes have to happen. */
    public static function clean(string $raw): string
    {
        // BYTES FIRST. Everything below is `preg_*` with the `/u` flag, and those return NULL on a
        // string that is not valid UTF-8 — so before this existed, one bad byte silently disabled
        // every fix in this class through the `?? $text` fallbacks, and then killed the INSERT.
        $text = self::scrub($raw);

        // Entities first: a decoded `&amp;` can expose a glued word behind it, never the reverse.
        $text = self::decode($text);
        $text = self::unglue($text);

        // Collapse the runs of whitespace the source is also full of, so `Maserati  Watch` — two
        // spaces, in the real file — does not travel into the catalogue.
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    /**
     * Bytes MariaDB will accept, and nothing invisible.
     *
     * ── What this caught (2026-09-17) ───────────────────────────────────────────────────────
     *
     * One row of the real export refused to insert at all:
     *
     *     SQLSTATE[22007]: Incorrect string value: '\x8EK50K5…' for column `…`.`title`
     *
     * A lone `0x8E` — not valid UTF-8, almost certainly the tail of a left-to-right mark (`E2 80 8E`)
     * that lost its first two bytes somewhere upstream of us. MariaDB refused the whole statement, so
     * a complete, otherwise perfect Calvin Klein product did not arrive.
     *
     * Two passes, and they fix different things:
     *
     *   1. **Invalid sequences are dropped.** `iconv(…//IGNORE)` rather than `mb_scrub()` because the
     *      latter substitutes U+FFFD — a visible `�` in a customer-facing title is worse than the
     *      byte being gone.
     *   2. **Invisible formatting characters are removed.** Zero-width spaces and the bidi marks
     *      (U+200B–U+200F, U+2028/2029, U+FEFF) survive a UTF-8 check perfectly well and then cause
     *      exactly this class of bug for the next person: a title that looks identical to another one
     *      and is not, a search that cannot find a product, a slug with a character nobody can type.
     *      They carry no meaning in a product name.
     */
    public static function scrub(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            $text = is_string($converted) ? $converted : '';
        }

        $stripped = preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]/u', '', $text);

        return $stripped ?? $text;
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
