<?php

// i18n-exempt-file: matching data, never rendered - the Arabic patterns the quality gate checks a text against

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Catalog\SearchExposure;
use App\Domain\Seo\LandingPages;
use App\Storefront\AuthorizedDealer;
use App\Storefront\ProductCards;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * THE AUTOMATED QUALITY GATE (SEO Phases 2 and 4, 2026-11-14). Nobody reviews copy before it goes out — the brief's
 * business fact 10 — so this does, the same way for a brand/category/landing page text and for an article. A text that
 * fails any rule is HELD with its reasons and is never indexed or published until it passes. Rules and lists:
 * config/content_gate.php.
 *
 * What it checks, per language (and across the two languages of one page):
 *   1. lengths and structure (the profile: intro, guide, sections, FAQ count and answer lengths; an article's
 *      minimum words, H2 count, section lengths, key points, images, link density);
 *   2. the banned filler phrases and the forbidden statements (human review, dealer/agent claims, the free-shipping
 *      threshold);
 *   3. the business facts: a return window, a delivery time or a warranty length stated otherwise than the facts;
 *   4. the catalogue: every model number named is a LIVE product of the shop; every price named lies within the
 *      LIVE price range of the page's products;
 *   5. every internal link resolves to a page the shop has; external links only to the approved sources;
 *   6. an external fact (a year other than the shop's 2013) needs a Sources section citing an approved source;
 *   7. EN and AR name the same model numbers and prices;
 *   8. each section carries at least one concrete detail (a number, a model, a link, a brand);
 *   9. similarity: no text shares more than `similarity_max` of its 5-word shingles with another text of the same
 *      language, on either shop.
 */
final class QualityGate
{
    /**
     * Check both languages of one page text. Returns per language: passed, the reasons, and the counts measured.
     *
     * @param  array{intro: string|null, description: string|null, meta_description: string|null}|null  $en
     * @param  array{intro: string|null, description: string|null, meta_description: string|null}|null  $ar
     * @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope
     * @return array{en: array{passed: bool, reasons: list<string>, stats: array<string, int>}, ar: array{passed: bool, reasons: list<string>, stats: array<string, int>}}
     */
    public function checkPage(int $storefrontId, string $type, string $key, ?array $en, ?array $ar, array $scope): array
    {
        $profile = Coerce::arr(config('content_gate.profiles.page'));
        $out = [];
        $facts = [];
        foreach (['en' => $en, 'ar' => $ar] as $locale => $text) {
            $reasons = [];
            $stats = [];
            if ($text === null || trim((string) ($text['description'] ?? '')) === '') {
                $out[$locale] = ['passed' => false, 'reasons' => ['no text in this language'], 'stats' => []];

                continue;
            }
            $intro = (string) ($text['intro'] ?? '');
            $body = (string) $text['description'];
            $meta = (string) ($text['meta_description'] ?? '');
            $doc = MarkdownDoc::parse($body);

            $stats['intro_words'] = Words::count($intro);
            $stats['guide_words'] = $doc->proseWords(excludeFaq: true);
            $stats['sections'] = count($doc->sections);
            $stats['faq'] = count($doc->faq);
            $this->range($reasons, 'the intro', $stats['intro_words'], self::scaled($profile['intro_words'] ?? [60, 90], $locale));
            $this->range($reasons, 'the guide', $stats['guide_words'], self::scaled($profile['guide_words'] ?? [800, 1200], $locale));
            if ($stats['sections'] < Coerce::int($profile['min_sections'] ?? 4)) {
                $reasons[] = sprintf('%d sections (at least %d)', $stats['sections'], Coerce::int($profile['min_sections'] ?? 4));
            }
            $this->faqRules($reasons, $doc, $profile, $locale);
            if ($meta === '') {
                $reasons[] = 'no meta description';
            } elseif (mb_strlen($meta) > 155) {
                $reasons[] = sprintf('meta description %d characters (at most 155)', mb_strlen($meta));
            }

            $all = $intro."\n".$body."\n".$meta;
            $this->language($reasons, $all, $locale);
            $this->dealerClaims($reasons, $storefrontId, $all, $scope);
            $this->businessFacts($reasons, $all, $locale);
            // the generated facts section is the catalogue's own numbers: the catalogue and language checks read the rest
            $facts[$locale] = $this->catalogue($reasons, $storefrontId, $intro."\n".$doc->withoutFacts()."\n".$meta, $scope);
            $this->links($reasons, $storefrontId, $doc);
            $this->sources($reasons, $all, $doc);
            $this->concreteSections($reasons, $doc, self::ownNames($scope));
            $stats['similarity_pct'] = (int) round(100 * $this->similarity($reasons, $storefrontId, $type, $key, $locale, $intro."\n".$body));

            $out[$locale] = ['passed' => $reasons === [], 'reasons' => $reasons, 'stats' => $stats];
        }

        // the two languages name the same models and prices
        if (isset($facts['en'], $facts['ar'])) {
            foreach (['models' => 'model numbers', 'prices' => 'prices'] as $k => $label) {
                if ($facts['en'][$k] !== $facts['ar'][$k]) {
                    $msg = sprintf('English and Arabic name different %s (EN: %s; AR: %s)', $label,
                        implode(', ', $facts['en'][$k]) ?: 'none', implode(', ', $facts['ar'][$k]) ?: 'none');
                    foreach (['en', 'ar'] as $l) {
                        $out[$l]['reasons'][] = $msg;
                        $out[$l]['passed'] = false;
                    }
                }
            }
        }

        return ['en' => $out['en'], 'ar' => $out['ar']];
    }

    /**
     * The scope of a page text: what its products are, for the catalogue checks.
     *
     * @return array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}
     */
    public static function scopeOf(int $storefrontId, string $type, string $key): array
    {
        if ($type === 'brand') {
            return ['brand_id' => self::brandId($key)];
        }
        if ($type === 'category') {
            return ['category_path' => $key];
        }
        $page = app(LandingPages::class)->find($storefrontId, $key);
        if ($page === null) {
            $parts = explode('/', $key);

            return ['brand_id' => self::brandId($parts[0])];
        }
        $family = $page['family'] === null ? null : array_search($page['family'], LandingPages::FAMILIES, true);

        return ['brand_id' => $page['brand_id'], 'family' => is_string($family) ? $family : null, 'gender_id' => $page['gender_id']];
    }

    /**
     * Check both languages of one ARTICLE (SEO Phase 4's writing rules, the brief items 2–4): the shared checks of a page
     * text plus the article profile — the minimum length, 5–10 H2 sections of 100–350 words, a key-points box, 4–6
     * questions, three images with their alt text, a live data block, a link to the brand page, link density, the title
     * and meta lengths, and a Sources section.
     *
     * @param  array{title: string, meta_title: string, meta_description: string, body: string}|null  $en
     * @param  array{title: string, meta_title: string, meta_description: string, body: string}|null  $ar
     * @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope
     * @return array{en: array{passed: bool, reasons: list<string>, stats: array<string, int>}, ar: array{passed: bool, reasons: list<string>, stats: array<string, int>}}
     */
    public function checkArticle(int $storefrontId, string $slug, ?array $en, ?array $ar, array $scope): array
    {
        $profile = Coerce::arr(config('content_gate.profiles.article'));
        $minWords = Coerce::arr($profile['words'] ?? []);
        $out = [];
        $facts = [];
        foreach (['en' => $en, 'ar' => $ar] as $locale => $a) {
            $reasons = [];
            $stats = [];
            if ($a === null || trim($a['body']) === '') {
                $out[$locale] = ['passed' => false, 'reasons' => ['no text in this language'], 'stats' => []];

                continue;
            }
            $doc = MarkdownDoc::parse($a['body']);
            $stats['words'] = $doc->proseWords(excludeFaq: false);
            $min = Coerce::int($minWords[$locale] ?? 1500);
            $max = Coerce::int($profile['max_words'] ?? 4000);
            if ($stats['words'] < $min) {
                $reasons[] = sprintf('%d words of prose (at least %d) — held, never padded', $stats['words'], $min);
            } elseif ($stats['words'] > $max) {
                $reasons[] = sprintf('%d words of prose (at most %d for an article that is not a pillar guide)', $stats['words'], $max);
            }
            // the H2 sections that are the article's body (not the questions, the sources or the key points)
            $body = array_values(array_filter($doc->sections, fn (array $s): bool => ! $s['faq'] && ! $s['sources'] && ! self::isKeyPoints($s['heading'])));
            $stats['h2'] = count($body);
            $h2 = array_values(Coerce::arr($profile['h2'] ?? [5, 10]));
            if ($stats['h2'] < Coerce::int($h2[0] ?? 5) || $stats['h2'] > Coerce::int($h2[1] ?? 10)) {
                $reasons[] = sprintf('%d H2 sections (%d–%d)', $stats['h2'], Coerce::int($h2[0] ?? 5), Coerce::int($h2[1] ?? 10));
            }
            $sectionRange = self::scaled($profile['section_words'] ?? [100, 350], $locale);
            foreach ($body as $sec) {
                $w = MarkdownDoc::parse("## x\n".$sec['text'])->proseWords();
                if ($w < $sectionRange[0] || $w > $sectionRange[1]) {
                    $reasons[] = sprintf('the section "%s" is %d words (%d–%d)', mb_substr($sec['heading'], 0, 50), $w, $sectionRange[0], $sectionRange[1]);
                }
            }
            $keyPoints = array_values(array_filter($doc->sections, fn (array $s): bool => self::isKeyPoints($s['heading'])));
            $bullets = $keyPoints === [] ? 0 : preg_match_all('/^\s*[-*]\s+\S/mu', $keyPoints[0]['text']);
            $kp = array_values(Coerce::arr($profile['key_points'] ?? [3, 5]));
            if ($bullets < Coerce::int($kp[0] ?? 3) || $bullets > Coerce::int($kp[1] ?? 5)) {
                $reasons[] = sprintf('a key-points box of %d bullets (%d–%d)', $bullets, Coerce::int($kp[0] ?? 3), Coerce::int($kp[1] ?? 5));
            }
            $this->faqRules($reasons, $doc, $profile, $locale);
            preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)\)/u', $a['body'], $im, PREG_SET_ORDER);
            $stats['images'] = count($im);
            if ($stats['images'] < Coerce::int($profile['images'] ?? 3)) {
                $reasons[] = sprintf('%d images in the text (at least %d)', $stats['images'], Coerce::int($profile['images'] ?? 3));
            }
            foreach ($im as $m) {
                if (trim($m[1]) === '') {
                    $reasons[] = 'an image with no alt text: '.mb_substr($m[2], 0, 60);
                }
            }
            $stats['blocks'] = (int) preg_match_all('/\{\{\s*(products|count|price-range|new-arrivals|compare|in-stock-alternatives)\b/u', $a['body']);
            if ($stats['blocks'] < 1) {
                $reasons[] = 'no live data block ({{products …}}, {{count …}}, {{price-range …}} or {{new-arrivals …}})';
            }
            if (($scope['brand_id'] ?? null) !== null) {
                $brand = Coerce::str(DB::table('catalog_brands')->where('id', $scope['brand_id'])->value('slug'));
                if ($brand !== '' && ! array_filter($doc->links, fn (array $l): bool => (bool) preg_match('#^(/ar)?/brand/'.preg_quote($brand, '#').'(/|$)#', $l['href']))) {
                    $reasons[] = 'no link to the brand page (/brand/'.$brand.')';
                }
            }
            $perHundred = Coerce::float($profile['links_per_100_words'] ?? 1.0);
            $internal = count(array_filter($doc->links, fn (array $l): bool => str_starts_with($l['href'], '/')));
            if ($stats['words'] > 0 && $internal > ceil($stats['words'] / 100 * $perHundred)) {
                $reasons[] = sprintf('%d internal links for %d words (at most about 1 per 100 words)', $internal, $stats['words']);
            }
            $titleMax = Coerce::int($profile['title_chars_en'] ?? 60) + 10;
            if ($locale === 'en' && mb_strlen($a['title']) > $titleMax) {
                $reasons[] = sprintf('the title is %d characters (about %d at most)', mb_strlen($a['title']), $titleMax);
            }
            if ($a['meta_description'] === '' || mb_strlen($a['meta_description']) > 155) {
                $reasons[] = sprintf('the meta description is %d characters (1–155)', mb_strlen($a['meta_description']));
            }
            if (array_filter($doc->sections, fn (array $s): bool => $s['sources']) === [] || $doc->sourceLinks === []) {
                $reasons[] = 'no Sources section with a linked source';
            }

            $all = $a['title']."\n".$a['meta_title']."\n".$a['meta_description']."\n".$a['body'];
            $this->language($reasons, $all, $locale);
            $this->dealerClaims($reasons, $storefrontId, $all, $scope);
            $this->businessFacts($reasons, $all, $locale);
            $facts[$locale] = $this->catalogue($reasons, $storefrontId, $doc->withoutFacts()."\n".$a['title']."\n".$a['meta_description'], $scope);
            $this->links($reasons, $storefrontId, $doc);
            $this->sources($reasons, $all, $doc);
            $this->concreteSections($reasons, $doc, self::ownNames($scope));
            $stats['similarity_pct'] = (int) round(100 * $this->similarity($reasons, $storefrontId, 'article', $slug, $locale, $a['body']));

            $out[$locale] = ['passed' => $reasons === [], 'reasons' => $reasons, 'stats' => $stats];
        }
        if (isset($facts['en'], $facts['ar'])) {
            foreach (['models' => 'model numbers', 'prices' => 'prices'] as $k => $label) {
                if ($facts['en'][$k] !== $facts['ar'][$k]) {
                    $msg = sprintf('English and Arabic name different %s (EN: %s; AR: %s)', $label,
                        implode(', ', $facts['en'][$k]) ?: 'none', implode(', ', $facts['ar'][$k]) ?: 'none');
                    foreach (['en', 'ar'] as $l) {
                        $out[$l]['reasons'][] = $msg;
                        $out[$l]['passed'] = false;
                    }
                }
            }
        }

        return ['en' => $out['en'], 'ar' => $out['ar']];
    }

    private static function isKeyPoints(string $heading): bool
    {
        return preg_match('/^(key points|in short|at a glance|أهم النقاط|النقاط الرئيسية|باختصار|خلاصة سريعة)/iu', trim($heading)) === 1;
    }

    // ── the rules ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $reasons
     * @param  array{0: int, 1: int}  $range
     */
    private function range(array &$reasons, string $what, int $n, array $range): void
    {
        if ($n < $range[0] || $n > $range[1]) {
            $reasons[] = sprintf('%s is %d words (%d–%d)', $what, $n, $range[0], $range[1]);
        }
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $profile
     */
    private function faqRules(array &$reasons, MarkdownDoc $doc, array $profile, string $locale): void
    {
        $faq = array_values(Coerce::arr($profile['faq'] ?? [4, 6]));
        $lo = Coerce::int($faq[0] ?? 4);
        $hi = Coerce::int($faq[1] ?? 6);
        $n = count($doc->faq);
        if ($n < $lo || $n > $hi) {
            $reasons[] = sprintf('%d questions in the FAQ (%d–%d)', $n, $lo, $hi);
        }
        $answer = self::scaled($profile['faq_answer_words'] ?? [40, 80], $locale);
        foreach ($doc->faq as $q) {
            $w = Words::count($q['answer']);
            if ($w < $answer[0] || $w > $answer[1]) {
                $reasons[] = sprintf('the answer to "%s" is %d words (%d–%d)', mb_substr($q['question'], 0, 60), $w, $answer[0], $answer[1]);
            }
        }
    }

    /** @param  list<string>  $reasons */
    private function language(array &$reasons, string $text, string $locale): void
    {
        $lower = mb_strtolower($text);
        foreach (Coerce::arr(config('content_gate.banned.'.$locale)) as $phrase) {
            if (is_string($phrase) && $phrase !== '' && mb_strpos($lower, mb_strtolower($phrase)) !== false) {
                $reasons[] = 'a banned phrase: "'.$phrase.'"';
            }
        }
        if ($locale === 'ar') {
            foreach (Coerce::arr(config('content_gate.banned.ar_openers')) as $opener) {
                if (is_string($opener) && preg_match('/(^|\n)\s*'.preg_quote($opener, '/').'/u', $text) === 1) {
                    $reasons[] = 'a banned opener: "'.$opener.' …"';
                }
            }
        }
        foreach (Coerce::arr(config('content_gate.forbidden')) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $text, $m) === 1) {
                $reasons[] = 'a statement the shops never make: "'.mb_substr($m[0], 0, 60).'"';
            }
        }
    }

    /**
     * "Authorized dealer" / «وكيل معتمد» only where the shop IS one for the text's brand (Michael, 2026-11-14).
     *
     * @param  list<string>  $reasons
     * @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope
     */
    private function dealerClaims(array &$reasons, int $storefrontId, string $text, array $scope): void
    {
        foreach (Coerce::arr(config('content_gate.dealer_claims')) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $text, $m) === 1
                && ! AuthorizedDealer::forBrandId($storefrontId, $scope['brand_id'] ?? null)) {
                $reasons[] = 'an authorized-dealer claim for a brand this shop is not marked as an authorized dealer of: "'.mb_substr($m[0], 0, 60).'"';
            }
        }
    }

    /** @param  list<string>  $reasons */
    private function businessFacts(array &$reasons, string $text, string $locale): void
    {
        $t = Words::westernDigits($text);
        $facts = Coerce::arr(config('content_gate.facts'));
        $returnDays = Coerce::int($facts['return_days'] ?? 14);
        $deliveryHours = Coerce::int($facts['delivery_hours'] ?? 24);
        $warrantyMax = Coerce::int($facts['warranty_years_max'] ?? 3);
        $patterns = $locale === 'ar'
            ? [
                ['/(إرجاع|استرجاع|ترجيع)[^.\n]{0,40}?(\d+)\s*(يوم|أيام|يومًا|يوماً)/u', 2, $returnDays, 'a return window'],
                ['/(\d+)\s*(يوم|أيام|يومًا|يوماً)[^.\n]{0,30}?(إرجاع|استرجاع)/u', 1, $returnDays, 'a return window'],
                ['/(توصيل|نوصّل|نوصل|يصل)[^.\n]{0,40}?(\d+)\s*(ساعة|ساعات)/u', 2, $deliveryHours, 'a delivery time'],
                ['/ضمان[^.\n]{0,30}?(\d+)\s*(سنة|سنوات|عام|أعوام)/u', 1, -$warrantyMax, 'a warranty length'],
            ]
            : [
                ['/\breturns?\b[^.\n]{0,40}?\b(\d+)[\s-]*days?\b/i', 1, $returnDays, 'a return window'],
                ['/\b(\d+)[\s-]*days?\b[^.\n]{0,30}?\breturns?\b/i', 1, $returnDays, 'a return window'],
                ['/\bdeliver(y|ed|s)?\b[^.\n]{0,40}?\b(\d+)[\s-]*hours?\b/i', 2, $deliveryHours, 'a delivery time'],
                ['/\b(\d+)[\s-]*years?\b[^.\n]{0,12}?\bwarranty\b|\bwarranty\b[^.\n]{0,30}?\b(\d+)[\s-]*years?\b/i', 0, -$warrantyMax, 'a warranty length'],
            ];
        foreach ($patterns as [$re, $group, $want, $label]) {
            if (preg_match_all($re, $t, $all, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($all as $m) {
                $value = $group === 0 ? (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '0')) : (int) ($m[$group] ?? 0);
                $bad = $want < 0 ? ($value < 1 || $value > -$want) : $value !== $want;
                if ($bad) {
                    $reasons[] = sprintf('%s that is not the shop\'s ("%s")', $label, mb_substr($m[0], 0, 60));
                }
            }
        }
    }

    /**
     * Model numbers and prices named, matched against the LIVE catalogue. Returns what was found (sorted), for the
     * EN/AR comparison.
     *
     * @param  list<string>  $reasons
     * @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope
     * @return array{models: list<string>, prices: list<int>}
     */
    private function catalogue(array &$reasons, int $storefrontId, string $text, array $scope): array
    {
        $t = Words::westernDigits($text);
        // model numbers: letters + digits codes (MK6953, 1513477, GW0066G2, NF9172) — at least 4 digits, as model numbers are
        preg_match_all('/\b(?=[A-Z0-9-]*\d{4})[A-Z]{0,5}-?\d[A-Z0-9-]{3,14}\b/', $t, $mm);
        // a year (a brand's founding, a collection's launch: 1600–2099) is not a model number
        $models = array_values(array_unique(array_filter($mm[0], fn (string $m): bool => ! preg_match('/^(1[6-9]|20)\d\d$/', $m) && ! preg_match('/^\d{1,3}(,\d{3})+$/', $m))));
        sort($models);
        if ($models !== []) {
            $norm = fn (string $s): string => strtoupper((string) preg_replace('/[\s-]+/', '', $s));
            $known = [];
            foreach ($this->live($storefrontId)->whereNotNull('p.model_number')->pluck('p.model_number') as $mn) {
                $known[$norm(Coerce::str($mn))] = true;
            }
            foreach ($models as $m) {
                if (! isset($known[$norm($m)])) {
                    $reasons[] = 'a model number the shop does not have LIVE: '.$m;
                }
            }
        }
        // prices in EGP: "EGP 4,350", "4,350 EGP", "٤٬٣٥٠ جنيه"
        preg_match_all('/(?:EGP|LE|E£)\s?(\d{1,3}(?:[,.]\d{3})+|\d{3,7})|(\d{1,3}(?:[,٬.]\d{3})+|\d{3,7})\s?(?:EGP|LE|جنيه|ج\.م)/u', $t, $pm, PREG_SET_ORDER);
        $prices = [];
        foreach ($pm as $m) {
            $raw = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
            $prices[] = (int) preg_replace('/[^\d]/', '', $raw);
        }
        $prices = array_values(array_unique(array_filter($prices, fn (int $p): bool => $p > 0)));
        sort($prices);
        if ($prices !== []) {
            $q = $this->scoped($this->live($storefrontId), $storefrontId, $scope);
            $range = $q->selectRaw('MIN(CASE WHEN p.sale_price > 0 AND p.sale_price < p.selling_price THEN p.sale_price ELSE p.selling_price END) AS lo, MAX(p.selling_price) AS hi')->first();
            $lo = $range === null ? null : Coerce::float(Row::nstr($range, 'lo') ?? '0');
            $hi = $range === null ? null : Coerce::float(Row::nstr($range, 'hi') ?? '0');
            foreach ($prices as $p) {
                if ($lo === null || $hi === null || $p < floor($lo) || $p > ceil($hi)) {
                    $reasons[] = sprintf('a price outside the page\'s LIVE prices (EGP %s; LIVE EGP %s–%s)', number_format($p), number_format((float) $lo), number_format((float) $hi));
                }
            }
        }

        // for the EN/AR comparison: the prices named next to a currency AND every thousands-separated amount — Arabic often
        // says "من ٤٬٥٠٠ إلى ٧٬٨٠٠ جنيه" where English repeats "EGP" before each
        preg_match_all('/\b\d{1,3}(?:,\d{3})+\b/', $t, $sep);
        $amounts = array_values(array_unique(array_merge($prices, array_map(fn (string $a): int => (int) str_replace(',', '', $a), $sep[0]))));
        sort($amounts);

        return ['models' => array_map(fn (string $m): string => strtoupper($m), $models), 'prices' => $amounts];
    }

    /** @param  list<string>  $reasons */
    private function links(array &$reasons, int $storefrontId, MarkdownDoc $doc): void
    {
        $hosts = array_values(array_map(fn (mixed $h): string => Coerce::str($h), Coerce::arr(config('content_gate.sources'))));
        foreach ($doc->links as $link) {
            $href = $link['href'];
            if (str_starts_with($href, '/')) {
                if (! SitePaths::exists($storefrontId, $href)) {
                    $reasons[] = 'a link to a page the shop does not have: '.$href;
                }
            } elseif (preg_match('#^https://([^/]+)#i', $href, $m) === 1) {
                $host = strtolower((string) preg_replace('/^www\./i', '', $m[1]));
                if (! self::approved($host, $hosts)) {
                    $reasons[] = 'a link outside the approved sources: '.$host;
                }
            } else {
                $reasons[] = 'a link that is neither a page of the shop nor an https source: '.mb_substr($href, 0, 60);
            }
        }
    }

    /** @param  list<string>  $reasons */
    private function sources(array &$reasons, string $text, MarkdownDoc $doc): void
    {
        $t = Words::westernDigits($text);
        $founded = Coerce::int(config('content_gate.facts.founded', 2013));
        preg_match_all('/\b(1[6-9]\d\d|20[0-2]\d)\b/', $t, $ym);
        $years = array_values(array_filter(array_unique(array_map('intval', $ym[1])), fn (int $y): bool => $y !== $founded));
        if ($years === []) {
            return;
        }
        $cited = array_filter($doc->sourceLinks, fn (string $href): bool => str_starts_with($href, 'https://'));
        if ($cited === []) {
            $reasons[] = sprintf('external facts (%s) with no Sources section citing an approved source', implode(', ', array_slice($years, 0, 5)));
        }
    }

    /** @param  list<string>  $reasons */
    /**
     * A section is concrete when it holds a number, a link, a data block, a bold term (a line or model named), or a proper
     * name that is not the page's own brand or the shop's name: a capitalized word inside an English sentence, a Latin
     * word inside Arabic. Naming the brand alone never makes a section concrete.
     *
     * @param  list<string>  $reasons
     * @param  list<string>  $ownNames  lower-cased words of the page's brand and the shops' names
     */
    private function concreteSections(array &$reasons, MarkdownDoc $doc, array $ownNames): void
    {
        foreach ($doc->sections as $s) {
            if ($s['faq'] || $s['sources'] || $s['facts']) {
                continue;
            }
            $t = Words::westernDigits($s['text']);
            $concrete = preg_match('/\d/', $t) === 1 || str_contains($s['text'], '](') || preg_match('/\{\{[a-z-]+/', $s['text']) === 1
                || preg_match('/\*\*[^*]{2,}\*\*/u', $s['text']) === 1;
            if (! $concrete) {
                $arabic = preg_match('/\p{Arabic}/u', $s['text']) === 1;
                $pattern = $arabic ? '/\b[A-Za-z][A-Za-z\'-]{2,}\b/u' : '/(?<![.!?:]\s)(?<!^)\b[A-Z][a-z\'’]{2,}\b/mu';
                preg_match_all($pattern, $s['text'], $nm);
                foreach ($nm[0] as $name) {
                    if (! in_array(mb_strtolower(rtrim($name, "'’s")), $ownNames, true) && ! in_array(mb_strtolower($name), $ownNames, true)) {
                        $concrete = true;
                        break;
                    }
                }
            }
            if (! $concrete) {
                $reasons[] = 'a section with no concrete detail (a number, a model, a link or a name): "'.mb_substr($s['heading'], 0, 60).'"';
            }
        }
    }

    /**
     * The highest 5-word-shingle overlap with any other text of the same language (page texts of both shops, articles).
     *
     * @param  list<string>  $reasons
     */
    private function similarity(array &$reasons, int $storefrontId, string $type, string $key, string $locale, string $text): float
    {
        $mine = Words::shingles($text);
        if ($mine === []) {
            return 0.0;
        }
        $max = 0.0;
        $with = '';
        foreach (DB::table('core_seo_texts')->where('locale', $locale)->whereNotNull('description')
            ->get(['storefront_id', 'subject_type', 'subject_key', 'intro', 'description']) as $raw) {
            $r = Row::cast($raw);
            if (Row::int($r, 'storefront_id') === $storefrontId && Row::str($r, 'subject_type') === $type && Row::str($r, 'subject_key') === $key) {
                continue;
            }
            $j = Words::jaccard($mine, Words::shingles((Row::nstr($r, 'intro') ?? '')."\n".(Row::nstr($r, 'description') ?? '')));
            if ($j > $max) {
                $max = $j;
                $with = Row::str($r, 'subject_type').' '.Row::str($r, 'subject_key').' (shop '.Row::int($r, 'storefront_id').')';
            }
        }
        foreach (DB::table('core_blog_translations as t')->join('core_blogs as b', 'b.id', '=', 't.blog_id')->where('t.locale', $locale)
            ->whereNotNull('t.body')->get(['b.storefront_id', 'b.slug', 't.body']) as $raw) {
            $r = Row::cast($raw);
            if ($type === 'article' && Row::int($r, 'storefront_id') === $storefrontId && Row::str($r, 'slug') === $key) {
                continue;                                                // the article itself
            }
            $j = Words::jaccard($mine, Words::shingles(Row::nstr($r, 'body') ?? ''));
            if ($j > $max) {
                $max = $j;
                $with = 'article '.Row::str($r, 'slug').' (shop '.Row::int($r, 'storefront_id').')';
            }
        }
        $limit = Coerce::float(config('content_gate.similarity_max', 0.25));
        if ($max > $limit) {
            $reasons[] = sprintf('%d%% of its phrasing repeats %s (at most %d%%)', (int) round($max * 100), $with, (int) round($limit * 100));
        }

        return $max;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The words that do not make a section concrete on their own: the page's brand, the shops' names.
     *
     * @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope
     * @return list<string>
     */
    private static function ownNames(array $scope): array
    {
        $words = ['watchizer', 'brand', 'fashion', 'the', 'a', 'an'];
        if (($scope['brand_id'] ?? null) !== null) {
            foreach (DB::table('catalog_brand_translations')->where('brand_id', $scope['brand_id'])->pluck('name') as $name) {
                foreach (preg_split('/[\s-]+/u', mb_strtolower(Coerce::str($name))) ?: [] as $w) {
                    $words[] = $w;
                }
            }
            $slug = DB::table('catalog_brands')->where('id', $scope['brand_id'])->value('slug');
            foreach (explode('-', Coerce::str($slug)) as $w) {
                $words[] = $w;
            }
        }

        return array_values(array_unique(array_filter($words, fn (string $w): bool => $w !== '')));
    }

    /** @return \Illuminate\Database\Query\Builder LIVE products search engines are shown, on one storefront */
    private function live(int $storefrontId): \Illuminate\Database\Query\Builder
    {
        return ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $storefrontId)
            ->whereRaw('COALESCE('.SearchExposure::effectiveSql('p').", '') <> ?", [SearchExposure::HIDDEN]);
    }

    /** @param  array{brand_id?: int|null, family?: string|null, gender_id?: int|null, category_path?: string|null}  $scope */
    private function scoped(\Illuminate\Database\Query\Builder $q, int $storefrontId, array $scope): \Illuminate\Database\Query\Builder
    {
        if (($scope['brand_id'] ?? null) !== null) {
            $q->where('p.brand_id', $scope['brand_id']);
        }
        if (($scope['family'] ?? null) !== null) {
            $q->where('p.family', $scope['family']);
        }
        if (($scope['gender_id'] ?? null) !== null) {
            $q->whereIn('sp.product_id', fn (\Illuminate\Database\Query\Builder $s) => $s->select('product_id')->from('catalog_product_gender')->where('gender_id', $scope['gender_id']));
        }
        if (($scope['category_path'] ?? null) !== null) {
            $path = $scope['category_path'];
            $q->whereIn('sp.product_id', fn (\Illuminate\Database\Query\Builder $s) => $s->select('scp.product_id')->from('storefront_category_product as scp')
                ->join('storefront_categories as sc', 'sc.id', '=', 'scp.storefront_category_id')
                ->where('sc.storefront_id', $storefrontId)
                ->where(fn (\Illuminate\Database\Query\Builder $w) => $w->where('sc.path', $path)->orWhere('sc.path', 'like', $path.'/%')));
        }

        return $q;
    }

    private static function brandId(string $slug): ?int
    {
        $id = DB::table('catalog_brands')->where('slug', $slug)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param  list<string>  $hosts */
    private static function approved(string $host, array $hosts): bool
    {
        foreach ($hosts as $h) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A [min, max] word range for a language: Arabic's minimum is `arabic_share` of the English one.
     *
     * @return array{0: int, 1: int}
     */
    private static function scaled(mixed $range, string $locale): array
    {
        $r = array_values(Coerce::arr($range));
        $min = Coerce::int($r[0] ?? 0);
        $max = Coerce::int($r[1] ?? PHP_INT_MAX);

        return $locale === 'ar' ? [(int) floor($min * Coerce::float(config('content_gate.arabic_share', 0.85))), $max] : [$min, $max];
    }
}
