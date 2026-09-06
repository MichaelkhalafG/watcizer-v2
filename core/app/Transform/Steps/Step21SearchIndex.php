<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 21 — catalog_product_search (product_id, locale, body): title ∥ brand ∥ model_name ∥
 * model_number ∥ search_keywords ∥ category names, per locale. Built from legacy names so
 * the index matches what the legacy storefront searches today; the future ProductIndexer
 * rebuilds it from clean tables.
 */
final class Step21SearchIndex implements Step
{
    public function number(): int
    {
        return 21;
    }

    public function name(): string
    {
        return 'search_index';
    }

    public function target(): string
    {
        return 'catalog_product_search';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $brands = $ctx->legacyTranslations('brand_translations', 'brand_id', 'brand_name');
        $types = $ctx->legacyTranslations('category_type_translations', 'category_type_id', 'category_type_name');
        $subs = $ctx->legacyTranslations('sub_type_translations', 'sub_type_id', 'sub_type_name');

        $ctx->chunkLegacy('products', ['id', 'brand_id', 'category_type_id', 'sub_type_id', 'model_number', 'search_keywords'], function (Collection $products) use ($ctx, $result, $brands, $types, $subs): void {
            $ids = $products->map(fn ($p) => Row::int($p, 'id'))->all();

            /** @var array<int, array<string, array{title: string, model_name: string}>> */
            $tr = [];
            $rows = $ctx->legacy->table('product_translations')->select(['product_id', 'locale', 'product_title', 'model_name'])->whereIn('product_id', $ids)->whereIn('locale', ['ar', 'en'])->orderBy('id')->get();
            foreach ($rows as $t) {
                $tr[Row::int($t, 'product_id')][Row::str($t, 'locale')] = ['title' => trim(Row::str($t, 'product_title')), 'model_name' => trim(Row::nstr($t, 'model_name') ?? '')];
            }

            $out = [];
            foreach ($products as $p) {
                $id = Row::int($p, 'id');
                $result->read++;
                $brandId = Row::int($p, 'brand_id');
                $typeId = Row::nint($p, 'category_type_id');
                $subId = Row::nint($p, 'sub_type_id');
                foreach (['en', 'ar'] as $locale) {
                    $t = $tr[$id][$locale] ?? $tr[$id][$locale === 'en' ? 'ar' : 'en'] ?? ['title' => "Product $id", 'model_name' => ''];
                    $parts = [
                        $t['title'],
                        $brands[$brandId][$locale] ?? '',
                        $t['model_name'],
                        trim(Row::nstr($p, 'model_number') ?? ''),
                        trim(Row::nstr($p, 'search_keywords') ?? ''),
                        $typeId === null ? '' : ($types[$typeId][$locale] ?? ''),
                        $subId === null ? '' : ($subs[$subId][$locale] ?? ''),
                    ];
                    $body = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, fn (string $s) => $s !== ''))));
                    $out[] = ['product_id' => $id, 'locale' => $locale, 'body' => $body];
                }
            }
            $result->writes->add($ctx->writer->upsert('catalog_product_search', ['product_id', 'locale', 'body'], ['product_id', 'locale'], ['body'], $out));
        });
    }
}
