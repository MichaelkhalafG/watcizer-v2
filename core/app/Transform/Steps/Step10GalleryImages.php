<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/**
 * Step 10 — product_images → catalog_product_images, id preserved, path = 'Product_image/<file>',
 * is_cover = 0, sort = legacy sort + 1 (the cover from step 9 holds sort 0).
 *
 * The legacy `is_cover` flag on product_images means "first gallery upload" (admin sets it
 * for sort 0); it is NOT the PDP cover, which is products.image. It is therefore dropped —
 * rehearsal #1 finding X-01 — and the legacy alt_en/alt_ar (never filled) are carried as-is.
 */
final class Step10GalleryImages implements Step
{
    public function number(): int
    {
        return 10;
    }

    public function name(): string
    {
        return 'gallery_images';
    }

    public function target(): string
    {
        return 'catalog_product_images (gallery)';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $ctx->chunkLegacy('product_images', ['id', 'product_id', 'image', 'is_cover', 'sort', 'alt_ar', 'alt_en', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result): void {
            $out = [];
            foreach ($rows as $row) {
                $result->read++;
                $file = trim(Row::str($row, 'image'));
                if ($file === '') {
                    $result->count('empty_file_skipped');
                    $ctx->diff('A-12', 'product_images', Row::int($row, 'id'), 'image=""', 'row skipped');

                    continue;
                }
                if (Row::bool($row, 'is_cover')) {
                    $result->count('legacy_is_cover_flag_dropped');   // X-01
                }
                $out[] = [
                    'id' => Row::int($row, 'id'),
                    'product_id' => Row::int($row, 'product_id'),
                    'path' => 'Product_image/'.$file,
                    'is_cover' => 0,
                    'sort' => min(65535, Row::int($row, 'sort') + 1),
                    'width' => null,
                    'height' => null,
                    'alt_en' => self::nullIfBlank(Row::nstr($row, 'alt_en')),
                    'alt_ar' => self::nullIfBlank(Row::nstr($row, 'alt_ar')),
                    'renditions' => null,
                    'created_at' => Row::nstr($row, 'created_at'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
            }
            $result->writes->add($ctx->writer->upsert('catalog_product_images', Step09CoverImages::COLUMNS, ['id'], ['product_id', 'path', 'is_cover', 'sort', 'alt_en', 'alt_ar', 'updated_at'], $out));
        });
    }

    private static function nullIfBlank(?string $v): ?string
    {
        return $v === null || trim($v) === '' ? null : $v;
    }
}
