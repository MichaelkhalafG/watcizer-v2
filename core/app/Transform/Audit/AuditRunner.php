<?php

namespace App\Transform\Audit;

use App\Support\LegacySlug;
use App\Transform\Config;
use App\Transform\FamilyResolver;
use App\Transform\LegacySource;
use App\Transform\Row;
use App\Transform\Steps\Step04Colors;
use App\Transform\Steps\Step13Variants;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The dirty-data audit of CLEAN_CORE_STUDY §2.9.5 — A-01 … A-25 — plus the X-codes
 * added by rehearsal #1 (things the real data showed that the checklist did not ask).
 * Runs against the legacy tables only, through the read-only LegacySource, and never
 * suppresses a finding: every non-zero code is listed row by row.
 */
final class AuditRunner
{
    /** legacy lookup table => [translation table, fk, name column] @var array<string, array{0: string, 1: string, 2: string}> */
    private const LOOKUPS = [
        'brands' => ['brand_translations', 'brand_id', 'brand_name'],
        'grades' => ['grade_translations', 'grade_id', 'grade_name'],
        'materials' => ['material_translations', 'material_id', 'material_name'],
        'shapes' => ['shape_translations', 'shape_id', 'shape_name'],
        'movement_types' => ['movement_type_translations', 'movement_type_id', 'movement_type_name'],
        'closure_types' => ['closure_type_translations', 'closure_type_id', 'closure_type_name'],
        'display_types' => ['display_type_translations', 'display_type_id', 'display_type_name'],
        'features' => ['feature_translations', 'feature_id', 'feature_name'],
        'genders' => ['gender_translations', 'gender_id', 'gender_name'],
        'size_types' => ['size_type_translations', 'size_type_id', 'size_type_name'],
        'colors' => ['color_translations', 'color_id', 'color_name'],
        'category_types' => ['category_type_translations', 'category_type_id', 'category_type_name'],
        'sub_types' => ['sub_type_translations', 'sub_type_id', 'sub_type_name'],
    ];

    /** products.<column> => parent table (A-24) @var array<string, string> */
    private const PRODUCT_FKS = [
        'brand_id' => 'brands', 'grade_id' => 'grades', 'category_type_id' => 'category_types', 'sub_type_id' => 'sub_types',
        'main_category_id' => 'categories', 'sub_category_id' => 'categories', 'product_type_id' => 'categories',
        'band_closure_id' => 'closure_types', 'dial_display_type_id' => 'display_types', 'case_shape_id' => 'shapes',
        'band_material_id' => 'materials', 'watch_movement_id' => 'movement_types', 'dial_case_material_id' => 'materials',
        'dial_glass_material_id' => 'materials', 'case_size_type_id' => 'size_types', 'band_size_type_id' => 'size_types',
        'water_resistance_size_type_id' => 'size_types', 'band_width_size_type_id' => 'size_types',
        'case_thickness_size_type_id' => 'size_types', 'watch_height_size_type_id' => 'size_types',
        'watch_width_size_type_id' => 'size_types', 'watch_length_size_type_id' => 'size_types',
    ];

    /** @var list<string> */
    private const WATCH_COLUMNS = [
        'case_size', 'case_shape_id', 'dial_case_material_id', 'dial_glass_material_id', 'case_thickness', 'band_material_id',
        'band_closure_id', 'band_length', 'band_width', 'dial_display_type_id', 'watch_movement_id', 'water_resistance',
        'watch_height', 'watch_width', 'watch_length', 'interchangeable_dial', 'interchangeable_strap', 'watch_box',
    ];

    /** @param  array<string, mixed>  $config  config('transform') */
    public function __construct(private readonly LegacySource $legacy, private readonly string $imagesRoot, private readonly array $config) {}

    public function run(): AuditReport
    {
        $r = new AuditReport;
        $r->context['legacy database'] = $this->legacy->host().'/'.$this->legacy->databaseName();
        $r->context['legacy session read-only'] = $this->legacy->isReadOnlyEnforced() ? 'yes (tx_read_only = 1)' : 'NOT ENFORCED';
        $r->context['images root'] = $this->imagesRoot.(is_dir($this->imagesRoot) ? '' : ' (MISSING)');

        $this->a01($r);
        $this->a02($r);
        $this->a03($r);
        $this->a04($r);
        $this->a05($r);
        $this->a06($r);
        $this->a07($r);
        $this->a08($r);
        $this->a09($r);
        $this->a10($r);
        $this->a11a12($r);
        $this->a13($r);
        $this->a14($r);
        $this->a15($r);
        $this->a16($r);
        $this->a17($r);
        $this->a18($r);
        $this->a19($r);
        $this->a20($r);
        $this->a21($r);
        $this->a22($r);
        $this->a23($r);
        $this->a24($r);
        $this->a25($r);
        $this->a26($r);
        $this->a27($r);
        $this->extras($r);
        $this->x07($r);

        return $r;
    }

    private function products(): Builder
    {
        return $this->legacy->table('products');
    }

    private function a01(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-01', 'lookup rows missing an ar or en translation', false, 'copy EN (or AR) into the missing locale'));
        foreach (self::LOOKUPS as $table => [$tr, $fk, $col]) {
            foreach (['ar', 'en'] as $locale) {
                $rows = $this->legacy->table($table)
                    ->leftJoin($tr, fn (JoinClause $j) => $j->on("$tr.$fk", '=', "$table.id")->where("$tr.locale", '=', $locale))
                    ->whereNull("$tr.id")->orWhere(fn (Builder $q) => $q->where("$tr.locale", $locale)->where("$tr.$col", ''))
                    ->select(["$table.id"])->orderBy("$table.id")->get();
                foreach ($rows as $row) {
                    $f->add($table, Row::int($row, 'id'), "missing $locale");
                }
            }
        }
    }

    private function a02(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-02', 'unit codes colliding after slugify', false, 'later ids get "-{id}" appended'));
        $names = $this->names('size_type_translations', 'size_type_id', 'size_type_name');
        $byCode = [];
        foreach ($this->legacy->table('size_types')->select(['id'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $byCode[LegacySlug::orId($names[$id]['en'] ?? '', $id)][] = $id;
        }
        foreach ($byCode as $code => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $f->add('size_types', $id, "code [$code] shared by ids ".implode(',', $ids));
                }
            }
        }
        $f->note = count($byCode).' distinct codes; note that size_types mixes garment/shoe sizes (XS…XXXXXL, 26…47, Free Size) with physical units (mm, cm, inch, ATM, Bar) — all become catalog_units.';
    }

    private function a03(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-03', 'non-hex colors.color_value', false, 'hex → NULL'));
        foreach ($this->legacy->table('colors')->select(['id', 'color_value'])->orderBy('id')->get() as $row) {
            if (Step04Colors::normaliseHex(Row::nstr($row, 'color_value')) === null) {
                $f->add('colors', Row::int($row, 'id'), 'color_value='.var_export(Row::nstr($row, 'color_value'), true));
            }
        }
    }

    private function a04(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-04', 'invalid sale price (not 0 < sale < selling)', false, 'sale_price → NULL'));
        $rows = $this->products()->select(['id', 'selling_price', 'sale_price_after_discount'])
            ->whereNotNull('sale_price_after_discount')
            ->whereRaw('NOT (sale_price_after_discount > 0 AND sale_price_after_discount < selling_price)')
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            $f->add('products', Row::int($row, 'id'), 'selling='.Row::money($row, 'selling_price').' sale='.Row::money($row, 'sale_price_after_discount'));
        }
    }

    private function a05(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-05', 'duplicate sku_unique', false, 'lowest id keeps the sku, later ids → NULL'));
        $dups = $this->products()->selectRaw('TRIM(sku_unique) AS sku, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids')
            ->whereNotNull('sku_unique')->whereRaw("TRIM(sku_unique) <> ''")->groupByRaw('TRIM(sku_unique)')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dups as $row) {
            $f->add('products', Row::str($row, 'ids'), 'sku ['.Row::str($row, 'sku').'] × '.Row::int($row, 'n'));
        }
    }

    private function a06(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-06', 'duplicate or empty wa_code', true, 'BLOCKS — fix in legacy first'));
        $dups = $this->products()->selectRaw('wa_code, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids')->groupBy('wa_code')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dups as $row) {
            $f->add('products', Row::str($row, 'ids'), 'wa_code ['.Row::str($row, 'wa_code').'] × '.Row::int($row, 'n'));
        }
        foreach ($this->products()->select(['id'])->where(fn (Builder $q) => $q->where('wa_code', '')->orWhereNull('wa_code'))->orderBy('id')->get() as $row) {
            $f->add('products', Row::int($row, 'id'), 'empty wa_code');
        }
    }

    private function a07(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-07', 'non-numeric warranty_years (or > 255)', false, 'warranty_years → NULL'));
        foreach ($this->products()->select(['id', 'warranty_years'])->whereNotNull('warranty_years')->whereRaw("TRIM(warranty_years) <> ''")->orderBy('id')->get() as $row) {
            $v = trim(Row::str($row, 'warranty_years'));
            if (preg_match('/^\d+$/', $v) !== 1 || (int) $v > 255) {
                $f->add('products', Row::int($row, 'id'), "warranty_years=[$v]");
            }
        }
    }

    private function a08(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-08', 'created_by / updated_by not in users', false, '→ NULL'));
        foreach (['created_by', 'updated_by'] as $col) {
            $rows = $this->products()->select(['products.id', "products.$col"])
                ->leftJoin('users', 'users.id', '=', "products.$col")
                ->whereNotNull("products.$col")->whereNull('users.id')->orderBy('products.id')->get();
            foreach ($rows as $row) {
                $f->add('products', Row::int($row, 'id'), "$col=".Row::int($row, $col).' (no such user)');
            }
        }
    }

    private function a09(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-09', 'missing or empty AR (or EN) product translation', false, 'copy the other locale; AR SEO dead until fixed (§2.17)'));
        foreach (['ar', 'en'] as $locale) {
            $rows = $this->products()->select(['products.id'])
                ->leftJoin('product_translations as t', fn (JoinClause $j) => $j->on('t.product_id', '=', 'products.id')->where('t.locale', '=', $locale))
                ->where(fn (Builder $q) => $q->whereNull('t.id')->orWhere('t.product_title', ''))
                ->orderBy('products.id')->get();
            foreach ($rows as $row) {
                $f->add('products', Row::int($row, 'id'), "missing $locale title");
            }
        }
    }

    private function a10(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-10', 'family vs columns: non-watch products carrying watch specs; watch products with extra_attributes; invalid extra_attributes JSON', false, 'specs row still written for non-watch; JSON invalid → specs NULL'));
        $families = new FamilyResolver(Config::stringKeyed($this->config['family'] ?? null));
        $typeNames = $this->names('category_type_translations', 'category_type_id', 'category_type_name');
        $subNames = $this->names('sub_type_translations', 'sub_type_id', 'sub_type_name');
        $rows = $this->products()->select(array_merge(['id', 'category_type_id', 'sub_type_id', 'extra_attributes'], self::WATCH_COLUMNS))->orderBy('id')->get();
        $count = ['watch' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $typeId = Row::nint($row, 'category_type_id');
            $subId = Row::nint($row, 'sub_type_id');
            $extra = Row::nstr($row, 'extra_attributes');
            $family = $families->resolve($typeId === null ? '' : ($typeNames[$typeId]['en'] ?? ''), $extra, $subId === null ? '' : ($subNames[$subId]['en'] ?? ''));
            $count[$family === 'watch' ? 'watch' : 'other']++;
            $filled = [];
            foreach (self::WATCH_COLUMNS as $col) {
                if ($row->{$col} !== null) {
                    $filled[] = $col;
                }
            }
            if ($family !== 'watch' && $filled !== []) {
                $f->add('products', Row::int($row, 'id'), "family=$family has ".implode(',', $filled));
            }
            $hasExtra = FamilyResolver::jsonKeys($extra) !== [];
            if ($family === 'watch' && $hasExtra) {
                $f->add('products', Row::int($row, 'id'), 'family=watch has extra_attributes keys '.implode(',', FamilyResolver::jsonKeys($extra)));
            }
            if (! $hasExtra && $extra !== null && trim($extra) !== '' && ! in_array(trim($extra), ['[]', '{}', 'null'], true)) {
                $f->add('products', Row::int($row, 'id'), 'extra_attributes is not a JSON object: '.mb_substr($extra, 0, 60));
            }
        }
        $f->note = sprintf('Family split on this data: watch=%d, non-watch=%d. No product carries extra_attributes, so non-watch families come from the sub type name map (bags → bag, wallets → wallet, perfumes → perfume) else `fashion`.', $count['watch'], $count['other']);
    }

    private function a11a12(AuditReport $r): void
    {
        $root = rtrim($this->imagesRoot, '/\\');
        $rootOk = is_dir($root);
        $productDir = $root.DIRECTORY_SEPARATOR.'Product';
        $galleryDir = $root.DIRECTORY_SEPARATOR.'Product_image';
        $present = fn (string $dir): int => is_dir($dir) ? count(array_filter(scandir($dir) ?: [], fn (string $n) => $n !== '.' && $n !== '..')) : 0;

        $a11 = $r->add(new AuditFinding('A-11', 'cover file missing on disk (products.image under Product/)', false, 'row still created; storefront shows placeholder'));
        $a12 = $r->add(new AuditFinding('A-12', 'gallery file missing on disk (product_images.image under Product_image/)', false, 'row still created'));
        $caveat = $rootOk
            ? sprintf('checked against %s — Product/ holds %d files, Product_image/ holds %d files; on this workstation the image tree is a PARTIAL copy, so these counts are NOT production findings. Re-read on the rehearsal/production host.', $root, $present($productDir), $present($galleryDir))
            : "images root [$root] does not exist — file checks could not run.";
        $a11->caveat = $caveat;
        $a12->caveat = $caveat;

        foreach ($this->products()->select(['id', 'image'])->orderBy('id')->get() as $row) {
            $file = trim(Row::str($row, 'image'));
            if ($file === '') {
                $a11->add('products', Row::int($row, 'id'), 'image is empty');
            } elseif (! is_file($productDir.DIRECTORY_SEPARATOR.$file)) {
                $a11->add('products', Row::int($row, 'id'), "Product/$file not found");
            }
        }
        foreach ($this->legacy->table('product_images')->select(['id', 'product_id', 'image'])->orderBy('id')->get() as $row) {
            $file = trim(Row::str($row, 'image'));
            if ($file === '') {
                $a12->add('product_images', Row::int($row, 'id'), 'image is empty (product '.Row::int($row, 'product_id').')');
            } elseif (! is_file($galleryDir.DIRECTORY_SEPARATOR.$file)) {
                $a12->add('product_images', Row::int($row, 'id'), "Product_image/$file not found (product ".Row::int($row, 'product_id').')');
            }
        }
    }

    private function a13(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-13', 'duplicate product_variants.sku', false, 'later ids → NULL'));
        $dups = $this->legacy->table('product_variants')->selectRaw('TRIM(sku) AS sku, GROUP_CONCAT(id ORDER BY id) AS ids')->whereNotNull('sku')->whereRaw("TRIM(sku) <> ''")->groupByRaw('TRIM(sku)')->havingRaw('COUNT(*) > 1')->get();
        foreach ($dups as $row) {
            $f->add('product_variants', Row::str($row, 'ids'), 'sku ['.Row::str($row, 'sku').']');
        }
    }

    private function a14(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-14', 'category-type slug would differ from today\'s /category/[slug]', true, 'BLOCKS — a live URL would change'));
        $names = $this->names('category_type_translations', 'category_type_id', 'category_type_name');
        $seen = [];
        foreach ($this->legacy->table('category_types')->select(['id'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $en = trim($names[$id]['en'] ?? '');
            $slug = LegacySlug::make($en);
            if ($slug === '') {
                $f->add('category_types', $id, "EN name [$en] slugifies to '' — today the SPA falls back to the id; the clean slug will be [$id]");
            }
            $slug = $slug !== '' ? $slug : (string) $id;
            if (isset($seen[$slug])) {
                $f->add('category_types', $id, "slug [$slug] collides with category_type {$seen[$slug]} — would get a suffix");
            }
            $seen[$slug] = $id;
        }
        $f->note = 'The transform uses the same slugify() as the SPA and the sitemap ('.implode(', ', array_map(fn (int|string $s, int $id) => "$id → /category/$s", array_keys($seen), array_values($seen))).'), so a difference can only come from an empty or colliding slug.';
    }

    private function a15(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-15', 'sub types with no products (placement must be confirmed), and (type, sub type) pairs used by a single product', false, 'orphans mirrored under the majority category type unless overridden in config transform.orphan_sub_type_parents'));
        $subNames = $this->names('sub_type_translations', 'sub_type_id', 'sub_type_name');
        $typeNames = $this->names('category_type_translations', 'category_type_id', 'category_type_name');
        $pairs = $this->products()->selectRaw('category_type_id, sub_type_id, COUNT(*) AS n')->whereNotNull('category_type_id')->whereNotNull('sub_type_id')->groupBy('category_type_id', 'sub_type_id')->orderBy('category_type_id')->orderBy('sub_type_id')->get();
        $perType = [];
        $used = [];
        foreach ($pairs as $row) {
            $t = Row::int($row, 'category_type_id');
            $s = Row::int($row, 'sub_type_id');
            $perType[$t] = ($perType[$t] ?? 0) + 1;
            $used[$s] = true;
            if (Row::int($row, 'n') === 1) {
                $f->add('products', "type $t / sub_type $s", 'pair used by exactly one product');
            }
        }
        $majority = null;
        $best = -1;
        foreach ($perType as $t => $n) {
            if ($n > $best) {
                $best = $n;
                $majority = $t;
            }
        }
        $overrides = is_array($this->config['orphan_sub_type_parents'] ?? null) ? $this->config['orphan_sub_type_parents'] : [];
        foreach ($this->legacy->table('sub_types')->select(['id'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            if (isset($used[$id])) {
                continue;
            }
            $override = $overrides[$id] ?? null;
            $target = is_int($override) ? $override : $majority;
            $f->add('sub_types', $id, sprintf('%s → category_type %s [%s] | [%s] has no products', is_int($override) ? 'PINNED BY CONFIG' : 'NO PIN, MAJORITY RULE GUESSED', var_export($target, true), $target === null ? '' : ($typeNames[$target]['en'] ?? ''), $subNames[$id]['en'] ?? ''));
        }
        $f->note = 'Majority rule = the category type with the most DISTINCT sub types among products ('.implode(', ', array_map(fn (int $t, int $n) => "type $t: $n sub types", array_keys($perType), array_values($perType))).'). Check every orphan row: the rule is blind to what the sub type NAME means.';
    }

    private function a16(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-16', 'products with active = 0', false, 'kept is_visible = 1 with catalog_products.is_active = 0 — decision pending (§3.2)'));
        foreach ($this->products()->select(['id'])->where('active', 0)->orderBy('id')->get() as $row) {
            $f->add('products', Row::int($row, 'id'), 'active=0');
        }
    }

    private function a17(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-17', 'product slug collisions (same EN title) and empty slugs', false, 'lowest id keeps the slug, later ids get "-{id}"; empty → id (as today)'));
        $titles = [];
        foreach ($this->legacy->table('product_translations')->select(['product_id', 'product_title'])->where('locale', 'en')->orderBy('product_id')->get() as $t) {
            $titles[Row::int($t, 'product_id')] = trim(Row::str($t, 'product_title'));
        }
        $bySlug = [];
        foreach ($this->products()->select(['id'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $slug = LegacySlug::make($titles[$id] ?? '');
            if ($slug === '') {
                $f->add('products', $id, 'EN title ['.($titles[$id] ?? '').'] slugifies to \'\' → slug will be the id');
                $slug = (string) $id;
            }
            $bySlug[$slug][] = $id;
        }
        foreach ($bySlug as $slug => $ids) {
            if (count($ids) > 1) {
                foreach (array_slice($ids, 1) as $id) {
                    $f->add('products', $id, "slug [$slug] already owned by product {$ids[0]} → [$slug-$id]; today both answer /product/$slug");
                }
            }
        }
    }

    private function a18(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-18', 'products with NULL category_type_id or sub_type_id', false, 'placed at depth 1 only, or unplaced'));
        foreach ($this->products()->select(['id', 'category_type_id', 'sub_type_id'])->where(fn (Builder $q) => $q->whereNull('category_type_id')->orWhereNull('sub_type_id'))->orderBy('id')->get() as $row) {
            $f->add('products', Row::int($row, 'id'), 'category_type_id='.var_export(Row::nint($row, 'category_type_id'), true).' sub_type_id='.var_export(Row::nint($row, 'sub_type_id'), true));
        }
    }

    private function a19(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-19', 'orphan or duplicate rows in the four product pivots', false, 'orphans skipped, duplicates collapsed'));
        foreach (['feature_product' => ['feature_id', 'features'], 'gender_product' => ['gender_id', 'genders'], 'color_dial_product' => ['color_id', 'colors'], 'color_band_product' => ['color_id', 'colors']] as $pivot => [$fk, $parent]) {
            $orphans = $this->legacy->table($pivot)->select(["$pivot.id", "$pivot.product_id", "$pivot.$fk"])
                ->leftJoin('products', 'products.id', '=', "$pivot.product_id")
                ->leftJoin($parent, "$parent.id", '=', "$pivot.$fk")
                ->where(fn (Builder $q) => $q->whereNull('products.id')->orWhereNull("$parent.id"))->orderBy("$pivot.id")->get();
            foreach ($orphans as $row) {
                $f->add($pivot, Row::int($row, 'id'), 'orphan: product_id='.Row::int($row, 'product_id')." $fk=".Row::int($row, $fk));
            }
            $dups = $this->legacy->table($pivot)->selectRaw("product_id, $fk, COUNT(*) AS n")->groupBy('product_id', $fk)->havingRaw('COUNT(*) > 1')->get();
            foreach ($dups as $row) {
                $f->add($pivot, Row::int($row, 'product_id').'/'.Row::int($row, $fk), 'duplicate × '.Row::int($row, 'n'));
            }
        }
    }

    private function a20(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-20', 'commerce rows referencing missing products (FK-enforced in production → must be 0)', true, 'BLOCKS the M2 pre-flight'));
        foreach (['order_items', 'cart_items', 'wishlist_items', 'product_ratings'] as $table) {
            $rows = $this->legacy->table($table)->select(["$table.id", "$table.product_id"])->leftJoin('products', 'products.id', '=', "$table.product_id")
                ->whereNotNull("$table.product_id")->whereNull('products.id')->orderBy("$table.id")->get();
            foreach ($rows as $row) {
                $f->add($table, Row::int($row, 'id'), 'product_id='.Row::int($row, 'product_id').' missing');
            }
        }
    }

    private function a21(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-21', 'offers.gift_product_ids referencing missing products', false, 'report only (offers stay legacy)'));
        $ids = [];
        foreach ($this->products()->select(['id'])->orderBy('id')->cursor() as $row) {
            $ids[Row::int($row, 'id')] = true;
        }
        foreach ($this->legacy->table('offers')->select(['id', 'gift_product_ids'])->orderBy('id')->get() as $row) {
            $raw = Row::nstr($row, 'gift_product_ids') ?? '';
            $decoded = json_decode($raw, true);
            $list = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)));
            foreach ($list as $gift) {
                if (! is_numeric($gift) || ! isset($ids[(int) $gift])) {
                    $f->add('offers', Row::int($row, 'id'), 'gift product ['.(is_scalar($gift) ? (string) $gift : '?').'] missing');
                }
            }
        }
    }

    private function a22(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-22', 'stored percentage_discount ≠ derived round((selling−sale)/selling×100)', false, 'compat emits the derived value; team accepts or fixes'));
        $rows = $this->products()->select(['id', 'selling_price', 'sale_price_after_discount', 'percentage_discount'])
            ->whereRaw('sale_price_after_discount > 0 AND sale_price_after_discount < selling_price')
            ->whereRaw('ROUND((selling_price - sale_price_after_discount) / selling_price * 100) <> ROUND(IFNULL(percentage_discount, 0))')
            ->orderBy('id')->get();
        foreach ($rows as $row) {
            $selling = (float) Row::money($row, 'selling_price');
            $sale = (float) Row::money($row, 'sale_price_after_discount');
            $f->add('products', Row::int($row, 'id'), sprintf('stored=%s derived=%d (selling %s, sale %s)', var_export(Row::nfloat($row, 'percentage_discount'), true), (int) round(($selling - $sale) / $selling * 100), Row::money($row, 'selling_price'), Row::money($row, 'sale_price_after_discount')));
        }
    }

    private function a23(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-23', 'product_variants live column shape (tripwire: must be the flat shape of 2026-09-05)', true, 'BLOCKS step 13 if changed'));
        $columns = $this->legacy->columns('product_variants');
        sort($columns);
        $expected = Step13Variants::FLAT_SHAPE;
        sort($expected);
        if ($columns !== $expected) {
            $f->add('product_variants', '-', 'columns now ['.implode(', ', $columns).']');
        }
        $f->note = 'Observed columns: '.implode(', ', $columns).'.';
    }

    private function a24(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-24', 'orphan values in the 22 products.*_id reference columns (FK-enforced → must be 0)', true, 'BLOCKS steps 6/8'));
        foreach (self::PRODUCT_FKS as $col => $parent) {
            $rows = $this->products()->select(['products.id', "products.$col"])->leftJoin("$parent as p", 'p.id', '=', "products.$col")
                ->whereNotNull("products.$col")->whereNull('p.id')->orderBy('products.id')->get();
            foreach ($rows as $row) {
                $f->add('products', Row::int($row, 'id'), "$col=".Row::int($row, $col)." not in $parent");
            }
        }
    }

    private function a25(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-25', 'other commerce orphans (offer_id, order_id, address_id, shipping_city_id, user_id)', false, 'report only'));
        $checks = [
            ['cart_items', 'offer_id', 'offers'], ['order_items', 'offer_id', 'offers'], ['wishlist_items', 'offer_id', 'offers'],
            ['banner_homes', 'offer_id', 'offers'], ['banner_sides', 'offer_id', 'offers'], ['banner_bottoms', 'offer_id', 'offers'],
            ['payment_statuses', 'order_id', 'orders'], ['orders', 'address_id', 'addresses'], ['addresses', 'shipping_city_id', 'shipping_cities'],
            ['orders', 'user_id', 'users'], ['addresses', 'user_id', 'users'], ['carts', 'user_id', 'users'], ['wishlists', 'user_id', 'users'],
            ['product_ratings', 'user_id', 'users'], ['social_accounts', 'user_id', 'users'],
        ];
        foreach ($checks as [$table, $col, $parent]) {
            $rows = $this->legacy->table($table)->select(["$table.id", "$table.$col"])->leftJoin("$parent as p", 'p.id', '=', "$table.$col")
                ->whereNotNull("$table.$col")->whereNull('p.id')->orderBy("$table.id")->get();
            foreach ($rows as $row) {
                $f->add($table, Row::int($row, 'id'), "$col=".Row::int($row, $col)." not in $parent");
            }
        }
    }

    /** Findings the checklist did not anticipate — added by rehearsal #1. */
    /**
     * A-26 (milestone audit, 2026-09-08) — a sub type with no products has nothing in the data
     * that says where it belongs, so the majority rule guesses: it files the sub type under the
     * category type that carries the most distinct sub types, which on this catalog is Fashion.
     * A watch-natured sub type added by the team (the real 28 "Automatic" is exactly this case)
     * would therefore land under Fashion silently. Every orphan must be pinned in
     * config `transform.orphan_sub_type_parents`, and this code BLOCKS the run until it is.
     */
    private function a26(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding(
            'A-26',
            'sub types with no products that are NOT pinned in config transform.orphan_sub_type_parents',
            true,
            'BLOCKS — pin each one (watch-natured → the Watches category type, otherwise Fashion). The majority rule is blind to what the name means and must never decide a new sub type.'
        ));

        $subNames = $this->names('sub_type_translations', 'sub_type_id', 'sub_type_name');
        $typeNames = $this->names('category_type_translations', 'category_type_id', 'category_type_name');

        $used = [];
        $perType = [];
        foreach ($this->products()->selectRaw('category_type_id, sub_type_id')->whereNotNull('sub_type_id')->distinct()->get() as $row) {
            $sub = Row::int($row, 'sub_type_id');
            $used[$sub] = true;
            $type = Row::nint($row, 'category_type_id');
            if ($type !== null) {
                $perType[$type] = ($perType[$type] ?? 0) + 1;
            }
        }
        $majority = null;
        $best = -1;
        foreach ($perType as $type => $n) {
            if ($n > $best) {
                $best = $n;
                $majority = $type;
            }
        }

        $types = [];
        foreach ($this->legacy->table('category_types')->select(['id'])->orderBy('id')->get() as $row) {
            $types[Row::int($row, 'id')] = true;
        }

        /** @var array<int, int> $overrides */
        $overrides = [];
        foreach (is_array($this->config['orphan_sub_type_parents'] ?? null) ? $this->config['orphan_sub_type_parents'] : [] as $sub => $type) {
            if (is_numeric($sub) && is_int($type)) {
                $overrides[(int) $sub] = $type;
            }
        }

        $existingSubTypes = [];
        foreach ($this->legacy->table('sub_types')->select(['id'])->orderBy('id')->get() as $row) {
            $existingSubTypes[Row::int($row, 'id')] = true;
        }

        // A pin for a sub type that is not in legacy at all: the id was never used, or the team
        // deleted the row. Either way the pin is a stale assumption about production, which is
        // exactly how rehearsal #2 found `Automatic` filed under Fashion. Loud, not silent.
        foreach ($overrides as $subId => $typeId) {
            if (! isset($existingSubTypes[$subId])) {
                $f->add('config', $subId, sprintf('transform.orphan_sub_type_parents pins sub_type %d → category_type %d, but sub_type %d does not exist in legacy — delete the pin (or fix its id)', $subId, $typeId, $subId));
            }
        }

        $pins = [];
        foreach (array_keys($existingSubTypes) as $id) {
            if (isset($used[$id])) {
                continue;                                   // has products → placed by its real (type, sub type) pair
            }
            $name = trim($subNames[$id]['en'] ?? '') !== '' ? $subNames[$id]['en'] : ($subNames[$id]['ar'] ?? '');
            $pin = $overrides[$id] ?? null;
            if ($pin === null) {
                $f->add('sub_types', $id, sprintf('[%s] has no products and no pin — the majority rule would file it under category_type %s [%s]', $name, var_export($majority, true), $majority === null ? '' : ($typeNames[$majority]['en'] ?? '')));

                continue;
            }
            if (! isset($types[$pin])) {
                $f->add('sub_types', $id, sprintf('[%s] is pinned to category_type %d, which does not exist', $name, $pin));

                continue;
            }
            $pins[] = sprintf('%d [%s] → %d [%s]', $id, $name, $pin, $typeNames[$pin]['en'] ?? '');
        }

        $f->note = 'Pins in force: '.($pins === [] ? 'none' : implode('; ', $pins)).'. A sub type that HAS products is placed by its real pair and needs no pin. Family is a separate map (config transform.family): a product under the Watches category type is family = watch whatever its sub type is called.';
    }

    /**
     * A-27 (rehearsal #2, 2026-09-08) — WARNING, never blocking. A-26 proves every orphan is
     * pinned; it cannot tell whether the pin is RIGHT. This reads the sub type's name against two
     * word lists (config `transform.family.*_sub_type_name_hints`) and reports a pin that points
     * the other way — the check that would have caught `Automatic → Fashion` on sight. A name that
     * matches both lists, or neither, is ambiguous and is never reported: the heuristic only ever
     * asks a human to look, and it decides nothing.
     */
    private function a27(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('A-27', 'pinned placement that disagrees with the sub type NAME (heuristic warning)', false, 'WARNING only — confirm the pin in config transform.orphan_sub_type_parents, or ignore if the name is misleading'));

        $family = is_array($this->config['family'] ?? null) ? $this->config['family'] : [];
        $watchWords = self::words($family['watch_sub_type_name_hints'] ?? null);
        $fashionWords = self::words($family['fashion_sub_type_name_hints'] ?? null);
        $watchTypeNames = self::words($family['watch_category_type_names'] ?? null);
        if ($watchWords === [] && $fashionWords === []) {
            $f->note = 'No name hints configured (transform.family.watch_sub_type_name_hints / fashion_sub_type_name_hints) — check skipped.';

            return;
        }

        $subNames = $this->names('sub_type_translations', 'sub_type_id', 'sub_type_name');
        $typeNames = $this->names('category_type_translations', 'category_type_id', 'category_type_name');
        /** @var array<int, int> $overrides */
        $overrides = [];
        foreach (is_array($this->config['orphan_sub_type_parents'] ?? null) ? $this->config['orphan_sub_type_parents'] : [] as $sub => $type) {
            if (is_numeric($sub) && is_int($type)) {
                $overrides[(int) $sub] = $type;
            }
        }

        $checked = 0;
        foreach ($overrides as $subId => $typeId) {
            $names = $subNames[$subId] ?? null;
            if ($names === null) {
                continue;                                   // phantom pin — A-26 owns that finding
            }
            $haystack = mb_strtolower(trim(($names['en'] ?? '').' '.($names['ar'] ?? '')));
            if ($haystack === '') {
                continue;
            }
            $looksWatch = self::matchesAny($haystack, $watchWords);
            $looksFashion = self::matchesAny($haystack, $fashionWords);
            if ($looksWatch === $looksFashion) {
                continue;                                   // ambiguous or unknown: say nothing
            }
            $checked++;
            $targetIsWatch = in_array(mb_strtolower(trim($typeNames[$typeId]['en'] ?? '')), $watchTypeNames, true);
            if ($looksWatch && ! $targetIsWatch) {
                $f->add('sub_types', $subId, sprintf('[%s] reads as a WATCH sub type but is pinned to category_type %d [%s]', trim($names['en'] ?? ''), $typeId, $typeNames[$typeId]['en'] ?? ''));
            } elseif ($looksFashion && $targetIsWatch) {
                $f->add('sub_types', $subId, sprintf('[%s] reads as a FASHION sub type but is pinned to category_type %d [%s]', trim($names['en'] ?? ''), $typeId, $typeNames[$typeId]['en'] ?? ''));
            }
        }

        $f->note = sprintf('Heuristic, non-blocking: %d of %d pins had a name clear enough to judge; the rest were ambiguous and left alone. Word lists live in config transform.family.{watch,fashion}_sub_type_name_hints.', $checked, count($overrides));
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $word) {
            if (is_string($word) && trim($word) !== '') {
                $out[] = mb_strtolower(trim($word));
            }
        }

        return $out;
    }

    /** @param  list<string>  $words */
    private static function matchesAny(string $haystack, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    private function extras(AuditReport $r): void
    {
        $x1 = $r->add(new AuditFinding('X-01', 'product_images rows flagged is_cover = 1 (legacy meaning: first gallery upload, NOT the PDP cover)', false, 'flag dropped; the PDP cover stays products.image (step 9)'));
        $x1->note = 'The legacy PDP (ProductResource) shows products.image first and then product_images ordered by sort; the study\'s step 9/10 rule reproduces that order exactly. The legacy is_cover flag is set by the admin for the sort-0 gallery upload and points at a DIFFERENT file than products.image on every row, so it carries no cover semantics and is not copied.';
        $sameFile = 0;
        foreach ($this->legacy->table('product_images')->select(['product_images.id', 'product_images.product_id', 'product_images.image', 'products.image as cover'])->join('products', 'products.id', '=', 'product_images.product_id')->where('product_images.is_cover', 1)->orderBy('product_images.id')->get() as $row) {
            $same = Row::str($row, 'image') === Row::str($row, 'cover');
            $sameFile += $same ? 1 : 0;
            $x1->add('product_images', Row::int($row, 'id'), 'product '.Row::int($row, 'product_id').($same ? ' — SAME file as products.image (would duplicate)' : ' — different file from products.image'));
        }
        $x1->note .= " Rows whose file equals products.image: $sameFile.";

        $x2 = $r->add(new AuditFinding('X-02', 'new_colors rows identical (EN name + hex) to a legacy colors row', false, 'mapped onto the legacy id in core_transform_id_map instead of inserting a duplicate'));
        $legacy = [];
        $colorNames = $this->names('color_translations', 'color_id', 'color_name');
        foreach ($this->legacy->table('colors')->select(['id', 'color_value'])->orderBy('id')->get() as $row) {
            $id = Row::int($row, 'id');
            $legacy[mb_strtolower(trim($colorNames[$id]['en'] ?? '')).'|'.(Step04Colors::normaliseHex(Row::nstr($row, 'color_value')) ?? '')] = $id;
        }
        foreach ($this->legacy->table('new_colors')->select(['id', 'name_en', 'hex'])->orderBy('id')->get() as $row) {
            $key = mb_strtolower(trim(Row::str($row, 'name_en'))).'|'.(Step04Colors::normaliseHex(Row::nstr($row, 'hex')) ?? '');
            if (isset($legacy[$key])) {
                $x2->add('new_colors', Row::int($row, 'id'), '['.Row::str($row, 'name_en').' '.(Row::nstr($row, 'hex') ?? '').'] = colors.id '.$legacy[$key]);
            }
        }
        $x2->note = 'Study step 4 says new_colors get new ids; on this data every new_colors row is a verbatim copy of colors 1–16 and nothing references new_colors (product_variants is empty), so inserting them would only create 16 duplicate colours in the dashboard.';

        $x3 = $r->add(new AuditFinding('X-03', 'product_images (product_id, sort) ties — gallery order ambiguous in legacy', false, 'sort copied (+1); ties keep id order'));
        foreach ($this->legacy->table('product_images')->selectRaw('product_id, sort, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids')->groupBy('product_id', 'sort')->havingRaw('COUNT(*) > 1')->orderBy('product_id')->get() as $row) {
            $x3->add('product_images', Row::str($row, 'ids'), 'product '.Row::int($row, 'product_id').' sort '.Row::int($row, 'sort').' × '.Row::int($row, 'n'));
        }

        $x4 = $r->add(new AuditFinding('X-04', 'products without any gallery row', false, 'informational — cover only'));
        foreach ($this->products()->select(['products.id'])->leftJoin('product_images', 'product_images.product_id', '=', 'products.id')->whereNull('product_images.id')->orderBy('products.id')->get() as $row) {
            $x4->add('products', Row::int($row, 'id'), 'no product_images rows');
        }

        $x5 = $r->add(new AuditFinding('X-05', 'product slugs longer than storefront_product.slug(191)', true, 'BLOCKS — a live URL could not be preserved'));
        $titles = [];
        foreach ($this->legacy->table('product_translations')->select(['product_id', 'product_title'])->where('locale', 'en')->get() as $t) {
            $titles[Row::int($t, 'product_id')] = trim(Row::str($t, 'product_title'));
        }
        $max = 0;
        foreach ($titles as $id => $title) {
            $len = mb_strlen(LegacySlug::orId($title, $id));
            $max = max($max, $len);
            if ($len > 191) {
                $x5->add('products', $id, "slug length $len");
            }
        }
        $x5->note = "Longest slug on this data: $max characters.";

        $x6 = $r->add(new AuditFinding('X-06', 'stock anomalies: negative stock / NULL market_stock', false, 'NULL market_stock → 0; negatives copied as-is into the ledger baseline'));
        foreach ($this->products()->select(['id', 'stock', 'market_stock'])->where(fn (Builder $q) => $q->where('stock', '<', 0)->orWhere('market_stock', '<', 0)->orWhereNull('market_stock'))->orderBy('id')->get() as $row) {
            $x6->add('products', Row::int($row, 'id'), 'stock='.Row::int($row, 'stock').' market_stock='.var_export(Row::nint($row, 'market_stock'), true));
        }
    }

    /** X-07 — schema fact behind the soft-delete reconciliation: which legacy tables carry deleted_at. */
    private function x07(AuditReport $r): void
    {
        $f = $r->add(new AuditFinding('X-07', 'legacy tables with a deleted_at column (soft-deleted rows the transform would have to carry)', false, 'none expected; if any appears, the transform must map it before running'));
        foreach (LegacySource::TABLES as $table) {
            if (in_array('deleted_at', $this->legacy->columns($table), true)) {
                $f->add($table, '-', 'has deleted_at');
            }
        }
        $f->note = 'Scanned all '.count(LegacySource::TABLES).' legacy tables through information_schema.';
    }

    /** @return array<int, array<string, string>> */
    private function names(string $table, string $fk, string $col): array
    {
        $out = [];
        foreach ($this->legacy->table($table)->select([$fk, 'locale', $col])->whereIn('locale', ['ar', 'en'])->orderBy('id')->get() as $row) {
            $out[Row::int($row, $fk)][Row::str($row, 'locale')] = Row::nstr($row, $col) ?? '';
        }

        return $out;
    }
}
