<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 8 — the watch columns of products → catalog_product_watch_specs (1:1, PK product_id).
 * A row is written for every product whose family is `watch` OR that carries any watch
 * value (A-10 counts the non-watch ones). Unit FKs are size_types ids, preserved in
 * catalog_units (step 3); lookup FKs are preserved in step 2.
 */
final class Step08WatchSpecs implements Step
{
    /** @var list<string> */
    private const LEGACY = [
        'id', 'case_size', 'case_size_type_id', 'case_shape_id', 'dial_case_material_id', 'dial_glass_material_id',
        'case_thickness', 'case_thickness_size_type_id', 'band_material_id', 'band_closure_id', 'band_length',
        'band_size_type_id', 'band_width', 'band_width_size_type_id', 'dial_display_type_id', 'watch_movement_id',
        'water_resistance', 'water_resistance_size_type_id', 'watch_height', 'watch_height_size_type_id',
        'watch_width', 'watch_width_size_type_id', 'watch_length', 'watch_length_size_type_id',
        'interchangeable_dial', 'interchangeable_strap', 'watch_box',
    ];

    /** @var list<string> */
    private const CLEAN = [
        'product_id', 'case_size', 'case_size_unit_id', 'case_shape_id', 'case_material_id', 'glass_material_id',
        'case_thickness', 'case_thickness_unit_id', 'band_material_id', 'band_closure_id', 'band_length',
        'band_length_unit_id', 'band_width', 'band_width_unit_id', 'dial_display_type_id', 'movement_type_id',
        'water_resistance', 'water_resistance_unit_id', 'height', 'height_unit_id', 'width', 'width_unit_id',
        'length', 'length_unit_id', 'interchangeable_dial', 'interchangeable_strap', 'watch_box',
    ];

    public function number(): int
    {
        return 8;
    }

    public function name(): string
    {
        return 'watch_specs';
    }

    public function target(): string
    {
        return 'catalog_product_watch_specs';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $ctx->chunkLegacy('products', self::LEGACY, function (Collection $rows) use ($ctx, $result): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $result->read++;

                $values = [
                    'product_id' => $id,
                    'case_size' => Row::nmoney($row, 'case_size'),
                    'case_size_unit_id' => Row::nint($row, 'case_size_type_id'),
                    'case_shape_id' => Row::nint($row, 'case_shape_id'),
                    'case_material_id' => Row::nint($row, 'dial_case_material_id'),
                    'glass_material_id' => Row::nint($row, 'dial_glass_material_id'),
                    'case_thickness' => Row::nmoney($row, 'case_thickness'),
                    'case_thickness_unit_id' => Row::nint($row, 'case_thickness_size_type_id'),
                    'band_material_id' => Row::nint($row, 'band_material_id'),
                    'band_closure_id' => Row::nint($row, 'band_closure_id'),
                    'band_length' => Row::nmoney($row, 'band_length'),
                    'band_length_unit_id' => Row::nint($row, 'band_size_type_id'),
                    'band_width' => Row::nmoney($row, 'band_width'),
                    'band_width_unit_id' => Row::nint($row, 'band_width_size_type_id'),
                    'dial_display_type_id' => Row::nint($row, 'dial_display_type_id'),
                    'movement_type_id' => Row::nint($row, 'watch_movement_id'),
                    'water_resistance' => Row::nint($row, 'water_resistance'),
                    'water_resistance_unit_id' => Row::nint($row, 'water_resistance_size_type_id'),
                    'height' => Row::nmoney($row, 'watch_height'),
                    'height_unit_id' => Row::nint($row, 'watch_height_size_type_id'),
                    'width' => Row::nmoney($row, 'watch_width'),
                    'width_unit_id' => Row::nint($row, 'watch_width_size_type_id'),
                    'length' => Row::nmoney($row, 'watch_length'),
                    'length_unit_id' => Row::nint($row, 'watch_length_size_type_id'),
                    'interchangeable_dial' => self::flag(Row::nbool($row, 'interchangeable_dial')),
                    'interchangeable_strap' => self::flag(Row::nbool($row, 'interchangeable_strap')),
                    'watch_box' => self::flag(Row::nbool($row, 'watch_box')),
                ];

                $hasValue = count(array_filter($values, fn (mixed $v, string $k) => $k !== 'product_id' && $v !== null, ARRAY_FILTER_USE_BOTH)) > 0;
                $isWatch = $ctx->family($id) === 'watch';

                if (! $isWatch && ! $hasValue) {
                    continue;
                }
                if (! $isWatch) {
                    $result->count('non_watch_with_specs');    // A-10
                }
                if ($isWatch && ! $hasValue) {
                    $result->count('watch_without_specs');
                }
                $out[] = $values;
            }
            $result->writes->add($ctx->writer->upsert('catalog_product_watch_specs', self::CLEAN, ['product_id'], array_values(array_diff(self::CLEAN, ['product_id'])), $out));
        });
    }

    private static function flag(?bool $v): ?int
    {
        return $v === null ? null : ($v ? 1 : 0);
    }
}
