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
        'case_size' => ['ar' => 'قياس جسم الساعة', 'en' => 'Case size'],
        'case_shape' => ['ar' => 'شكل جسم الساعة', 'en' => 'Case shape'],
        'case_material' => ['ar' => 'مادة جسم الساعة', 'en' => 'Case material'],
        'glass_material' => ['ar' => 'خامة الزجاج', 'en' => 'Glass material'],
        'case_thickness' => ['ar' => 'سماكة جسم الساعة', 'en' => 'Case thickness'],
        'band_material' => ['ar' => 'خامة السوار', 'en' => 'Band material'],
        'band_closure' => ['ar' => 'قفل السوار', 'en' => 'Band closure'],
        'band_length' => ['ar' => 'طول السوار', 'en' => 'Band length'],
        'band_width' => ['ar' => 'عرض السوار', 'en' => 'Band width'],
        'dial_display_type' => ['ar' => 'نوع عرض القرص', 'en' => 'Dial display type'],
        'movement_type' => ['ar' => 'نوع الحركة', 'en' => 'Movement'],
        'water_resistance' => ['ar' => 'مقاومة الماء', 'en' => 'Water resistance'],
        'height' => ['ar' => 'ارتفاع الساعة', 'en' => 'Watch height'],
        'width' => ['ar' => 'عرض الساعة', 'en' => 'Watch width'],
        'length' => ['ar' => 'طول الساعة', 'en' => 'Watch length'],
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
        /*
         * `p.sku`, not `p.model_number` (item 4, 2026-09-19). The two columns held the same thing
         * and are now one; `sku` is the survivor because 6,799 products had one against 295.
         *
         * The storefront gains by it: the model line on a product page used to appear for 295
         * products and now appears for 6,858.
         */
        $row = $this->cards->base()->select(array_merge(ProductCards::columns(), ['p.sku', 'p.warranty_years', 'p.specs']))->where('sp.slug', $slug)->first();
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
        $images2 = $this->cards->images([$productId]);
        $primary = $this->cards->primaryPlacements([$productId]);
        $card = $this->cards->card($row, $translations, $images2, $primary);

        $images = [];
        foreach (DB::table('catalog_product_images')->select(['id', 'path', 'is_cover', 'sort', 'width', 'height', 'alt_en', 'alt_ar', 'renditions'])->where('product_id', $productId)->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')->get() as $img) {
            $images[] = ['id' => Row::int($img, 'id'), 'is_cover' => Row::bool($img, 'is_cover')] + ProductCards::image($img);
        }

        $pivots = $this->pivots($productId);
        $dial = $this->colourRefs($pivots['dial']);
        $band = $this->colourRefs($pivots['band']);
        $main = $this->colourRefs($pivots['main']);
        // The card's `colors` (spec §8.2, dial → band → main, each once) from the pivots this detail
        // already loaded — the same list a listing row carries, with no extra query.
        $cardColours = [];
        foreach (['dial' => $dial, 'band' => $band, 'main' => $main] as $role => $refs) {
            foreach ($refs as $ref) {
                $cid = Val::int($ref, 'id');
                if (! isset($cardColours[$cid])) {
                    $cardColours[$cid] = $ref + ['role' => $role];
                }
            }
        }
        $card['colors'] = array_values($cardColours);
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

        // array_replace, not `+`: the card now carries a picker-sized `variants` list (ProductCards::variants),
        // and the detail's FULL rows (price, label, both stock buckets) must win that key.
        $product = array_replace($card, [
            'long_description' => $tr['long_description'] ?? [],
            'model_name' => $tr['model_name'] ?? [],
            'country' => $tr['country'] ?? [],
            'stone' => $tr['stone'] ?? [],
            // The public key stays `model_number`: it is what the storefront's API answers with,
            // and renaming it would be a breaking change to a contract this merge has no business
            // touching. Only where the value comes FROM has changed.
            'model_number' => Row::nstr($row, 'sku'),
            'warranty_years' => Row::nint($row, 'warranty_years'),
            'images' => $images,
            'specs' => array_merge($ws === null ? [] : $this->watchSpecs($ws), is_array($family) ? self::familySpecs($family) : []),
            'attributes' => [
                'features' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('features', $i), $pivots['features']))),
                'genders' => array_values(array_filter(array_map(fn (int $i) => $this->lookups->ref('genders', $i), $pivots['genders']))),
                'colors' => ['dial' => $dial, 'band' => $band, 'main' => $main],
            ],
            'categories' => $categories,
            'breadcrumb' => $breadcrumb,
            'variants' => $this->variants($productId, $card),
            'meta' => ['title' => $tr['meta_title'] ?? [], 'description' => $tr['meta_description'] ?? []],
        ]);

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

    /**
     * Colour refs for a list of colour ids, unknown ids dropped.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function colourRefs(array $ids): array
    {
        return array_values(array_filter(array_map(fn (int $i): ?array => $this->lookups->ref('colors', $i), $ids)));
    }

    /** @return array{features: list<int>, genders: list<int>, dial: list<int>, band: list<int>, main: list<int>} */
    private function pivots(int $productId): array
    {
        $out = ['features' => [], 'genders' => [], 'dial' => [], 'band' => [], 'main' => []];
        $rows = DB::query()->fromSub(
            DB::table('catalog_product_feature')->selectRaw("'features' AS kind, feature_id AS ref_id, '' AS role, 0 AS position")->where('product_id', $productId)
                ->unionAll(DB::table('catalog_product_gender')->selectRaw("'genders' AS kind, gender_id AS ref_id, '' AS role, 0 AS position")->where('product_id', $productId))
                ->unionAll(DB::table('catalog_product_color')->selectRaw("'colors' AS kind, color_id AS ref_id, role, position")->where('product_id', $productId)),
            'piv'
        )->orderBy('kind')->orderBy('position')->orderBy('ref_id')->get();   // colours: role order (position) first
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
        // A flag is served as a WORD per locale, not as a raw boolean (2026-10-23). It used to be `true`, which
        // every consumer then had to know to translate: Brand Fashion's page carried a `typeof v === 'boolean'`
        // branch for it, and Watchizer's printed the literal "true" the moment it read v2 instead of compat.
        // Same decision as the family-spec labels below - the rendering of a field belongs with the field.
        $flag = function (string $key) use ($ws, &$rows): void {
            $word = self::familyFlag(Row::nbool($ws, $key));
            if ($word !== null) {
                $rows[] = ['key' => $key, 'label' => self::LABELS[$key], 'value' => $word, 'unit' => null];
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
     * The FAMILY specs a non-watch product carries in `catalog_products.specs` - a wallet's dimensions and
     * card slots, a perfume's volume and scent, a power bank's battery - as labelled rows.
     *
     * Each key's label, unit and kind are declared below (2026-10-23). They used to be declared in the
     * storefront instead, in ProductDetailClient's EXTRA_LABELS map, which meant v2 served
     * `ucfirst(str_replace('_', ' ', $key))` as the label in BOTH locales and the raw stored value: a wallet
     * read "Wallet card slots - 8" and "Coin pocket - 1" through v2 where the component printed "Card Slots -
     * 8" and "Coin Pocket - Yes", and an Arabic page got the English label. Measured on 20 live products
     * (19 fashion, 1 watch) when Watchizer's product page was moved here.
     *
     * A label belongs with its field, not with one component that happens to render it, so the map is here and
     * every v2 consumer gets the same rows. An UNDECLARED key still falls back to the humanized key, so a new
     * spec the dashboard starts writing appears immediately rather than disappearing - readable, and a prompt
     * to add it here.
     *
     * @var array<string, array{en: string, ar: string, unit?: string, bool?: true}>
     */
    private const FAMILY_SPEC_LABELS = [
        'width_cm' => ['en' => 'Width', 'ar' => 'العرض', 'unit' => 'cm'],
        'height_cm' => ['en' => 'Height', 'ar' => 'الارتفاع', 'unit' => 'cm'],
        'depth_cm' => ['en' => 'Depth', 'ar' => 'العمق', 'unit' => 'cm'],
        'strap_length_cm' => ['en' => 'Strap Length', 'ar' => 'طول الحزام', 'unit' => 'cm'],
        'bag_strap_type' => ['en' => 'Strap Type', 'ar' => 'نوع الحزام'],
        'bag_compartments' => ['en' => 'Compartments', 'ar' => 'الأقسام'],
        'laptop_compartment' => ['en' => 'Laptop Compartment', 'ar' => 'جيب لابتوب', 'bool' => true],
        'waterproof' => ['en' => 'Waterproof', 'ar' => 'مقاوم للماء', 'bool' => true],
        'wallet_card_slots' => ['en' => 'Card Slots', 'ar' => 'جيوب البطاقات'],
        'rfid_protection' => ['en' => 'RFID Protection', 'ar' => 'حماية RFID', 'bool' => true],
        'coin_pocket' => ['en' => 'Coin Pocket', 'ar' => 'جيب نقود', 'bool' => true],
        'perfume_volume' => ['en' => 'Volume', 'ar' => 'الحجم', 'unit' => 'ml'],
        'perfume_type' => ['en' => 'Type', 'ar' => 'النوع'],
        'perfume_scent' => ['en' => 'Scent', 'ar' => 'الرائحة'],
        'elec_battery_capacity' => ['en' => 'Battery', 'ar' => 'البطارية', 'unit' => 'mAh'],
        'elec_connectivity' => ['en' => 'Connectivity', 'ar' => 'الاتصال'],
    ];

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
            $declared = self::FAMILY_SPEC_LABELS[$key] ?? null;
            if ($declared === null) {
                // not declared above: readable rather than missing, and a prompt to declare it
                $fallback = ucfirst(str_replace('_', ' ', $key));
                $rows[] = ['key' => $key, 'label' => ['ar' => $fallback, 'en' => $fallback], 'value' => $value, 'unit' => null];

                continue;
            }
            // a flag reads as a word in both languages, never as 1 or 0
            $out = ($declared['bool'] ?? false)
                ? self::familyFlag($value)
                : $value;
            if ($out === null) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => ['ar' => $declared['ar'], 'en' => $declared['en']],
                'value' => $out,
                // the unit is a plain code here, not a `size_types` row: these are declared, not looked up
                'unit' => isset($declared['unit']) ? ['id' => null, 'code' => $declared['unit'], 'name' => ['en' => $declared['unit'], 'ar' => $declared['unit']]] : null,
            ];
        }

        return $rows;
    }

    /**
     * A declared flag as a localized word. Anything that is not recognisably true or false is dropped rather
     * than printed as a number, which is what "Coin pocket - 1" was.
     *
     * @return array{en: string, ar: string}|null
     */
    private static function familyFlag(mixed $value): ?array
    {
        $yes = $value === true || $value === 1 || $value === '1' || $value === 'true';
        $no = $value === false || $value === 0 || $value === '0' || $value === 'false';
        if (! $yes && ! $no) {
            return null;
        }

        return $yes ? ['en' => 'Yes', 'ar' => 'نعم'] : ['en' => 'No', 'ar' => 'لا'];
    }
}
