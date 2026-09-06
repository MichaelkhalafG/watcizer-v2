<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 2 — grades, materials, shapes, movement_types, closure_types, display_types,
 * features, genders (+translations) → catalog_* (+translations), ids preserved.
 * Translation column `<x>_name` → `name`; grade `description` and `image` kept.
 */
final class Step02Lookups implements Step
{
    /**
     * legacy plural => [singular, has grade extras]
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    public const LOOKUPS = [
        'grades' => ['grade', true],
        'materials' => ['material', false],
        'shapes' => ['shape', false],
        'movement_types' => ['movement_type', false],
        'closure_types' => ['closure_type', false],
        'display_types' => ['display_type', false],
        'features' => ['feature', false],
        'genders' => ['gender', false],
    ];

    public function number(): int
    {
        return 2;
    }

    public function name(): string
    {
        return 'lookups';
    }

    public function target(): string
    {
        return 'catalog_{grades,materials,shapes,movement_types,closure_types,display_types,features,genders} (+translations)';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        foreach (self::LOOKUPS as $plural => [$singular, $isGrade]) {
            $this->one($ctx, $result, $plural, $singular, $isGrade);
        }
    }

    private function one(TransformContext $ctx, StepResult $result, string $plural, string $singular, bool $isGrade): void
    {
        $fk = "{$singular}_id";
        $trTable = "{$singular}_translations";
        $names = $ctx->legacyTranslations($trTable, $fk, "{$singular}_name");
        $descriptions = $isGrade ? $ctx->legacyTranslations($trTable, $fk, 'description') : [];

        $columns = $isGrade ? ['id', 'image_path', 'created_at', 'updated_at'] : ['id', 'created_at', 'updated_at'];
        $update = $isGrade ? ['image_path', 'updated_at'] : ['updated_at'];
        $trColumns = $isGrade ? [$fk, 'locale', 'name', 'description'] : [$fk, 'locale', 'name'];
        $trUpdate = $isGrade ? ['name', 'description'] : ['name'];
        $legacyColumns = $isGrade ? ['id', 'image', 'created_at', 'updated_at'] : ['id', 'created_at', 'updated_at'];

        $ctx->chunkLegacy($plural, $legacyColumns, function (Collection $rows) use ($ctx, $result, $plural, $singular, $fk, $isGrade, $names, $descriptions, $columns, $update, $trColumns, $trUpdate): void {
            $masters = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $en = trim($names[$id]['en'] ?? '');
                $ar = trim($names[$id]['ar'] ?? '');
                if ($ar === '') {
                    $ar = $en;
                    $result->count("{$plural}:ar_copied_from_en");
                }
                if ($en === '') {
                    $en = $ar !== '' ? $ar : ucfirst(str_replace('_', ' ', $singular))." $id";
                    $result->count("{$plural}:en_missing");
                }

                $master = ['id' => $id, 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                if ($isGrade) {
                    $image = Row::nstr($row, 'image');
                    $master['image_path'] = ($image !== null && trim($image) !== '') ? 'Grade/'.$image : null;
                }
                $masters[] = $master;

                foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
                    $t = [$fk => $id, 'locale' => $locale, 'name' => $name];
                    if ($isGrade) {
                        $d = $descriptions[$id][$locale] ?? null;
                        $t['description'] = ($d === null || trim($d) === '') ? null : $d;
                    }
                    $translations[] = $t;
                }
                $result->read++;
            }

            $result->writes->add($ctx->writer->upsert("catalog_{$plural}", $columns, ['id'], $update, $masters));
            $result->writes->add($ctx->writer->upsert("catalog_{$singular}_translations", $trColumns, [$fk, 'locale'], $trUpdate, $translations));
        });
    }
}
