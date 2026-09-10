<?php

namespace App\Storefront;

use App\Support\Val;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * `GET /api/v2/{storefront}/products/{slug}` — the native ProductDetail (CLEAN_CORE_STUDY §3.5.3):
 * the card + descriptions, images, `specs[]` rendered server-side from watch specs / family
 * specs, `attributes`, `categories[]`, `breadcrumb[]`, `meta` and `related[]` cards.
 * Cached per product (`sf:{id}:product:{pid}:v{n}`).
 */
final class ProductDetail
{
    /** Spec labels (ar/en) for the watch-spec rows; the family JSON keys get a humanised label. */
    private const LABELS = [
        'case_size' => ['ar' => 'مقاس الإطار', 'en' => 'Case size'],
        'case_shape' => ['ar' => 'شكل الإطار', 'en' => 'Case shape'],
        'case_material' => ['ar' => 'خامة الإطار', 'en' => 'Case material'],
        'glass_material' => ['ar' => 'خامة الزجاج', 'en' => 'Glass material'],
        'case_thickness' => ['ar' => 'سُمك الإطار', 'en' => 'Case thickness'],
        'band_material' => ['ar' => 'خامة السوار', 'en' => 'Band material'],
        'band_closure' => ['ar' => 'قفل السوار', 'en' => 'Band closure'],
        'band_length' => ['ar' => 'طول السوار', 'en' => 'Band length'],
        'band_width' => ['ar' => 'عرض السوار', 'en' => 'Band width'],
        'dial_display_type' => ['ar' => 'نوع العرض', 'en' => 'Display type'],
        'movement_type' => ['ar' => 'نوع الحركة', 'en' => 'Movement'],
        'water_resistance' => ['ar' => 'مقاومة الماء', 'en' => 'Water resistance'],
        'height' => ['ar' => 'الارتفاع', 'en' => 'Height'],
        'width' => ['ar' => 'العرض', 'en' => 'Width'],
        'length' => ['ar' => 'الطول', 'en' => 'Length'],
        'interchangeable_dial' => ['ar' => 'ميناء قابل للتبديل', 'en' => 'Interchangeable dial'],
        'interchangeable_strap' => ['ar' => 'سوار قابل للتبديل', 'en' => 'Interchangeable strap'],
        'watch_box' => ['ar' => 'علبة الساعة', 'en' => 'Watch box'],
    ];

    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly Lookups $lookups,
        private readonly CategoryTree $tree,
        private readonly ProductCards $cards,
    ) {}

    /** @return array{product: array<string, mixed>, related: list<array<string, mixed>>}|null */
    public function bySlug(string $slug): ?array
    {
        $row = $this->cards->base()->select(array_merge(ProductCards::columns(), ['p.model_number', 'p.warranty_years', 'p.specs']))->where('sp.slug', $slug)->first();
        if ($row === null) {
            return null;
        }
        $productId = Row::int($row, 'product_id');

        /** @var array{product: array<string, mixed>, related: list<array<string, mixed>>, ids: list<int>} $dto */
        $dto = $this->cache->remember($this->ctx->id(), 'product', (string) $productId, config()->integer('storefront.ttl.product'), fn () => $this->build($row));
        foreach ($dto['ids'] as $id) {
            $this->ctx->tags->product($this->ctx->id(), $id);
        }
        foreach (Val::list($dto['product'], 'categories') as $c) {
            if (is_array($c) && is_int($c['id'] ?? null)) {
                $this->ctx->tags->category($this->ctx->id(), $c['id']);
            }
        }

        return ['product' => $dto['product'], 'related' => $dto['related']];
    }

    /** @return array{product: array<string, mixed>, related: list<array<string, mixed>>, ids: list<int>} */
    private function build(stdClass $row): array
    {
        $productId = Row::int($row, 'product_id');
        $translations = $this->cards->translations([$productId], ['title', 'short_description', 'long_description', 'model_name', 'country', 'stone', 'meta_title', 'meta_description']);
        $tr = $translations[$productId] ?? [];
        $covers = $this->cards->covers([$productId]);
        $primary = $this->cards->primaryPlacements([$productId]);
        $card = $this->cards->card($row, $translations, $covers, $primary);

        $images = [];
        foreach (DB::table('catalog_product_images')->select(['id', 'path', 'is_cover', 'sort', 'width', 'height', 'alt_en', 'alt_ar', 'renditions'])->where('product_id', $productId)->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->get() as $img) {
            $images[] = ['id' => Row::int($img, 'id'), 'is_cover' => Row::bool($img, 'is_cover')] + ProductCards::image($img);
        }

        $pivots = $this->pivots($productId);
        $categories = [];
        foreach (DB::table('storefront_category_product')->select(['storefront_category_id', 'is_primary'])->where('storefront_id', $this->ctx->id())->where('product_id', $productId)->orderByDesc('is_primary')->orderBy('storefront_category_id')->get() as $pl) {
            $node = $this->tree->node(Row::int($pl, 'storefront_category_id'));
            if ($node !== null) {
                $categories[] = CategoryTree::ref($node) + ['is_primary' => Row::bool($pl, 'is_primary')];
            }
        }
        $primary = Val::narr($card, 'primary_category');
        $breadcrumb = $primary !== null ? $this->tree->breadcrumb(Val::int($primary, 'id')) : [];

        $ws = DB::table('catalog_product_watch_specs')->where('product_id', $productId)->first();
        $specsJson = Row::nstr($row, 'specs');
        $family = json_decode($specsJson ?? 'null', true);

        $product = $card + [
            'long_description' => $tr['long_description'] ?? [],
            'model_name' => $tr['model_name'] ?? [],
            'country' => $tr['country'] ?? [],
            'stone' => $tr['stone'] ?? [],
            'model_number' => Row::nstr($row, 'model_number'),
            'warranty_years' => Row::nint($row, 'warranty_years'),
            'images' => $images,
            'specs' => array_merge($ws === null ? [] : $this->watchSpecs($ws), is_array($family) ? self::familySpecs($family) : []),
            'attributes' => [
                'features' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('features', $i), $pivots['features']))),
                'genders' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('genders', $i), $pivots['genders']))),
                'colors' => [
                    'dial' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('colors', $i), $pivots['dial']))),
                    'band' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('colors', $i), $pivots['band']))),
                    'main' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('colors', $i), $pivots['main']))),
                ],
            ],
            'categories' => $categories,
            'breadcrumb' => $breadcrumb,
            'variants' => $this->variants($productId, $card),
            'meta' => ['title' => $tr['meta_title'] ?? [], 'description' => $tr['meta_description'] ?? []],
        ];

        $related = $this->related($productId, $primary, Row::int($row, 'brand_id'));
        $ids = [$productId];
        foreach ($related as $r) {
            $ids[] = Val::int($r, 'id');
        }

        return ['product' => $product, 'related' => $related, 'ids' => $ids];
    }

    /**
     * Same storefront, same primary category subtree (else same brand), visible, in stock, newest first.
     *
     * @param  array<string, mixed>|null  $primary
     * @return list<array<string, mixed>>
     */
    private function related(int $productId, ?array $primary, int $brandId): array
    {
        $query = $this->cards->base()->where('sp.product_id', '!=', $productId)->where('p.in_stock', 1);
        if ($primary !== null) {
            $this->cards->placedIn($query, $this->tree->subtreeIds(Val::int($primary, 'id')));
        } else {
            $query->where('p.brand_id', $brandId);
        }
        $rows = $query->select(ProductCards::columns())->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(config()->integer('storefront.listing.related'))->get()->all();

        return $this->cards->build($rows);
    }

    /**
     * The product's buyable variants (wave 3.5), each with its OWN availability.
     *
     * An empty list means "this product has no variants" and the card's product-level
     * `stock` is what the shopper buys against — which is every watch and every bag today, and
     * exactly the pre-wave-3.5 behaviour. When the list is NOT empty the card's `stock` is the
     * maintained AGGREGATE of these rows and nothing may be bought at product level; the frontend
     * must make the shopper pick one.
     *
     * `price` is resolved per variant as the product's effective price plus the variant's
     * `price_delta`, through the same {@see StorefrontPricing} helper every other price goes
     * through, so a variant can carry a surcharge without a second pricing rule existing.
     *
     * INACTIVE variants are omitted: they are not buyable. Their units still count toward the
     * product's quantity (they exist in the warehouse and the ledger balances on them) but never
     * toward `in_stock` — see §3.10.
     *
     * @param  array<string, mixed>  $card
     * @return list<array<string, mixed>>
     */
    private function variants(int $productId, array $card): array
    {
        $rows = DB::table('catalog_product_variants')
            ->where('product_id', $productId)->where('is_active', 1)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'sku', 'label', 'color_id', 'size_id', 'price_delta', 'stock_express', 'stock_market']);
        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<string, mixed> $price */
        $price = is_array($card['price'] ?? null) ? $card['price'] : [];
        $base = is_numeric($price['amount'] ?? null) ? (float) $price['amount'] : 0.0;
        $sale = is_numeric($price['sale_amount'] ?? null) ? (float) $price['sale_amount'] : null;
        $currency = is_string($price['currency'] ?? null) ? $price['currency'] : 'EGP';

        $out = [];
        foreach ($rows as $row) {
            $delta = (float) Row::money($row, 'price_delta');
            $express = Row::int($row, 'stock_express');
            $market = Row::int($row, 'stock_market');
            $colorId = Row::nint($row, 'color_id');
            $sizeId = Row::nint($row, 'size_id');

            $out[] = [
                'id' => Row::int($row, 'id'),
                'sku' => Row::nstr($row, 'sku'),
                'label' => Row::str($row, 'label'),
                'size' => $sizeId === null ? null : $this->lookups->ref('sizes', $sizeId),
                'color' => $colorId === null ? null : $this->lookups->ref('colors', $colorId),
                'price' => StorefrontPricing::resolve(
                    number_format($base + $delta, 2, '.', ''),
                    $sale === null ? null : number_format($sale + $delta, 2, '.', ''),
                    $currency,
                ),
                'stock' => ['express' => $express, 'market' => $market, 'in_stock' => $express > 0 || $market > 0],
            ];
        }

        return $out;
    }

    /** @return array{features: list<int>, genders: list<int>, dial: list<int>, band: list<int>, main: list<int>} */
    private function pivots(int $productId): array
    {
        $out = ['features' => [], 'genders' => [], 'dial' => [], 'band' => [], 'main' => []];
        $rows = DB::query()->fromSub(
            DB::table('catalog_product_feature')->selectRaw("'features' AS kind, feature_id AS ref_id, '' AS role")->where('product_id', $productId)
                ->unionAll(DB::table('catalog_product_gender')->selectRaw("'genders' AS kind, gender_id AS ref_id, '' AS role")->where('product_id', $productId))
                ->unionAll(DB::table('catalog_product_color')->selectRaw("'colors' AS kind, color_id AS ref_id, role")->where('product_id', $productId)),
            'piv'
        )->orderBy('kind')->orderBy('ref_id')->get();
        foreach ($rows as $r) {
            $kind = Row::str($r, 'kind');
            $id = Row::int($r, 'ref_id');
            if ($kind === 'colors') {
                $role = Row::str($r, 'role');
                if (isset($out[$role])) {
                    $out[$role][] = $id;
                }
            } elseif ($kind === 'features' || $kind === 'genders') {
                $out[$kind][] = $id;
            }
        }

        return $out;
    }

    /**
     * `specs[]` rows from `catalog_product_watch_specs`: lookup names, numbers with units, booleans.
     *
     * @return list<array<string, mixed>>
     */
    private function watchSpecs(stdClass $ws): array
    {
        $rows = [];
        $lookup = function (string $key, string $table, string $column) use ($ws, &$rows): void {
            $ref = $this->lookups->ref($table, Row::nint($ws, $column));
            if ($ref !== null) {
                $rows[] = ['key' => $key, 'label' => self::LABELS[$key], 'value' => $ref['name'], 'unit' => null];
            }
        };
        $number = function (string $key, string $column, string $unitColumn) use ($ws, &$rows): void {
            $v = Row::nfloat($ws, $column);
            if ($v !== null) {
                $rows[] = ['key' => $key, 'label' => self::LABELS[$key], 'value' => $v, 'unit' => $this->lookups->ref('units', Row::nint($ws, $unitColumn))];
            }
        };
        $flag = function (string $key) use ($ws, &$rows): void {
            $v = Row::nbool($ws, $key);
            if ($v !== null) {
                $rows[] = ['key' => $key, 'label' => self::LABELS[$key], 'value' => $v, 'unit' => null];
            }
        };
        $number('case_size', 'case_size', 'case_size_unit_id');
        $lookup('case_shape', 'shapes', 'case_shape_id');
        $lookup('case_material', 'materials', 'case_material_id');
        $lookup('glass_material', 'materials', 'glass_material_id');
        $number('case_thickness', 'case_thickness', 'case_thickness_unit_id');
        $lookup('band_material', 'materials', 'band_material_id');
        $lookup('band_closure', 'closure_types', 'band_closure_id');
        $number('band_length', 'band_length', 'band_length_unit_id');
        $number('band_width', 'band_width', 'band_width_unit_id');
        $lookup('dial_display_type', 'display_types', 'dial_display_type_id');
        $lookup('movement_type', 'movements', 'movement_type_id');
        $number('water_resistance', 'water_resistance', 'water_resistance_unit_id');
        $number('height', 'height', 'height_unit_id');
        $number('width', 'width', 'width_unit_id');
        $number('length', 'length', 'length_unit_id');
        $flag('interchangeable_dial');
        $flag('interchangeable_strap');
        $flag('watch_box');

        return $rows;
    }

    /**
     * @param  array<mixed>  $family
     * @return list<array<string, mixed>>
     */
    private static function familySpecs(array $family): array
    {
        $rows = [];
        foreach ($family as $key => $value) {
            if (! is_string($key) || $value === null || $value === '' || is_array($value)) {
                continue;
            }
            $label = ucfirst(str_replace('_', ' ', $key));
            $rows[] = ['key' => $key, 'label' => ['ar' => $label, 'en' => $label], 'value' => $value, 'unit' => null];
        }

        return $rows;
    }
}
