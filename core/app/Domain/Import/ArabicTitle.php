<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * An Arabic title built from an English one — the import's MACHINE translation (wave 4D).
 *
 * ── What this is, said plainly, because the badge depends on it being honest ─────────────────
 *
 * This is **not** a neural translator and does not pretend to be one. It is a dictionary and a
 * sentence pattern, and it works only because the source titles are formulaic:
 *
 *     "Tommy Hilfiger Watch For Men 1791594"   → "ساعة Tommy Hilfiger رجالي 1791594"
 *     "GUESS Women Cross Bag GUESS BAG0027"    → "حقيبة GUESS نسائي BAG0027"
 *     "FOSSIL FS4991 Men's Water Resistant Chronograph Watch"
 *                                              → "ساعة FOSSIL رجالي كرونوغراف مقاومة للماء FS4991"
 *
 * Every title it produces is marked `is_machine = 1` on the translation row, shows a badge in the
 * dashboard, and is filterable so the team can work through the backlog. The developer's
 * requirement is the point: *"do not let a machine-translated row look identical to a
 * human-written one."*
 *
 * ── Three deliberate refusals to be clever ───────────────────────────────────────────────────
 *
 *  1. **The brand stays in Latin script.** Egyptian retail writes "ساعة Tommy Hilfiger", not a
 *     transliteration, and a transliteration nobody searches for is worse than the original.
 *  2. **The model code is copied verbatim.** `AR1968`, `MK3438`, `NF9155A` are how a customer
 *     finds the product; translating or reordering them would break search.
 *  3. **Words it does not know are DROPPED, never transliterated letter by letter.** A title with
 *     an unknown adjective becomes a shorter correct Arabic title rather than a longer wrong one.
 *     What is lost is visible to the reviewer, which is what the badge is for.
 */
final class ArabicTitle
{
    /**
     * Product nouns — the head of the Arabic phrase. Order matters: the FIRST match wins, so the
     * more specific term has to come before the more general one ("sun glasses" before "glasses",
     * "power bank" before "bank" would be a bug waiting to happen).
     *
     * @var array<string, string>
     */
    private const NOUNS = [
        'sunglass' => 'نظارة شمسية',
        'sun glass' => 'نظارة شمسية',
        'eyewear' => 'نظارة',
        'smartwatch' => 'ساعة ذكية',
        'smart watch' => 'ساعة ذكية',
        'wristwatch' => 'ساعة يد',
        'wrist watch' => 'ساعة يد',
        'watch' => 'ساعة',
        'crossbody' => 'حقيبة كروس',
        'cross bag' => 'حقيبة كروس',
        'backpack' => 'حقيبة ظهر',
        'handbag' => 'حقيبة يد',
        'tote' => 'حقيبة توت',
        'satchel' => 'حقيبة ساتشيل',
        'luggage' => 'حقيبة سفر',
        'bag' => 'حقيبة',
        'wallet' => 'محفظة',
        'belt' => 'حزام',
        'cap' => 'كاب',
        'sneaker' => 'حذاء رياضي',
        'shoe' => 'حذاء',
        'slipper' => 'شبشب',
        'sock' => 'شراب',
        'swimsuit' => 'مايوه',
        'perfume' => 'عطر',
        'fragrance' => 'عطر',
        'bracelet' => 'إسورة',
        'necklace' => 'سلسلة',
        'keychain' => 'ميدالية مفاتيح',
        'cufflink' => 'أزرار أكمام',
        'scarf' => 'وشاح',
        'tie' => 'كرافتة',
        'pen' => 'قلم',
        // electronics — the Joyroom half of the import speaks this half of the dictionary
        'power bank' => 'باور بانك',
        'powerbank' => 'باور بانك',
        'screen protector' => 'واقي شاشة',
        'tempered glass' => 'واقي شاشة زجاجي',
        'charger' => 'شاحن',
        'charging' => 'شاحن',
        'cable' => 'كابل',
        'earphone' => 'سماعة',
        'earbud' => 'سماعة',
        'headphone' => 'سماعة رأس',
        'holder' => 'حامل',
        'stand' => 'حامل',
        'adapter' => 'محول',
        'speaker' => 'سماعة بلوتوث',
    ];

    /**
     * Gender words, WOMEN FIRST and matched as whole words.
     *
     * Both halves of that sentence are a bug this file already had: `str_contains('women', 'men')`
     * is TRUE, so a substring match with men first titled every women's product رجالي — caught by
     * the test that reads a real title from their file ("GUESS Women Cross Bag"). Order alone is
     * not enough either, because "women" would then match inside a model code; the match is
     * anchored to word boundaries.
     *
     * @var array<string, string>
     */
    private const GENDERS = [
        'unisex' => 'للجنسين',
        "women's" => 'نسائي',
        'womens' => 'نسائي',
        'woman' => 'نسائي',
        'women' => 'نسائي',
        "men's" => 'رجالي',
        'mens' => 'رجالي',
        'man' => 'رجالي',
        'men' => 'رجالي',
        'kids' => 'أطفال',
        'boys' => 'أولادي',
        'girls' => 'بناتي',
    ];

    /**
     * Descriptors worth keeping, in the order they should appear in Arabic.
     *
     * @var array<string, string>
     */
    private const DESCRIPTORS = [
        'chronograph' => 'كرونوغراف',
        'automatic' => 'أوتوماتيك',
        'quartz' => 'كوارتز',
        'analog' => 'أنالوج',
        'analogue' => 'أنالوج',
        'digital' => 'ديجيتال',
        'multifunction' => 'متعدد الوظائف',
        'stainless steel' => 'ستانلس ستيل',
        'leather' => 'جلد',
        'silicone' => 'سيليكون',
        'rubber' => 'مطاط',
        'fabric' => 'قماش',
        'satin' => 'ساتان',
        'mesh' => 'مش',
        'water resistant' => 'مقاوم للماء',
        'waterproof' => 'مقاوم للماء',
        'wireless' => 'لاسلكي',
        'magnetic' => 'مغناطيسي',
        'fast charging' => 'شحن سريع',
        'privacy' => 'حماية الخصوصية',
        'gold' => 'ذهبي',
        'rose gold' => 'روز جولد',
        'silver' => 'فضي',
        'black' => 'أسود',
        'white' => 'أبيض',
        'blue' => 'أزرق',
        'brown' => 'بني',
        'green' => 'أخضر',
        'red' => 'أحمر',
    ];

    /**
     * Build the Arabic title. Returns null when there is nothing to build from — the caller then
     * marks the product as missing its Arabic rather than storing an empty string.
     *
     * @param  string|null  $brand  the brand's English name, kept in Latin script
     */
    public static function from(string $english, ?string $brand = null): ?string
    {
        $source = trim(preg_replace('/\s+/u', ' ', $english) ?? '');
        if ($source === '') {
            return null;
        }

        $haystack = mb_strtolower($source);

        // The brand is removed from the haystack BEFORE anything is matched, or a brand containing
        // a dictionary word ("Police", "Coach", "Guess") would be read as a descriptor.
        if ($brand !== null && $brand !== '') {
            $haystack = str_replace(mb_strtolower($brand), ' ', $haystack);
        }

        $noun = self::firstMatch($haystack, self::NOUNS);
        // Whole words only — see the note on GENDERS.
        $gender = self::firstWord($haystack, self::GENDERS);

        $descriptors = [];
        foreach (self::DESCRIPTORS as $needle => $arabic) {
            if (str_contains($haystack, $needle) && ! in_array($arabic, $descriptors, true)) {
                $descriptors[] = $arabic;
            }
            if (count($descriptors) === 3) {
                break;              // three is a title; six is an inventory sheet
            }
        }

        $code = self::modelCode($source);

        /*
         * A title with NO noun is one the dictionary did not recognise at all — a perfume named
         * after a person, say. Rather than emit "Baxart نسائي", which reads as a fragment, the
         * English is kept so the row is still findable and the reviewer can see what it was. This
         * is checked FIRST: those titles often have no code either, and an empty parts list used to
         * return null here — which meant the product arrived with no Arabic at all.
         */
        if ($noun === null) {
            return trim(($brand ?? '').' '.$source);
        }

        $parts = [];
        foreach ([$noun, $brand, $gender, $descriptors === [] ? null : implode(' ', $descriptors), $code] as $part) {
            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * The model code: the longest token that mixes letters and digits, or a long bare number.
     *
     * `AR1968`, `MK3438`, `NF9155A`, `1791594`, `GW0262G3` — how a customer searches. A token that
     * is only letters is a word, and a short number is a size or a millimetre figure.
     *
     * @param  string  $source  the ORIGINAL title, because case matters in a code
     */
    private static function modelCode(string $source): ?string
    {
        $best = null;
        foreach (preg_split('/[\s,|\/]+/u', $source) ?: [] as $token) {
            $token = trim($token, " \t\n\r\0\x0B-–—()[]");
            if ($token === '' || mb_strlen($token) < 4 || mb_strlen($token) > 24) {
                continue;
            }
            $hasDigit = preg_match('/\d/', $token) === 1;
            $hasAlpha = preg_match('/[A-Za-z]/', $token) === 1;

            if (! $hasDigit) {
                continue;
            }
            // "30" or "44" alone is a size; "1791594" is a code.
            if (! $hasAlpha && mb_strlen($token) < 5) {
                continue;
            }
            // "44mm" is a measurement, not a code.
            if (preg_match('/^\d+\s*(mm|ml|cm|g)$/i', $token) === 1) {
                continue;
            }
            if ($best === null || mb_strlen($token) > mb_strlen($best)) {
                $best = $token;
            }
        }

        return $best;
    }

    /**
     * Like {@see self::firstMatch()}, but the needle must be a WHOLE WORD.
     *
     * @param  array<string, string>  $dictionary
     */
    private static function firstWord(string $haystack, array $dictionary): ?string
    {
        foreach ($dictionary as $needle => $arabic) {
            $pattern = '/(?<![a-z])'.preg_quote($needle, '/').'(?![a-z])/iu';
            if (preg_match($pattern, $haystack) === 1) {
                return $arabic;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $dictionary
     */
    private static function firstMatch(string $haystack, array $dictionary): ?string
    {
        foreach ($dictionary as $needle => $arabic) {
            if (str_contains($haystack, $needle)) {
                return $arabic;
            }
        }

        return null;
    }
}
