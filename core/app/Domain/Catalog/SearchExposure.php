<?php

namespace App\Domain\Catalog;

use App\Storefront\ProductCards;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * WHAT SEARCH ENGINES AND AI ARE SHOWN OF A PRODUCT (Michael, 2026-11-14; config `catalog.search_exposure`).
 *
 * The products stay on sale exactly as they are — names, prices, pages. What changes is only what search engines and
 * AI read: a product the RULE matches is `hidden` (noindex,follow; out of the sitemaps, /llms.txt, the Meta and Google
 * feeds, brand-page copy, articles' data blocks and ItemLists; no Product structured data) — except a ROLEX it matches,
 * which is `restricted`: indexable and in the sitemap (Rolex pages are the only ones Search Console shows bringing
 * clicks), but its structured data names no brand and claims no authenticity, and it is out of /llms.txt, the feeds,
 * the articles' data blocks, "best/top" lists and any "original watches" grouping. On EVERY matched page the
 * "authenticity" badge and wording are gone; the rest of the site keeps them.
 *
 * THE RULE — a product matches when ANY of these is true:
 *  1. its title (EN or AR) carries a copy word: copy, replica, mirror, 1:1, first copy, master quality, high copy, AAA
 *     (كوبي، طبق الأصل، تقليد);
 *  2. its English title says "inspired by" followed by a brand the catalogue knows, or its Arabic title «مستوحاة من»;
 *  3. its title says "Style" (ستايل) and the product is a luxury maison's, or names one;
 *  4. it is a WATCH maison's (Rolex, Patek Philippe, Hublot, Audemars Piguet, Richard Mille, Cartier, Omega, …) priced
 *     below EGP 100,000 — no genuine one is (on 2026-11-14 the dearest of them all was EGP 12,500);
 *  5. it is Louis Vuitton's, Chanel's, Hermès's or Dior's priced below EGP 10,000;
 *  6. it is a WATCH of a brand listed with a price line for its watches only (Gucci below EGP 20,000; its perfumes,
 *     sunglasses and other items stay outside the rule).
 * Gucci, Prada and Versace are left out on purpose: their genuine perfumes and sunglasses sell at these prices.
 *
 * Measured on the 2026-11-14 copy (rules 1–4): Watchizer 354 of 2,123 visible products, Brand Fashion 1,524 of 9,529.
 *
 * THE SWITCH — `search_override`, the product form's "Search engines" choice — always wins, both ways: 0 hides a
 * product the rule leaves alone, 1 shows normally one the rule matched.
 */
final class SearchExposure
{
    public const HIDDEN = 'hidden';

    public const RESTRICTED = 'restricted';

    /** @var list<string>|null the catalogue's brand names (English, lower case), for rule 2 — read once per process */
    private ?array $brandNames = null;

    /**
     * The class a product's EFFECTIVE exposure is, in SQL — the switch first, then the rule. `$alias` is the
     * `catalog_products` alias of the query: code, never input.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function effectiveSql(string $alias = 'p'): string
    {
        return "(CASE WHEN {$alias}.search_override = 0 THEN 'hidden' WHEN {$alias}.search_override = 1 THEN NULL ELSE {$alias}.search_class END)";
    }

    /** The effective class from a row carrying `search_class` and `search_override`. */
    public static function effective(stdClass $row): ?string
    {
        $override = Row::nint($row, 'search_override');
        if ($override === 0) {
            return self::HIDDEN;
        }
        if ($override === 1) {
            return null;
        }
        $class = Row::nstr($row, 'search_class');

        return in_array($class, [self::HIDDEN, self::RESTRICTED], true) ? $class : null;
    }

    /**
     * Why the rule matches a product (empty = it does not), in words the dashboard can show.
     *
     * @param  list<string>  $brandNames  the catalogue's brand names, English, lower case
     * @return list<string>
     */
    public static function reasons(string $brandSlug, float $price, string $titleEn, string $titleAr, array $brandNames, string $family = ''): array
    {
        $cfg = config()->array('catalog.search_exposure', []);
        $watch = self::strings($cfg['watch_maisons'] ?? []);
        $fashion = self::strings($cfg['fashion_maisons'] ?? []);
        $watchBelow = Coerce::nfloat($cfg['watch_maison_below'] ?? null) ?? 100000.0;
        $fashionBelow = Coerce::nfloat($cfg['fashion_maison_below'] ?? null) ?? 10000.0;

        $out = [];
        if (preg_match('/\b(copy|replica|mirror|1:1|first[- ]copy|master[- ]quality|high[- ]copy|aaa)\b/iu', $titleEn) === 1
            || preg_match('/(كوبي|طبق الأصل|تقليد)/u', $titleAr) === 1) { // i18n-exempt: matching data, never rendered - the copy words the rule looks for in an Arabic title (SearchExposureTest)
            $out[] = 'copy word';
        }
        if (preg_match('/\binspired\s+by\s+([a-z][a-z .&\'-]{1,40})/iu', $titleEn, $m) === 1) {
            $after = mb_strtolower($m[1]);
            foreach ($brandNames as $name) {
                if ($name !== '' && str_starts_with($after, $name)) {
                    $out[] = 'inspired by a brand';
                    break;
                }
            }
        }
        if ($out === [] && preg_match('/مستوح[اى]ة?\s+من/u', $titleAr) === 1) { // i18n-exempt: matching data, never rendered - "inspired by" in an Arabic title (SearchExposureTest)
            $out[] = 'inspired by (Arabic)';
        }
        $maison = in_array($brandSlug, $watch, true) || in_array($brandSlug, $fashion, true)
            || preg_match('/\b(rolex|patek|hublot|audemars|richard mille|cartier|omega|vacheron|breitling|louis vuitton|chanel|herm[eè]s|dior)\b/iu', $titleEn) === 1;
        if ($maison && (preg_match('/\bstyle\b/iu', $titleEn) === 1 || preg_match('/ستايل/u', $titleAr) === 1)) { // i18n-exempt: matching data, never rendered - "Style" in an Arabic title (SearchExposureTest)
            $out[] = 'style + luxury maison';
        }
        if (in_array($brandSlug, $watch, true) && $price < $watchBelow) {
            $out[] = 'watch maison below EGP '.number_format($watchBelow);
        }
        if (in_array($brandSlug, $fashion, true) && $price < $fashionBelow) {
            $out[] = 'fashion maison below EGP '.number_format($fashionBelow);
        }
        $watchOnly = is_array($cfg['watch_only_below'] ?? null) ? $cfg['watch_only_below'] : [];
        $line = Coerce::nfloat($watchOnly[$brandSlug] ?? null);
        if ($family === 'watch' && $line !== null && $price < $line) {
            $out[] = 'watch of '.$brandSlug.' below EGP '.number_format($line);
        }

        return $out;
    }

    /**
     * The class the rule gives: hidden, restricted (a matched Rolex), or null.
     *
     * @param  list<string>  $reasons
     */
    public static function classOf(string $brandSlug, array $reasons): ?string
    {
        if ($reasons === []) {
            return null;
        }
        $restricted = self::strings(config()->array('catalog.search_exposure', [])['restricted_brands'] ?? []);

        return in_array($brandSlug, $restricted, true) ? self::RESTRICTED : self::HIDDEN;
    }

    /**
     * A storefront's brands whose visible products are ALL kept from search (`hidden`), and those whose indexable
     * ones are all a matched Rolex (`restricted`) — v2's meta answers them, /llms.txt names neither, and their
     * brand-page copy may not claim originality (`seo:strip-originality`).
     *
     * @return array{hidden_brand_ids: list<int>, restricted_brand_ids: list<int>}
     */
    public static function brands(int $storefrontId): array
    {
        $rows = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $storefrontId)
            ->groupBy('p.brand_id')
            ->selectRaw('p.brand_id, SUM(COALESCE('.self::effectiveSql('p').", '') = '') AS open_n, SUM(COALESCE(".self::effectiveSql('p').", '') = 'restricted') AS restricted_n")
            ->get();
        $hidden = [];
        $restricted = [];
        foreach ($rows as $r) {
            $open = Row::int($r, 'open_n');
            $res = Row::int($r, 'restricted_n');
            if ($open === 0 && $res === 0) {
                $hidden[] = Row::int($r, 'brand_id');
            } elseif ($open === 0) {
                $restricted[] = Row::int($r, 'brand_id');
            }
        }

        return ['hidden_brand_ids' => $hidden, 'restricted_brand_ids' => $restricted];
    }

    /** The "Original watches" collection's names and address (Michael, 2026-11-14). */
    public const ORIGINAL_WATCHES = ['slug' => 'original-watches', 'name' => ['en' => 'Original watches', 'ar' => 'ساعات أصلية']]; // i18n-exempt: storefront content - the Original watches page name the v2 menu sends to the shop, in the shopper's language

    /**
     * ORIGINAL WATCHES (Michael, 2026-11-14): the watches the rule leaves alone — never a hidden product, never a
     * matched Rolex (restricted), never a brand that names no maker (config `catalog.original_watches.exclude_brands`).
     * One definition for the listing, the menu's count and the sitemap. `$query` joins `catalog_products` as `p`.
     */
    public static function onlyOriginalWatches(Builder $query): Builder
    {
        return $query->where('p.family', 'watch')
            ->whereRaw(self::effectiveSql('p').' IS NULL')
            ->whereNotIn('p.brand_id', DB::table('catalog_brands')->whereIn('slug', config()->array('catalog.original_watches.exclude_brands', []))->select('id'));
    }

    /** Recompute one product's class (after a save). Returns true when it changed. */
    public function refresh(int $productId): bool
    {
        return $this->apply($this->rows([$productId])) > 0;
    }

    /**
     * Recompute every product (`catalog:warm`, and the deploy). Only rows whose class changed are written.
     *
     * @return array{changed: int, hidden: int, restricted: int}
     */
    public function refreshAll(): array
    {
        $changed = 0;
        DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->select('id')
            ->chunkById(1000, function ($chunk) use (&$changed): void {
                $ids = [];
                foreach ($chunk as $r) {
                    $ids[] = Row::int($r, 'id');
                }
                $changed += $this->apply($this->rows($ids));
            });

        return [
            'changed' => $changed,
            'hidden' => DB::table('catalog_products')->whereNull('deleted_at')->where('search_class', self::HIDDEN)->count(),
            'restricted' => DB::table('catalog_products')->whereNull('deleted_at')->where('search_class', self::RESTRICTED)->count(),
        ];
    }

    /**
     * Why the rule matches one product, for the product form (empty = it does not).
     *
     * @return list<string>
     */
    public function reasonsFor(int $productId): array
    {
        $row = $this->rows([$productId])[0] ?? null;

        return $row === null ? [] : self::reasons($row['brand'], $row['price'], $row['en'], $row['ar'], $this->brandNames(), $row['family']);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, brand: string, price: float, en: string, ar: string, family: string, class: ?string}>
     */
    private function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('catalog_products as p')
            ->join('catalog_brands as b', 'b.id', '=', 'p.brand_id')
            ->whereIn('p.id', $ids)
            ->select(['p.id', 'b.slug', 'p.family', 'p.selling_price', 'p.sale_price', 'p.search_class'])
            ->selectRaw("(SELECT t.title FROM catalog_product_translations t WHERE t.product_id = p.id AND t.locale = 'en' LIMIT 1) AS title_en")
            ->selectRaw("(SELECT t.title FROM catalog_product_translations t WHERE t.product_id = p.id AND t.locale = 'ar' LIMIT 1) AS title_ar")
            ->get();
        foreach ($rows as $r) {
            $selling = (float) Row::money($r, 'selling_price');
            $sale = Row::nstr($r, 'sale_price') === null ? 0.0 : (float) Row::money($r, 'sale_price');
            // the price a shopper pays: the sale when it is a valid one (0 < sale < selling), else the selling price
            $price = $sale > 0 && $sale < $selling ? $sale : $selling;
            $out[] = [
                'id' => Row::int($r, 'id'), 'brand' => Row::str($r, 'slug'), 'price' => $price,
                'en' => Row::nstr($r, 'title_en') ?? '', 'ar' => Row::nstr($r, 'title_ar') ?? '', 'family' => Row::str($r, 'family'),
                'class' => Row::nstr($r, 'search_class'),
            ];
        }

        return $out;
    }

    /** @param  list<array{id: int, brand: string, price: float, en: string, ar: string, family: string, class: ?string}>  $rows */
    private function apply(array $rows): int
    {
        $changed = 0;
        $names = $this->brandNames();
        foreach ($rows as $row) {
            $class = self::classOf($row['brand'], self::reasons($row['brand'], $row['price'], $row['en'], $row['ar'], $names, $row['family']));
            if ($class !== $row['class']) {
                DB::table('catalog_products')->where('id', $row['id'])->update(['search_class' => $class]);
                $changed++;
            }
        }

        return $changed;
    }

    /** @return list<string> */
    private function brandNames(): array
    {
        if ($this->brandNames === null) {
            $this->brandNames = [];
            foreach (DB::table('catalog_brand_translations')->where('locale', 'en')->pluck('name') as $name) {
                $n = mb_strtolower(trim(is_string($name) ? $name : ''));
                // "Generic" names no maker; a two-letter name would match the start of any word
                if (mb_strlen($n) > 2 && $n !== 'generic') {
                    $this->brandNames[] = $n;
                }
            }
        }

        return $this->brandNames;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }
}
