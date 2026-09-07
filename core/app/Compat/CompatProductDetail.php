<?php

namespace App\Compat;

use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `GET products/{id}` and `GET products/by-name/{name}` — the legacy ProductResource (99 keys,
 * in its exact order) plus `related[]` in the ProductListResource shape, built from the clean
 * tables. Ratings and reviewer names come from the shared tables (read-only).
 */
final class CompatProductDetail
{
    public function __construct(
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly CompatProducts $products,
        private readonly int $storefrontId,
    ) {}

    /** @return array{product: array<string, mixed>, related: list<array<string, mixed>>}|null */
    public function byId(int $id): ?array
    {
        $row = $this->products->query()->where('p.id', $id)->first();

        return $row === null ? null : $this->payload($row);
    }

    /**
     * Exact match on the English title; ties resolved like the legacy firstOrFail (lowest id).
     *
     * @return array{product: array<string, mixed>, related: list<array<string, mixed>>}|null
     */
    public function byName(string $name): ?array
    {
        $productId = DB::table('catalog_product_translations')
            ->where('locale', 'en')
            ->where('title', $name)
            ->orderBy('product_id')
            ->value('product_id');
        if (! is_int($productId) && ! (is_string($productId) && ctype_digit($productId))) {
            return null;
        }

        return $this->byId((int) $productId);
    }

    /** @return array{product: array<string, mixed>, related: list<array<string, mixed>>} */
    private function payload(stdClass $row): array
    {
        $id = Row::int($row, 'id');
        $tr = $this->products->translations([$id])[$id] ?? [];
        $piv = $this->products->pivots([$id])[$id] ?? ['features' => [], 'genders' => [], 'dial' => [], 'band' => []];
        $place = $this->products->placements([$id], $this->categories)[$id] ?? ['type' => null, 'sub' => null];
        $en = $tr['en'] ?? [];
        $ar = $tr['ar'] ?? [];

        $ratings = $this->ratings($id);
        $ratingsCount = count($ratings['all']);
        $brandId = Row::int($row, 'brand_id');
        $gradeId = Row::nint($row, 'grade_id');
        $typeLegacyId = $place['type'] === null ? null : CompatCategories::legacyIdOf($place['type']);
        $subLegacyId = $place['sub'] === null ? null : CompatCategories::legacyIdOf($place['sub']);
        $stock = Row::int($row, 'stock_express');
        $market = Row::int($row, 'stock_market');
        $selling = Row::nstr($row, 'selling_price');
        $sale = Row::nstr($row, 'sale_price');
        $coverFile = LegacyJson::basename(Row::nstr($row, 'cover_path'));

        $images = [];
        if ($coverFile !== null) {
            $images[] = LegacyJson::imageUrl($coverFile);
        }
        foreach ($this->galleryFiles($id) as $file) {
            $images[] = LegacyJson::imageUrl($file, 'Product_image');
        }

        $w = fn (string $col): int|string|null => CompatProducts::watch($row, $col);
        $wInt = fn (string $col): ?int => Row::nint($row, 'ws_'.$col);
        $lookupName = fn (string $table, string $col, string $locale): ?string => $this->names->name($table, $wInt($col), $locale);
        $nodeName = fn (?array $node, string $locale): ?string => $node === null ? null : $this->categories->name($node, $locale);

        $specs = Row::nstr($row, 'specs');
        $extra = $specs === null ? null : json_decode($specs, true);

        $product = [
            'id' => $id,
            'name_en' => $en['title'] ?? null,
            'name_ar' => $ar['title'] ?? null,
            'slug' => Row::str($row, 'storefront_slug'),
            'description_en' => $en['long_description'] ?? null,
            'description_ar' => $ar['long_description'] ?? null,
            'price' => $selling === null ? null : (float) $selling,
            'sale_price' => $sale === null ? null : (float) $sale,
            'images' => $images,
            'brand' => [
                'id' => $brandId,
                'name_en' => $this->names->name('brands', $brandId, 'en'),
                'name_ar' => $this->names->name('brands', $brandId, 'ar'),
                'logo_url' => LegacyJson::imageUrl(Val::nstr($this->names->master('brands', $brandId) ?? [], 'image'), 'Brand'),
            ],
            'main_category' => null,
            'sub_type' => $place['sub'] === null ? null : ['id' => $subLegacyId, 'name_en' => $nodeName($place['sub'], 'en'), 'name_ar' => $nodeName($place['sub'], 'ar')],
            'gender' => $this->manyNamed('genders', $piv['genders']),
            'grade' => $this->named('grades', $gradeId),
            'dial_colors' => $this->manyNamed('colors', $piv['dial']),
            'band_colors' => $this->manyNamed('colors', $piv['band']),
            'features' => $this->manyNamed('features', $piv['features']),
            'in_stock' => $stock > 0 || $market > 0,
            'stock_quantity' => $stock + $market,
            'average_rating' => $ratingsCount > 0 ? round($ratings['avg'], 1) : null,
            'ratings_count' => $ratingsCount,
            'ratings' => $ratings['latest'],
            'product_title' => $en['title'] ?? null,
            'product_title_ar' => $ar['title'] ?? null,
            'selling_price' => $selling === null ? null : (float) $selling,
            'sale_price_after_discount' => $sale === null ? null : (float) $sale,
            'short_description' => $en['short_description'] ?? null,
            'short_description_ar' => $ar['short_description'] ?? null,
            'long_description' => $en['long_description'] ?? null,
            'long_description_ar' => $ar['long_description'] ?? null,
            'image' => LegacyJson::imageUrl($coverFile),
            'stock' => $stock,
            'market_stock' => $market,
            'brand_name' => $this->names->name('brands', $brandId, 'en'),
            'brand_name_ar' => $this->names->name('brands', $brandId, 'ar'),
            'sub_type_name' => $nodeName($place['sub'], 'en'),
            'sub_type_name_ar' => $nodeName($place['sub'], 'ar'),
            'dial_color' => $this->colorRows($piv['dial']),
            'band_color' => $this->colorRows($piv['band']),
            'feature' => array_map(fn (int $i) => ['id' => $i, 'name' => $this->names->name('features', $i, 'en'), 'name_ar' => $this->names->name('features', $i, 'ar')], $piv['features']),
            'water_resistance' => $w('water_resistance'),
            'case_thickness' => $w('case_thickness'),
            'band_length' => $w('band_length'),
            'model_number' => Row::nstr($row, 'model_number'),
            'watch_movement' => $lookupName('movement_types', 'watch_movement_id', 'en'),
            'watch_movement_ar' => $lookupName('movement_types', 'watch_movement_id', 'ar'),
            'case_shape' => $lookupName('shapes', 'case_shape_id', 'en'),
            'case_shape_ar' => $lookupName('shapes', 'case_shape_id', 'ar'),
            'band_material' => $lookupName('materials', 'band_material_id', 'en'),
            'band_material_ar' => $lookupName('materials', 'band_material_id', 'ar'),
            'glass_material' => $lookupName('materials', 'dial_glass_material_id', 'en'),
            'glass_material_ar' => $lookupName('materials', 'dial_glass_material_id', 'ar'),
            'case_material' => $lookupName('materials', 'dial_case_material_id', 'en'),
            'case_material_ar' => $lookupName('materials', 'dial_case_material_id', 'ar'),
            'category_type' => $nodeName($place['type'], 'en'),
            'category_type_ar' => $nodeName($place['type'], 'ar'),
            'category_type_name' => $nodeName($place['type'], 'en'),
            'band_width' => $w('band_width'),
            'watch_height' => $w('watch_height'),
            'watch_width' => $w('watch_width'),
            'watch_length' => $w('watch_length'),
            'water_resistance_size_type' => $lookupName('size_types', 'water_resistance_size_type_id', 'en'),
            'case_size_type' => $lookupName('size_types', 'case_size_type_id', 'en'),
            'band_size_type' => $lookupName('size_types', 'band_size_type_id', 'en'),
            'band_width_size_type' => $lookupName('size_types', 'band_width_size_type_id', 'en'),
            'case_thickness_size_type' => $lookupName('size_types', 'case_thickness_size_type_id', 'en'),
            'watch_height_size_type' => $lookupName('size_types', 'watch_height_size_type_id', 'en'),
            'watch_width_size_type' => $lookupName('size_types', 'watch_width_size_type_id', 'en'),
            'watch_length_size_type' => $lookupName('size_types', 'watch_length_size_type_id', 'en'),
            'dial_glass_material' => $lookupName('materials', 'dial_glass_material_id', 'en'),
            'dial_case_material' => $lookupName('materials', 'dial_case_material_id', 'en'),
            'band_closure' => $lookupName('closure_types', 'band_closure_id', 'en'),
            'band_closure_ar' => $lookupName('closure_types', 'band_closure_id', 'ar'),
            'dial_display_type' => $lookupName('display_types', 'dial_display_type_id', 'en'),
            'dial_display_type_ar' => $lookupName('display_types', 'dial_display_type_id', 'ar'),
            'country' => $en['country'] ?? null,
            'country_ar' => $ar['country'] ?? null,
            'stone' => $en['stone'] ?? null,
            'stone_ar' => $ar['stone'] ?? null,
            'brand_string' => $this->names->name('brands', $brandId, 'en'),
            'grade_string' => $this->names->name('grades', $gradeId, 'en'),
            'sub_type_string' => $nodeName($place['sub'], 'en'),
            'gender_string' => self::joined(array_map(fn (int $i) => $this->names->name('genders', $i, 'en'), $piv['genders'])),
            'feature_string' => self::joined(array_map(fn (int $i) => $this->names->name('features', $i, 'en'), $piv['features'])),
            'brand_id' => $brandId,
            'sub_type_id' => $subLegacyId,
            'category_type_id' => $typeLegacyId,
            'grade_id' => $gradeId,
            'watch_movement_id' => $wInt('watch_movement_id'),
            'case_shape_id' => $wInt('case_shape_id'),
            'band_material_id' => $wInt('band_material_id'),
            'case_size' => $w('case_size'),
            'warranty_years' => CompatProducts::warranty($row),
            'interchangeable_dial' => $w('interchangeable_dial'),
            'interchangeable_strap' => $w('interchangeable_strap'),
            'watch_box' => $w('watch_box'),
            'model_name' => $en['model_name'] ?? null,
            'model_name_ar' => $ar['model_name'] ?? null,
            'extra_attributes' => $extra,
        ];

        return ['product' => $product, 'related' => $this->related($id, $brandId, $subLegacyId)];
    }

    /** @return array{id: int, name_en: string|null, name_ar: string|null}|null */
    private function named(string $table, ?int $lookupId): ?array
    {
        if ($lookupId === null || $this->names->master($table, $lookupId) === null) {
            return null;
        }

        return ['id' => $lookupId, 'name_en' => $this->names->name($table, $lookupId, 'en'), 'name_ar' => $this->names->name($table, $lookupId, 'ar')];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name_en: string|null, name_ar: string|null}>
     */
    private function manyNamed(string $table, array $ids): array
    {
        $out = [];
        foreach ($ids as $i) {
            $row = $this->named($table, $i);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function colorRows(array $ids): array
    {
        $out = [];
        foreach ($ids as $i) {
            $out[] = [
                'id' => $i,
                'color_id' => $i,
                'name' => $this->names->name('colors', $i, 'en'),
                'name_ar' => $this->names->name('colors', $i, 'ar'),
                'color_name_en' => $this->names->name('colors', $i, 'en'),
                'color_name_ar' => $this->names->name('colors', $i, 'ar'),
                'color_value' => Val::nstr($this->names->master('colors', $i) ?? [], 'color_value'),
            ];
        }

        return $out;
    }

    /**
     * Legacy related query: same sub type OR same brand, in stock, not self, first 6 by id
     * (the legacy query has no ORDER BY; InnoDB returned PK order on every sampled product).
     *
     * @return list<array<string, mixed>>
     */
    private function related(int $id, int $brandId, ?int $subTypeLegacyId): array
    {
        $subNodeIds = [];
        if ($subTypeLegacyId !== null) {
            foreach ($this->categories->nodes() as $node) {
                if ($node['legacy_source'] === 'sub_type' && $node['legacy_id'] === $subTypeLegacyId) {
                    $subNodeIds[] = Val::int($node, 'id');
                }
            }
        }
        $sf = $this->storefrontId;
        $rows = $this->products->query()
            ->where('p.id', '!=', $id)
            ->where(function (Builder $q) use ($brandId, $subNodeIds, $sf): void {
                $q->where('p.brand_id', $brandId);
                if ($subNodeIds !== []) {
                    $q->orWhereExists(function (Builder $e) use ($subNodeIds, $sf): void {
                        $e->selectRaw('1')->from('storefront_category_product as rel')
                            ->whereColumn('rel.product_id', 'p.id')
                            ->where('rel.storefront_id', $sf)
                            ->whereIn('rel.storefront_category_id', $subNodeIds);
                    });
                }
            })
            ->where(function (Builder $q): void {
                $q->where('p.stock_express', '>', 0)->orWhere('p.stock_market', '>', 0);
            })
            ->orderBy('p.id')
            ->limit(6)
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'id');
        }
        $translations = $this->products->translations($ids);
        $pivots = $this->products->pivots($ids);
        $placements = $this->products->placements($ids, $this->categories);
        $stats = $this->products->ratingStats($ids);

        $out = [];
        foreach ($rows as $row) {
            $rid = Row::int($row, 'id');
            $out[] = $this->listRow($row, $translations[$rid] ?? [], $pivots[$rid]['genders'] ?? [], $placements[$rid]['sub'] ?? null, $stats[$rid] ?? null);
        }

        return $out;
    }

    /**
     * The legacy ProductListResource row.
     *
     * @param  array<string, array<string, mixed>>  $tr
     * @param  list<int>  $genderIds
     * @param  array<string, mixed>|null  $subNode
     * @param  array{avg: float|null, count: int}|null  $stats
     * @return array<string, mixed>
     */
    private function listRow(stdClass $row, array $tr, array $genderIds, ?array $subNode, ?array $stats): array
    {
        $en = $tr['en'] ?? [];
        $ar = $tr['ar'] ?? [];
        $brandId = Row::int($row, 'brand_id');
        $gradeId = Row::nint($row, 'grade_id');
        $selling = Row::nstr($row, 'selling_price');
        $sale = Row::nstr($row, 'sale_price');
        $stock = Row::int($row, 'stock_express');
        $market = Row::int($row, 'stock_market');
        $image = LegacyJson::imageUrl(LegacyJson::basename(Row::nstr($row, 'cover_path')));
        $genderNames = fn (string $locale): array => array_values(array_filter(array_map(fn (int $i) => $this->names->name('genders', $i, $locale), $genderIds), fn (?string $n) => $n !== null && $n !== ''));
        $avg = $stats === null ? null : $stats['avg'];

        return [
            'id' => Row::int($row, 'id'),
            'name_en' => $en['title'] ?? null,
            'name_ar' => $ar['title'] ?? null,
            'slug' => Row::str($row, 'storefront_slug'),
            'price' => $selling === null ? null : (float) $selling,
            'sale_price' => $sale === null ? null : (float) $sale,
            'main_image_url' => $image,
            'brand_name_en' => $this->names->name('brands', $brandId, 'en'),
            // ProductListResource assigns `brand_name_ar` twice; PHP keeps the FIRST position, so the
            // legacy row has 28 keys with brand_name_ar here (not in the alias block below).
            'brand_name_ar' => $this->names->name('brands', $brandId, 'ar'),
            'category_name_en' => null,
            'category_name_ar' => null,
            'grade_name_en' => $this->names->name('grades', $gradeId, 'en'),
            'grade_name_ar' => $this->names->name('grades', $gradeId, 'ar'),
            'gender_name_en' => $genderNames('en'),
            'gender_name_ar' => $genderNames('ar'),
            'in_stock' => $stock > 0 || $market > 0,
            'average_rating' => $avg === null ? null : round($avg, 2),
            'ratings_count' => $stats === null ? 0 : $stats['count'],
            'product_title' => $en['title'] ?? null,
            'product_title_ar' => $ar['title'] ?? null,
            'selling_price' => $selling === null ? null : (float) $selling,
            'sale_price_after_discount' => $sale === null ? null : (float) $sale,
            'short_description' => $en['short_description'] ?? null,
            'short_description_ar' => $ar['short_description'] ?? null,
            'image' => $image,
            'stock' => $stock,
            'market_stock' => $market,
            'active' => Row::int($row, 'is_active'),
            'brand_name' => $this->names->name('brands', $brandId, 'en'),
            'sub_type_name' => $subNode === null ? null : $this->categories->name($subNode, 'en'),
            'sub_type_name_ar' => $subNode === null ? null : $this->categories->name($subNode, 'ar'),
        ];
    }

    /**
     * Gallery file names in legacy `productImages()` order (sort, then id).
     *
     * @return list<string>
     */
    private function galleryFiles(int $productId): array
    {
        $out = [];
        $rows = DB::table('catalog_product_images')->select(['path'])->where('product_id', $productId)->where('is_cover', 0)->orderBy('sort')->orderBy('id')->get();
        foreach ($rows as $r) {
            $file = LegacyJson::basename(Row::str($r, 'path'));
            if ($file !== null) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Ratings of one product from the shared tables: all rows (for count/avg) and the 10 most recent
     * with the reviewer's name only (ProductResource never exposes e-mail/phone).
     *
     * @return array{all: list<int>, avg: float, latest: list<array<string, mixed>>}
     */
    private function ratings(int $productId): array
    {
        $rows = DB::connection('legacy')->table('product_ratings as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->select(['r.rating', 'r.comment', 'r.created_at', 'u.first_name', 'u.last_name'])
            ->where('r.product_id', $productId)
            ->orderByDesc('r.created_at')->orderBy('r.id')
            ->get();
        $all = [];
        $latest = [];
        foreach ($rows as $r) {
            $rating = Row::int($r, 'rating');
            $all[] = $rating;
            if (count($latest) < 10) {
                $name = trim((Row::nstr($r, 'first_name') ?? '').' '.(Row::nstr($r, 'last_name') ?? ''));
                $latest[] = [
                    'user_name' => $name !== '' ? $name : 'Anonymous',
                    'rating' => $rating,
                    'comment' => Row::nstr($r, 'comment'),
                    'created_at' => LegacyJson::iso8601(Row::nstr($r, 'created_at')),
                ];
            }
        }

        return ['all' => $all, 'avg' => $all === [] ? 0.0 : array_sum($all) / count($all), 'latest' => $latest];
    }

    /** @param  list<string|null>  $names */
    private static function joined(array $names): string
    {
        return implode(', ', array_values(array_filter($names, fn (?string $n) => $n !== null && $n !== '')));
    }
}
