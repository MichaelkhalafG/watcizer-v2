<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Console\Commands\SeoSeedTextsCommand;
use App\Domain\Content\SeoFacts;
use App\Domain\Content\SeoTexts;
use App\Domain\Content\SeoTextStructure;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Storefront\CategoryTree;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /manage/storefronts/{storefront}/page-texts — the introduction and meta description of each BRAND page and each
 * CATEGORY page, in Arabic and English (M1y, SEO item 5, 2026-10-30).
 *
 * The storefront's brand and category pages had no words of their own: a templated "Browse N … at Watchizer" meta
 * description and no text above the grid, which is the thinnest a page can be for a search engine. The text lives in
 * `core_seo_texts`, keyed by the brand's slug and the category's PATH as the storefront's own tree builds it — the
 * menu-visible nodes, a parent that is hidden leaving its child at the root ({@see CategoryTree}) —
 * so the key this screen writes is exactly the key the v2 listing looks up.
 *
 * Same ability and scope as the banners screen: it decides what one storefront's pages SAY. Every save is logged
 * and flushes the storefront's cache, as the banners screen does.
 */
final class SeoTextController
{
    public function __construct(private readonly StorefrontCache $cache) {}

    public function index(Storefront $storefront): Response
    {
        $sf = (int) $storefront->id;
        $texts = SeoTexts::all($sf);

        $subjects = [];
        foreach (self::brands($sf) as $brand) {
            $subjects[] = $brand + ['type' => 'brand', 'texts' => self::textsOf($texts, 'brand', $brand['key'])];
        }
        foreach (self::categories($sf) as $category) {
            $subjects[] = $category + ['type' => 'category', 'texts' => self::textsOf($texts, 'category', $category['key'])];
        }

        return Inertia::render('Manage/PageTexts/Index', [
            'storefront' => ['id' => $sf, 'code' => $storefront->code, 'name' => $storefront->name],
            'subjects' => $subjects,
            'meta_limit' => 160,
            // the Markdown editor's preview shows an uploaded picture from this storefront's image host (Part 6.1)
            'assetBase' => config()->string('storefront.asset_base'),
            'notice' => ManageText::t('page_texts.notice', 'النص يظهر في صفحة الماركة أو التصنيف تحت المنتجات، والوصف المختصر هو ما يظهر في نتائج جوجل. اتركه فارغاً ليستخدم المتجر نصه التلقائي.'),
        ]);
    }

    /**
     * ONE PAGE'S TEXT AS THE PARTS ITS LAYOUT DRAWS (item 43 B, 2026-11-05) — what the edit form shows as fields
     * instead of Markdown: the intro, "<name> at <shop>" (its paragraph, and the catalogue's facts with which of them
     * show), every other section (a paragraph and its points) and the questions block. Per language. `structured:
     * false` means that language's text is not in the shape the fields edit (somebody wrote it freely): the form
     * keeps the Markdown editor for it and says why.
     *
     * The facts are GENERATED (`SeoFacts`, from `facts.json` — `seo:count-facts --write` refreshes it): the form
     * shows them read-only and only chooses which ones show.
     */
    public function structure(Request $request, Storefront $storefront): JsonResponse
    {
        $sf = (int) $storefront->id;
        $type = Coerce::str($request->query('type'));
        $key = Coerce::str($request->query('key'));
        $subject = self::subject($sf, $type, $key);
        if ($subject === null) {
            return response()->json(['message' => 'unknown page'], 404);
        }
        $code = (string) $storefront->code;
        $shop = SeoSeedTextsCommand::SHOP_NAMES[$code] ?? ['en' => (string) $storefront->name, 'ar' => (string) $storefront->name];
        $sections = SeoFacts::section($code, $type, $key, $shop, $subject['names']);
        $texts = self::textsOf(SeoTexts::all($sf), $type, $key);

        $out = [];
        foreach (SeoTexts::LOCALES as $locale) {
            $generated = SeoTextStructure::generated($sections[$locale]);
            $parsed = SeoTextStructure::parse($texts[$locale]['description']);
            $out[$locale] = self::fields($parsed, $generated);
        }

        return response()->json($out);
    }

    /**
     * @param  array{intro: string, at: array{heading: string, paragraph: string, facts: list<array{label: string, line: string}>}|null, sections: list<array{heading: string, paragraph: string, bullets: list<string>}>, faq: array{heading: string, body: string}|null}|null  $parsed
     * @param  array{heading: string, paragraph: string, facts: list<array{label: string, line: string}>}  $generated
     * @return array<string, mixed>
     */
    private static function fields(?array $parsed, array $generated): array
    {
        if ($parsed === null) {
            return ['structured' => false];
        }
        $stored = [];
        foreach ($parsed['at']['facts'] ?? [] as $fact) {
            $stored[$fact['label']] = $fact['line'];
        }
        // the catalogue's facts in the catalogue's order, each with whether the text shows it; then any fact the
        // text carries that the catalogue no longer produces (kept as written until somebody hides it)
        $facts = [];
        foreach ($generated['facts'] as $fact) {
            $facts[] = ['label' => $fact['label'], 'value' => SeoTextStructure::value($fact['line']), 'shown' => isset($stored[$fact['label']]) || $parsed['at'] === null, 'generated' => true];
            unset($stored[$fact['label']]);
        }
        foreach ($stored as $label => $line) {
            $facts[] = ['label' => $label, 'value' => SeoTextStructure::value($line), 'shown' => true, 'generated' => false];
        }

        return [
            'structured' => true,
            'intro' => $parsed['intro'],
            'at' => [
                'present' => $parsed['at'] !== null,
                'heading' => $parsed['at']['heading'] ?? $generated['heading'],
                'paragraph' => $parsed['at']['paragraph'] ?? $generated['paragraph'],
                'facts' => $facts,
            ],
            'sections' => $parsed['sections'],
            'faq' => $parsed['faq'] ?? ['heading' => '', 'body' => ''],
        ];
    }

    /**
     * The Markdown of a page's parts as the form sent them. The fact LINES are never taken from the request: a
     * shown fact is the catalogue's line for its label (or, for a fact the catalogue no longer produces, the line
     * the text already had).
     *
     * @param  array<mixed>  $fields
     */
    private static function compile(array $fields, string $generatedSection, string $storedText): string
    {
        $generated = SeoTextStructure::generated($generatedSection);
        $lines = [];
        foreach (SeoTextStructure::parse($storedText)['at']['facts'] ?? [] as $fact) {
            $lines[$fact['label']] = $fact['line'];
        }
        foreach ($generated['facts'] as $fact) {
            $lines[$fact['label']] = $fact['line'];
        }
        $at = Coerce::arr($fields['at'] ?? []);
        $facts = [];
        foreach (Coerce::arr($at['shown'] ?? []) as $label) {
            if (is_string($label) && isset($lines[$label])) {
                $facts[] = ['label' => $label, 'line' => $lines[$label]];
            }
        }
        $heading = trim(Coerce::str($at['heading'] ?? ''));
        $paragraph = trim(Coerce::str($at['paragraph'] ?? ''));

        $sections = [];
        foreach (Coerce::arr($fields['sections'] ?? []) as $raw) {
            $raw = Coerce::arr($raw);
            $title = trim(Coerce::str($raw['heading'] ?? ''));
            if ($title === '') {
                continue;
            }
            $bullets = [];
            foreach (Coerce::arr($raw['bullets'] ?? []) as $bullet) {
                if (is_string($bullet) && trim($bullet) !== '') {
                    $bullets[] = $bullet;
                }
            }
            $sections[] = ['heading' => $title, 'paragraph' => Coerce::str($raw['paragraph'] ?? ''), 'bullets' => $bullets];
        }
        $faq = Coerce::arr($fields['faq'] ?? []);
        $faqHeading = trim(Coerce::str($faq['heading'] ?? ''));

        return SeoTextStructure::compile([
            'intro' => Coerce::str($fields['intro'] ?? ''),
            'at' => $heading === '' || ($paragraph === '' && $facts === []) ? null : ['heading' => $heading, 'paragraph' => $paragraph, 'facts' => $facts],
            'sections' => $sections,
            'faq' => $faqHeading === '' ? null : ['heading' => $faqHeading, 'body' => Coerce::str($faq['body'] ?? '')],
        ]);
    }

    /**
     * @return array{key: string, name: string, names: array{en: string, ar: string}}|null the page, when this storefront has it
     */
    private static function subject(int $sf, string $type, string $key): ?array
    {
        if (! in_array($type, SeoTexts::TYPES, true)) {
            return null;
        }
        foreach ($type === 'brand' ? self::brands($sf) : self::categories($sf) as $candidate) {
            if ($candidate['key'] === $key) {
                return ['key' => $candidate['key'], 'name' => $candidate['name'], 'names' => $candidate['names']];
            }
        }

        return null;
    }

    public function update(Request $request, Storefront $storefront): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $data = Coerce::arr($request->validate([
            'type' => ['required', Rule::in(SeoTexts::TYPES)],
            'key' => ['required', 'string', 'max:191'],
            // THE PARTS (item 43 B): when a language sends `structure`, its text is compiled from the parts and its
            // `description` is ignored. Free text only — every part is escaped by the same Markdown rules as before.
            'en.structure' => ['nullable', 'array'],
            'ar.structure' => ['nullable', 'array'],
            '*.structure.intro' => ['nullable', 'string', 'max:6000'],
            '*.structure.at.heading' => ['nullable', 'string', 'max:200'],
            '*.structure.at.paragraph' => ['nullable', 'string', 'max:4000'],
            '*.structure.at.shown' => ['nullable', 'array', 'max:20'],
            '*.structure.at.shown.*' => ['string', 'max:120'],
            '*.structure.sections' => ['nullable', 'array', 'max:8'],
            '*.structure.sections.*.heading' => ['nullable', 'string', 'max:200'],
            '*.structure.sections.*.paragraph' => ['nullable', 'string', 'max:4000'],
            '*.structure.sections.*.bullets' => ['nullable', 'array', 'max:20'],
            '*.structure.sections.*.bullets.*' => ['nullable', 'string', 'max:600'],
            '*.structure.faq.heading' => ['nullable', 'string', 'max:200'],
            '*.structure.faq.body' => ['nullable', 'string', 'max:12000'],
            // Markdown since 2026-11-05 (Part 6.1): sections, lists and a questions block make a text several times
            // the length of the two paragraphs this field held; the column is TEXT.
            'en.description' => ['nullable', 'string', 'max:20000'],
            'en.meta_description' => ['nullable', 'string', 'max:320'],
            'ar.description' => ['nullable', 'string', 'max:20000'],
            'ar.meta_description' => ['nullable', 'string', 'max:320'],
        ]));
        $type = Coerce::str($data['type']);
        $key = Coerce::str($data['key']);

        // Only a page the storefront actually has: a key typed by hand would be a text no page ever shows.
        $subject = null;
        foreach ($type === 'brand' ? self::brands($sf) : self::categories($sf) as $candidate) {
            if ($candidate['key'] === $key) {
                $subject = $candidate;
            }
        }
        if ($subject === null) {
            throw ValidationException::withMessages(['key' => ManageText::t('page_texts.unknown_page', 'هذه الصفحة غير موجودة في هذا المتجر.')]);
        }

        $generated = null;
        $stored = null;
        $byLocale = [];
        foreach (SeoTexts::LOCALES as $locale) {
            $fields = Coerce::arr($data[$locale] ?? []);
            if (is_array($fields['structure'] ?? null)) {
                $code = (string) $storefront->code;
                $generated ??= SeoFacts::section($code, $type, $key, SeoSeedTextsCommand::SHOP_NAMES[$code] ?? ['en' => (string) $storefront->name, 'ar' => (string) $storefront->name], $subject['names']);
                $stored ??= self::textsOf(SeoTexts::all($sf), $type, $key);
                $fields['description'] = self::compile($fields['structure'], $generated[$locale], $stored[$locale]['description']);
            }
            $byLocale[$locale] = [
                'description' => Coerce::nstr($fields['description'] ?? null),
                'meta_description' => Coerce::nstr($fields['meta_description'] ?? null),
            ];
        }
        SeoTexts::save($sf, $type, $key, $byLocale, Coerce::nint(Auth::id()), $subject['name'].' ('.$key.')');
        $this->cache->flush($sf);

        return back()->with('status', ManageText::t('page_texts.saved', 'تم حفظ نص الصفحة.'));
    }

    /**
     * The brands this storefront shows: placed, visible, active products — the brands a shopper can reach a page for.
     *
     * @return list<array{key: string, name: string, products: int, names: array{en: string, ar: string}}>
     */
    public static function brands(int $storefrontId): array
    {
        $rows = DB::table('catalog_brands as b')
            ->join('catalog_products as p', 'p.brand_id', '=', 'b.id')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->leftJoin('catalog_brand_translations as ar', function (JoinClause $j): void {
                $j->on('ar.brand_id', '=', 'b.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('catalog_brand_translations as en', function (JoinClause $j): void {
                $j->on('en.brand_id', '=', 'b.id')->where('en.locale', '=', 'en');
            })
            ->where('b.is_active', true)->where('sp.is_visible', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->groupBy('b.id', 'b.slug', 'ar.name', 'en.name')
            ->orderByRaw('COUNT(DISTINCT p.id) DESC')->orderBy('b.slug')
            ->selectRaw('b.slug AS slug, ar.name AS name_ar, en.name AS name_en, COUNT(DISTINCT p.id) AS products')
            ->get();

        $out = [];
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $slug = Row::str($r, 'slug');
            $en = trim(Row::nstr($r, 'name_en') ?? '') ?: $slug;
            $out[] = [
                'key' => $slug, 'name' => LocalisedName::pick(Row::nstr($r, 'name_ar'), Row::nstr($r, 'name_en'), $slug), 'products' => Row::int($r, 'products'),
                // each language's OWN name, for the page texts (an Arabic name inside an English text is the bug)
                'names' => ['en' => $en, 'ar' => trim(Row::nstr($r, 'name_ar') ?? '') ?: $en],
            ];
        }

        return $out;
    }

    /**
     * The storefront's category pages, keyed by their slug path — built exactly as {@see CategoryTree}
     * builds it, from the menu-visible nodes, so the key matches what the listing receives.
     *
     * @return list<array{key: string, name: string, depth: int, names: array{en: string, ar: string}}>
     */
    public static function categories(int $storefrontId): array
    {
        $visible = StorefrontCategory::query()->visibleInMenu($storefrontId)
            ->select(['storefront_categories.id', 'storefront_categories.parent_id', 'storefront_categories.slug'])
            ->orderBy('storefront_categories.depth')->orderBy('storefront_categories.sort_order')->orderBy('storefront_categories.id')
            ->get();
        $nodes = [];
        foreach ($visible as $node) {
            $nodes[(int) $node->id] = ['parent' => $node->parent_id === null ? null : (int) $node->parent_id, 'slug' => (string) $node->slug];
        }
        if ($nodes === []) {
            return [];
        }
        $names = [];
        foreach (DB::table('storefront_category_translations')->whereIn('storefront_category_id', array_keys($nodes))->get(['storefront_category_id', 'locale', 'name']) as $raw) {
            $r = Row::cast($raw);
            $names[Row::int($r, 'storefront_category_id')][Row::str($r, 'locale')] = Row::str($r, 'name');
        }

        $out = [];
        foreach ($nodes as $id => $node) {
            $segments = [];
            $cursor = $id;
            while ($cursor !== null && isset($nodes[$cursor])) {
                array_unshift($segments, $nodes[$cursor]['slug']);
                $cursor = $nodes[$cursor]['parent'];
            }
            $out[] = [
                'key' => implode('/', $segments),
                'name' => LocalisedName::pick($names[$id]['ar'] ?? null, $names[$id]['en'] ?? null, $node['slug']),
                'depth' => count($segments),
                'names' => ['en' => trim($names[$id]['en'] ?? '') ?: $node['slug'], 'ar' => trim($names[$id]['ar'] ?? '') ?: (trim($names[$id]['en'] ?? '') ?: $node['slug'])],
            ];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $out;
    }

    /**
     * @param  array<string, array<string, array<string, array{description: string|null, meta_description: string|null}>>>  $texts
     * @return array<string, array{description: string, meta_description: string}>
     */
    private static function textsOf(array $texts, string $type, string $key): array
    {
        $out = [];
        foreach (SeoTexts::LOCALES as $locale) {
            $row = $texts[$type][$key][$locale] ?? null;
            $out[$locale] = ['description' => $row['description'] ?? '', 'meta_description' => $row['meta_description'] ?? ''];
        }

        return $out;
    }
}
