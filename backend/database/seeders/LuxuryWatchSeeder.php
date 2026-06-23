<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * LuxuryWatchSeeder
 * -----------------
 * Wipes the catalog graph and reseeds it with a large set of REAL luxury
 * watches (12 brands, 72 products) mapped onto the ACTUAL project schema.
 *
 * Schema notes (verified against migrations / models):
 *   - `AllProduct` API returns the raw Product model with no image accessor,
 *     so `products.image` is rendered verbatim by the SPA. We therefore store
 *     the full external placeholder URL directly in `image` (see PLACEHOLDER).
 *   - `brands` has only `image`; `brand_translations` only `brand_name`. There
 *     are NO country / founding-year / description columns on brands, so brand
 *     metadata cannot be persisted there. Country-of-origin is instead stored
 *     per product via `product_translations.country` (which DOES exist).
 *   - Watch specs (case material, band material, movement) are FK lookups, not
 *     strings — we seed `materials`, `movement_types`, `colors` and reference
 *     them. `case_size` (mm) and `water_resistance` (m) are direct columns.
 *   - Prices: the schema has a single currency set (purchase/selling/sale).
 *     We store realistic EGP retail in `selling_price`; there is no USD column.
 *   - TRUNCATE auto-commits in MySQL and would break the requested transaction
 *     wrap, so the wipe uses FK-disabled DELETE (data cleared, tables kept)
 *     and the whole run is wrapped in DB::transaction().
 */
class LuxuryWatchSeeder extends Seeder
{
    /** Single placeholder used for every image slot — replace later. */
    private const PLACEHOLDER = 'https://cdn-images.farfetch-contents.com/36/05/81/24/36058124_67671596_1000.jpg';

    private int $brandCount = 0;
    private int $productCount = 0;
    private int $imageCount = 0;

    public function run(): void
    {
        DB::transaction(function () {
            $this->wipe();

            $catTypeId = DB::table('category_types')->insertGetId([
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('category_type_translations')->insert([
                ['category_type_id' => $catTypeId, 'locale' => 'en', 'category_type_name' => 'Watches'],
                ['category_type_id' => $catTypeId, 'locale' => 'ar', 'category_type_name' => 'ساعات'],
            ]);

            $categories = $this->seedCategories();
            $subTypes   = $this->seedSubTypes();
            $grades     = $this->seedGrades();
            $genders    = $this->seedGenders();
            $movements  = $this->seedMovements();
            $materials  = $this->seedMaterials();
            $colors     = $this->seedColors();
            [$brands, $brandCountry] = $this->seedBrands();

            $ratingUserId = $this->resolveRatingUser();

            // Category bucket per marketing grade (for main_category_id).
            $gradeCategory = [
                'Iconic Luxury'     => 'Luxury Watches',
                'Sport & Dive'      => 'Sport Watches',
                'Classic Elegance'  => 'Dress Watches',
                'Heritage Collection' => 'Dress Watches',
            ];

            foreach ($this->catalog() as $i => $row) {
                [$brand, $en, $ar, $ref, $price, $caseMat, $size, $movement,
                 $wr, $dial, $band, $bandMat, $sub, $grade, $gender] = $row;

                $hasSale = ($i % 4 === 0);
                $sale    = $hasSale ? round($price * 0.9) : null;
                $pct     = $hasSale ? 10 : null;

                $productId = DB::table('products')->insertGetId([
                    'category_type_id'          => $catTypeId,
                    'brand_id'                  => $brands[$brand],
                    'grade_id'                  => $grades[$grade],
                    'sub_type_id'               => $subTypes[$sub],
                    'main_category_id'          => $categories[$gradeCategory[$grade]],
                    'watch_movement_id'         => $movements[$movement],
                    'dial_case_material_id'     => $materials[$caseMat],
                    'band_material_id'          => $materials[$bandMat],
                    'case_size'                 => $size,
                    'water_resistance'          => $wr,
                    'model_number'              => $ref,
                    'sku_unique'                => strtoupper(Str::substr($brand, 0, 3)) . '-' . $ref,
                    'image'                     => self::PLACEHOLDER,
                    'purchase_price'            => round($price * 0.75),
                    'selling_price'             => $price,
                    'sale_price_after_discount' => $sale,
                    'percentage_discount'       => $pct,
                    'stock'                     => rand(3, 25),
                    'market_stock'              => rand(1, 10),
                    'average_rate'              => rand(40, 50) / 10,
                    'active'                    => 1,
                    'seo_slug'                  => Str::slug($brand . ' ' . $en . ' ' . $ref),
                    'wa_code'                   => 'WA-' . strtoupper(Str::random(8)),
                    'created_at'                => now(),
                    'updated_at'                => now(),
                ]);
                $this->productCount++;

                $shortEn = "{$brand} {$en} — {$size}mm {$caseMat} case, {$movement} movement, {$wr}m water resistance.";
                $shortAr = "{$en} من {$brand} — قطر {$size} مم، خامة {$this->mAr($caseMat)}، حركة {$this->moveAr($movement)}، مقاومة للماء حتى {$wr} متر.";
                $longEn  = "The {$brand} {$en} (ref. {$ref}) is a {$movement} timepiece featuring a {$size}mm {$caseMat} case with a {$dial} dial on a {$bandMat} {$band} band. A definitive example of {$brand}'s craftsmanship.";
                $longAr  = "تُعد ساعة {$en} من {$brand} (موديل {$ref}) قطعة {$this->moveAr($movement)} بقطر {$size} مم من {$this->mAr($caseMat)} مع مينا بلون {$this->cAr($dial)} وسوار من {$this->mAr($bandMat)}. مثال راقٍ على حرفية {$brand}.";

                DB::table('product_translations')->insert([
                    [
                        'product_id' => $productId, 'locale' => 'en',
                        'product_title' => "{$brand} {$en}", 'model_name' => $ref,
                        'country' => $brandCountry[$brand][0], 'stone' => null,
                        'short_description' => $shortEn, 'long_description' => $longEn,
                    ],
                    [
                        'product_id' => $productId, 'locale' => 'ar',
                        'product_title' => "{$brand} {$ar}", 'model_name' => $ref,
                        'country' => $brandCountry[$brand][1], 'stone' => null,
                        'short_description' => $shortAr, 'long_description' => $longAr,
                    ],
                ]);

                // 3 gallery images (all the same placeholder for now).
                for ($s = 0; $s < 3; $s++) {
                    DB::table('product_images')->insert([
                        'product_id' => $productId,
                        'image'      => self::PLACEHOLDER,
                        'is_cover'   => $s === 0,
                        'sort'       => $s,
                        'alt_en'     => "{$brand} {$en}",
                        'alt_ar'     => "{$brand} {$ar}",
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->imageCount++;
                }

                DB::table('gender_product')->insert([
                    'gender_id' => $genders[$gender], 'product_id' => $productId,
                ]);
                DB::table('color_dial_product')->insert([
                    'color_id' => $colors[$dial], 'product_id' => $productId,
                ]);
                DB::table('color_band_product')->insert([
                    'color_id' => $colors[$band], 'product_id' => $productId,
                ]);

                DB::table('product_ratings')->insert([
                    'product_id' => $productId, 'user_id' => $ratingUserId,
                    'rating' => rand(4, 5), 'comment' => 'Exceptional craftsmanship and finish.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->command->info('────────────────────────────────────────');
        $this->command->info("✅ Luxury catalog seeded");
        $this->command->info("   Brands  inserted: {$this->brandCount}");
        $this->command->info("   Products inserted: {$this->productCount}");
        $this->command->info("   Images  inserted: {$this->imageCount}");
        $this->command->info('────────────────────────────────────────');
    }

    /** FK-disabled DELETE wipe (transaction-safe alternative to TRUNCATE). */
    private function wipe(): void
    {
        Schema::disableForeignKeyConstraints();
        $tables = [
            'order_items', 'cart_items', 'wishlist_items',
            'product_ratings', 'offer_ratings',
            'product_variants', 'feature_product', 'gender_product',
            'color_dial_product', 'color_band_product',
            'product_images', 'product_translations', 'products',
            'offer_translations', 'offers',
            'brand_translations', 'brands',
            'category_translations', 'categories',
            'category_type_translations', 'category_types',
            'sub_type_translations', 'sub_types',
            'grade_translations', 'grades',
            'gender_translations', 'genders',
            'color_translations', 'colors',
            'material_translations', 'materials',
            'movement_type_translations', 'movement_types',
        ];
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->delete();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    private function seedCategories(): array
    {
        $defs = [
            ['Luxury Watches', 'الساعات الفاخرة'],
            ['Sport Watches',  'الساعات الرياضية'],
            ['Dress Watches',  'الساعات الكلاسيكية'],
        ];
        $map = [];
        foreach ($defs as $i => [$en, $ar]) {
            $id = DB::table('categories')->insertGetId([
                'parent_id' => null, 'level' => 1,
                'slug' => Str::slug($en), 'image' => null,
                'is_active' => 1, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('category_translations')->insert([
                ['category_id' => $id, 'locale' => 'en', 'name' => $en, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
                ['category_id' => $id, 'locale' => 'ar', 'name' => $ar, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedSubTypes(): array
    {
        $defs = [
            ['Diver', 'ساعات الغوص'],
            ['Chronograph', 'كرونوغراف'],
            ['GMT', 'جي إم تي'],
            ['Dress', 'كلاسيكية'],
            ['Pilot', 'ساعات الطيارين'],
            ['Sports', 'رياضية'],
        ];
        $map = [];
        foreach ($defs as [$en, $ar]) {
            $id = DB::table('sub_types')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('sub_type_translations')->insert([
                ['sub_type_id' => $id, 'locale' => 'en', 'sub_type_name' => $en],
                ['sub_type_id' => $id, 'locale' => 'ar', 'sub_type_name' => $ar],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedGrades(): array
    {
        $defs = [
            ['Iconic Luxury', 'الفخامة الأيقونية', 'The most coveted timepieces in horology.', 'أكثر الساعات رغبةً في عالم صناعة الوقت.'],
            ['Sport & Dive', 'الرياضة والغوص', 'Built for performance under pressure.', 'صُممت للأداء تحت الضغط.'],
            ['Classic Elegance', 'الأناقة الكلاسيكية', 'Timeless dress watches for every occasion.', 'ساعات كلاسيكية خالدة لكل المناسبات.'],
            ['Heritage Collection', 'مجموعة التراث', 'Vintage-inspired designs reborn.', 'تصاميم مستوحاة من الطراز القديم تُبعث من جديد.'],
        ];
        $map = [];
        foreach ($defs as [$en, $ar, $dEn, $dAr]) {
            $id = DB::table('grades')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('grade_translations')->insert([
                ['grade_id' => $id, 'locale' => 'en', 'grade_name' => $en, 'description' => $dEn],
                ['grade_id' => $id, 'locale' => 'ar', 'grade_name' => $ar, 'description' => $dAr],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedGenders(): array
    {
        $defs = [['Men', 'رجالي'], ['Women', 'نسائي'], ['Unisex', 'للجنسين']];
        $map = [];
        foreach ($defs as [$en, $ar]) {
            $id = DB::table('genders')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('gender_translations')->insert([
                ['gender_id' => $id, 'locale' => 'en', 'gender_name' => $en],
                ['gender_id' => $id, 'locale' => 'ar', 'gender_name' => $ar],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedMovements(): array
    {
        $map = [];
        foreach ($this->movementDefs() as [$en, $ar]) {
            $id = DB::table('movement_types')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('movement_type_translations')->insert([
                ['movement_type_id' => $id, 'locale' => 'en', 'movement_type_name' => $en],
                ['movement_type_id' => $id, 'locale' => 'ar', 'movement_type_name' => $ar],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedMaterials(): array
    {
        $map = [];
        foreach ($this->materialDefs() as [$en, $ar]) {
            $id = DB::table('materials')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('material_translations')->insert([
                ['material_id' => $id, 'locale' => 'en', 'material_name' => $en],
                ['material_id' => $id, 'locale' => 'ar', 'material_name' => $ar],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    private function seedColors(): array
    {
        $map = [];
        foreach ($this->colorDefs() as [$en, $ar, $hex]) {
            $id = DB::table('colors')->insertGetId([
                'color_value' => $hex, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('color_translations')->insert([
                ['color_id' => $id, 'locale' => 'en', 'color_name' => $en],
                ['color_id' => $id, 'locale' => 'ar', 'color_name' => $ar],
            ]);
            $map[$en] = $id;
        }
        return $map;
    }

    /** @return array{0: array<string,int>, 1: array<string,array{0:string,1:string}>} */
    private function seedBrands(): array
    {
        $defs = [
            ['Rolex', 'رولكس', 'Switzerland', 'سويسرا'],
            ['Omega', 'أوميغا', 'Switzerland', 'سويسرا'],
            ['Patek Philippe', 'باتيك فيليب', 'Switzerland', 'سويسرا'],
            ['Audemars Piguet', 'أوديمار بيغيه', 'Switzerland', 'سويسرا'],
            ['Cartier', 'كارتييه', 'France', 'فرنسا'],
            ['TAG Heuer', 'تاغ هوير', 'Switzerland', 'سويسرا'],
            ['Breitling', 'بريتلينغ', 'Switzerland', 'سويسرا'],
            ['IWC Schaffhausen', 'آي دبليو سي شافهاوزن', 'Switzerland', 'سويسرا'],
            ['Jaeger-LeCoultre', 'جيجر لوكولتر', 'Switzerland', 'سويسرا'],
            ['Hublot', 'هوبلو', 'Switzerland', 'سويسرا'],
            ['Tudor', 'تيودور', 'Switzerland', 'سويسرا'],
            ['Longines', 'لونجين', 'Switzerland', 'سويسرا'],
        ];
        $map = [];
        $country = [];
        foreach ($defs as [$en, $ar, $cEn, $cAr]) {
            $id = DB::table('brands')->insertGetId([
                'image' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('brand_translations')->insert([
                ['brand_id' => $id, 'locale' => 'en', 'brand_name' => $en],
                ['brand_id' => $id, 'locale' => 'ar', 'brand_name' => $ar],
            ]);
            $map[$en] = $id;
            $country[$en] = [$cEn, $cAr];
            $this->brandCount++;
        }
        return [$map, $country];
    }

    private function resolveRatingUser(): int
    {
        $id = DB::table('users')->value('id');
        if ($id) {
            return $id;
        }
        return DB::table('users')->insertGetId([
            'first_name' => 'Seed', 'last_name' => 'Tester',
            'email' => 'seed-tester@watchizer.local', 'type' => 'User',
            'password' => bcrypt('password'), 'phone_number' => '01000000000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Lookup definitions ──────────────────────────────────────────────

    private function movementDefs(): array
    {
        return [['Automatic', 'أوتوماتيك'], ['Manual', 'يدوي'], ['Quartz', 'كوارتز']];
    }

    private function materialDefs(): array
    {
        return [
            ['Stainless Steel', 'ستانلس ستيل'],
            ['Yellow Gold', 'ذهب أصفر'],
            ['Rose Gold', 'ذهب وردي'],
            ['White Gold', 'ذهب أبيض'],
            ['Platinum', 'بلاتين'],
            ['Titanium', 'تيتانيوم'],
            ['Ceramic', 'سيراميك'],
            ['Leather', 'جلد'],
            ['Alligator Leather', 'جلد التمساح'],
            ['Rubber', 'مطاط'],
        ];
    }

    private function colorDefs(): array
    {
        return [
            ['Black', 'أسود', '#111111'],
            ['White', 'أبيض', '#f5f5f5'],
            ['Silver', 'فضي', '#c0c0c0'],
            ['Blue', 'أزرق', '#1f3a5f'],
            ['Green', 'أخضر', '#1b4332'],
            ['Grey', 'رمادي', '#4b4f54'],
            ['Brown', 'بني', '#4a2c1a'],
            ['Champagne', 'شمبانيا', '#f3e5ab'],
            ['Salmon', 'سلموني', '#ff9e80'],
            ['Rose Gold', 'ذهبي وردي', '#b76e79'],
        ];
    }

    private function mAr(string $en): string
    {
        foreach ($this->materialDefs() as [$e, $a]) {
            if ($e === $en) return $a;
        }
        return $en;
    }

    private function moveAr(string $en): string
    {
        foreach ($this->movementDefs() as [$e, $a]) {
            if ($e === $en) return $a;
        }
        return $en;
    }

    private function cAr(string $en): string
    {
        foreach ($this->colorDefs() as [$e, $a]) {
            if ($e === $en) return $a;
        }
        return $en;
    }

    // ── Catalog: 72 real luxury watches ─────────────────────────────────
    // [brand, en, ar, ref, priceEGP, caseMat, sizeMM, movement, waterRes,
    //  dialColor, bandColor, bandMaterial, subType, grade, gender]
    private function catalog(): array
    {
        return [
            // ── Rolex ──
            ['Rolex', 'Submariner Date', 'سابمارينر دايت', '126610LN', 650000, 'Stainless Steel', 41, 'Automatic', 300, 'Black', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],
            ['Rolex', 'Datejust 41', 'دايت جست 41', '126334', 520000, 'Stainless Steel', 41, 'Automatic', 100, 'Blue', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Men'],
            ['Rolex', 'GMT-Master II', 'جي إم تي ماستر 2', '126710BLRO', 950000, 'Stainless Steel', 40, 'Automatic', 100, 'Black', 'Silver', 'Stainless Steel', 'GMT', 'Iconic Luxury', 'Men'],
            ['Rolex', 'Cosmograph Daytona', 'كوزموغراف دايتونا', '116500LN', 2200000, 'Stainless Steel', 40, 'Automatic', 100, 'White', 'Silver', 'Stainless Steel', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Rolex', 'Day-Date 40', 'داي دايت 40', '228238', 1900000, 'Yellow Gold', 40, 'Automatic', 100, 'Champagne', 'Champagne', 'Yellow Gold', 'Dress', 'Iconic Luxury', 'Men'],
            ['Rolex', 'Explorer II', 'إكسبلورر 2', '226570', 700000, 'Stainless Steel', 42, 'Automatic', 100, 'White', 'Silver', 'Stainless Steel', 'GMT', 'Sport & Dive', 'Men'],

            // ── Omega ──
            ['Omega', 'Speedmaster Moonwatch Professional', 'سبيدماستر موون ووتش', '310.30.42.50.01.001', 380000, 'Stainless Steel', 42, 'Manual', 50, 'Black', 'Silver', 'Stainless Steel', 'Chronograph', 'Heritage Collection', 'Men'],
            ['Omega', 'Seamaster Diver 300M', 'سيماستر دايفر 300', '210.30.42.20.03.001', 320000, 'Stainless Steel', 42, 'Automatic', 300, 'Blue', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],
            ['Omega', 'Seamaster Aqua Terra', 'سيماستر أكوا تيرا', '220.10.41.21.03.004', 360000, 'Stainless Steel', 41, 'Automatic', 150, 'Blue', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Men'],
            ['Omega', 'Constellation', 'كونستليشن', '131.10.39.20.06.001', 280000, 'Stainless Steel', 39, 'Automatic', 100, 'Grey', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Men'],
            ['Omega', 'De Ville Prestige', 'دو فيل برستيج', '424.13.40.20.02.001', 250000, 'Stainless Steel', 40, 'Automatic', 30, 'Silver', 'Brown', 'Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Omega', 'Seamaster Planet Ocean 600M', 'سيماستر بلانيت أوشن', '215.30.44.21.01.001', 420000, 'Stainless Steel', 43, 'Automatic', 600, 'Black', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],

            // ── Patek Philippe ──
            ['Patek Philippe', 'Nautilus 5711/1A', 'نوتيلوس 5711', '5711/1A-010', 4500000, 'Stainless Steel', 40, 'Automatic', 120, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Iconic Luxury', 'Men'],
            ['Patek Philippe', 'Aquanaut', 'أكوانوت', '5167A-001', 3800000, 'Stainless Steel', 40, 'Automatic', 120, 'Black', 'Black', 'Rubber', 'Sports', 'Iconic Luxury', 'Men'],
            ['Patek Philippe', 'Calatrava', 'كالاترافا', '5227G-010', 2400000, 'White Gold', 39, 'Automatic', 30, 'White', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Patek Philippe', 'Grand Complications', 'غراند كومبليكيشن', '5270G-019', 6500000, 'White Gold', 41, 'Manual', 30, 'Silver', 'Brown', 'Alligator Leather', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Patek Philippe', 'Twenty~4 Automatic', 'توينتي فور', '7300/1200A-001', 1800000, 'Stainless Steel', 36, 'Automatic', 30, 'Grey', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Women'],
            ['Patek Philippe', 'Nautilus 5712', 'نوتيلوس 5712', '5712/1A-001', 4200000, 'Stainless Steel', 40, 'Automatic', 60, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Iconic Luxury', 'Men'],

            // ── Audemars Piguet ──
            ['Audemars Piguet', 'Royal Oak Selfwinding', 'رويال أوك سيلف وايندنغ', '15500ST.OO.1220ST.01', 1700000, 'Stainless Steel', 41, 'Automatic', 50, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Iconic Luxury', 'Men'],
            ['Audemars Piguet', 'Royal Oak Offshore', 'رويال أوك أوفشور', '26470ST.OO.A027CA.01', 2200000, 'Stainless Steel', 42, 'Automatic', 100, 'Black', 'Black', 'Rubber', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Audemars Piguet', 'Royal Oak Chronograph', 'رويال أوك كرونوغراف', '26331ST.OO.1220ST.02', 2600000, 'Stainless Steel', 41, 'Automatic', 50, 'Blue', 'Silver', 'Stainless Steel', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Audemars Piguet', 'Code 11.59 Automatic', 'كود 11.59', '15210BC.OO.A002CR.01', 1500000, 'White Gold', 41, 'Automatic', 30, 'Blue', 'Black', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Audemars Piguet', 'Royal Oak Jumbo Extra-Thin', 'رويال أوك جامبو', '15202ST.OO.1240ST.01', 1750000, 'Stainless Steel', 39, 'Automatic', 50, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Iconic Luxury', 'Men'],
            ['Audemars Piguet', 'Royal Oak Perpetual Calendar', 'رويال أوك بربتشوال', '26574ST.OO.1220ST.02', 5500000, 'Stainless Steel', 41, 'Automatic', 20, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Iconic Luxury', 'Men'],

            // ── Cartier ──
            ['Cartier', 'Santos de Cartier', 'سانتو دو كارتييه', 'WSSA0030', 360000, 'Stainless Steel', 40, 'Automatic', 100, 'Silver', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Men'],
            ['Cartier', 'Tank Must', 'تانك ماست', 'WSTA0041', 180000, 'Stainless Steel', 34, 'Quartz', 30, 'Silver', 'Black', 'Leather', 'Dress', 'Classic Elegance', 'Women'],
            ['Cartier', 'Ballon Bleu de Cartier', 'بالون بلو', 'WSBB0040', 320000, 'Stainless Steel', 42, 'Automatic', 30, 'Silver', 'Silver', 'Stainless Steel', 'Dress', 'Classic Elegance', 'Men'],
            ['Cartier', 'Santos-Dumont', 'سانتو دومون', 'WSSA0022', 220000, 'Stainless Steel', 38, 'Quartz', 30, 'Silver', 'Brown', 'Alligator Leather', 'Dress', 'Heritage Collection', 'Men'],
            ['Cartier', 'Panthère de Cartier', 'بانتير دو كارتييه', 'W2PN0006', 300000, 'Yellow Gold', 27, 'Quartz', 30, 'Silver', 'Champagne', 'Yellow Gold', 'Dress', 'Classic Elegance', 'Women'],
            ['Cartier', 'Tank Française', 'تانك فرانسيز', 'WGTA0024', 260000, 'Stainless Steel', 32, 'Automatic', 30, 'Silver', 'Silver', 'Stainless Steel', 'Dress', 'Heritage Collection', 'Women'],

            // ── TAG Heuer ──
            ['TAG Heuer', 'Carrera Chronograph', 'كاريرا كرونوغراف', 'CBN2A1B.BA0643', 240000, 'Stainless Steel', 44, 'Automatic', 100, 'Black', 'Silver', 'Stainless Steel', 'Chronograph', 'Sport & Dive', 'Men'],
            ['TAG Heuer', 'Monaco Calibre Heuer 02', 'موناكو', 'CBL2111.BA0644', 380000, 'Stainless Steel', 39, 'Automatic', 100, 'Blue', 'Silver', 'Stainless Steel', 'Chronograph', 'Heritage Collection', 'Men'],
            ['TAG Heuer', 'Aquaracer Professional 300', 'أكواريسر 300', 'WBP201A.BA0632', 200000, 'Stainless Steel', 43, 'Automatic', 300, 'Blue', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],
            ['TAG Heuer', 'Formula 1', 'فورمولا 1', 'CAZ101N.FC8243', 120000, 'Stainless Steel', 43, 'Quartz', 200, 'Black', 'Black', 'Rubber', 'Sports', 'Sport & Dive', 'Men'],
            ['TAG Heuer', 'Carrera Twin-Time', 'كاريرا توين تايم', 'WBN201A.BA0640', 260000, 'Stainless Steel', 41, 'Automatic', 100, 'Silver', 'Silver', 'Stainless Steel', 'GMT', 'Sport & Dive', 'Men'],
            ['TAG Heuer', 'Autavia', 'أوتافيا', 'WBE5114.EB0173', 230000, 'Stainless Steel', 42, 'Automatic', 100, 'Green', 'Brown', 'Leather', 'Pilot', 'Heritage Collection', 'Men'],

            // ── Breitling ──
            ['Breitling', 'Navitimer B01 Chronograph 43', 'نافيتايمر', 'AB0138241B1A1', 420000, 'Stainless Steel', 43, 'Automatic', 30, 'Blue', 'Silver', 'Stainless Steel', 'Chronograph', 'Heritage Collection', 'Men'],
            ['Breitling', 'Superocean Automatic 42', 'سوبر أوشن 42', 'A17375211B1A1', 240000, 'Stainless Steel', 42, 'Automatic', 300, 'Black', 'Black', 'Rubber', 'Diver', 'Sport & Dive', 'Men'],
            ['Breitling', 'Chronomat B01 42', 'كرونومات 42', 'AB0134101C1A1', 380000, 'Stainless Steel', 42, 'Automatic', 200, 'Blue', 'Silver', 'Stainless Steel', 'Chronograph', 'Sport & Dive', 'Men'],
            ['Breitling', 'Avenger Automatic 43', 'أفنجر 43', 'A17318101B1X1', 280000, 'Stainless Steel', 43, 'Automatic', 300, 'Black', 'Black', 'Leather', 'Pilot', 'Sport & Dive', 'Men'],
            ['Breitling', 'Premier B01 Chronograph 42', 'بريمير', 'AB0118221C1P1', 300000, 'Stainless Steel', 42, 'Automatic', 100, 'Silver', 'Brown', 'Alligator Leather', 'Chronograph', 'Classic Elegance', 'Men'],
            ['Breitling', 'Superocean Heritage B20', 'سوبر أوشن هيريتاج', 'AB2010121B1A1', 320000, 'Stainless Steel', 42, 'Automatic', 200, 'Black', 'Silver', 'Stainless Steel', 'Diver', 'Heritage Collection', 'Men'],

            // ── IWC Schaffhausen ──
            ['IWC Schaffhausen', 'Portugieser Chronograph', 'بورتوغيزر كرونوغراف', 'IW371617', 450000, 'Stainless Steel', 41, 'Automatic', 30, 'Blue', 'Brown', 'Alligator Leather', 'Chronograph', 'Classic Elegance', 'Men'],
            ['IWC Schaffhausen', 'Pilot\'s Watch Mark XX', 'بايلوت مارك 20', 'IW328201', 290000, 'Stainless Steel', 40, 'Automatic', 100, 'Blue', 'Silver', 'Stainless Steel', 'Pilot', 'Sport & Dive', 'Men'],
            ['IWC Schaffhausen', 'Portofino Automatic', 'بورتوفينو', 'IW356517', 280000, 'Stainless Steel', 40, 'Automatic', 30, 'Silver', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['IWC Schaffhausen', 'Big Pilot\'s Watch 43', 'بيغ بايلوت 43', 'IW329301', 600000, 'Stainless Steel', 43, 'Automatic', 100, 'Black', 'Brown', 'Leather', 'Pilot', 'Iconic Luxury', 'Men'],
            ['IWC Schaffhausen', 'Aquatimer Automatic', 'أكواتايمر', 'IW328803', 350000, 'Stainless Steel', 42, 'Automatic', 300, 'Black', 'Black', 'Rubber', 'Diver', 'Sport & Dive', 'Men'],
            ['IWC Schaffhausen', 'Ingenieur Automatic 40', 'إنجينير 40', 'IW328901', 380000, 'Stainless Steel', 40, 'Automatic', 100, 'Grey', 'Silver', 'Stainless Steel', 'Sports', 'Sport & Dive', 'Men'],

            // ── Jaeger-LeCoultre ──
            ['Jaeger-LeCoultre', 'Reverso Classic Medium', 'ريفرسو كلاسيك', 'Q2548520', 380000, 'Stainless Steel', 40, 'Automatic', 30, 'Silver', 'Black', 'Alligator Leather', 'Dress', 'Heritage Collection', 'Men'],
            ['Jaeger-LeCoultre', 'Master Ultra Thin Moon', 'ماستر الترا ثين مون', 'Q1368420', 600000, 'Stainless Steel', 39, 'Automatic', 50, 'Blue', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Jaeger-LeCoultre', 'Polaris Date', 'بولاريس دايت', 'Q9068180', 480000, 'Stainless Steel', 42, 'Automatic', 200, 'Blue', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],
            ['Jaeger-LeCoultre', 'Master Control Date', 'ماستر كنترول', 'Q4018420', 420000, 'Stainless Steel', 40, 'Automatic', 50, 'Silver', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Jaeger-LeCoultre', 'Reverso Tribute Duoface', 'ريفرسو تريبيوت', 'Q3908420', 700000, 'Stainless Steel', 47, 'Manual', 30, 'Blue', 'Black', 'Alligator Leather', 'Dress', 'Iconic Luxury', 'Men'],
            ['Jaeger-LeCoultre', 'Master Ultra Thin', 'ماستر الترا ثين', 'Q1288420', 450000, 'Stainless Steel', 38, 'Automatic', 50, 'Silver', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],

            // ── Hublot ──
            ['Hublot', 'Big Bang Unico 42', 'بيغ بانغ يونيكو', '441.NX.1171.RX', 950000, 'Titanium', 42, 'Automatic', 100, 'Grey', 'Black', 'Rubber', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Hublot', 'Classic Fusion 42', 'كلاسيك فيوجن', '542.NX.1171.RX', 420000, 'Titanium', 42, 'Automatic', 50, 'Black', 'Black', 'Rubber', 'Dress', 'Sport & Dive', 'Men'],
            ['Hublot', 'Big Bang Integral', 'بيغ بانغ إنتغرال', '451.NX.1170.NX', 1100000, 'Titanium', 42, 'Automatic', 100, 'Black', 'Grey', 'Titanium', 'Chronograph', 'Iconic Luxury', 'Men'],
            ['Hublot', 'Spirit of Big Bang', 'سبيريت أوف بيغ بانغ', '601.NX.0173.LR', 850000, 'Titanium', 42, 'Automatic', 100, 'Black', 'Black', 'Rubber', 'Chronograph', 'Sport & Dive', 'Men'],
            ['Hublot', 'Classic Fusion Chronograph', 'كلاسيك فيوجن كرونو', '521.NX.1171.RX', 550000, 'Titanium', 42, 'Automatic', 50, 'Blue', 'Blue', 'Rubber', 'Chronograph', 'Sport & Dive', 'Men'],
            ['Hublot', 'Big Bang Sang Bleu II', 'بيغ بانغ سانغ بلو', '418.NX.1107.RX', 1300000, 'Titanium', 45, 'Automatic', 100, 'Black', 'Black', 'Rubber', 'Chronograph', 'Iconic Luxury', 'Men'],

            // ── Tudor ──
            ['Tudor', 'Black Bay 58', 'بلاك باي 58', 'M79030N-0001', 190000, 'Stainless Steel', 39, 'Automatic', 200, 'Black', 'Silver', 'Stainless Steel', 'Diver', 'Heritage Collection', 'Men'],
            ['Tudor', 'Black Bay GMT', 'بلاك باي جي إم تي', 'M79830RB-0001', 230000, 'Stainless Steel', 41, 'Automatic', 200, 'Black', 'Silver', 'Stainless Steel', 'GMT', 'Sport & Dive', 'Men'],
            ['Tudor', 'Pelagos 39', 'بيلاغوس 39', 'M25407N-0001', 250000, 'Titanium', 39, 'Automatic', 200, 'Black', 'Grey', 'Titanium', 'Diver', 'Sport & Dive', 'Men'],
            ['Tudor', 'Royal', 'رويال', 'M28500-0008', 140000, 'Stainless Steel', 41, 'Automatic', 100, 'Blue', 'Silver', 'Stainless Steel', 'Sports', 'Classic Elegance', 'Men'],
            ['Tudor', 'Black Bay Chrono', 'بلاك باي كرونو', 'M79360N-0001', 280000, 'Stainless Steel', 41, 'Automatic', 200, 'Black', 'Silver', 'Stainless Steel', 'Chronograph', 'Sport & Dive', 'Men'],
            ['Tudor', '1926', '1926', 'M91450-0005', 120000, 'Stainless Steel', 39, 'Automatic', 100, 'Silver', 'Silver', 'Stainless Steel', 'Dress', 'Heritage Collection', 'Men'],

            // ── Longines ──
            ['Longines', 'Master Collection', 'ماستر كولكشن', 'L2.793.4.78.3', 160000, 'Stainless Steel', 40, 'Automatic', 30, 'Blue', 'Brown', 'Alligator Leather', 'Dress', 'Classic Elegance', 'Men'],
            ['Longines', 'HydroConquest', 'هيدرو كونكويست', 'L3.781.4.96.6', 110000, 'Stainless Steel', 41, 'Automatic', 300, 'Blue', 'Silver', 'Stainless Steel', 'Diver', 'Sport & Dive', 'Men'],
            ['Longines', 'Spirit Zulu Time', 'سبيريت زولو تايم', 'L3.812.4.63.6', 170000, 'Stainless Steel', 42, 'Automatic', 100, 'Black', 'Silver', 'Stainless Steel', 'GMT', 'Sport & Dive', 'Men'],
            ['Longines', 'Conquest', 'كونكويست', 'L3.830.4.92.6', 120000, 'Stainless Steel', 41, 'Automatic', 100, 'Green', 'Silver', 'Stainless Steel', 'Sports', 'Sport & Dive', 'Men'],
            ['Longines', 'DolceVita', 'دولتشي فيتا', 'L5.512.0.71.7', 130000, 'Stainless Steel', 28, 'Quartz', 30, 'Silver', 'Black', 'Leather', 'Dress', 'Classic Elegance', 'Women'],
            ['Longines', 'Legend Diver', 'ليجند دايفر', 'L3.774.4.50.2', 150000, 'Stainless Steel', 42, 'Automatic', 300, 'Black', 'Black', 'Leather', 'Diver', 'Heritage Collection', 'Men'],
        ];
    }
}
