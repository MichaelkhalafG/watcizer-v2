<?php

namespace App\Compat;

use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `all_product`, `all_product_image`, `all_product_rating`, `show_shipping_city` in the legacy
 * shapes (DetailsProductController / OrderController@ShowShippingCity), built from the clean
 * tables and the shared tables.
 */
final class CompatCatalog
{
    /** Relation-level translations as the legacy eager load orders them (index order = locale asc). */
    private const RELATION_ORDER = ['ar', 'en'];

    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly CompatProducts $products,
        private readonly int $storefrontId,
    ) {}

    /** @return list<array<string, mixed>> */
    public function allProduct(string $appLocale): array
    {
        $ttl = config()->integer('compat.ttl.all_product');

        /** @var list<array<string, mixed>> */
        return $this->cache->remember($this->storefrontId, 'compat_all_product', $appLocale, $ttl, fn () => $this->buildAllProduct($appLocale));
    }

    /**
     * Rebuild and store the whole catalogue now, and return it (the warm-up; see `CompatListing::warm`).
     *
     * @return list<array<string, mixed>>
     */
    public function refreshAllProduct(string $appLocale): array
    {
        return $this->cache->refresh($this->storefrontId, 'compat_all_product', $appLocale, config()->integer('compat.ttl.all_product'), fn () => $this->buildAllProduct($appLocale));
    }

    /**
     * The `all_product` rows of just these products, read LIVE — no cache. A listing page needs 24
     * rows; reading them out of the cached whole catalogue cost 40–67 ms warm and ~400 ms cold on
     * the live host (2026-09-28), while these few indexed queries cost a few ms and are never stale.
     * Same builder as `allProduct`, so a row is identical either way (CatalogCardsLiveTest).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function productRows(string $appLocale, array $ids): array
    {
        return $ids === [] ? [] : $this->buildAllProduct($appLocale, $ids);
    }

    /**
     * @param  list<int>|null  $only  these products only (null = the whole catalogue)
     * @return list<array<string, mixed>>
     */
    private function buildAllProduct(string $appLocale, ?array $only = null): array
    {
        $query = $this->products->query()->orderBy('p.id');
        if ($only !== null) {
            $query->whereIn('p.id', $only);
        }
        $rows = $query->get();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $translations = $this->products->translations($ids);
        $pivots = $this->products->pivots($ids);
        $placements = $this->products->placements($ids, $this->categories);

        $out = [];
        foreach ($rows as $row) {
            $id = Row::int($row, 'id');
            $place = $placements[$id] ?? ['type' => null, 'sub' => null];
            $tr = $translations[$id] ?? [];
            $current = $tr[$appLocale] ?? null;
            $piv = $pivots[$id] ?? ['features' => [], 'genders' => [], 'dial' => [], 'band' => []];

            $r = [
                'id' => $id,
                'category_type_id' => $place['type'] === null ? null : CompatCategories::legacyIdOf($place['type']),
                'brand_id' => Row::int($row, 'brand_id'),
                'grade_id' => Row::nint($row, 'grade_id'),
                'sub_type_id' => $place['sub'] === null ? null : CompatCategories::legacyIdOf($place['sub']),
            ];
            foreach (CompatProducts::WATCH_COLUMNS as $col) {
                $r[$col] = CompatProducts::watch($row, $col);
            }
            $r['image'] = LegacyJson::legacyImage(Row::nstr($row, 'cover_path'), 'Product') ?? '';
            $r['warranty_years'] = CompatProducts::warranty($row);
            $r['interchangeable_dial'] = CompatProducts::watch($row, 'interchangeable_dial');
            $r['interchangeable_strap'] = CompatProducts::watch($row, 'interchangeable_strap');
            $r['average_rate'] = Row::nstr($row, 'rating_avg');
            $r['selling_price'] = Row::nstr($row, 'selling_price');
            $r['sale_price_after_discount'] = Row::nstr($row, 'sale_price');
            $r['percentage_discount'] = CompatProducts::percentageDiscount($row);
            $r['stock'] = Row::int($row, 'stock_express');
            $r['market_stock'] = Row::int($row, 'stock_market');
            $r['search_keywords'] = Row::nstr($row, 'search_keywords');
            $r['watch_box'] = CompatProducts::watch($row, 'watch_box');
            $r['active'] = Row::int($row, 'is_active');
            $r['created_at'] = LegacyJson::ts(Row::nstr($row, 'created_at'));
            $r['updated_at'] = LegacyJson::ts(Row::nstr($row, 'updated_at'));
            // astrotomic appends the current-locale translated attributes (null when the locale row is missing).
            $r['product_title'] = $current['title'] ?? null;
            $r['model_name'] = $current['model_name'] ?? null;
            $r['country'] = $current['country'] ?? null;
            $r['stone'] = $current['stone'] ?? null;
            $r['long_description'] = $current['long_description'] ?? null;
            $r['short_description'] = $current['short_description'] ?? null;
            $r['feature'] = $this->relationRows('features', $piv['features'], $id, 'feature_id', $appLocale);
            $r['gender'] = $this->relationRows('genders', $piv['genders'], $id, 'gender_id', $appLocale);
            $r['dial_color'] = $this->relationRows('colors', $piv['dial'], $id, 'color_id', $appLocale);
            $r['band_color'] = $this->relationRows('colors', $piv['band'], $id, 'color_id', $appLocale);
            $r['translations'] = self::productTranslationRows($id, $tr);
            $out[] = $r;
        }

        return $out;
    }

    /** Gender order in the menu, the sidebar and the chip strip; any other gender follows by name. */
    private const GENDER_ORDER = ['Men', 'Women', 'Unisex', 'Kids', 'Boys', 'Girls'];

    /**
     * What the storefront's header menu needs to know about the catalogue (C-1 stage 2, 2026-09-27),
     * so it no longer scans every product in the browser:
     *
     *  - `brand_ids`: brands with at least one product (no dead brand links);
     *  - `sub_types_by_category`: category type id → the sub-type ids its products are in;
     *  - `brands_by_category`: category type id → the brand ids with products in it;
     *  - `genders`: every gender a product carries, with both names.
     *
     * Derived from the `all_product` rows themselves, so "has products" means exactly what the
     * listing shows. Ids are the legacy ids the storefront's links and filters use. There is no
     * legacy counterpart: this is a storefront-only read, not a compat shape.
     *
     * @return array{brand_ids: list<int>, sub_types_by_category: array<string, list<int>>, brands_by_category: array<string, list<int>>, genders: list<array{en: string, ar: string}>}
     */
    public function nav(): array
    {
        $ttl = config()->integer('compat.ttl.all_product');

        /** @var array{brand_ids: list<int>, sub_types_by_category: array<string, list<int>>, brands_by_category: array<string, list<int>>, genders: list<array{en: string, ar: string}>} */
        return $this->cache->remember($this->storefrontId, 'compat_nav', '', $ttl, fn () => $this->buildNav());
    }

    /** @return array{brand_ids: list<int>, sub_types_by_category: array<string, list<int>>, brands_by_category: array<string, list<int>>, genders: list<array{en: string, ar: string}>} */
    private function buildNav(): array
    {
        $brands = [];
        $subTypes = [];
        $brandsByCategory = [];
        $genders = [];
        foreach ($this->allProduct(config()->string('compat.pinned_locale')) as $row) {
            $brand = is_int($row['brand_id'] ?? null) ? $row['brand_id'] : null;
            $type = is_int($row['category_type_id'] ?? null) ? $row['category_type_id'] : null;
            $sub = is_int($row['sub_type_id'] ?? null) ? $row['sub_type_id'] : null;
            if ($brand !== null) {
                $brands[$brand] = true;
            }
            if ($type !== null && $sub !== null) {
                $subTypes[$type][$sub] = true;
            }
            if ($type !== null && $brand !== null) {
                $brandsByCategory[$type][$brand] = true;
            }
            foreach (is_array($row['gender'] ?? null) ? $row['gender'] : [] as $gender) {
                $names = ['en' => '', 'ar' => ''];
                foreach (is_array($gender) && is_array($gender['translations'] ?? null) ? $gender['translations'] : [] as $t) {
                    if (is_array($t) && is_string($t['locale'] ?? null) && is_string($t['gender_name'] ?? null) && array_key_exists($t['locale'], $names)) {
                        $names[$t['locale']] = $t['gender_name'];
                    }
                }
                if ($names['en'] !== '' && ! isset($genders[$names['en']])) {
                    $genders[$names['en']] = ['en' => $names['en'], 'ar' => $names['ar'] !== '' ? $names['ar'] : $names['en']];
                }
            }
        }

        $ids = static function (array $set): array {
            $out = array_map('intval', array_keys($set));
            sort($out);

            return $out;
        };
        $byCategory = static function (array $map) use ($ids): array {
            ksort($map);
            $out = [];
            foreach ($map as $type => $set) {
                $out[(string) $type] = $ids(is_array($set) ? $set : []);
            }

            return $out;
        };
        uksort($genders, static function (string $a, string $b): int {
            $ia = array_search($a, self::GENDER_ORDER, true);
            $ib = array_search($b, self::GENDER_ORDER, true);

            return [$ia === false ? 99 : $ia, $a] <=> [$ib === false ? 99 : $ib, $b];
        });

        return [
            'brand_ids' => $ids($brands),
            'sub_types_by_category' => $byCategory($subTypes),
            'brands_by_category' => $byCategory($brandsByCategory),
            'genders' => array_values($genders),
        ];
    }

    /**
     * Legacy `product_translations` rows for one product, locale ascending (the legacy index order).
     *
     * @param  array<string, array<string, mixed>>  $tr
     * @return list<array<string, mixed>>
     */
    public static function productTranslationRows(int $productId, array $tr): array
    {
        $rows = [];
        foreach (['ar', 'en'] as $locale) {
            $t = $tr[$locale] ?? null;
            if ($t === null) {
                continue;
            }
            $rows[] = [
                'id' => $t['id'],
                'locale' => $locale,
                'product_id' => $productId,
                'product_title' => $t['title'],
                'model_name' => $t['model_name'],
                'country' => $t['country'],
                'stone' => $t['stone'],
                'long_description' => $t['long_description'],
                'short_description' => $t['short_description'],
            ];
        }

        return $rows;
    }

    /**
     * belongsToMany rows as the legacy models serialise them: columns, appended name, pivot, translations.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function relationRows(string $legacyTable, array $ids, int $productId, string $pivotFk, string $appLocale): array
    {
        $out = [];
        foreach ($ids as $id) {
            $row = $this->names->legacyRow($legacyTable, $id, $appLocale, self::RELATION_ORDER);
            if ($row === null) {
                continue;
            }
            $translations = $row['translations'];
            unset($row['translations']);
            $row['pivot'] = ['product_id' => $productId, $pivotFk => $id];
            $row['translations'] = $translations;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * `ProductImage::all()` — the gallery rows (legacy `product_images` = clean rows with is_cover = 0).
     * Clean `sort` is legacy sort + 1 (step 10); the legacy `is_cover` flag was dropped (X-01) → false.
     *
     * @return list<array<string, mixed>>
     */
    public function allProductImage(): array
    {
        $ttl = config()->integer('compat.ttl.all_product_image');

        /** @var list<array<string, mixed>> */
        return $this->cache->remember($this->storefrontId, 'compat_all_product_image', '', $ttl, fn (): array => $this->buildProductImages(null));
    }

    /**
     * The gallery rows of just these products, read live (see `productRows`).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function productImages(array $ids): array
    {
        return $ids === [] ? [] : $this->buildProductImages($ids);
    }

    /**
     * @param  list<int>|null  $only
     * @return list<array<string, mixed>>
     */
    private function buildProductImages(?array $only): array
    {
        $out = [];
        $query = DB::table('catalog_product_images')
            ->select(['id', 'product_id', 'path', 'sort', 'alt_ar', 'alt_en', 'created_at', 'updated_at'])
            ->where('is_cover', 0)
            ->orderBy('id');
        if ($only !== null) {
            $query->whereIn('product_id', $only);
        }
        foreach ($query->get() as $row) {
            $out[] = [
                'id' => Row::int($row, 'id'),
                'product_id' => Row::int($row, 'product_id'),
                'image' => LegacyJson::legacyImage(Row::str($row, 'path'), 'Product_image') ?? '',
                'is_cover' => false,
                'sort' => max(0, Row::int($row, 'sort') - 1),
                'alt_ar' => Row::nstr($row, 'alt_ar'),
                'alt_en' => Row::nstr($row, 'alt_en'),
                'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
            ];
        }

        return $out;
    }

    /**
     * `ProductRating::all()` — shared table, read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function allProductRating(): array
    {
        return $this->buildProductRatings(null);
    }

    /**
     * The ratings of just these products (see `productRows`).
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function productRatings(array $ids): array
    {
        return $ids === [] ? [] : $this->buildProductRatings($ids);
    }

    /**
     * @param  list<int>|null  $only
     * @return list<array<string, mixed>>
     */
    private function buildProductRatings(?array $only): array
    {
        $out = [];
        $query = DB::connection('legacy')->table('product_ratings')
            ->select(['id', 'user_id', 'product_id', 'rating', 'comment', 'created_at', 'updated_at'])
            ->orderBy('id');
        if ($only !== null) {
            $query->whereIn('product_id', $only);
        }
        foreach ($query->get() as $row) {
            $out[] = [
                'id' => Row::int($row, 'id'),
                'user_id' => Row::int($row, 'user_id'),
                'product_id' => Row::int($row, 'product_id'),
                'rating' => Row::int($row, 'rating'),
                'comment' => Row::nstr($row, 'comment'),
                'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
            ];
        }

        return $out;
    }

    /**
     * `ShippingCity::with('translations')->get()` — shared table, read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function shippingCities(string $appLocale): array
    {
        $translations = [];
        $rows = DB::connection('legacy')->table('shipping_city_translations')
            ->select(['id', 'locale', 'shipping_city_id', 'city_name'])
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $translations[Row::int($row, 'shipping_city_id')][] = [
                'id' => Row::int($row, 'id'),
                'locale' => Row::str($row, 'locale'),
                'shipping_city_id' => Row::int($row, 'shipping_city_id'),
                'city_name' => Row::nstr($row, 'city_name'),
            ];
        }
        $out = [];
        foreach (DB::connection('legacy')->table('shipping_cities')->select(['id', 'shipping_cost', 'created_at', 'updated_at'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $name = null;
            foreach ($translations[$id] ?? [] as $t) {
                if ($t['locale'] === $appLocale) {
                    $name = $t['city_name'];
                }
            }
            $out[] = [
                'id' => $id,
                'shipping_cost' => Row::nstr($row, 'shipping_cost'),
                'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
                'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
                'city_name' => $name,
                'translations' => $translations[$id] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    public function rowsById(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach ($this->products->query()->whereIn('p.id', $ids)->orderBy('p.id')->get() as $row) {
            $out[Row::int($row, 'id')] = $row;
        }

        return $out;
    }
}
