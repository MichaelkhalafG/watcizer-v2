<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * WatchTestSeeder — realistic test data adapted to the ACTUAL schema.
 *
 * NOTE: The original spec referenced tables/columns that do not exist in this
 * project (main_categories, dial_colors, band_colors, dial_color_product, …).
 * This seeder was adjusted to the real schema (per the task instruction to
 * "match EXACT column names"):
 *   - main category    -> `categories` (+ `category_translations`.name)  [not main_categories]
 *   - colors           -> single `colors` table (+ `color_translations`.color_name)
 *   - dial/band pivots -> `color_dial_product` / `color_band_product` with `color_id`
 *   - products require NON-NULL: image, wa_code, purchase_price -> provided
 *   - grade_translations requires NON-NULL `description` -> provided
 *   - product rating user_id -> resolved/created (FK to users)
 */
class WatchTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Brand
        $brandId = DB::table('brands')->insertGetId([
            'image'      => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('brand_translations')->insert([
            ['brand_id' => $brandId, 'locale' => 'en', 'brand_name' => 'Rolex'],
            ['brand_id' => $brandId, 'locale' => 'ar', 'brand_name' => 'رولكس'],
        ]);

        // 2. Category type (Watches)
        $catTypeId = DB::table('category_types')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('category_type_translations')->insert([
            ['category_type_id' => $catTypeId, 'locale' => 'en', 'category_type_name' => 'Watches'],
            ['category_type_id' => $catTypeId, 'locale' => 'ar', 'category_type_name' => 'ساعات'],
        ]);

        // 3. Main category  (real table is `categories`, translatable name in `category_translations`)
        $mainCatId = DB::table('categories')->insertGetId([
            'parent_id'  => null,
            'level'      => 1,
            'slug'       => 'classic-' . Str::lower(Str::random(5)),
            'image'      => null,
            'is_active'  => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('category_translations')->insert([
            ['category_id' => $mainCatId, 'locale' => 'en', 'name' => 'Classic', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['category_id' => $mainCatId, 'locale' => 'ar', 'name' => 'كلاسيك', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 4. Sub type
        $subTypeId = DB::table('sub_types')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sub_type_translations')->insert([
            ['sub_type_id' => $subTypeId, 'locale' => 'en', 'sub_type_name' => 'Chronograph'],
            ['sub_type_id' => $subTypeId, 'locale' => 'ar', 'sub_type_name' => 'كرونوجراف'],
        ]);

        // 5. Grade  (grade_translations.description is NOT NULL)
        $gradeId = DB::table('grades')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('grade_translations')->insert([
            ['grade_id' => $gradeId, 'locale' => 'en', 'grade_name' => 'Premium', 'description' => 'Top-tier authenticated grade.'],
            ['grade_id' => $gradeId, 'locale' => 'ar', 'grade_name' => 'بريميوم', 'description' => 'أعلى درجة موثقة.'],
        ]);

        // 6. Gender
        $genderId = DB::table('genders')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('gender_translations')->insert([
            ['gender_id' => $genderId, 'locale' => 'en', 'gender_name' => 'Men'],
            ['gender_id' => $genderId, 'locale' => 'ar', 'gender_name' => 'رجالي'],
        ]);

        // 7. Dial color  (single `colors` table; translatable color_name)
        $dialColorId = DB::table('colors')->insertGetId([
            'color_value' => '#1a1a2e',
            'created_at'  => now(), 'updated_at' => now(),
        ]);
        DB::table('color_translations')->insert([
            ['color_id' => $dialColorId, 'locale' => 'en', 'color_name' => 'Midnight Blue'],
            ['color_id' => $dialColorId, 'locale' => 'ar', 'color_name' => 'أزرق داكن'],
        ]);

        // 8. Band color
        $bandColorId = DB::table('colors')->insertGetId([
            'color_value' => '#2c1810',
            'created_at'  => now(), 'updated_at' => now(),
        ]);
        DB::table('color_translations')->insert([
            ['color_id' => $bandColorId, 'locale' => 'en', 'color_name' => 'Dark Brown'],
            ['color_id' => $bandColorId, 'locale' => 'ar', 'color_name' => 'بني داكن'],
        ]);

        // Resolve a user for ratings (product_ratings.user_id is a required FK).
        $userId = DB::table('users')->value('id');
        if (! $userId) {
            $userId = DB::table('users')->insertGetId([
                'first_name'   => 'Seed',
                'last_name'    => 'Tester',
                'email'        => 'seed-tester@watchizer.local',
                'type'         => 'User',
                'password'     => Hash::make('password'),
                'phone_number' => '01000000000',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // 9. Create 6 products
        $products = [
            ['title_en' => 'Submariner Date',   'title_ar' => 'سابمارينر دايت',   'price' => 85000, 'sale' => 79000],
            ['title_en' => 'Datejust 41',       'title_ar' => 'دايتجست 41',       'price' => 65000, 'sale' => null],
            ['title_en' => 'GMT Master II',     'title_ar' => 'جي إم تي ماستر 2', 'price' => 95000, 'sale' => 89000],
            ['title_en' => 'Daytona',           'title_ar' => 'دايتونا',           'price' => 120000,'sale' => null],
            ['title_en' => 'Explorer II',       'title_ar' => 'إكسبلورر 2',       'price' => 72000, 'sale' => 68000],
            ['title_en' => 'Yacht-Master 40',   'title_ar' => 'يخت ماستر 40',     'price' => 88000, 'sale' => null],
        ];

        foreach ($products as $i => $p) {
            $productId = DB::table('products')->insertGetId([
                'brand_id'                  => $brandId,
                'main_category_id'          => $mainCatId,
                'sub_type_id'               => $subTypeId,
                'grade_id'                  => $gradeId,
                'category_type_id'          => $catTypeId,
                'purchase_price'            => $p['price'] * 0.7,                  // required NOT NULL
                'selling_price'             => $p['price'],
                'sale_price_after_discount' => $p['sale'],
                'stock'                     => 10,
                'market_stock'              => 5,
                'active'                    => 1,
                'seo_slug'                  => Str::slug($p['title_en']),
                'water_resistance'          => 300,
                'case_thickness'            => 12,
                'band_length'               => 20,
                'model_number'              => 'RLX-00' . ($i + 1),
                'image'                     => 'placeholder.png',                  // required NOT NULL
                'wa_code'                   => 'WA-' . strtoupper(Str::random(6)), // required NOT NULL
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);

            DB::table('product_translations')->insert([
                [
                    'product_id'        => $productId,
                    'locale'            => 'en',
                    'product_title'     => $p['title_en'],
                    'short_description' => 'A masterpiece of Swiss engineering.',
                    'long_description'  => 'This timepiece represents the pinnacle of Rolex craftsmanship.',
                ],
                [
                    'product_id'        => $productId,
                    'locale'            => 'ar',
                    'product_title'     => $p['title_ar'],
                    'short_description' => 'تحفة فنية من الهندسة السويسرية.',
                    'long_description'  => 'تمثل هذه الساعة قمة الحرفية لدى رولكس.',
                ],
            ]);

            // Pivot: gender
            DB::table('gender_product')->insert([
                'gender_id' => $genderId, 'product_id' => $productId,
            ]);

            // Pivot: dial color  (color_dial_product.color_id)
            DB::table('color_dial_product')->insert([
                'color_id' => $dialColorId, 'product_id' => $productId,
            ]);

            // Pivot: band color  (color_band_product.color_id)
            DB::table('color_band_product')->insert([
                'color_id' => $bandColorId, 'product_id' => $productId,
            ]);

            // Rating  (unique on user_id+product_id; one per product is fine)
            DB::table('product_ratings')->insert([
                'product_id' => $productId,
                'user_id'    => $userId,
                'rating'     => rand(4, 5),
                'comment'    => 'Absolutely stunning timepiece!',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('✅ 6 test products seeded successfully.');
    }
}
