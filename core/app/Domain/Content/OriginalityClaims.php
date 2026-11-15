<?php

namespace App\Domain\Content;

/**
 * THE ORIGINALITY CLAIMS IN A PAGE'S TEXT (Michael, 2026-11-14).
 *
 * A BRAND whose products on a shop are all kept from search engines, or all a matched Rolex (SearchExposure), may not
 * have a page that says its watches are original. The texts seeded on 2026-10-29
 * (`database/seeders/content/seo/brands.json`) say it three ways, and each goes:
 *
 *  1. the section "## Original, with its papers" / «## أصلية ومعها أوراقها», up to the next "## " heading;
 *  2. the question and answer that asks whether the watches are original ("### Are the Rolex watches at … original?" /
 *     «### هل ساعات Rolex في … أصلية؟») — any heading level — up to the next heading;
 *  3. the word "Original" / «أصلية» in the meta description ("Original Rolex watches in Egypt …").
 *
 * A GENERAL WATCH CATEGORY (Watches and its sub-categories) lists the matched products too, so its text says only what
 * is true of every watch on it: the section is renamed, its "every watch is original" sentence becomes
 * self::SENTENCE, the question goes (rule 2) and the meta description loses its "Original" (rule 3). The claim lives
 * on the Original watches page only, where it is true.
 *
 * Nothing else is touched: "in its original, unopened state" (the exchange condition) stays.
 */
final class OriginalityClaims
{
    /** The section's new heading on a general watch category, and the sentence it says instead (EN and AR). */
    public const HEADING = ['en' => 'Warranty and what comes with it', 'ar' => 'الضمان وما يأتي مع الساعة']; // i18n-exempt: storefront content - the heading written into a shop page text (OriginalityClaimsTest)

    public const SENTENCE = [
        'en' => 'Each watch’s warranty and what comes with it are listed on its product page.',
        'ar' => 'ضمان كل ساعة وما يأتي معها مذكوران في صفحتها.', // i18n-exempt: storefront content - the sentence written into a shop page text (OriginalityClaimsTest)
    ];

    public static function stripDescription(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = str_replace("\r\n", "\n", $text);
        // 1. the section, up to the next level-two heading
        $t = (string) preg_replace('/^##[ \t]+(Original, with its papers|أصلية ومعها أوراقها)[ \t]*\n.*?(?=^##[ \t]|\z)/msu', '', $t); // i18n-exempt: matching data, never rendered - the section heading removed from a shop page text

        return self::withoutTheQuestion($t);
    }

    public static function rewordWatchCategory(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = str_replace("\r\n", "\n", $text);
        $t = (string) preg_replace('/^##[ \t]+Original, with its papers[ \t]*$/mu', '## '.self::HEADING['en'], $t);
        $t = (string) preg_replace('/^##[ \t]+أصلية ومعها أوراقها[ \t]*$/mu', '## '.self::HEADING['ar'], $t); // i18n-exempt: matching data, never rendered - the heading renamed in a shop page text
        $t = (string) preg_replace('/Every watch is original and comes with its box, a warranty card and a sales invoice[^.\n]*\./u', self::SENTENCE['en'], $t);
        $t = (string) preg_replace('/كل ساعة أصلية[^.\n]*\./u', self::SENTENCE['ar'], $t); // i18n-exempt: matching data, never rendered - the sentence replaced in a shop page text

        return self::withoutTheQuestion($t);
    }

    public static function stripMeta(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        // "Original Rolex watches …" / "Original, with warranty; …"
        $t = (string) preg_replace('/^\s*Original,?\s+/u', '', $text);
        // «ساعات رولكس أصلية في مصر …» / «أصلية بضمان، …»: the word, once, wherever it opens a clause
        $t = (string) preg_replace('/(^|\s)أصلية،?\s+/u', '$1', $t, 1); // i18n-exempt: matching data, never rendered - the word removed from a meta description
        $t = trim($t);
        if ($t !== '' && $t !== $text) {
            $t = mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1);
        }

        return $t === '' ? null : $t;
    }

    /**
     * The free-shipping threshold, which no SEO copy may mention (Michael's standing rule; found 2026-11-14 in the
     * Watches and Fashion texts of both shops: «، والطلبات فوق ١٠٬٠٠٠ جنيه شحنها مجاني»). The clause goes, the sentence
     * keeps its delivery promise.
     */
    public static function withoutFreeShippingThreshold(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = (string) preg_replace('/،\s*والطلبات فوق [^.،\n]*شحنها مجاني/u', '', $text); // i18n-exempt: matching data, never rendered - the free-shipping clause removed from a shop page text
        $t = (string) preg_replace('/[,;]\s*(and\s+)?(free (delivery|shipping)|orders? over)[^.\n]*(free|EGP)[^.\n]*/iu', '', $t);

        return $t;
    }

    /** 2. a question asking whether they are original, with its answer, up to the next heading of any level */
    private static function withoutTheQuestion(string $t): ?string
    {
        $t = (string) preg_replace('/^#{1,6}[ \t]+[^\n]*?(\boriginal\?|أصلية؟)[ \t]*\n.*?(?=^#{1,6}[ \t]|\z)/msiu', '', $t); // i18n-exempt: matching data, never rendered - the originality question removed from a shop page text
        $t = trim((string) preg_replace("/\n{3,}/u", "\n\n", $t));

        return $t === '' ? null : $t;
    }
}
