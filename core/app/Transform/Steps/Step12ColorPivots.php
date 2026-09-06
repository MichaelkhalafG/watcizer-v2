<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 12 — color_dial_product / color_band_product → catalog_product_color with
 * role = dial / band; for family != watch the dial pivot is ALSO written as role = main
 * (the fashion "main colour"). PK (product_id, color_id, role); duplicates collapse.
 */
final class Step12ColorPivots implements Step
{
    public function number(): int
    {
        return 12;
    }

    public function name(): string
    {
        return 'color_pivots';
    }

    public function target(): string
    {
        return 'catalog_product_color';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $products = $ctx->families();
        $colors = $ctx->legacyIdSet('colors');
        $seen = [];

        foreach (['color_dial_product' => 'dial', 'color_band_product' => 'band'] as $table => $role) {
            $ctx->chunkLegacy($table, ['id', 'product_id', 'color_id'], function (Collection $rows) use ($ctx, $result, $table, $role, $products, $colors, &$seen): void {
                $out = [];
                foreach ($rows as $row) {
                    $result->read++;
                    $productId = Row::int($row, 'product_id');
                    $colorId = Row::int($row, 'color_id');
                    if (! isset($products[$productId]) || ! isset($colors[$colorId])) {
                        $result->count("$table:orphan_skipped");                 // A-19
                        $ctx->diff('A-19', $table, Row::int($row, 'id'), "product_id=$productId color_id=$colorId", 'orphan, skipped');

                        continue;
                    }
                    $roles = [$role];
                    if ($role === 'dial' && $products[$productId] !== 'watch') {
                        $roles[] = 'main';
                    }
                    foreach ($roles as $r) {
                        $key = "$productId:$colorId:$r";
                        if (isset($seen[$key])) {
                            $result->count("$table:duplicate_collapsed");        // A-19

                            continue;
                        }
                        $seen[$key] = true;
                        $out[] = ['product_id' => $productId, 'color_id' => $colorId, 'role' => $r];
                        $result->count("role:$r");
                    }
                }
                $result->writes->add($ctx->writer->upsert('catalog_product_color', ['product_id', 'color_id', 'role'], ['product_id', 'color_id', 'role'], [], $out));
            });
        }
    }
}
