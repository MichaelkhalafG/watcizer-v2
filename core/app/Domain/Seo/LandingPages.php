<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Catalog\SearchExposure;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
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
 * gate in both languages; until then it is served with noindex and left out of the sitemaps. Both follow the catalogue
 * on their own: a page that drops under 6 leaves, one that reaches 6 appears.
 *
 * The list is built from three grouped queries over the storefront's placements (never a query per page), cached under
 * the storefront's build lock with the last good copy served meanwhile, and warmed by catalog:warm.
 */
final class LandingPages
{
    public const MIN_LIVE = 6;

    /** catalog_products.family → the address segment. Only these families get landing pages. */
    public const FAMILIES = ['watch' => 'watches', 'bag' => 'bags', 'wallet' => 'wallets', 'perfume' => 'perfumes'];

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * The storefront's landing pages, keyed by their key (`tommy-hilfiger/men`, `guess/bags/women`).
     *
     * @return array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, products: int, indexable: bool}>
     */
    public function all(int $storefrontId): array
    {
        /** @var array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, products: int, indexable: bool}> */
        return $this->cache->rememberGuarded($storefrontId, 'landing_pages', '', config()->integer('storefront.ttl.count'),
            fn (): array => $this->build($storefrontId));
    }

    /**
     * One landing page by key, or null when the storefront has no such page (now).
     *
     * @return array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, products: int, indexable: bool}|null
     */
    public function find(int $storefrontId, string $key): ?array
    {
        return $this->all($storefrontId)[$key] ?? null;
    }

    /**
     * The landing pages of one brand, in key order.
     *
     * @return list<array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, products: int, indexable: bool}>
     */
    public function ofBrand(int $storefrontId, string $brandSlug): array
    {
        return array_values(array_filter($this->all($storefrontId), fn (array $p): bool => $p['brand'] === $brandSlug));
    }

    public static function key(string $brand, ?string $family, ?string $gender): string
    {
        return implode('/', array_filter([$brand, $family, $gender], fn (?string $s): bool => $s !== null && $s !== ''));
    }

    /** @return array<string, array{key: string, path: string, brand: string, brand_id: int, family: ?string, gender: ?string, gender_id: ?int, products: int, indexable: bool}> */
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
        foreach ($live()->groupBy('p.brand_id')->selectRaw('p.brand_id AS b, COUNT(*) AS n')->get() as $r) {
            $byBrand[Row::int($r, 'b')] = Row::int($r, 'n');
        }
        $byFamily = [];
        foreach ($live()->whereIn('p.family', $families)->groupBy('p.brand_id', 'p.family')->selectRaw('p.brand_id AS b, p.family AS f, COUNT(*) AS n')->get() as $r) {
            $byFamily[Row::int($r, 'b')][Row::str($r, 'f')] = Row::int($r, 'n');
        }
        $byGender = [];
        $byBoth = [];
        foreach ($live()->join('catalog_product_gender as pg', 'pg.product_id', '=', 'sp.product_id')
            ->groupBy('p.brand_id', 'p.family', 'pg.gender_id')->selectRaw('p.brand_id AS b, p.family AS f, pg.gender_id AS g, COUNT(*) AS n')->get() as $r) {
            $b = Row::int($r, 'b');
            $g = Row::int($r, 'g');
            $byGender[$b][$g] = ($byGender[$b][$g] ?? 0) + Row::int($r, 'n');
            if (in_array(Row::str($r, 'f'), $families, true)) {
                $byBoth[$b][Row::str($r, 'f')][$g] = Row::int($r, 'n');
            }
        }

        $passed = $this->passedKeys($storefrontId);
        $out = [];
        $add = function (int $brandId, ?string $family, ?int $genderId, int $n) use (&$out, $brands, $genders, $passed): void {
            $brand = $brands[$brandId] ?? null;
            $gender = $genderId === null ? null : ($genders[$genderId] ?? null);
            if ($brand === null || ($genderId !== null && $gender === null)) {
                return;
            }
            $familySlug = $family === null ? null : self::FAMILIES[$family];
            $key = self::key($brand, $familySlug, $gender);
            $out[$key] = [
                'key' => $key, 'path' => '/brand/'.$key, 'brand' => $brand, 'brand_id' => $brandId, 'family' => $familySlug,
                'gender' => $gender, 'gender_id' => $genderId, 'products' => $n, 'indexable' => isset($passed[$key]),
            ];
        };
        foreach ($byBrand as $b => $total) {
            foreach ($byGender[$b] ?? [] as $g => $n) {
                if ($n >= self::MIN_LIVE && $n < $total) {
                    $add($b, null, $g, $n);
                }
            }
            foreach ($byFamily[$b] ?? [] as $f => $n) {
                if ($n >= self::MIN_LIVE && $n < $total) {
                    $add($b, $f, null, $n);
                }
            }
            foreach ($byBoth[$b] ?? [] as $f => $byG) {
                foreach ($byG as $g => $n) {
                    if ($n >= self::MIN_LIVE && $n < ($byFamily[$b][$f] ?? 0) && $n < ($byGender[$b][$g] ?? 0)) {
                        $add($b, $f, $g, $n);
                    }
                }
            }
        }
        ksort($out);

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
