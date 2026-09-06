<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 11 — feature_product / gender_product → catalog_product_feature / catalog_product_gender.
 * Composite PK on the clean side; legacy pivots allow duplicates, so rows are deduped
 * in memory and written with an upsert whose "update" is a no-op (INSERT IGNORE semantics).
 * Orphan legacy pivot rows (product or lookup missing) are skipped and counted (A-19).
 */
final class Step11FeatureGenderPivots implements Step
{
    public function number(): int
    {
        return 11;
    }

    public function name(): string
    {
        return 'feature_gender_pivots';
    }

    public function target(): string
    {
        return 'catalog_product_feature, catalog_product_gender';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $products = $ctx->families();
        $this->pivot($ctx, $result, 'feature_product', 'feature_id', 'features', 'catalog_product_feature', $products);
        $this->pivot($ctx, $result, 'gender_product', 'gender_id', 'genders', 'catalog_product_gender', $products);
    }

    /**
     * @param  non-empty-string  $fk
     * @param  array<int, string>  $products
     */
    private function pivot(TransformContext $ctx, StepResult $result, string $legacyTable, string $fk, string $lookupTable, string $cleanTable, array $products): void
    {
        $lookups = $ctx->legacyIdSet($lookupTable);
        $seen = [];

        $ctx->chunkLegacy($legacyTable, ['id', 'product_id', $fk], function (Collection $rows) use ($ctx, $result, $legacyTable, $fk, $cleanTable, $products, $lookups, &$seen): void {
            $out = [];
            foreach ($rows as $row) {
                $result->read++;
                $productId = Row::int($row, 'product_id');
                $lookupId = Row::int($row, $fk);
                if (! isset($products[$productId]) || ! isset($lookups[$lookupId])) {
                    $result->count("$legacyTable:orphan_skipped");            // A-19
                    $ctx->diff('A-19', $legacyTable, Row::int($row, 'id'), "product_id=$productId $fk=$lookupId", 'orphan, skipped');

                    continue;
                }
                $key = "$productId:$lookupId";
                if (isset($seen[$key])) {
                    $result->count("$legacyTable:duplicate_collapsed");        // A-19
                    $ctx->diff('A-19', $legacyTable, Row::int($row, 'id'), "product_id=$productId $fk=$lookupId", 'duplicate, collapsed');

                    continue;
                }
                $seen[$key] = true;
                $out[] = ['product_id' => $productId, $fk => $lookupId];
            }
            $result->writes->add($ctx->writer->upsert($cleanTable, ['product_id', $fk], ['product_id', $fk], [], $out));
        });
    }
}
