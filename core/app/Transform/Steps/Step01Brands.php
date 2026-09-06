<?php

namespace App\Transform\Steps;

use App\Support\LegacySlug;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;

/** Step 1 — brands + brand_translations → catalog_brands (+translations), id preserved. */
final class Step01Brands implements Step
{
    /** @var list<string> */
    private const COLUMNS = ['id', 'slug', 'logo_path', 'is_active', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const TR_COLUMNS = ['brand_id', 'locale', 'name'];

    public function number(): int
    {
        return 1;
    }

    public function name(): string
    {
        return 'brands';
    }

    public function target(): string
    {
        return 'catalog_brands, catalog_brand_translations';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $tr = $ctx->legacyTranslations('brand_translations', 'brand_id', 'brand_name');
        $seenSlugs = [];

        $ctx->chunkLegacy('brands', ['id', 'image', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $tr, &$seenSlugs): void {
            $brands = [];
            $translations = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $en = trim($tr[$id]['en'] ?? '');
                $ar = trim($tr[$id]['ar'] ?? '');
                if ($ar === '') {
                    $ar = $en;                                   // A-01 handling: copy EN
                    $result->count('ar_copied_from_en');
                }
                if ($en === '') {
                    $en = $ar !== '' ? $ar : "Brand $id";
                    $result->count('en_missing');
                }

                $slug = LegacySlug::orId($en, $id);
                if (isset($seenSlugs[$slug])) {
                    $slug .= "-$id";                             // dedupe rule §2.9.2 step 1
                    $result->count('slug_deduped');
                }
                $seenSlugs[$slug] = true;

                $image = Row::nstr($row, 'image');
                $brands[] = [
                    'id' => $id,
                    'slug' => $slug,
                    'logo_path' => ($image !== null && trim($image) !== '') ? 'Brand/'.$image : null,
                    'is_active' => 1,
                    'created_at' => Row::nstr($row, 'created_at'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
                $translations[] = ['brand_id' => $id, 'locale' => 'en', 'name' => $en];
                $translations[] = ['brand_id' => $id, 'locale' => 'ar', 'name' => $ar];
                $result->read++;
            }

            $result->writes->add($ctx->writer->upsert('catalog_brands', self::COLUMNS, ['id'], ['slug', 'logo_path', 'is_active', 'updated_at'], $brands));
            $result->writes->add($ctx->writer->upsert('catalog_brand_translations', self::TR_COLUMNS, ['brand_id', 'locale'], ['name'], $translations));
        });
    }
}
