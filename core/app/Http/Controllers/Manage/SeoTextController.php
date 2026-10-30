<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Content\SeoTexts;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategory;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
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
 * menu-visible nodes, a parent that is hidden leaving its child at the root ({@see \App\Storefront\CategoryTree}) —
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
            'notice' => ManageText::t('page_texts.notice', 'النص يظهر في صفحة الماركة أو التصنيف تحت المنتجات، والوصف المختصر هو ما يظهر في نتائج جوجل. اتركه فارغاً ليستخدم المتجر نصه التلقائي.'),
        ]);
    }

    public function update(Request $request, Storefront $storefront): RedirectResponse
    {
        $sf = (int) $storefront->id;
        $data = Coerce::arr($request->validate([
            'type' => ['required', Rule::in(SeoTexts::TYPES)],
            'key' => ['required', 'string', 'max:191'],
            'en.description' => ['nullable', 'string', 'max:5000'],
            'en.meta_description' => ['nullable', 'string', 'max:320'],
            'ar.description' => ['nullable', 'string', 'max:5000'],
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

        $byLocale = [];
        foreach (SeoTexts::LOCALES as $locale) {
            $fields = Coerce::arr($data[$locale] ?? []);
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
     * @return list<array{key: string, name: string, products: int}>
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
            $out[] = ['key' => $slug, 'name' => LocalisedName::pick(Row::nstr($r, 'name_ar'), Row::nstr($r, 'name_en'), $slug), 'products' => Row::int($r, 'products')];
        }

        return $out;
    }

    /**
     * The storefront's category pages, keyed by their slug path — built exactly as {@see \App\Storefront\CategoryTree}
     * builds it, from the menu-visible nodes, so the key matches what the listing receives.
     *
     * @return list<array{key: string, name: string, depth: int}>
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
