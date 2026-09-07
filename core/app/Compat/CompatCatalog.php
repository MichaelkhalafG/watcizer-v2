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

    /** @return list<array<string, mixed>> */
    private function buildAllProduct(string $appLocale): array
    {
        $rows = $this->products->query()->orderBy('p.id')->get();
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
            $r['image'] = LegacyJson::basename(Row::nstr($row, 'cover_path')) ?? '';
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
        return $this->cache->remember($this->storefrontId, 'compat_all_product_image', '', $ttl, function (): array {
            $out = [];
            $rows = DB::table('catalog_product_images')
                ->select(['id', 'product_id', 'path', 'sort', 'alt_ar', 'alt_en', 'created_at', 'updated_at'])
                ->where('is_cover', 0)
                ->orderBy('id')
                ->get();
            foreach ($rows as $row) {
                $out[] = [
                    'id' => Row::int($row, 'id'),
                    'product_id' => Row::int($row, 'product_id'),
                    'image' => LegacyJson::basename(Row::str($row, 'path')) ?? '',
                    'is_cover' => false,
                    'sort' => max(0, Row::int($row, 'sort') - 1),
                    'alt_ar' => Row::nstr($row, 'alt_ar'),
                    'alt_en' => Row::nstr($row, 'alt_en'),
                    'created_at' => LegacyJson::ts(Row::nstr($row, 'created_at')),
                    'updated_at' => LegacyJson::ts(Row::nstr($row, 'updated_at')),
                ];
            }

            return $out;
        });
    }

    /**
     * `ProductRating::all()` — shared table, read-only.
     *
     * @return list<array<string, mixed>>
     */
    public function allProductRating(): array
    {
        $out = [];
        $rows = DB::connection('legacy')->table('product_ratings')
            ->select(['id', 'user_id', 'product_id', 'rating', 'comment', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
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
