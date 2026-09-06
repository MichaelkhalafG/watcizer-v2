<?php

namespace App\Transform\Steps;

use App\Transform\FamilyResolver;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;
use stdClass;

/**
 * Step 6 — products → catalog_products, id preserved (CLEAN_CORE_STUDY §2.2, §2.9.2).
 *
 * Reads an EXPLICIT list of legacy columns (no hs_code — it does not exist, drift
 * report S-01) and writes an explicit list of clean columns. Dropped on purpose:
 * main_category_id, sub_category_id, product_type_id, status, tags, views_count,
 * seo_slug, percentage_discount (derived), category_type_id/sub_type_id (become
 * placements in step 19), the watch columns (step 8), image (step 9), seo_* (step 7).
 */
final class Step06Products implements Step
{
    /** @var list<string> */
    public const LEGACY_COLUMNS = [
        'id', 'category_type_id', 'sub_type_id', 'brand_id', 'grade_id', 'sku_unique', 'model_number',
        'warranty_years', 'wa_code', 'average_rate', 'purchase_price', 'selling_price',
        'sale_price_after_discount', 'percentage_discount', 'stock', 'market_stock',
        'low_stock_threshold', 'extra_attributes', 'search_keywords', 'active', 'created_by',
        'updated_by', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    public const CLEAN_COLUMNS = [
        'id', 'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'model_number', 'hs_code',
        'purchase_price', 'selling_price', 'sale_price', 'currency', 'stock_express', 'stock_market',
        'in_stock', 'low_stock_threshold', 'warranty_years', 'is_active', 'rating_avg', 'rating_count',
        'search_keywords', 'specs', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** Refreshed on re-run: everything except the preserved id, created_at and the dashboard-owned deleted_at. @var list<string> */
    private const UPDATE_COLUMNS = [
        'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'model_number', 'hs_code',
        'purchase_price', 'selling_price', 'sale_price', 'currency', 'stock_express', 'stock_market',
        'in_stock', 'low_stock_threshold', 'warranty_years', 'is_active', 'rating_avg', 'rating_count',
        'search_keywords', 'specs', 'created_by', 'updated_by', 'updated_at',
    ];

    public function number(): int
    {
        return 6;
    }

    public function name(): string
    {
        return 'products';
    }

    public function target(): string
    {
        return 'catalog_products';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        // A-06: duplicate wa_code aborts the step (must be fixed in legacy first).
        $dupWa = $ctx->legacy->table('products')->select('wa_code')->groupBy('wa_code')->havingRaw('COUNT(*) > 1')->pluck('wa_code');
        if ($dupWa->isNotEmpty()) {
            throw new RuntimeException('A-06: duplicate wa_code in legacy products ['.$dupWa->implode(', ').']. Fix in legacy, then re-run.');
        }
        $emptyWa = $ctx->legacy->table('products')->where('wa_code', '')->orWhereNull('wa_code')->count();
        if ($emptyWa > 0) {
            throw new RuntimeException("A-06: $emptyWa legacy products have an empty wa_code. Fix in legacy, then re-run.");
        }

        // A-05: for a duplicated sku_unique only the lowest id keeps it.
        /** @var array<string, int> */
        $skuKeeper = [];
        foreach ($ctx->legacy->table('products')->selectRaw('TRIM(sku_unique) AS sku, MIN(id) AS keeper')->whereNotNull('sku_unique')->whereRaw("TRIM(sku_unique) <> ''")->groupByRaw('TRIM(sku_unique)')->havingRaw('COUNT(*) > 1')->get() as $row) {
            $skuKeeper[Row::str($row, 'sku')] = Row::int($row, 'keeper');
        }

        $users = $ctx->legacyIdSet('users');

        /** @var array<int, array{avg: float, count: int}> */
        $ratings = [];
        foreach ($ctx->legacy->table('product_ratings')->selectRaw('product_id, AVG(rating) AS avg_rating, COUNT(*) AS n')->groupBy('product_id')->get() as $row) {
            $ratings[Row::int($row, 'product_id')] = ['avg' => Row::nfloat($row, 'avg_rating') ?? 0.0, 'count' => Row::int($row, 'n')];
        }

        $families = new FamilyResolver($ctx->configArray('family'));
        $maxId = 0;

        $ctx->chunkLegacy('products', self::LEGACY_COLUMNS, function (Collection $rows) use ($ctx, $result, $skuKeeper, $users, $ratings, $families, &$maxId): void {
            $out = [];
            foreach ($rows as $row) {
                $out[] = $this->map($ctx, $result, $row, $skuKeeper, $users, $ratings, $families);
                $maxId = max($maxId, Row::int($row, 'id'));
                $result->read++;
            }
            $result->writes->add($ctx->writer->upsert('catalog_products', self::CLEAN_COLUMNS, ['id'], self::UPDATE_COLUMNS, $out));
        });

        $result->count('max_id', $maxId);
        $result->note('AUTO_INCREMENT target for catalog_products: '.($maxId + 1).' (DDL issued by the command after commit; skipped under --dry-run).');
    }

    /**
     * @param  array<string, int>  $skuKeeper
     * @param  array<int, true>  $users
     * @param  array<int, array{avg: float, count: int}>  $ratings
     * @return array<string, mixed>
     */
    private function map(TransformContext $ctx, StepResult $result, stdClass $row, array $skuKeeper, array $users, array $ratings, FamilyResolver $families): array
    {
        $id = Row::int($row, 'id');

        $categoryTypeId = Row::nint($row, 'category_type_id');
        $subTypeId = Row::nint($row, 'sub_type_id');
        $typeEn = $categoryTypeId === null ? '' : $ctx->legacyName('category_type_translations', 'category_type_id', 'category_type_name', $categoryTypeId);
        $subEn = $subTypeId === null ? '' : $ctx->legacyName('sub_type_translations', 'sub_type_id', 'sub_type_name', $subTypeId);
        $extra = Row::nstr($row, 'extra_attributes');
        $family = $families->resolve($typeEn, $extra, $subEn);
        $ctx->setFamily($id, $family);
        $result->count("family:$family");

        $sku = Row::nstr($row, 'sku_unique');
        $sku = $sku === null || trim($sku) === '' ? null : trim($sku);
        if ($sku !== null && isset($skuKeeper[$sku]) && $skuKeeper[$sku] !== $id) {
            $result->count('sku_nulled_duplicate');           // A-05
            $sku = null;
        }

        $selling = Row::money($row, 'selling_price');
        $sale = Row::nmoney($row, 'sale_price_after_discount');
        if ($sale !== null && ! ((float) $sale > 0 && (float) $sale < (float) $selling)) {
            $result->count('sale_price_nulled');              // A-04
            $sale = null;
        }
        $storedPct = Row::nfloat($row, 'percentage_discount');
        if ($sale !== null) {
            $derived = (int) round(((float) $selling - (float) $sale) / (float) $selling * 100);
            if ((int) round($storedPct ?? 0.0) !== $derived) {
                $ctx->diff('A-22', 'products', $id, 'percentage_discount='.var_export($storedPct, true), "derived=$derived");
            }
        }

        $warrantyRaw = Row::nstr($row, 'warranty_years');
        $warranty = null;
        if ($warrantyRaw !== null && trim($warrantyRaw) !== '') {
            if (preg_match('/^\d+$/', trim($warrantyRaw)) === 1 && (int) trim($warrantyRaw) <= 255) {
                $warranty = (int) trim($warrantyRaw);
            } else {
                $result->count('warranty_nulled');            // A-07
            }
        }

        $createdBy = Row::nint($row, 'created_by');
        if ($createdBy !== null && ! isset($users[$createdBy])) {
            $result->count('created_by_nulled');              // A-08
            $createdBy = null;
        }
        $updatedBy = Row::nint($row, 'updated_by');
        if ($updatedBy !== null && ! isset($users[$updatedBy])) {
            $result->count('updated_by_nulled');              // A-08
            $updatedBy = null;
        }

        $specs = null;
        if ($extra !== null && FamilyResolver::jsonKeys($extra) !== []) {
            $decoded = json_decode($extra, true);
            $specs = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            if ($specs === false) {
                $specs = null;
            }
        } elseif ($extra !== null && trim($extra) !== '' && ! in_array(trim($extra), ['[]', '{}', 'null'], true)) {
            $result->count('extra_attributes_invalid_json');  // A-10 extension
        }

        $rating = $ratings[$id] ?? null;
        $ratingAvg = $rating === null ? null : number_format(min(9.99, round($rating['avg'], 2)), 2, '.', '');
        $legacyAvg = Row::nfloat($row, 'average_rate');
        if ($rating === null && $legacyAvg !== null && $legacyAvg > 0) {
            $ctx->diff('A-22r', 'products', $id, "average_rate=$legacyAvg", 'no product_ratings rows → rating_avg NULL');
        }

        $stockExpress = Row::int($row, 'stock');
        $stockMarket = Row::nint($row, 'market_stock') ?? 0;
        $keywords = Row::nstr($row, 'search_keywords');

        return [
            'id' => $id,
            'family' => $family,
            'brand_id' => Row::int($row, 'brand_id'),
            'grade_id' => Row::nint($row, 'grade_id'),
            'wa_code' => trim(Row::str($row, 'wa_code')),
            'sku' => $sku,
            'model_number' => self::nullIfBlank(Row::nstr($row, 'model_number')),
            'hs_code' => null,
            'purchase_price' => Row::money($row, 'purchase_price'),
            'selling_price' => $selling,
            'sale_price' => $sale,
            'currency' => 'EGP',
            'stock_express' => $stockExpress,
            'stock_market' => $stockMarket,
            'in_stock' => ($stockExpress > 0 || $stockMarket > 0) ? 1 : 0,
            'low_stock_threshold' => max(0, Row::int($row, 'low_stock_threshold')),
            'warranty_years' => $warranty,
            'is_active' => Row::bool($row, 'active') ? 1 : 0,
            'rating_avg' => $ratingAvg,
            'rating_count' => $rating === null ? 0 : $rating['count'],
            'search_keywords' => self::nullIfBlank($keywords),
            'specs' => $specs,
            'created_by' => $createdBy,
            'updated_by' => $updatedBy,
            'created_at' => Row::nstr($row, 'created_at'),
            'updated_at' => Row::nstr($row, 'updated_at'),
            'deleted_at' => null,
        ];
    }

    private static function nullIfBlank(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : $v;
    }
}
