<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Catalog\SearchExposure;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * LANDING PAGES (SEO Phase 2, 2026-11-14): fixed pages per brand × gender, brand × family and brand × family × gender —
 * `/brand/tommy-hilfiger/men`, `/brand/guess/bags`, `/brand/guess/bags/women` — on each storefront, in both languages.
 *
 * A page EXISTS only where people can shop it and it says something the brand page does not:
 *   - at least MIN_LIVE (6) LIVE products that search engines are shown (visible, active, not deleted, not hidden by
 *     SearchExposure; out of stock counts — the brief's STOCK RULE), counted with the SAME filters the page lists;
 *   - and it NARROWS its parent: a brand whose products are all men's has no `/men` page (it would be the brand page
 *     twice); a `/bags/women` page needs fewer products than both `/bags` and `/women`.
 * A page is INDEXABLE only once its own copy (page text `landing`, keyed `brand/family/gender`) has passed the quality
 * gate in both languages AND at least MIN_IN_STOCK (3) of its products are in stock (Michael, 2026-11-15: a page people
 * cannot buy from is not one to send searchers to); until then it is served with noindex and left out of the sitemaps.
 * All of it follows the catalogue on its own, both ways: a page that drops under 6 leaves, one that reaches 6 appears;
 * one whose stock falls under 3 goes noindex, and is indexed again when 3 are back (the next catalog:warm).
 *
 * The list is built from three grouped queries over the storefront's placements (never a query per page), cached under
 * the storefront's build lock with the last good copy served meanwhile, and warmed by catalog:warm.
 *
 * BY DIAL COLOUR AND BY STRAP (Michael, 2026-11-17; the 2026-11-16 comparison's structural gap): a brand's watches by
 * their dial colour — `/brand/tommy-hilfiger/watches/blue-dial` — and by their strap — `/brand/guess/watches/leather-strap`
 * — by the SAME rules: 6+ LIVE products that narrow the brand's watches page, indexable once their copy passed the gate
 * in both languages and 3 are in stock. Counted with the listing's own filters (the dial colour role; the material
 * filter, band or case), which is why a strap page exists only for a STRAP material (STRAP_MATERIALS — leather,
 * silicone, rubber, nylon, mesh steel: never a case's material, so "band or case" is the band). The address segment is
 * the colour's / material's English name as the shop slugs it (src/utils/slugs.js toSlug), so both sides agree.
 * Lines (a brand's collections) have no field in the catalogue and get no pages.
 */
final class LandingPages
{
    public const MIN_LIVE = 6;

    /** In-stock products a page with passed copy needs before it is indexed. */
    public const MIN_IN_STOCK = 3;

    /** catalog_products.family → the address segment. Only these families get landing pages. */
    public const FAMILIES = ['watch' => 'watches', 'bag' => 'bags', 'wallet' => 'wallets', 'perfume' => 'perfumes'];

    /** The materials a watch's STRAP page may be about (their English names' slugs): never a case's material. */
    public const STRAP_MATERIALS = ['leather', 'silicone', 'rubber', 'nylon', 'mesh-steel'];

    /** The address segment's ending for each kind (blue-dial, leather-strap). */
    public const DIAL_SUFFIX = '-dial';

    public const STRAP_SUFFIX = '-strap';

    /** A name as the shop slugs it (src/utils/slugs.js toSlug) — the colour's and the material's address segment. */
    public static function slug(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace("/['’`&]/u", '', $s);
        $s = (string) preg_replace('/[^a-z0-9\s-]/', '', $s);
        $s = (string) preg_replace('/\s+/', '-', $s);
        $s = (string) preg_replace('/-+/', '-', $s);

        return trim($s, '-');
    }

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * The storefront's landing pages, keyed by their key (`tommy-hilfiger/men`, `guess/bags/women`).
     *
     * @return array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}>
     */
    public function all(int $storefrontId): array
    {
        /** @var array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}> */
        return $this->cache->rememberGuarded($storefrontId, 'landing_pages', '', config()->integer('storefront.ttl.count'),
            fn (): array => $this->build($storefrontId));
    }

    /**
     * One landing page by key, or null when the storefront has no such page (now).
     *
     * @return array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}|null
     */
    public function find(int $storefrontId, string $key): ?array
    {
        return $this->all($storefrontId)[$key] ?? null;
    }

    /**
     * The landing pages of one brand, in key order.
     *
     * @return list<array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}>
     */
    public function ofBrand(int $storefrontId, string $brandSlug): array
    {
        return array_values(array_filter($this->all($storefrontId), fn (array $p): bool => $p['brand'] === $brandSlug));
    }

    public static function key(string $brand, ?string $family, ?string $gender): string
    {
        return implode('/', array_filter([$brand, $family, $gender], fn (?string $s): bool => $s !== null && $s !== ''));
    }

    /** @return array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}> */
    private function build(int $storefrontId): array
    {
        $genders = [];
        foreach (DB::table('catalog_gender_translations')->where('locale', 'en')->get(['gender_id', 'name']) as $g) {
            $genders[Row::int($g, 'gender_id')] = strtolower(trim(Row::str($g, 'name')));
        }
        $brands = [];
        foreach (DB::table('catalog_brands')->whereNotNull('slug')->get(['id', 'slug']) as $b) {
            $brands[Row::int($b, 'id')] = Row::str($b, 'slug');
        }
        $live = fn () => ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $storefrontId)
            ->whereNotNull('p.brand_id')
            ->whereRaw('COALESCE('.SearchExposure::effectiveSql('p').", '') <> ?", [SearchExposure::HIDDEN]);
        $families = array_keys(self::FAMILIES);

        $byBrand = [];
        $byBrand = [];
        foreach ($live()->groupBy('p.brand_id')->selectRaw('p.brand_id AS b, COUNT(*) AS n')->get() as $r) {
            $byBrand[Row::int($r, 'b')] = Row::int($r, 'n');
        }
        // the page counts come with their in-stock part (s), from the same grouped queries
        $byFamily = [];
        $stockFamily = [];
        foreach ($live()->whereIn('p.family', $families)->groupBy('p.brand_id', 'p.family')
            ->selectRaw('p.brand_id AS b, p.family AS f, COUNT(*) AS n, SUM(p.in_stock = 1) AS s')->get() as $r) {
            $byFamily[Row::int($r, 'b')][Row::str($r, 'f')] = Row::int($r, 'n');
            $stockFamily[Row::int($r, 'b')][Row::str($r, 'f')] = Row::int($r, 's');
        }
        $byGender = [];
        $stockGender = [];
        $byBoth = [];
        $stockBoth = [];
        foreach ($live()->join('catalog_product_gender as pg', 'pg.product_id', '=', 'sp.product_id')
            ->groupBy('p.brand_id', 'p.family', 'pg.gender_id')
            ->selectRaw('p.brand_id AS b, p.family AS f, pg.gender_id AS g, COUNT(*) AS n, SUM(p.in_stock = 1) AS s')->get() as $r) {
            $b = Row::int($r, 'b');
            $g = Row::int($r, 'g');
            $byGender[$b][$g] = ($byGender[$b][$g] ?? 0) + Row::int($r, 'n');
            $stockGender[$b][$g] = ($stockGender[$b][$g] ?? 0) + Row::int($r, 's');
            if (in_array(Row::str($r, 'f'), $families, true)) {
                $byBoth[$b][Row::str($r, 'f')][$g] = Row::int($r, 'n');
                $stockBoth[$b][Row::str($r, 'f')][$g] = Row::int($r, 's');
            }
        }

        // BY DIAL COLOUR AND BY STRAP (2026-11-17): the brand's watches, counted with the listing's own filters
        $byDial = [];
        $stockDial = [];
        foreach ($live()->where('p.family', 'watch')->join('catalog_product_color as pc', fn (JoinClause $j) => $j->on('pc.product_id', '=', 'sp.product_id')->where('pc.role', '=', 'dial'))
            ->groupBy('p.brand_id', 'pc.color_id')->selectRaw('p.brand_id AS b, pc.color_id AS c, COUNT(DISTINCT sp.product_id) AS n, COUNT(DISTINCT CASE WHEN p.in_stock = 1 THEN sp.product_id END) AS s')->get() as $r) {
            $byDial[Row::int($r, 'b')][Row::int($r, 'c')] = Row::int($r, 'n');
            $stockDial[Row::int($r, 'b')][Row::int($r, 'c')] = Row::int($r, 's');
        }
        $colorNames = self::names('catalog_color_translations', 'color_id');
        $materialNames = self::names('catalog_material_translations', 'material_id');
        $strapIds = array_keys(array_filter($materialNames, fn (array $n): bool => in_array(self::slug($n['en']), self::STRAP_MATERIALS, true)));
        $byStrap = [];
        $stockStrap = [];
        if ($strapIds !== []) {
            foreach ($live()->where('p.family', 'watch')->join('catalog_product_watch_specs as ws', 'ws.product_id', '=', 'sp.product_id')
                ->join('catalog_materials as m', fn (JoinClause $j) => $j->on('m.id', '=', 'ws.band_material_id')->orOn('m.id', '=', 'ws.case_material_id'))
                ->whereIn('m.id', $strapIds)
                ->groupBy('p.brand_id', 'm.id')->selectRaw('p.brand_id AS b, m.id AS m, COUNT(DISTINCT sp.product_id) AS n, COUNT(DISTINCT CASE WHEN p.in_stock = 1 THEN sp.product_id END) AS s')->get() as $r) {
                $byStrap[Row::int($r, 'b')][Row::int($r, 'm')] = Row::int($r, 'n');
                $stockStrap[Row::int($r, 'b')][Row::int($r, 'm')] = Row::int($r, 's');
            }
        }

        $passed = $this->passedKeys($storefrontId);
        $out = [];
        $add = function (int $brandId, ?string $family, ?int $genderId, int $n, int $inStock) use (&$out, $brands, $genders, $passed): void {
            $brand = $brands[$brandId] ?? null;
            $gender = $genderId === null ? null : ($genders[$genderId] ?? null);
            if ($brand === null || ($genderId !== null && $gender === null)) {
                return;
            }
            $familySlug = $family === null ? null : self::FAMILIES[$family];
            $key = self::key($brand, $familySlug, $gender);
            $out[$key] = [
                'key' => $key, 'path' => '/brand/'.$key, 'brand' => $brand, 'brand_id' => $brandId, 'family' => $familySlug,
                'gender' => $gender, 'gender_id' => $genderId, 'dial' => null, 'strap' => null, 'name' => null, 'products' => $n, 'in_stock' => $inStock,
                'indexable' => isset($passed[$key]) && $inStock >= self::MIN_IN_STOCK,
            ];
        };
        // a dial-colour or strap page of a brand's watches: `kind` dial|strap, the colour's / material's slug and names
        /** @param array{en: string, ar: string} $name */
        $addWatch = function (int $brandId, string $kind, string $slug, array $name, int $n, int $inStock) use (&$out, $brands, $passed): void {
            $brand = $brands[$brandId] ?? null;
            if ($brand === null || $slug === '') {
                return;
            }
            $key = self::key($brand, self::FAMILIES['watch'], $slug.($kind === 'dial' ? self::DIAL_SUFFIX : self::STRAP_SUFFIX));
            $out[$key] = [
                'key' => $key, 'path' => '/brand/'.$key, 'brand' => $brand, 'brand_id' => $brandId, 'family' => self::FAMILIES['watch'],
                'gender' => null, 'gender_id' => null, 'dial' => $kind === 'dial' ? $slug : null, 'strap' => $kind === 'strap' ? $slug : null,
                'name' => $name, 'products' => $n, 'in_stock' => $inStock,
                'indexable' => isset($passed[$key]) && $inStock >= self::MIN_IN_STOCK,
            ];
        };
        foreach ($byBrand as $b => $total) {
            foreach ($byGender[$b] ?? [] as $g => $n) {
                if ($n >= self::MIN_LIVE && $n < $total) {
                    $add($b, null, $g, $n, $stockGender[$b][$g] ?? 0);
                }
            }
            foreach ($byFamily[$b] ?? [] as $f => $n) {
                if ($n >= self::MIN_LIVE && $n < $total) {
                    $add($b, $f, null, $n, $stockFamily[$b][$f] ?? 0);
                }
            }
            $watches = $byFamily[$b]['watch'] ?? 0;
            foreach ($byDial[$b] ?? [] as $c => $n) {
                if ($n >= self::MIN_LIVE && $n < $watches && isset($colorNames[$c])) {
                    $addWatch($b, 'dial', self::slug($colorNames[$c]['en']), $colorNames[$c], $n, $stockDial[$b][$c] ?? 0);
                }
            }
            foreach ($byStrap[$b] ?? [] as $m => $n) {
                if ($n >= self::MIN_LIVE && $n < $watches && isset($materialNames[$m])) {
                    $addWatch($b, 'strap', self::slug($materialNames[$m]['en']), $materialNames[$m], $n, $stockStrap[$b][$m] ?? 0);
                }
            }
            foreach ($byBoth[$b] ?? [] as $f => $byG) {
                foreach ($byG as $g => $n) {
                    if ($n >= self::MIN_LIVE && $n < ($byFamily[$b][$f] ?? 0) && $n < ($byGender[$b][$g] ?? 0)) {
                        $add($b, $f, $g, $n, $stockBoth[$b][$f][$g] ?? 0);
                    }
                }
            }
        }
        ksort($out);

        /** @var array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, dial: ?string, strap: ?string, name: ?array{en: string, ar: string}, products: int, in_stock: int, indexable: bool}> $out */
        return $out;
    }

    /**
     * A lookup's names in both languages, by id (the colours', the materials').
     *
     * @return array<int, array{en: string, ar: string}>
     */
    private static function names(string $table, string $fk): array
    {
        $en = [];
        $ar = [];
        foreach (DB::table($table)->whereIn('locale', ['en', 'ar'])->get([$fk, 'locale', 'name']) as $r) {
            if (Row::str($r, 'locale') === 'en') {
                $en[Row::int($r, $fk)] = trim(Row::str($r, 'name'));
            } else {
                $ar[Row::int($r, $fk)] = trim(Row::str($r, 'name'));
            }
        }
        $out = [];
        foreach ($en as $id => $name) {
            if ($name !== '') {
                $out[$id] = ['en' => $name, 'ar' => ($ar[$id] ?? '') !== '' ? $ar[$id] : $name];
            }
        }

        return $out;
    }

    /**
     * The landing keys whose copy passed the quality gate in BOTH languages.
     *
     * @return array<string, true>
     */
    private function passedKeys(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('core_seo_texts')->where('storefront_id', $storefrontId)->where('subject_type', 'landing')
            ->where('gate_status', 'passed')->groupBy('subject_key')->havingRaw('COUNT(DISTINCT locale) = 2')
            ->pluck('subject_key') as $key) {
            $out[Coerce::str($key)] = true;
        }

        return $out;
    }
}
