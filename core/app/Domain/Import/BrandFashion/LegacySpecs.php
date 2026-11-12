<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

/**
 * The old shop's free-text specification rows → the fields core actually has (2026-11-11).
 *
 * On the WordPress site a product's specs are an ACF repeater of up to 31 `label / value` pairs in
 * English with an Arabic twin (`product_specs_N_text`, `_content`, `_text_ar`, `_content_ar`). The
 * labels were typed by hand, so the same field arrives as `Dial Colour`, `dial colour\t` and
 * `Dial Color`. Core has typed fields instead: the watch block's lookup columns, a few numbers with
 * a unit, colour roles, and a material on the JSON blocks.
 *
 * ── The rule: map a LABEL only where its meaning is not in doubt ────────────────────────────────
 *
 * The table below is the whole mapping; a label not in it is reported with its count, never
 * squeezed into the nearest field. Two entries are a judgement and say so in the report:
 * `face material` → the CASE material (the label is Amazon's wording for the case), and
 * `dial display material` → the GLASS (its values are `mineral` / `sapphire`, which are crystals).
 *
 * The VALUE is matched to a lookup by name at import time, on the target database, and only an
 * exact (case-insensitive) name matches: `Silicon` is not silently `Silicone`. Unmatched values are
 * reported with their counts so the team can add a synonym or a lookup row deliberately.
 */
final class LegacySpecs
{
    /** A lookup id column on the watch block. */
    public const LOOKUP = 'lookup';

    /** A number with a unit (`43 Mm`, `29 mm`). */
    public const MEASURE = 'measure';

    /** A colour, with the role core stores it under. */
    public const COLOUR = 'colour';

    /** Men / Women / Unisex. */
    public const GENDER = 'gender';

    /** A whole number of millilitres (`100 ml`). */
    public const VOLUME = 'volume';

    /** A material on a JSON spec block (bag, wallet, fashion). */
    public const MATERIAL = 'material';

    /**
     * normalised label => [kind, target, lookup list or unit field].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const LABELS = [
        // ── watch block, lookups ──────────────────────────────────────────────────────────────
        'band material' => [self::LOOKUP, 'band_material_id', 'materials'],
        'strap material' => [self::LOOKUP, 'band_material_id', 'materials'],
        'band closure' => [self::LOOKUP, 'band_closure_id', 'closure_types'],
        'face/dial type' => [self::LOOKUP, 'dial_display_type_id', 'display_types'],
        'display type' => [self::LOOKUP, 'dial_display_type_id', 'display_types'],
        'watch movement' => [self::LOOKUP, 'movement_type_id', 'movements'],
        'movement' => [self::LOOKUP, 'movement_type_id', 'movements'],
        'face/dial shape' => [self::LOOKUP, 'case_shape_id', 'shapes'],
        'face material' => [self::LOOKUP, 'case_material_id', 'materials'],
        'case material' => [self::LOOKUP, 'case_material_id', 'materials'],
        'dial display material' => [self::LOOKUP, 'glass_material_id', 'materials'],

        // ── watch block, measures ─────────────────────────────────────────────────────────────
        'case size/diameter' => [self::MEASURE, 'case_size', 'case_size_unit_id'],
        'case thickness' => [self::MEASURE, 'case_thickness', 'case_thickness_unit_id'],
        'band width' => [self::MEASURE, 'band_width', 'band_width_unit_id'],
        'watch water resistance' => [self::MEASURE, 'water_resistance', 'water_resistance_unit_id'],
        'water resistance' => [self::MEASURE, 'water_resistance', 'water_resistance_unit_id'],

        // ── colours ───────────────────────────────────────────────────────────────────────────
        'band colour' => [self::COLOUR, 'band', ''],
        'band color' => [self::COLOUR, 'band', ''],
        'dial colour' => [self::COLOUR, 'dial', ''],
        'dial color' => [self::COLOUR, 'dial', ''],
        'colour' => [self::COLOUR, 'main', ''],
        'color' => [self::COLOUR, 'main', ''],
        'frame colour' => [self::COLOUR, 'main', ''],

        // ── the rest ──────────────────────────────────────────────────────────────────────────
        'department' => [self::GENDER, 'gender', ''],
        'gender' => [self::GENDER, 'gender', ''],
        'material' => [self::MATERIAL, 'material_id', 'materials'],
        'fragrance size' => [self::VOLUME, 'perfume_volume_ml', ''],
    ];

    /** Gender words as the old shop wrote them → core's English gender names. */
    public const GENDER_WORDS = [
        'men' => 'Men', 'man' => 'Men', 'male' => 'Men', 'for men' => 'Men', 'mens' => 'Men', "men's" => 'Men',
        'women' => 'Women', 'woman' => 'Women', 'female' => 'Women', 'for woman' => 'Women', 'for women' => 'Women',
        'womens' => 'Women', "women's" => 'Women', 'ladies' => 'Women',
        'unisex' => 'Unisex',
    ];

    /** Unit words → the EN name of core's `catalog_units` row. */
    public const UNIT_WORDS = [
        'mm' => 'mm', 'millimeter' => 'mm', 'millimeters' => 'mm', 'millimetre' => 'mm', 'millimetres' => 'mm',
        'cm' => 'cm', 'atm' => 'ATM', 'bar' => 'Bar',
        // Metres — water resistance only (the importer refuses them on any other measure).
        // The unit row is named "metres" (code `metre`): `m` is already the size M (D-19).
        'm' => 'metres', 'meter' => 'metres', 'meters' => 'metres', 'metre' => 'metres', 'metres' => 'metres',
    ];

    /** The unit name of metres, and the one field it may measure. */
    public const METRES = 'metres';

    /** Lower-case, tabs and repeated spaces gone, so `Dial Colour\t` is `dial colour`. */
    public static function normaliseLabel(string $label): string
    {
        $text = mb_strtolower(LegacyText::line($label));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    public static function forLabel(string $label): ?array
    {
        return self::LABELS[self::normaliseLabel($label)] ?? null;
    }

    /**
     * `43 Mm` → [43.0, 'mm']; `5 ATM` → [5.0, 'ATM']; `50 M` → [50.0, null] (metres: no unit row,
     * so the caller reports it instead of storing a number with no unit). Null when no number.
     *
     * @return array{0: float, 1: string|null}|null
     */
    public static function measure(string $value): ?array
    {
        if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*([a-z]*)\.?\s*$/iu', LegacyText::line($value), $m) !== 1) {
            return null;
        }

        $number = (float) str_replace(',', '.', $m[1]);
        $unit = self::UNIT_WORDS[mb_strtolower($m[2])] ?? null;

        return [$number, $unit];
    }

    /** `100 ml` / `100ML` / `100` → 100. Null when it is not a plain volume. */
    public static function volume(string $value): ?int
    {
        if (preg_match('/^\s*(\d{1,4})\s*(ml)?\s*$/iu', LegacyText::line($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public static function gender(string $value): ?string
    {
        return self::GENDER_WORDS[mb_strtolower(LegacyText::line($value))] ?? null;
    }
}
