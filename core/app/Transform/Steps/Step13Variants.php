<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 13 — product_variants (flat live shape) → catalog_product_variants, id preserved.
 * label = name; price_delta = price − product.selling_price when price is set;
 * stock_express = stock; duplicate sku → NULL for later ids (A-13).
 * A-23 tripwire: the step aborts if the legacy table is not the flat shape confirmed
 * 2026-09-05 (id, product_id, name, price, stock, sku, image, timestamps).
 */
final class Step13Variants implements Step
{
    /** @var list<string> */
    public const FLAT_SHAPE = ['id', 'product_id', 'name', 'price', 'stock', 'sku', 'image', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const CLEAN = ['id', 'product_id', 'sku', 'label', 'color_id', 'size_id', 'price_delta', 'stock_express', 'stock_market', 'is_active', 'sort', 'created_at', 'updated_at'];

    public function number(): int
    {
        return 13;
    }

    public function name(): string
    {
        return 'variants';
    }

    public function target(): string
    {
        return 'catalog_product_variants';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $columns = $ctx->legacy->columns('product_variants');
        sort($columns);
        $expected = self::FLAT_SHAPE;
        sort($expected);
        if ($columns !== $expected) {
            throw new RuntimeException('A-23 tripwire: product_variants shape changed — got ['.implode(', ', $columns).'], expected the flat shape ['.implode(', ', $expected).']. Update step 13 before running.');
        }

        /** @var array<int, string> */
        $selling = [];
        foreach ($ctx->legacy->table('products')->select(['id', 'selling_price'])->orderBy('id')->cursor() as $p) {
            $selling[Row::int($p, 'id')] = Row::money($p, 'selling_price');
        }

        /** @var array<string, int> */
        $skuKeeper = [];
        foreach ($ctx->legacy->table('product_variants')->selectRaw('TRIM(sku) AS sku, MIN(id) AS keeper')->whereNotNull('sku')->whereRaw("TRIM(sku) <> ''")->groupByRaw('TRIM(sku)')->havingRaw('COUNT(*) > 1')->get() as $row) {
            $skuKeeper[Row::str($row, 'sku')] = Row::int($row, 'keeper');
        }

        $ctx->chunkLegacy('product_variants', self::FLAT_SHAPE, function (Collection $rows) use ($ctx, $result, $selling, $skuKeeper): void {
            $out = [];
            $position = [];
            foreach ($rows as $row) {
                $result->read++;
                $id = Row::int($row, 'id');
                $productId = Row::int($row, 'product_id');
                if (! isset($selling[$productId])) {
                    $result->count('orphan_skipped');
                    $ctx->diff('A-19', 'product_variants', $id, "product_id=$productId", 'orphan, skipped');

                    continue;
                }
                $sku = Row::nstr($row, 'sku');
                $sku = $sku === null || trim($sku) === '' ? null : trim($sku);
                if ($sku !== null && isset($skuKeeper[$sku]) && $skuKeeper[$sku] !== $id) {
                    $result->count('sku_nulled_duplicate');                     // A-13
                    $sku = null;
                }
                $price = Row::nmoney($row, 'price');
                $position[$productId] = ($position[$productId] ?? 0) + 1;

                $out[] = [
                    'id' => $id,
                    'product_id' => $productId,
                    'sku' => $sku,
                    'label' => mb_substr(trim(Row::str($row, 'name')) !== '' ? trim(Row::str($row, 'name')) : "Variant $id", 0, 100),
                    'color_id' => null,
                    'size_id' => null,
                    'price_delta' => $price === null ? '0.00' : number_format((float) $price - (float) $selling[$productId], 2, '.', ''),
                    'stock_express' => Row::int($row, 'stock'),
                    'stock_market' => 0,
                    'is_active' => 1,
                    'sort' => $position[$productId],
                    'created_at' => Row::nstr($row, 'created_at'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
            }
            $result->writes->add($ctx->writer->upsert('catalog_product_variants', self::CLEAN, ['id'], ['product_id', 'sku', 'label', 'price_delta', 'stock_express', 'sort', 'updated_at'], $out));
        });
    }
}
