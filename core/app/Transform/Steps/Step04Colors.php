<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 4 — colors + color_translations (ids preserved), then new_colors → catalog_colors.
 *
 * new_colors rows carry no legacy relation (nothing references them today). A new_colors
 * row whose (EN name, hex) equals a legacy colors row is MAPPED onto that legacy id in
 * transform_id_map instead of being inserted as a duplicate (rehearsal #1 finding X-02:
 * new_colors 1–16 are a verbatim copy of colors 1–16). A genuinely new colour gets
 * catalog_colors.id = new_color_id_offset + new_colors.id.
 */
final class Step04Colors implements Step
{
    public function number(): int
    {
        return 4;
    }

    public function name(): string
    {
        return 'colors';
    }

    public function target(): string
    {
        return 'catalog_colors, catalog_color_translations, transform_id_map';
    }

    public static function normaliseHex(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/^#?([0-9a-fA-F]{6})$/', trim($value), $m) === 1 ? '#'.strtoupper($m[1]) : null;
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $names = $ctx->legacyTranslations('color_translations', 'color_id', 'color_name');

        /** @var array<string, int> "lower(en)|hex" => legacy id */
        $legacyIndex = [];
        $maxLegacyId = 0;

        $ctx->chunkLegacy('colors', ['id', 'color_value', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $names, &$legacyIndex, &$maxLegacyId): void {
            $colors = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $maxLegacyId = max($maxLegacyId, $id);
                $en = trim($names[$id]['en'] ?? '');
                $ar = trim($names[$id]['ar'] ?? '');
                if ($ar === '') {
                    $ar = $en;
                    $result->count('ar_copied_from_en');
                }
                if ($en === '') {
                    $en = $ar !== '' ? $ar : "Color $id";
                    $result->count('en_missing');
                }
                $hex = self::normaliseHex(Row::nstr($row, 'color_value'));
                if ($hex === null) {
                    $result->count('non_hex_value');           // A-03 handling: NULL
                }
                $legacyIndex[mb_strtolower($en).'|'.($hex ?? '')] = $id;

                $colors[] = ['id' => $id, 'hex' => $hex, 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                $translations[] = ['color_id' => $id, 'locale' => 'en', 'name' => $en];
                $translations[] = ['color_id' => $id, 'locale' => 'ar', 'name' => $ar];
                $result->read++;
            }
            $result->writes->add($ctx->writer->upsert('catalog_colors', ['id', 'hex', 'created_at', 'updated_at'], ['id'], ['hex', 'updated_at'], $colors));
            $result->writes->add($ctx->writer->upsert('catalog_color_translations', ['color_id', 'locale', 'name'], ['color_id', 'locale'], ['name'], $translations));
        });

        $offset = $ctx->configInt('new_color_id_offset');
        if ($maxLegacyId >= $offset) {
            throw new RuntimeException("Tripwire: legacy colors.id ($maxLegacyId) reached new_color_id_offset ($offset); raise the offset before running.");
        }

        $ctx->chunkLegacy('new_colors', ['id', 'name_en', 'name_ar', 'hex', 'is_active', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $legacyIndex, $offset): void {
            $colors = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $en = trim(Row::str($row, 'name_en'));
                $ar = trim(Row::str($row, 'name_ar'));
                $hex = self::normaliseHex(Row::nstr($row, 'hex'));
                $result->read++;

                $existing = $legacyIndex[mb_strtolower($en).'|'.($hex ?? '')] ?? null;
                if ($existing !== null) {
                    $ctx->idMap->remember('new_colors', $id, 'catalog_colors', $existing);
                    $result->count('new_colors_mapped_to_legacy');

                    continue;
                }

                $target = $offset + $id;
                $ctx->idMap->remember('new_colors', $id, 'catalog_colors', $target);
                $result->count('new_colors_inserted');
                if (! Row::bool($row, 'is_active')) {
                    $result->count('new_colors_inactive_flag_dropped'); // catalog_colors has no is_active column
                }
                $colors[] = ['id' => $target, 'hex' => $hex, 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                $translations[] = ['color_id' => $target, 'locale' => 'en', 'name' => $en !== '' ? $en : ($ar !== '' ? $ar : "Color $target")];
                $translations[] = ['color_id' => $target, 'locale' => 'ar', 'name' => $ar !== '' ? $ar : $en];
            }
            $result->writes->add($ctx->writer->upsert('catalog_colors', ['id', 'hex', 'created_at', 'updated_at'], ['id'], ['hex', 'updated_at'], $colors));
            $result->writes->add($ctx->writer->upsert('catalog_color_translations', ['color_id', 'locale', 'name'], ['color_id', 'locale'], ['name'], $translations));
        });
    }
}
