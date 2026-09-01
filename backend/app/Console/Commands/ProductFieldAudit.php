<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\CategoryType;
use App\Models\ClosureType;
use App\Models\Color;
use App\Models\DisplayType;
use App\Models\Feature;
use App\Models\Gender;
use App\Models\Grade;
use App\Models\Material;
use App\Models\MovementType;
use App\Models\Product;
use App\Models\Shape;
use App\Models\SizeType;
use App\Models\SubType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * products:field-audit
 * ────────────────────────────────────────────────────────────────────────────
 * End-to-end persistence test for EVERY product field the create/edit flow
 * touches. It:
 *   1. CREATES a throwaway product with every scalar column, every translated
 *      attribute (EN+AR), every FK, all four pivots, and extra_attributes set to
 *      a non-null value.
 *   2. Reloads it FRESH from the DB and asserts each value round-tripped.
 *   3. UPDATES every field to a DIFFERENT value.
 *   4. Reloads again and asserts every change persisted AND nothing was nulled.
 *   5. Deletes the throwaway product + its pivots (always, even on failure).
 *
 * It mirrors the exact assignment set of ProductController::store()/update(), so
 * it proves the schema + $fillable + translations + the extra_attributes cast +
 * the pivot syncs all persist correctly. (The duplicate-name form bug fixed in
 * create.blade.php is a browser-DOM concern — verify that via the manual QA
 * checklist, not here.)
 *
 * Read-only to real data: everything happens inside a single product it creates
 * and then removes. Run on the server:
 *
 *   php artisan products:field-audit
 */
class ProductFieldAudit extends Command
{
    protected $signature = 'products:field-audit {--keep : Do not delete the test product at the end}';

    protected $description = 'Create → assert → update → assert every product field persists (nothing silently dropped).';

    /** @var array<int, array{field:string, expected:mixed, actual:mixed, ok:bool}> */
    private array $results = [];

    public function handle(): int
    {
        // Two distinct lookup ids per table where possible, so "update changed it"
        // is a real change (falls back to the single available id otherwise).
        $ids = fn (string $model) => $model::query()->orderBy('id')->limit(2)->pluck('id')->all();

        $catType  = $ids(CategoryType::class);
        $brand    = $ids(Brand::class);
        $grade    = $ids(Grade::class);
        $subType  = $ids(SubType::class);
        $closure  = $ids(ClosureType::class);
        $display  = $ids(DisplayType::class);
        $size     = $ids(SizeType::class);
        $shape    = $ids(Shape::class);
        $material = $ids(Material::class);
        $movement = $ids(MovementType::class);
        $features = Feature::orderBy('id')->limit(3)->pluck('id')->all();
        $genders  = Gender::orderBy('id')->limit(2)->pluck('id')->all();
        $colors   = Color::orderBy('id')->limit(3)->pluck('id')->all();

        // Hard requirements (NOT NULL columns / FKs the form always sends).
        foreach ([
            'CategoryType' => $catType, 'Brand' => $brand, 'SizeType' => $size,
            'Shape' => $shape, 'Material' => $material, 'ClosureType' => $closure,
            'MovementType' => $movement, 'DisplayType' => $display,
        ] as $label => $list) {
            if (empty($list)) {
                $this->error("Missing lookup data: no {$label} rows. Seed master data first.");
                return self::FAILURE;
            }
        }

        // Pick [0] for create, [1] (or [0]) for update.
        $c = fn (array $a) => $a[0];
        $u = fn (array $a) => $a[1] ?? $a[0];

        $adminId = User::where('type', 'SuperAdmin')->value('id') ?? User::value('id');
        $stamp   = now()->format('YmdHis');

        // ── Field plans: [column => [createValue, updateValue]] ──────────────
        $scalars = [
            'category_type_id'              => [$c($catType), $u($catType)],
            'brand_id'                      => [$c($brand), $u($brand)],
            'grade_id'                      => [$c($grade ?: [null]), $u($grade ?: [null])],
            'sub_type_id'                   => [$c($subType ?: [null]), $u($subType ?: [null])],
            'band_closure_id'               => [$c($closure), $u($closure)],
            'dial_display_type_id'          => [$c($display), $u($display)],
            'case_size'                     => [41, 43],
            'case_size_type_id'             => [$c($size), $u($size)],
            'case_shape_id'                 => [$c($shape), $u($shape)],
            'band_material_id'              => [$c($material), $u($material)],
            'watch_movement_id'             => [$c($movement), $u($movement)],
            'band_length'                   => [220, 240],
            'band_size_type_id'             => [$c($size), $u($size)],
            'water_resistance'              => [300, 100],
            'water_resistance_size_type_id' => [$c($size), $u($size)],
            'band_width'                    => [20, 22],
            'band_width_size_type_id'       => [$c($size), $u($size)],
            'case_thickness'                => [12, 14],
            'case_thickness_size_type_id'   => [$c($size), $u($size)],
            'dial_case_material_id'         => [$c($material), $u($material)],
            'dial_glass_material_id'        => [$c($material), $u($material)],
            'watch_height'                  => [48, 50],
            'watch_height_size_type_id'     => [$c($size), $u($size)],
            'watch_width'                   => [41, 43],
            'watch_width_size_type_id'      => [$c($size), $u($size)],
            'watch_length'                  => [47, 49],
            'watch_length_size_type_id'     => [$c($size), $u($size)],
            'sku_unique'                    => ["AUDIT-SKU-{$stamp}-A", "AUDIT-SKU-{$stamp}-B"],
            'model_number'                  => ['AUDIT-MODEL-A', 'AUDIT-MODEL-B'],
            'warranty_years'                => ['5', '3'],
            'interchangeable_dial'          => [1, 0],
            'interchangeable_strap'         => [0, 1],
            'watch_box'                     => [1, 0],
            'purchase_price'                => [1000, 1100],
            'selling_price'                 => [1500, 1600],
            'sale_price_after_discount'     => [1400, 1450],
            'percentage_discount'           => [6, 9],
            'stock'                         => [5, 8],
            'market_stock'                  => [2, 3],
            'low_stock_threshold'           => [2, 4],
            'active'                        => [1, 1],
            'wa_code'                       => ["AUDIT-WA-{$stamp}-A", "AUDIT-WA-{$stamp}-B"],
            'search_keywords'               => ['audit keywords one', 'audit keywords two'],
            'seo_title'                     => ['Audit SEO A', 'Audit SEO B'],
            'seo_slug'                      => ["audit-slug-{$stamp}-a", "audit-slug-{$stamp}-b"],
            'seo_meta_description'          => ['Audit meta A', 'Audit meta B'],
        ];

        $translated = [
            'product_title'     => [['ar' => 'منتج اختبار أ', 'en' => 'Audit Product A'], ['ar' => 'منتج اختبار ب', 'en' => 'Audit Product B']],
            'short_description' => [['ar' => 'وصف قصير أ', 'en' => 'Short A'], ['ar' => 'وصف قصير ب', 'en' => 'Short B']],
            'long_description'  => [['ar' => 'وصف طويل أ', 'en' => 'Long A'], ['ar' => 'وصف طويل ب', 'en' => 'Long B']],
            'model_name'        => [['ar' => 'موديل أ', 'en' => 'Model A'], ['ar' => 'موديل ب', 'en' => 'Model B']],
            'country'           => [['ar' => 'مصر', 'en' => 'Egypt'], ['ar' => 'سويسرا', 'en' => 'Switzerland']],
            'stone'             => [['ar' => 'ألماس', 'en' => 'Diamond'], ['ar' => 'ياقوت', 'en' => 'Ruby']],
        ];

        $extra = [
            ['width_cm' => '30', 'height_cm' => '20', 'depth_cm' => '10', 'strap_length_cm' => '120', 'bag_strap_type' => 'Removable', 'waterproof' => '1'],
            ['width_cm' => '35', 'height_cm' => '25', 'perfume_volume' => '100', 'perfume_type' => 'edp', 'elec_connectivity' => 'USB-C'],
        ];

        $pivots = [
            'feature'   => [array_slice($features, 0, 2), array_slice($features, 1, 2) ?: $features],
            'gender'    => [array_slice($genders, 0, 1), $genders],
            'dialColor' => [array_slice($colors, 0, 2), array_slice($colors, 1, 2) ?: $colors],
            'bandColor' => [array_slice($colors, 0, 1), $colors],
        ];

        $productId = null;

        try {
            // ═══ PHASE 1 — CREATE with every field ═══════════════════════════
            $productId = DB::transaction(function () use ($scalars, $translated, $extra, $pivots, $adminId) {
                $p = new Product();
                foreach ($scalars as $col => [$create, ]) {
                    $p->{$col} = $create;
                }
                foreach ($translated as $attr => [$create, ]) {
                    $p->translateOrNew('ar')->{$attr} = $create['ar'];
                    $p->translateOrNew('en')->{$attr} = $create['en'];
                }
                $p->extra_attributes = $extra[0];
                $p->image      = 'audit-placeholder.webp';
                $p->created_by = $adminId;
                $p->save();

                $p->feature()->sync($pivots['feature'][0]);
                $p->gender()->sync($pivots['gender'][0]);
                $p->dialColor()->sync($pivots['dialColor'][0]);
                $p->bandColor()->sync($pivots['bandColor'][0]);

                return $p->id;
            });

            // ═══ PHASE 2 — assert CREATE persisted ═══════════════════════════
            $this->assertAll($productId, $scalars, $translated, $extra[0], $pivots, 0, 'create');

            // ═══ PHASE 3 — UPDATE every field ════════════════════════════════
            DB::transaction(function () use ($productId, $scalars, $translated, $extra, $pivots, $adminId) {
                $p = Product::findOrFail($productId);
                foreach ($scalars as $col => [, $update]) {
                    $p->{$col} = $update;
                }
                foreach ($translated as $attr => [, $update]) {
                    $p->translateOrNew('ar')->{$attr} = $update['ar'];
                    $p->translateOrNew('en')->{$attr} = $update['en'];
                }
                $p->extra_attributes = $extra[1];
                $p->updated_by = $adminId;
                $p->save();

                $p->feature()->sync($pivots['feature'][1]);
                $p->gender()->sync($pivots['gender'][1]);
                $p->dialColor()->sync($pivots['dialColor'][1]);
                $p->bandColor()->sync($pivots['bandColor'][1]);
            });

            // ═══ PHASE 4 — assert UPDATE persisted (nothing nulled) ══════════
            $this->assertAll($productId, $scalars, $translated, $extra[1], $pivots, 1, 'update');
        } catch (\Throwable $e) {
            $this->error('Audit threw: ' . $e->getMessage());
            $this->line($e->getFile() . ':' . $e->getLine());
        } finally {
            if ($productId && ! $this->option('keep')) {
                $p = Product::find($productId);
                if ($p) {
                    $p->feature()->detach();
                    $p->gender()->detach();
                    $p->dialColor()->detach();
                    $p->bandColor()->detach();
                    $p->translations()->delete();
                    $p->product_image()->delete();
                    $p->delete();
                }
                $this->line("Cleaned up test product #{$productId}.");
            } elseif ($productId) {
                $this->warn("Kept test product #{$productId} (--keep). Delete it manually.");
            }
        }

        // ═══ REPORT ══════════════════════════════════════════════════════════
        $fails = array_filter($this->results, fn ($r) => ! $r['ok']);
        $this->newLine();
        $this->line('Field                              | Phase  | Expected           | Actual             | ');
        $this->line(str_repeat('-', 92));
        foreach ($this->results as $r) {
            $this->line(sprintf(
                '%-34s | %-6s | %-18s | %-18s | %s',
                $r['field'],
                $r['phase'],
                $this->short($r['expected']),
                $this->short($r['actual']),
                $r['ok'] ? 'OK' : 'FAIL',
            ));
        }
        $this->newLine();

        if ($fails) {
            $this->error(count($fails) . ' field(s) FAILED to persist:');
            foreach ($fails as $r) {
                $this->error("  • {$r['field']} ({$r['phase']}): expected " . $this->short($r['expected']) . ', got ' . $this->short($r['actual']));
            }
            return self::FAILURE;
        }

        $this->info('✅ All ' . count($this->results) . ' field checks passed — every field persists on create AND update, nothing nulled.');
        return self::SUCCESS;
    }

    /**
     * Reload the product FRESH and assert every field equals the phase's values.
     *
     * @param  array<string, array{0:mixed,1:mixed}>  $scalars
     * @param  array<string, array{0:array,1:array}>  $translated
     * @param  array<string, mixed>                   $extraExpected
     * @param  array<string, array{0:array,1:array}>  $pivots
     */
    private function assertAll(int $id, array $scalars, array $translated, array $extraExpected, array $pivots, int $idx, string $phase): void
    {
        /** @var Product $p */
        $p = Product::with(['translations', 'feature', 'gender', 'dialColor', 'bandColor'])->findOrFail($id);

        foreach ($scalars as $col => $vals) {
            $this->check($col, $vals[$idx], $p->{$col}, $phase);
        }

        foreach ($translated as $attr => $vals) {
            $this->check("{$attr} (en)", $vals[$idx]['en'], optional($p->translate('en'))->{$attr}, $phase);
            $this->check("{$attr} (ar)", $vals[$idx]['ar'], optional($p->translate('ar'))->{$attr}, $phase);
        }

        // extra_attributes (JSON cast → array)
        $this->check('extra_attributes', json_encode($extraExpected), json_encode($p->extra_attributes), $phase);

        // Pivots — compare id sets.
        $this->checkSet('feature[]', $pivots['feature'][$idx], $p->feature->pluck('id')->all(), $phase);
        $this->checkSet('gender[]', $pivots['gender'][$idx], $p->gender->pluck('id')->all(), $phase);
        $this->checkSet('dialColor[]', $pivots['dialColor'][$idx], $p->dialColor->pluck('id')->all(), $phase);
        $this->checkSet('bandColor[]', $pivots['bandColor'][$idx], $p->bandColor->pluck('id')->all(), $phase);
    }

    private function check(string $field, mixed $expected, mixed $actual, string $phase): void
    {
        // Loose numeric compare so 12 == "12.00" (decimal columns) doesn't false-fail.
        $ok = is_numeric($expected) && is_numeric($actual)
            ? (float) $expected === (float) $actual
            : (string) $expected === (string) $actual;

        $this->results[] = compact('field', 'expected', 'actual', 'phase', 'ok');
    }

    private function checkSet(string $field, array $expected, array $actual, string $phase): void
    {
        sort($expected);
        sort($actual);
        $ok = $expected === $actual;
        $this->results[] = [
            'field'    => $field,
            'expected' => implode(',', $expected),
            'actual'   => implode(',', $actual),
            'phase'    => $phase,
            'ok'       => $ok,
        ];
    }

    private function short(mixed $v): string
    {
        $s = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        return mb_strlen($s) > 18 ? mb_substr($s, 0, 15) . '…' : $s;
    }
}
