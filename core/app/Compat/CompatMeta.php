<?php

namespace App\Compat;

use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * `GET catalog/meta` (legacy CatalogMetaController@index) built from the clean tables.
 *
 * `tables.*` are raw model rows exactly as astrotomic serialises them (columns, the appended
 * current-locale attribute, translations[] in legacy PK order = en, ar). The sibling keys
 * (`brands[]`, `categories[]`, … `shipping_cities[]`) are never read by the storefront (v1 §2.5.1)
 * but are reproduced anyway where the clean/shared tables hold the data; banners and shipping
 * cities are legacy/shared tables read through the read-only connection (D5).
 */
final class CompatMeta
{
    /** meta lookup translation order as observed on the legacy app: en first (PK order). */
    private const META_ORDER = ['en', 'ar'];

    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly CompatNames $names,
        private readonly CompatCategories $categories,
        private readonly int $storefrontId,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $appLocale): array
    {
        $ttl = config()->integer('compat.ttl.meta');

        /** @var array<string, mixed> */
        return $this->cache->remember($this->storefrontId, 'compat_meta', $appLocale, $ttl, fn () => $this->assemble($appLocale));
    }

    /** @return array<string, mixed> */
    private function assemble(string $appLocale): array
    {
        $rows = fn (string $legacyTable): array => array_values(array_filter(array_map(
            fn (int $id) => $this->names->legacyRow($legacyTable, $id, $appLocale, self::META_ORDER),
            $this->names->ids($legacyTable),
        )));
        $named = function (string $legacyTable): array {
            $out = [];
            foreach ($this->names->ids($legacyTable) as $id) {
                $out[] = ['id' => $id, 'name_en' => $this->names->name($legacyTable, $id, 'en'), 'name_ar' => $this->names->name($legacyTable, $id, 'ar')];
            }

            return $out;
        };

        $colors = $rows('colors');

        return [
            'tables' => [
                'categoryTypes' => $this->categoryTypes($appLocale),
                'brands' => $rows('brands'),
                'grades' => $rows('grades'),
                'subTypes' => $this->subTypes($appLocale),
                'colors' => $colors,
                'materials' => $rows('materials'),
                'shapes' => $rows('shapes'),
                'sizeTypes' => $rows('size_types'),
                'displayTypes' => $rows('display_types'),
                'closureTypes' => $rows('closure_types'),
                'movementTypes' => $rows('movement_types'),
            ],
            'brands' => array_map(fn (int $id) => [
                'id' => $id,
                'name_en' => $this->names->name('brands', $id, 'en'),
                'name_ar' => $this->names->name('brands', $id, 'ar'),
                'logo_url' => $this->folderUrl('Brand', self::nstr($this->names->master('brands', $id)['image'] ?? null)),
            ], $this->names->ids('brands')),
            'categories' => $this->legacyCategories(),
            'sub_types' => array_map(fn (array $node) => [
                'id' => CompatCategories::legacyIdOf($node),
                'name_en' => $this->categories->name($node, 'en'),
                'name_ar' => $this->categories->name($node, 'ar'),
                'category_id' => null,
            ], $this->categories->subTypes()),
            'genders' => $named('genders'),
            'grades' => $named('grades'),
            'dial_colors' => $named('colors'),
            'band_colors' => $named('colors'),
            'features' => $named('features'),
            'banners' => $this->banners(),
            'shipping_cities' => $this->shippingCities(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categoryTypes(string $appLocale): array
    {
        $out = [];
        foreach ($this->categories->categoryTypes() as $node) {
            $out[] = [
                'id' => CompatCategories::legacyIdOf($node),
                'image' => LegacyJson::basename(self::nstr($node['image_path'])),
                'created_at' => LegacyJson::ts(self::nstr($node['created_at'])),
                'updated_at' => LegacyJson::ts(self::nstr($node['updated_at'])),
                'category_type_name' => $this->categories->name($node, $appLocale),
                'translations' => $this->categories->translationRows($node, 'category_type_id', 'category_type_name', self::META_ORDER),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function subTypes(string $appLocale): array
    {
        $out = [];
        foreach ($this->categories->subTypes() as $node) {
            $image = LegacyJson::basename(self::nstr($node['image_path']));
            $out[] = [
                'id' => CompatCategories::legacyIdOf($node),
                'image' => $image,
                'created_at' => LegacyJson::ts(self::nstr($node['created_at'])),
                'updated_at' => LegacyJson::ts(self::nstr($node['updated_at'])),
                'image_url' => $this->folderUrl('Sub_type', $image),
                'sub_type_name' => $this->categories->name($node, $appLocale),
                'translations' => $this->categories->translationRows($node, 'sub_type_id', 'sub_type_name', self::META_ORDER),
            ];
        }

        return $out;
    }

    /**
     * Legacy `categories[]`: the dormant `categories` roots (parent NULL, active, by sort_order) —
     * preserved by the transform under the hidden `legacy-tree` node (step 17).
     *
     * @return list<array<string, mixed>>
     */
    private function legacyCategories(): array
    {
        $roots = [];
        foreach ($this->categories->nodes() as $node) {
            if ($node['legacy_source'] !== 'category' || $node['legacy_parent_id'] !== null || $node['is_active'] !== true) {
                continue;
            }
            $roots[] = $node;
        }
        usort($roots, fn (array $a, array $b) => [$a['sort_order'], $a['legacy_id']] <=> [$b['sort_order'], $b['legacy_id']]);

        return array_map(fn (array $node) => [
            'id' => CompatCategories::legacyIdOf($node),
            'name_en' => $this->categories->name($node, 'en'),
            'name_ar' => $this->categories->name($node, 'ar'),
        ], $roots);
    }

    /**
     * Legacy content table (D5), read-only.
     *
     * @return list<array<string, mixed>>
     */
    private function banners(): array
    {
        $out = [];
        foreach (DB::connection('legacy')->table('banner_homes')->select(['id', 'image', 'offer_id'])->orderBy('id')->get() as $row) {
            $out[] = [
                'id' => Row::int($row, 'id'),
                'image_url' => $this->folderUrl('Banner_home', Row::nstr($row, 'image')),
                'link' => Row::nint($row, 'offer_id'),
                'order' => null,
            ];
        }

        return $out;
    }

    /**
     * Shared table, read-only.
     *
     * @return list<array<string, mixed>>
     */
    private function shippingCities(): array
    {
        $names = [];
        foreach (DB::connection('legacy')->table('shipping_city_translations')->select(['shipping_city_id', 'locale', 'city_name'])->orderBy('id')->get() as $row) {
            $names[Row::int($row, 'shipping_city_id')][Row::str($row, 'locale')] = Row::nstr($row, 'city_name');
        }
        $out = [];
        foreach (DB::connection('legacy')->table('shipping_cities')->select(['id', 'shipping_cost'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $cost = Row::nfloat($row, 'shipping_cost');
            $out[] = ['id' => $id, 'name_en' => $names[$id]['en'] ?? null, 'name_ar' => $names[$id]['ar'] ?? null, 'shipping_cost' => $cost];
        }

        return $out;
    }

    /** CatalogMetaController::$img — `asset_base/Uploads_Images/<folder>/<file>` or null. */
    private function folderUrl(string $folder, ?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        return rtrim(config()->string('compat.asset_base'), '/').'/Uploads_Images/'.$folder.'/'.$file;
    }

    private static function nstr(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
