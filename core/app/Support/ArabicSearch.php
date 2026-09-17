<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One spelling of Arabic for the search index and for every query against it (A-UX-1, 2026-09-17).
 *
 * ── The defect ───────────────────────────────────────────────────────────────────────────────
 *
 * Neither the index nor the query normalised Arabic orthography, so an operator typing the ordinary
 * spelling of a word was told the product does not exist. Measured over the real 7,713-product
 * catalogue:
 *
 * | typed | found | actually in the catalogue |
 * |---|---|---|
 * | `ساعه` (ha for ta-marbuta) | 0 | 4 674 |
 * | `حقيبه` | 0 | 770 |
 * | `نظاره` | 0 | 1 239 |
 * | `اسود` (plain alef, as typed) | 1 | 506 |
 * | `الرجالى` (alef maqsura) | 0 | 13 |
 * | `سـاعة` (with tatweel) | 0 | 4 674 |
 *
 * None of these is a typo. They are how people type Arabic: the ta-marbuta/ha and alef-maqsura/ya
 * endings are interchangeable in ordinary writing, hamza is routinely dropped from alef, and tatweel
 * is decoration a keyboard inserts. A search that demands the catalogue's exact spelling demands
 * that the operator guess how a supplier's data-entry clerk typed it.
 *
 * ── Why one function, used on BOTH sides ────────────────────────────────────────────────────
 *
 * Normalising only the query would find nothing: the index still holds `ساعة` and the query now asks
 * for `ساعه`. Normalising only the index has the same problem mirrored. The two have to agree, so
 * there is one function and four callers — the two writers (`ProductIndexer`, the transform's
 * `Step21SearchIndex`) and the two readers (the dashboard list, and the STOREFRONT's own product
 * search). Miss one and the index and the query disagree, which is exactly today's bug with
 * different symptoms.
 *
 * That is also why `Step21SearchIndex` must call it: the transform rebuilds this table from legacy
 * names, and `ProductIndexer`'s docblock rests on a re-index producing the byte-identical row. A
 * normaliser in one and not the other would break that property and make every transform re-run
 * report changes.
 *
 * ── What it deliberately does NOT do ────────────────────────────────────────────────────────
 *
 * **It does not touch what anybody sees.** This is a search key, never a display value: the title on
 * the screen, in the CSV and in the storefront stays exactly as it was typed. `ة` is correct Arabic
 * and stays correct Arabic; only the shadow copy in `catalog_product_search.body` is folded.
 *
 * **It does not fold Arabic-Indic digits** (`٠-٩` → `0-9`). It is the same family of problem and
 * probably worth doing, but the review did not measure it and a model-number search is not something
 * to change on a guess. Raised, not done.
 *
 * **It leaves English alone.** Every rule below is confined to the Arabic block, so a Latin title
 * passes through byte-identical and the FULLTEXT behaviour of the 4,000 English products is
 * untouched by this change.
 *
 * i18n-exempt-file: the Arabic here is DATA, not language. `FOLD` is a table of Unicode letters
 * mapped to other Unicode letters, and the examples in this docblock are the measured search terms
 * that prove it. None of it is ever shown to a person — nothing in this file reaches a screen, a
 * refusal or a report — so there is nothing for the translation seam to carry, and wrapping a letter
 * in `ManageText::t()` would be asking for a translation of the letter `ة`. The positive test the
 * convention requires is `tests/Unit/ArabicSearchTest.php`, which pins every mapping by behaviour.
 */
final class ArabicSearch
{
    /**
     * Letters folded to one form, because Arabic writes them interchangeably in ordinary text.
     *
     * The hamza carriers (`أإآٱ`) fold to bare alef rather than the other way round: bare alef is
     * what an operator's keyboard produces without a deliberate extra keystroke, and it is the form
     * that appeared in the measurement above as "as typed".
     *
     * @var array<string, string>
     */
    private const FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي',
        'ؤ' => 'و',
        'ئ' => 'ي',
    ];

    /**
     * Marks that carry no word identity: the harakat (U+064B–U+065F), the superscript alef
     * (U+0670), and tatweel (U+0640), which is a stretching decoration rather than a letter.
     *
     * Diacritics are stripped even though MariaDB's collation already ignores them for `LIKE` —
     * FULLTEXT tokenises before the collation sees it, and the index must hold one spelling
     * whichever path reads it.
     */
    private const STRIP = '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u';

    /**
     * The searchable form of a string — for the index body and for the typed term alike.
     *
     * Order matters: strip the marks first, so a `ة` wearing a shadda is still recognised as a `ة`
     * by the fold that follows.
     */
    public static function normalise(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Nothing Arabic in it: leave the bytes exactly as they are rather than paying for two
        // passes over the 4,000 English titles on every index write and every keystroke.
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1) {
            return $text;
        }

        $stripped = preg_replace(self::STRIP, '', $text);
        $text = is_string($stripped) ? $stripped : $text;

        return strtr($text, self::FOLD);
    }
}
