<?php

// i18n-exempt-file: matching data, never rendered - the quality gate's Arabic banned phrases and forbidden statements

/*
|--------------------------------------------------------------------------
| The automated quality gate (SEO Phases 2 and 4, 2026-11-14)
|--------------------------------------------------------------------------
|
| Nobody reviews copy before it is published (the brief's business fact 10): this gate does instead. A text that fails
| ANY check is "held" with its reasons on the dashboard and is never indexed (a landing page) or published (an
| article) until it passes. App\Domain\Content\QualityGate reads everything below; nothing here is a secret.
|
| Lengths are counted in words of PROSE: headings, tables, lists of facts, data blocks and FAQ questions do not count;
| FAQ answers count only toward the FAQ rule. Arabic packs more into each word: its minimums are 85 % of the English.
*/
return [
    'profiles' => [
        // a brand, category or landing page (the brief, Phase 2 item 2)
        'page' => [
            'intro_words' => [60, 90],
            'guide_words' => [800, 1200],
            'faq' => [4, 6],
            'faq_answer_words' => [40, 80],
            'min_sections' => 4,
        ],
        // an article (the brief, Phase 4 items 2 and 3)
        'article' => [
            // the minimum — Michael's OPTION B (2026-11-14): the competitor's average WITHOUT tables, + 10 %: English about
            // 2,400 words, Arabic about 1,800 (the first proposal, ×1.25 with tables — 2,688 / 2,285 — is withdrawn)
            'words' => ['en' => 2400, 'ar' => 1800],
            'max_words' => 4000,
            'h2' => [5, 10],
            'section_words' => [100, 350],
            'faq' => [4, 6],
            'faq_answer_words' => [40, 80],
            'key_points' => [3, 5],
            'images' => 3,
            'links_per_100_words' => 1.0,
            'title_chars_en' => 60,
        ],
        // a product's long description (Michael, 2026-11-15: written from the record's facts, EN + AR, through the gate):
        // from the thin-description line (catalog.thin_description_chars) up; every fact it states is the record's own
        'product' => [
            'chars' => [300, 1600],
        ],
    ],

    // Arabic minimums are this share of the English ones (words)
    'arabic_share' => 0.85,

    // a text whose 5-word shingles overlap another page text or article above this share fails (Jaccard)
    'similarity_max' => 0.25,

    // filler the brief bans (checked case-blind, as whole phrases); "سواء كنت … أو …" only as an opener
    'banned' => [
        'en' => [
            "in today's fast-paced world", 'delve', 'elevate your style', 'look no further', "it's important to note",
            'it is important to note', 'in conclusion', 'unlock the secrets', 'game-changer', 'whether you are a', 'nestled',
            'a testament to', 'in the realm of', 'tapestry', 'embark on a journey', 'the perfect blend', 'second to none',
        ],
        'ar' => [
            'في عالمنا اليوم', 'لا شك أن', 'لا شكّ أن', 'في الختام', 'تجدر الإشارة إلى', 'وفي النهاية', 'غني عن القول',
            'مما لا شك فيه', 'في هذا المقال سوف',
        ],
        'ar_openers' => ['سواء كنت'],
    ],

    // statements the shops must never make (the business facts and Michael's rules)
    'forbidden' => [
        // never claim human review or editing
        '/\b(reviewed|edited|checked|verified) by (our|an?) (team|editor|expert|experts|specialist)/i',
        '/(راجع|راجعه|حرّره|حرره|دقّقه)\s+(فريق|محرر|خبير)/u',
        // never mention the free-shipping threshold
        '/free\s+(shipping|delivery)\s+(on|for|over|above)/i',
        '/(شحن|توصيل)\s+مجاني\s+(للطلبات|على الطلبات|فوق|عند)/u',
        '/(الطلبات|طلبات)\s+فوق\s+[\d٠-٩٬,.]+/u',
    ],

    // AUTHORIZED DEALER (Michael, 2026-11-14): allowed ONLY in a text about a brand the shop is an authorized dealer for
    // (App\Storefront\AuthorizedDealer — the storefront screen's switches); anywhere else it fails
    'dealer_claims' => [
        '/\b(authori[sz]ed|official)\s+(dealer|agent|distributor|retailer|reseller|stockist)\b/i',
        '/(وكيل|موزع|موزّع|بائع)\s+(معتمد|رسمي)/u',
    ],

    // the business facts a text may state, and nothing else (a different number fails)
    'facts' => [
        'return_days' => 14,
        'delivery_hours' => 24,
        'founded' => 2013,
        'warranty_years_max' => 3,
    ],

    /*
     * THE SOURCE LIST (the brief, Phase 4 item 4: "only from a source list you build and show me once"). An external
     * fact (a founding year, a movement, a material, a perfume note…) must be cited, in the text's Sources section, to a
     * page on one of these hosts. Official brand sites first, then reputable references. Michael sees this list once in
     * the Phase 4 report; adding a host is a one-line change here.
     */
    'sources' => [
        // official brand sites
        'tommy.com', 'global.tommy.com', 'michaelkors.com', 'hugoboss.com', 'naviforce.com', 'fossil.com', 'guess.com',
        'alba-watch.com', 'seikowatches.com', 'seiko.co.jp', 'emporioarmani.com', 'armani.com', 'tissotwatches.com',
        'diesel.com', 'maserati.com', 'lacoste.com', 'casio.com', 'gshock.com', 'invictawatch.com', 'justcavalli.com',
        'coach.com', 'longchamp.com', 'toryburch.com', 'calvinklein.com', 'calvinklein.us', 'armaniexchange.com',
        'pinko.com', 'karl.com', 'swatchgroup.com', 'skmei.com', 'minifocuswatch.com',
        // reputable references
        'britannica.com', 'wikipedia.org', 'hodinkee.com', 'fratellowatches.com', 'monochrome-watches.com',
        'watchtime.com', 'europastar.com', 'fhs.swiss', 'cosc.swiss', 'iso.org', 'fragrantica.com',
        'perfumesociety.org', 'leathercraftmasters.com', 'investopedia.com',
    ],
];
