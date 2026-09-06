<?php

namespace App\Transform\Steps;

use App\Support\LegacySlug;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/** Step 3 — size_types (+translations) → catalog_units (+translations), id preserved, code = slugify(en). */
final class Step03Units implements Step
{
    public function number(): int
    {
        return 3;
    }

    public function name(): string
    {
        return 'units';
    }

    public function target(): string
    {
        return 'catalog_units, catalog_unit_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $names = $ctx->legacyTranslations('size_type_translations', 'size_type_id', 'size_type_name');
        $seen = [];

        $ctx->chunkLegacy('size_types', ['id', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $names, &$seen): void {
            $units = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $en = trim($names[$id]['en'] ?? '');
                $ar = trim($names[$id]['ar'] ?? '');
                if ($ar === '') {
                    $ar = $en;
                    $result->count('ar_copied_from_en');
                }
                if ($en === '') {
                    $en = $ar !== '' ? $ar : "Unit $id";
                    $result->count('en_missing');
                }

                $code = LegacySlug::orId($en, $id);
                if (isset($seen[$code])) {
                    $code .= "-$id";                             // A-02 handling
                    $result->count('code_deduped');
                }
                $seen[$code] = true;
                if (strlen($code) > 16) {
                    throw new RuntimeException("size_types.id=$id: unit code [$code] exceeds catalog_units.code(16). Refusing to truncate silently.");
                }

                $units[] = ['id' => $id, 'code' => $code, 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                $translations[] = ['unit_id' => $id, 'locale' => 'en', 'name' => $en];
                $translations[] = ['unit_id' => $id, 'locale' => 'ar', 'name' => $ar];
                $result->read++;
            }

            $result->writes->add($ctx->writer->upsert('catalog_units', ['id', 'code', 'created_at', 'updated_at'], ['id'], ['code', 'updated_at'], $units));
            $result->writes->add($ctx->writer->upsert('catalog_unit_translations', ['unit_id', 'locale', 'name'], ['unit_id', 'locale'], ['name'], $translations));
        });
    }
}
