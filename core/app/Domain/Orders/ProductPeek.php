<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Catalog\SpecBlocks;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Enough of a product to CONFIRM it, for a screen that is not the product screen (item 12).
 *
 * ── The question this answers ────────────────────────────────────────────────────────────────
 *
 * An order line used to say "طقم ساعة" and a code. A team member packing that order has to know
 * they are holding the right thing, and a title shared by forty products is not how anybody checks.
 * So a line opens a panel with the photograph, both names, the supplier's code, the brand, the
 * variant the warehouse is meant to pick, the colours, and the specifications — the same facts that
 * are printed on the box.
 *
 * ── Why it is built with the PAGE, not fetched when the panel opens ──────────────────────────
 *
 * An order has a handful of lines. Loading their products with the page costs four grouped queries
 * and removes everything a fetch would bring with it: a route to authorise, a loading state, an
 * error state, and a panel that can be empty for a reason the operator cannot see. The cost is
 * bounded by the order, and an order with eighty lines is not a thing this shop has.
 *
 * Every query here is grouped over the whole id list — never one per line (AGENTS §2.24).
 *
 * ── Why it does not reuse `Storefront\ProductDetail` ─────────────────────────────────────────
 *
 * That class answers the same question for a CUSTOMER, and it is built on `StorefrontContext`: a
 * storefront, its locales, its cache. The dashboard has no storefront in hand here — the order
 * queue is deliberately cross-storefront — and manufacturing one to read a spec label would be
 * inventing context to satisfy a constructor. The labels also differ: the customer reads a product
 * page, the operator reads the dashboard's own field names, which is what `SpecBlocks` holds.
 *
 * @phpstan-type PeekSpec array{label: string, value: string}
 * @phpstan-type Peek array{title: array{ar: string, en: string}, wa_code: string|null, sku: string|null, brand: array{ar: string, en: string}, family: string, family_label: string, cover: string|null, model_number: string|null, specs: list<PeekSpec>}
 */
final class ProductPeek
{
    /**
     * One entry per product id that exists, keyed by id.
     *
     * @param  list<int>  $productIds
     * @return array<int, Peek>
     */
    public static function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $out = [];
        $families = [];

        foreach (
            DB::table('catalog_products as p')
                ->leftJoin('catalog_product_translations as pt_ar', function (JoinClause $join): void {
                    $join->on('pt_ar.product_id', '=', 'p.id')->where('pt_ar.locale', '=', 'ar');
                })
                ->leftJoin('catalog_product_translations as pt_en', function (JoinClause $join): void {
                    $join->on('pt_en.product_id', '=', 'p.id')->where('pt_en.locale', '=', 'en');
                })
                ->leftJoin('catalog_brand_translations as bt_ar', function (JoinClause $join): void {
                    $join->on('bt_ar.brand_id', '=', 'p.brand_id')->where('bt_ar.locale', '=', 'ar');
                })
                ->leftJoin('catalog_brand_translations as bt_en', function (JoinClause $join): void {
                    $join->on('bt_en.brand_id', '=', 'p.brand_id')->where('bt_en.locale', '=', 'en');
                })
                ->whereIn('p.id', $ids)
                ->get([
                    'p.id', 'p.wa_code', 'p.sku', 'p.family', 'p.model_number', 'p.specs',
                    'pt_ar.title as title_ar', 'pt_en.title as title_en',
                    'bt_ar.name as brand_ar', 'bt_en.name as brand_en',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $family = Row::str($row, 'family');
            $families[$family] = true;

            $out[$id] = [
                'title' => [
                    'ar' => Row::nstr($row, 'title_ar') ?? '',
                    'en' => Row::nstr($row, 'title_en') ?? '',
                ],
                'wa_code' => Row::nstr($row, 'wa_code'),
                'sku' => Row::nstr($row, 'sku'),
                'brand' => [
                    'ar' => Row::nstr($row, 'brand_ar') ?? '',
                    'en' => Row::nstr($row, 'brand_en') ?? '',
                ],
                'family' => $family,
                // The family as a WORD, not the stored token. `watch` is a column value; an
                // operator reads "ساعات". The products list translates the same six tokens in
                // React — same keys, one shared English file, so the two cannot drift.
                'family_label' => self::familyLabel($family),
                'model_number' => Row::nstr($row, 'model_number'),
                'cover' => null,
                'specs' => [],
                // Not part of the shape the caller sees — consumed by the spec pass below and
                // removed before this array is returned.
                '_specs_json' => Row::nstr($row, 'specs'),
            ];
        }

        self::attachCovers($out, $ids);
        self::attachSpecs($out, $ids, array_keys($families));

        foreach ($out as $id => $peek) {
            unset($out[$id]['_specs_json']);
        }

        /** @var array<int, Peek> $out */
        return $out;
    }

    /**
     * The colours a legacy order line stored, resolved to NAMES (item 12).
     *
     * `order_items.color_band` and `color_dial` hold a hex string — `#1F3A5F` — and the order screen
     * printed it verbatim. Nobody picks a watch off a shelf by its hex code. `catalog_colors` is the
     * table that maps hex to a name in both languages, and it is the same table the product form
     * picks from, so the name shown on the order is the name the catalogue uses.
     *
     * A hex with NO matching row keeps its hex and returns no name, deliberately: a colour the
     * catalogue has never heard of is a fact worth seeing, and inventing "أزرق" for `#1E3A5E`
     * because it is close to `#1F3A5F` would be the screen guessing.
     *
     * @param  list<string>  $hexes
     * @return array<string, array{ar: string, en: string}> keyed by UPPER-CASE hex
     */
    public static function colourNames(array $hexes): array
    {
        $wanted = [];
        foreach ($hexes as $hex) {
            $clean = strtoupper(trim($hex));
            if ($clean !== '') {
                $wanted[$clean] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $out = [];
        foreach (
            DB::table('catalog_colors as c')
                ->leftJoin('catalog_color_translations as ar', function (JoinClause $join): void {
                    $join->on('ar.color_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin('catalog_color_translations as en', function (JoinClause $join): void {
                    $join->on('en.color_id', '=', 'c.id')->where('en.locale', '=', 'en');
                })
                // Stored upper-case by the transform (deviation D-07) and upper-cased again by the
                // lookup writer, so an exact match is the right comparison — but the incoming value
                // is whatever the legacy cart wrote, which is why it is upper-cased above.
                ->whereIn(DB::raw('UPPER(c.hex)'), array_keys($wanted))
                ->get(['c.hex', 'ar.name as name_ar', 'en.name as name_en']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[strtoupper(Row::str($row, 'hex'))] = [
                'ar' => Row::nstr($row, 'name_ar') ?? '',
                'en' => Row::nstr($row, 'name_en') ?? '',
            ];
        }

        return $out;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /** One of the six product families, in the operator's language. */
    private static function familyLabel(string $family): string
    {
        return match ($family) {
            'watch' => ManageText::t('products.family_watch', 'ساعات'),
            'fashion' => ManageText::t('products.family_fashion', 'أزياء'),
            'bag' => ManageText::t('products.family_bag', 'حقائب'),
            'wallet' => ManageText::t('products.family_wallet', 'محافظ'),
            'perfume' => ManageText::t('products.family_perfume', 'عطور'),
            'electronics' => ManageText::t('products.family_electronics', 'إلكترونيات'),
            'other' => ManageText::t('products.family_other', 'أخرى'),
            // A family outside the six is data the operator must see verbatim, not a guess.
            default => $family,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $out
     * @param  list<int>  $ids
     */
    private static function attachCovers(array &$out, array $ids): void
    {
        // The read layer's own cover rule — `is_cover` first, then `sort` — so the first row seen
        // per product wins, exactly as the products list and the storefront resolve it.
        $seen = [];
        foreach (
            DB::table('catalog_product_images')
                ->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get(['product_id', 'path']) as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'product_id');
            if (isset($seen[$id]) || ! isset($out[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[$id]['cover'] = ImageUrl::src(Row::str($row, 'path'));
        }
    }

    /**
     * The populated specification fields, labelled the way the product form labels them.
     *
     * @param  array<int, array<string, mixed>>  $out
     * @param  list<int>  $ids
     * @param  list<string>  $families
     */
    private static function attachSpecs(array &$out, array $ids, array $families): void
    {
        /*
         * The lookup lists, loaded ONCE for every family on the page rather than once per line.
         * A watch block names eight lists plus units; an order of six watches would otherwise read
         * them fifty-four times to render the same nine dropdowns' worth of names.
         *
         * @var array<string, array<string, string>> $options  lookup name => id => label
         */
        $options = [];
        $blocks = [];
        foreach ($families as $family) {
            $block = SpecBlocks::for($family);
            if ($block === null) {
                continue;
            }
            $blocks[$family] = $block;
            foreach ($block['fields'] as $field) {
                foreach ([$field['lookup'] ?? null, isset($field['unit']) ? 'units' : null] as $lookup) {
                    if (is_string($lookup) && ! array_key_exists($lookup, $options)) {
                        $map = [];
                        foreach (SpecBlocks::options($lookup) as $option) {
                            $map[$option['value']] = $option['label'];
                        }
                        $options[$lookup] = $map;
                    }
                }
            }
        }

        // The dedicated watch table, for the products whose family stores specs in columns.
        $watch = self::watchRows($ids);

        foreach ($out as $id => $peek) {
            $family = Coerce::str($peek['family'] ?? null);
            $block = $blocks[$family] ?? null;
            if ($block === null) {
                continue;
            }

            /*
             * Two storage shapes, and the block says which: `watch` reads the dedicated table's
             * COLUMNS, every other family reads the `specs` JSON column's KEYS. The difference is
             * the schema's, not a preference — see `SpecBlocks`.
             */
            $values = self::valuesFor(
                $block['table'],
                Coerce::nstr($peek['_specs_json'] ?? null),
                $watch[$id] ?? [],
            );

            $specs = [];
            foreach ($block['fields'] as $field) {
                $value = self::render($field, $values, $options);
                if ($value !== null) {
                    $specs[] = ['label' => $field['label'], 'value' => $value];
                }
            }

            $out[$id]['specs'] = $specs;
        }
    }

    /**
     * `catalog_product_watch_specs`, one row per product, keyed by product id.
     *
     * The columns are rebuilt into a string-keyed map rather than cast, because a spec block
     * addresses its fields BY NAME and `array<mixed>` does not promise names. It is the same
     * normalisation `Coerce::arr()` performs for a decoded JSON document, for the same reason.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private static function watchRows(array $ids): array
    {
        $out = [];
        foreach (DB::table(SpecBlocks::WATCH_SPECS_TABLE)->whereIn('product_id', $ids)->get() as $raw) {
            $row = Row::cast($raw);
            $columns = [];
            foreach (get_object_vars($row) as $column => $value) {
                $columns[(string) $column] = $value;
            }
            $out[Row::int($row, 'product_id')] = $columns;
        }

        return $out;
    }

    /**
     * The field values for one product — from the JSON column, or from the dedicated table's row.
     *
     * A method rather than a ternary at the call site because the two branches have different key
     * types until something says otherwise, and the something should be a signature rather than an
     * annotation bolted onto a local.
     *
     * @param  array<string, mixed>  $watchRow
     * @return array<string, mixed>
     */
    private static function valuesFor(string $table, ?string $specsJson, array $watchRow): array
    {
        if ($table !== 'specs') {
            return $watchRow;
        }

        return Coerce::arr(json_decode($specsJson ?? '', true));
    }

    /**
     * One field as a sentence, or null when the product has no value for it.
     *
     * Empty fields are DROPPED rather than shown blank. This panel exists to confirm a product, and
     * a list of twenty rows where fourteen say nothing buries the six that identify it. The product
     * screen is where a missing specification is visible and fixable.
     *
     * @param  array{key: string, label: string, type: string, unit?: string, lookup?: string}  $field
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, string>>  $options
     */
    private static function render(array $field, array $values, array $options): ?string
    {
        $raw = $values[$field['key']] ?? null;
        if ($raw === null || $raw === '' || is_array($raw)) {
            return null;
        }

        if ($field['type'] === 'boolean') {
            // A false flag is a real answer and is kept: "no box" is something the packer needs.
            return (bool) $raw
                ? ManageText::t('common.yes', 'نعم')
                : ManageText::t('common.no', 'لا');
        }

        if ($field['type'] === 'lookup' && isset($field['lookup'])) {
            $label = $options[$field['lookup']][Coerce::str($raw)] ?? null;

            // An id whose lookup row is gone shows the id, not a blank: a dangling reference is
            // something to investigate, and a blank hides it.
            return $label ?? '#'.Coerce::str($raw);
        }

        $text = Coerce::str($raw);
        if ($text === '') {
            return null;
        }

        if (isset($field['unit'])) {
            $unit = $options['units'][Coerce::str($values[$field['unit']] ?? null)] ?? null;
            if ($unit !== null) {
                $text .= ' '.$unit;
            }
        }

        return $text;
    }
}
