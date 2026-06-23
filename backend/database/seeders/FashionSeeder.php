<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FashionSeeder — adds a "Fashion" category type with fashion sub-types,
 * fashion brands, and fashion products (test/dummy data).
 *
 * Schema notes (verified against the live DB, Prompt 1 audit):
 *   - brands / sub_types / category_types hold only (id, image, timestamps);
 *     all names live in their *_translations tables (Astrotomic).
 *   - sub_types has NO category_type_id column — the sub-type ↔ category-type
 *     relationship exists only implicitly through products (which carry both
 *     category_type_id and sub_type_id). So we just create the sub-types and
 *     bind them to Fashion by creating Fashion products that reference them.
 *   - products NOT NULL (no default): brand_id, image, wa_code,
 *     purchase_price, selling_price, stock. (category_type_id / sub_type_id
 *     / grade_id are nullable.)
 *   - product_translations NOT NULL: locale, product_id, product_title,
 *     long_description, short_description.
 */
class FashionSeeder extends Seeder
{
    public function run(): void
    {
        // ── Image pool (bare filenames; frontend rebuilds the URL per folder) ──
        $scan = function (string $sub) {
            $exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $dir = public_path('Uploads_Images/' . $sub);
            if (!is_dir($dir)) {
                return [];
            }
            return collect(scandir($dir))
                ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts))
                ->values()
                ->all();
        };
        $brandImages   = $scan('Brand');
        $productImages = $scan('Product');
        $pick = fn (array $pool) => empty($pool) ? 'placeholder.png' : $pool[array_rand($pool)];

        DB::transaction(function () use ($brandImages, $productImages, $pick) {
            // ── A) Fashion category type (find-or-create) ──────────────────
            $catTypeId = DB::table('category_type_translations')
                ->where('locale', 'en')
                ->where('category_type_name', 'Fashion')
                ->value('category_type_id');

            if (!$catTypeId) {
                $catTypeId = DB::table('category_types')->insertGetId([
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('category_type_translations')->insert([
                    ['category_type_id' => $catTypeId, 'locale' => 'en', 'category_type_name' => 'Fashion'],
                    ['category_type_id' => $catTypeId, 'locale' => 'ar', 'category_type_name' => 'أزياء'],
                ]);
            }
            $this->command->info('Fashion category type: ID ' . $catTypeId);

            // ── B) Fashion sub-types (find-or-create by EN name) ───────────
            $subTypeDefs = [
                ['en' => 'Caps',       'ar' => 'قبعات'],
                ['en' => 'Belts',      'ar' => 'أحزمة'],
                ['en' => 'Wallets',    'ar' => 'محافظ'],
                ['en' => 'Bags',       'ar' => 'حقائب'],
                ['en' => 'Perfumes',   'ar' => 'عطور'],
                ['en' => 'Sunglasses', 'ar' => 'نظارات شمسية'],
                ['en' => 'Jewelry',    'ar' => 'مجوهرات'],
                ['en' => 'Bracelets',  'ar' => 'أساور'],
                ['en' => 'Scarves',    'ar' => 'أوشحة'],
                ['en' => 'Keychains',  'ar' => 'مفاتيح'],
            ];

            $subTypeIds = [];   // EN name => id
            $subTypesCreated = 0;
            foreach ($subTypeDefs as $st) {
                $id = DB::table('sub_type_translations')
                    ->where('locale', 'en')
                    ->where('sub_type_name', $st['en'])
                    ->value('sub_type_id');

                if (!$id) {
                    $id = DB::table('sub_types')->insertGetId([
                        'image' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('sub_type_translations')->insert([
                        ['sub_type_id' => $id, 'locale' => 'en', 'sub_type_name' => $st['en']],
                        ['sub_type_id' => $id, 'locale' => 'ar', 'sub_type_name' => $st['ar']],
                    ]);
                    $subTypesCreated++;
                }
                $subTypeIds[$st['en']] = $id;
            }
            $this->command->info('Sub-types created: ' . $subTypesCreated . ' (total mapped: ' . count($subTypeIds) . ')');

            // ── C) Fashion brands (find-or-create by EN name) ──────────────
            $brandDefs = [
                ['en' => 'Gucci',          'ar' => 'غوتشي'],
                ['en' => 'Louis Vuitton',  'ar' => 'لويس فيتون'],
                ['en' => 'Versace',        'ar' => 'فيرساتشي'],
                ['en' => 'Burberry',       'ar' => 'بربري'],
                ['en' => 'Hugo Boss',      'ar' => 'هوغو بوس'],
                ['en' => 'Lacoste',        'ar' => 'لاكوست'],
                ['en' => 'Tommy Hilfiger', 'ar' => 'تومي هيلفيغر'],
                ['en' => 'Calvin Klein',   'ar' => 'كالفن كلاين'],
            ];

            $brandIds = [];   // EN name => id
            $brandsCreated = 0;
            foreach ($brandDefs as $b) {
                $id = DB::table('brand_translations')
                    ->where('locale', 'en')
                    ->where('brand_name', $b['en'])
                    ->value('brand_id');

                if (!$id) {
                    $id = DB::table('brands')->insertGetId([
                        'image' => $pick($brandImages),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('brand_translations')->insert([
                        ['brand_id' => $id, 'locale' => 'en', 'brand_name' => $b['en']],
                        ['brand_id' => $id, 'locale' => 'ar', 'brand_name' => $b['ar']],
                    ]);
                    $brandsCreated++;
                }
                $brandIds[$b['en']] = $id;
            }
            $this->command->info('Brands created: ' . $brandsCreated . ' (total mapped: ' . count($brandIds) . ')');

            // ── D) Fashion products (distributed across sub-types) ─────────
            // price = [min, max] EGP per sub-type.
            $priceRanges = [
                'Caps'       => [200, 800],
                'Belts'      => [300, 1200],
                'Wallets'    => [500, 3000],
                'Bags'       => [1000, 8000],
                'Perfumes'   => [800, 5000],
                'Sunglasses' => [500, 3000],
                'Jewelry'    => [1000, 10000],
                'Bracelets'  => [500, 4000],
                'Scarves'    => [300, 2000],
                'Keychains'  => [100, 600],
            ];

            // Curated products: [EN title, AR title, sub-type, brand, discount? (0 or %)]
            $productDefs = [
                ['Monogram Baseball Cap',     'قبعة بيسبول مونوغرام',      'Caps',       'Gucci',          20],
                ['Embroidered Logo Cap',      'قبعة بشعار مطرز',           'Caps',       'Lacoste',        0],
                ['Striped Knit Beanie',       'قبعة صوفية مخططة',          'Caps',       'Tommy Hilfiger', 15],
                ['Reversible Leather Belt',   'حزام جلد قابل للعكس',       'Belts',      'Calvin Klein',   25],
                ['GG Buckle Belt',            'حزام بإبزيم GG',            'Belts',      'Gucci',          0],
                ['Classic Logo Belt',         'حزام بشعار كلاسيكي',        'Belts',      'Hugo Boss',      10],
                ['Bifold Leather Wallet',     'محفظة جلد قابلة للطي',      'Wallets',    'Louis Vuitton',  0],
                ['Slim Card Holder',          'حامل بطاقات نحيف',          'Wallets',    'Burberry',       30],
                ['Zip-Around Wallet',         'محفظة بسحاب',               'Wallets',    'Versace',        0],
                ['Leather Tote Bag',          'حقيبة يد جلدية',            'Bags',       'Louis Vuitton',  15],
                ['Quilted Shoulder Bag',      'حقيبة كتف مبطنة',           'Bags',       'Gucci',          0],
                ['Canvas Backpack',           'حقيبة ظهر قماشية',          'Bags',       'Tommy Hilfiger', 20],
                ['Nova Check Crossbody',      'حقيبة كروس نوفا تشيك',      'Bags',       'Burberry',       0],
                ['Eau de Parfum Intense',     'عطر او دو بارفان انتنس',    'Perfumes',   'Versace',        25],
                ['Signature Cologne',         'كولونيا سيغنتشر',           'Perfumes',   'Hugo Boss',      0],
                ['Floral Eau de Toilette',    'عطر او دو تواليت زهري',     'Perfumes',   'Calvin Klein',   10],
                ['Oud Royal Parfum',          'عطر عود رويال',             'Perfumes',   'Gucci',          0],
                ['Aviator Sunglasses',        'نظارة شمسية افياتور',       'Sunglasses', 'Versace',        20],
                ['Cat-Eye Sunglasses',        'نظارة شمسية كات اي',        'Sunglasses', 'Gucci',          0],
                ['Square Frame Shades',       'نظارة شمسية مربعة',         'Sunglasses', 'Burberry',       15],
                ['Gold Chain Necklace',       'قلادة سلسلة ذهبية',         'Jewelry',    'Versace',        0],
                ['Crystal Stud Earrings',     'أقراط كريستال',             'Jewelry',    'Gucci',          30],
                ['Interlocking Bracelet',     'سوار متشابك',               'Bracelets',  'Tommy Hilfiger', 0],
                ['Leather Wrap Bracelet',     'سوار جلد ملفوف',            'Bracelets',  'Hugo Boss',      15],
                ['Silk Logo Scarf',           'وشاح حرير بشعار',           'Scarves',    'Burberry',       0],
                ['Cashmere Blend Scarf',      'وشاح كشمير',                'Scarves',    'Louis Vuitton',  20],
                ['Metal Logo Keychain',       'ميدالية مفاتيح معدنية',     'Keychains',  'Lacoste',        0],
                ['Leather Tassel Keyring',    'ميدالية مفاتيح جلدية',      'Keychains',  'Calvin Klein',   10],
            ];

            $productsCreated = 0;
            foreach ($productDefs as $p) {
                [$titleEn, $titleAr, $stName, $brandName, $discount] = $p;
                [$min, $max] = $priceRanges[$stName];

                $selling = (float) rand($min, $max);
                $sale = $discount > 0 ? round($selling * (1 - $discount / 100), 2) : null;

                $productId = DB::table('products')->insertGetId([
                    'category_type_id'          => $catTypeId,
                    'sub_type_id'               => $subTypeIds[$stName],
                    'brand_id'                  => $brandIds[$brandName],
                    'purchase_price'            => round($selling * 0.6, 2),
                    'selling_price'             => $selling,
                    'sale_price_after_discount' => $sale,
                    'percentage_discount'       => $discount > 0 ? $discount : null,
                    'stock'                     => rand(5, 50),
                    'market_stock'              => rand(0, 10),
                    'active'                    => 1,
                    'image'                     => $pick($productImages),
                    'wa_code'                   => 'WA-' . strtoupper(Str::random(6)),
                    'seo_slug'                  => Str::slug($titleEn) . '-' . strtolower(Str::random(4)),
                    'created_at'                => now(),
                    'updated_at'                => now(),
                ]);

                DB::table('product_translations')->insert([
                    [
                        'product_id'        => $productId,
                        'locale'            => 'en',
                        'product_title'     => $titleEn,
                        'short_description' => $brandName . ' ' . $stName . ' — premium fashion accessory.',
                        'long_description'  => 'Authentic ' . $brandName . ' ' . $titleEn
                            . '. A premium fashion piece crafted from high-quality materials, '
                            . 'combining timeless design with everyday versatility.',
                    ],
                    [
                        'product_id'        => $productId,
                        'locale'            => 'ar',
                        'product_title'     => $titleAr,
                        'short_description' => 'إكسسوار أزياء فاخر من ' . $brandName . '.',
                        'long_description'  => 'منتج أصلي من ' . $brandName
                            . '. قطعة أزياء فاخرة مصنوعة من خامات عالية الجودة تجمع بين التصميم الأنيق والاستخدام اليومي.',
                    ],
                ]);

                $productsCreated++;
            }

            $this->command->info('Products created: ' . $productsCreated);
        });
    }
}
