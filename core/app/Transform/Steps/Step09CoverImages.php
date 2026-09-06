<?php

namespace App\Transform\Steps;

use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 9 — products.image → catalog_product_images cover row (is_cover = 1, sort = 0,
 * path = 'Product/<file>'). The legacy PDP shows this file first, then the gallery
 * (ProductResource), which is exactly what (is_cover DESC, sort) reproduces.
 *
 * Ids: gallery rows preserve product_images.id (step 10), so cover rows live at
 * cover_image_id_offset + product id — deterministic, collision-free, recorded in
 * transform_id_map. Tripwire: abort if a legacy gallery id ever reaches the offset.
 */
final class Step09CoverImages implements Step
{
    /** @var list<string> */
    public const COLUMNS = ['id', 'product_id', 'path', 'is_cover', 'sort', 'width', 'height', 'alt_en', 'alt_ar', 'renditions', 'created_at', 'updated_at'];

    public function number(): int
    {
        return 9;
    }

    public function name(): string
    {
        return 'cover_images';
    }

    public function target(): string
    {
        return 'catalog_product_images (covers), transform_id_map';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $offset = $ctx->configInt('cover_image_id_offset');
        $max = $ctx->legacy->table('product_images')->max('id');
        $maxGalleryId = is_numeric($max) ? (int) $max : 0;
        if ($maxGalleryId >= $offset) {
            throw new RuntimeException("Tripwire: legacy product_images.id ($maxGalleryId) reached cover_image_id_offset ($offset); raise the offset before running.");
        }

        $ctx->chunkLegacy('products', ['id', 'image', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $offset): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $result->read++;
                $file = trim(Row::str($row, 'image'));
                if ($file === '') {
                    $result->count('no_cover_file');
                    $ctx->diff('A-11', 'products', $id, 'image=""', 'no cover row created');

                    continue;
                }
                $coverId = $offset + $id;
                $ctx->idMap->remember('products.image', $id, 'catalog_product_images', $coverId);
                $out[] = [
                    'id' => $coverId,
                    'product_id' => $id,
                    'path' => 'Product/'.$file,
                    'is_cover' => 1,
                    'sort' => 0,
                    'width' => null,
                    'height' => null,
                    'alt_en' => null,
                    'alt_ar' => null,
                    'renditions' => null,
                    'created_at' => Row::nstr($row, 'created_at'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
            }
            $result->writes->add($ctx->writer->upsert('catalog_product_images', self::COLUMNS, ['id'], ['product_id', 'path', 'is_cover', 'sort', 'updated_at'], $out));
        });
    }
}
