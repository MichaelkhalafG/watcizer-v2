<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 7 — product_translations → catalog_product_translations keyed (product_id, locale).
 * product_title → title; meta_title/meta_description from products.seo_title /
 * seo_meta_description onto the EN row only; empty/missing AR → copy of EN (A-09).
 */
final class Step07ProductTranslations implements Step
{
    /** @var list<string> */
    private const COLUMNS = ['product_id', 'locale', 'title', 'short_description', 'long_description', 'model_name', 'country', 'stone', 'meta_title', 'meta_description'];

    /** @var list<string> */
    private const UPDATE = ['title', 'short_description', 'long_description', 'model_name', 'country', 'stone', 'meta_title', 'meta_description'];

    public function number(): int
    {
        return 7;
    }

    public function name(): string
    {
        return 'product_translations';
    }

    public function target(): string
    {
        return 'catalog_product_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $ctx->chunkLegacy('products', ['id', 'seo_title', 'seo_meta_description'], function (Collection $products) use ($ctx, $result): void {
            $ids = $products->map(fn ($p) => Row::int($p, 'id'))->all();

            /** @var array<int, array<string, array<string, string|null>>> */
            $byProduct = [];
            $legacyRows = $ctx->legacy->table('product_translations')
                ->select(['product_id', 'locale', 'product_title', 'model_name', 'country', 'stone', 'long_description', 'short_description'])
                ->whereIn('product_id', $ids)
                ->orderBy('id')
                ->get();
            foreach ($legacyRows as $t) {
                $locale = Row::str($t, 'locale');
                if (! in_array($locale, ['ar', 'en'], true)) {
                    $result->count('locale_ignored');
                    $result->read++;

                    continue;
                }
                $byProduct[Row::int($t, 'product_id')][$locale] = [
                    'title' => trim(Row::str($t, 'product_title')),
                    'short_description' => Row::nstr($t, 'short_description'),
                    'long_description' => Row::nstr($t, 'long_description'),
                    'model_name' => Row::nstr($t, 'model_name'),
                    'country' => Row::nstr($t, 'country'),
                    'stone' => Row::nstr($t, 'stone'),
                ];
                $result->read++;
            }

            $out = [];
            foreach ($products as $p) {
                $id = Row::int($p, 'id');
                $en = $byProduct[$id]['en'] ?? null;
                $ar = $byProduct[$id]['ar'] ?? null;

                if ($en === null || $en['title'] === '') {
                    $result->count('en_missing_copied_from_ar');       // A-09 (EN variant)
                    $en = $ar !== null && $ar['title'] !== '' ? $ar : ($en ?? ['title' => "Product $id", 'short_description' => null, 'long_description' => null, 'model_name' => null, 'country' => null, 'stone' => null]);
                    if ($en['title'] === '') {
                        $en['title'] = "Product $id";
                    }
                }
                if ($ar === null || $ar['title'] === '') {
                    $result->count('ar_missing_copied_from_en');       // A-09
                    $ar = $en;
                }

                $out[] = $this->row($ctx, $result, $id, 'en', $en, $this->limit($ctx, $result, $id, 'seo_title', Row::nstr($p, 'seo_title')), $this->limit($ctx, $result, $id, 'seo_meta_description', Row::nstr($p, 'seo_meta_description')));
                $out[] = $this->row($ctx, $result, $id, 'ar', $ar, null, null);
            }

            $result->writes->add($ctx->writer->upsert('catalog_product_translations', self::COLUMNS, ['product_id', 'locale'], self::UPDATE, $out));
        });
    }

    /**
     * @param  array<string, string|null>  $t
     * @return array<string, mixed>
     */
    private function row(TransformContext $ctx, StepResult $result, int $id, string $locale, array $t, ?string $metaTitle, ?string $metaDescription): array
    {
        return [
            'product_id' => $id,
            'locale' => $locale,
            'title' => $this->limit($ctx, $result, $id, "title.$locale", $t['title'] ?? '') ?? '',
            'short_description' => self::nullIfBlank($t['short_description'] ?? null),
            'long_description' => self::nullIfBlank($t['long_description'] ?? null),
            'model_name' => self::nullIfBlank($t['model_name'] ?? null),
            'country' => self::nullIfBlank($t['country'] ?? null),
            'stone' => self::nullIfBlank($t['stone'] ?? null),
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
        ];
    }

    /** varchar(255) targets: never truncate silently — count it and record the diff. */
    private function limit(TransformContext $ctx, StepResult $result, int $id, string $field, ?string $value): ?string
    {
        $value = self::nullIfBlank($value);
        if ($value !== null && mb_strlen($value) > 255) {
            $result->count('truncated_255');
            $ctx->diff('LEN', 'products', $id, "$field length ".mb_strlen($value), 'truncated to 255');

            return mb_substr($value, 0, 255);
        }

        return $value;
    }

    private static function nullIfBlank(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : $v;
    }
}
