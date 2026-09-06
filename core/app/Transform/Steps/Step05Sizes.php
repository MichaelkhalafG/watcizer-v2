<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 5 — new_sizes → catalog_sizes (+translations). catalog_sizes has no other
 * source, so new_sizes.id is carried over unchanged (recorded in transform_id_map
 * all the same); `sort` is the row's position within its `type` by legacy id.
 */
final class Step05Sizes implements Step
{
    public function number(): int
    {
        return 5;
    }

    public function name(): string
    {
        return 'sizes';
    }

    public function target(): string
    {
        return 'catalog_sizes, catalog_size_translations, transform_id_map';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        /** @var array<string, int> */
        $positions = [];

        $ctx->chunkLegacy('new_sizes', ['id', 'name_en', 'name_ar', 'type', 'is_active', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, &$positions): void {
            $sizes = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $type = trim(Row::str($row, 'type'));
                if ($type === '') {
                    $type = 'general';
                }
                if (strlen($type) > 24) {
                    throw new RuntimeException("new_sizes.id=$id: type [$type] exceeds catalog_sizes.type(24).");
                }
                $positions[$type] = ($positions[$type] ?? 0) + 1;
                $en = trim(Row::str($row, 'name_en'));
                $ar = trim(Row::str($row, 'name_ar'));
                if (! Row::bool($row, 'is_active')) {
                    $result->count('inactive_flag_dropped');   // catalog_sizes has no is_active column
                }

                $sizes[] = ['id' => $id, 'type' => $type, 'sort' => $positions[$type], 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                $translations[] = ['size_id' => $id, 'locale' => 'en', 'name' => $en !== '' ? $en : ($ar !== '' ? $ar : "Size $id")];
                $translations[] = ['size_id' => $id, 'locale' => 'ar', 'name' => $ar !== '' ? $ar : $en];
                $ctx->idMap->remember('new_sizes', $id, 'catalog_sizes', $id);
                $result->read++;
            }
            $result->writes->add($ctx->writer->upsert('catalog_sizes', ['id', 'type', 'sort', 'created_at', 'updated_at'], ['id'], ['type', 'sort', 'updated_at'], $sizes));
            $result->writes->add($ctx->writer->upsert('catalog_size_translations', ['size_id', 'locale', 'name'], ['size_id', 'locale'], ['name'], $translations));
        });
    }
}
