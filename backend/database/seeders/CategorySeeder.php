<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        if (Category::count() > 0) {
            $this->command->warn('Categories already seeded. Skipping.');
            return;
        }

        /*
         * STRUCTURE RULES:
         * - Level 1 = Main Category (product TYPE, never gender)
         * - Level 2 = Sub Category  (can include gender context: "Men Watches")
         * - Level 3 = Product Type  (specific type within sub)
         * - Gender is stored on the PRODUCT itself (men/women/unisex/kids)
         */

        $tree = [

            // ══════════════════════════════════════════
            // 1. WATCHES
            // ══════════════════════════════════════════
            [
                'en' => 'Watches', 'ar' => 'ساعات', 'slug' => 'watches',
                'children' => [
                    [
                        'en' => 'Original Watches', 'ar' => 'ساعات أوريجينال', 'slug' => 'original-watches',
                        'children' => [
                            ['en' => 'Luxury Watches',  'ar' => 'ساعات فاخرة',  'slug' => 'luxury-watches-original'],
                            ['en' => 'Classic Watches', 'ar' => 'ساعات كلاسيك', 'slug' => 'classic-watches-original'],
                            ['en' => 'Automatic',       'ar' => 'أوتوماتيك',    'slug' => 'automatic-original'],
                            ['en' => 'Quartz',          'ar' => 'كوارتز',       'slug' => 'quartz-original'],
                            ['en' => 'Digital',         'ar' => 'ديجيتال',      'slug' => 'digital-original'],
                            ['en' => 'Fashion Watches', 'ar' => 'ساعات فاشون',  'slug' => 'fashion-watches-original'],
                        ],
                    ],
                    [
                        'en' => 'Men Watches', 'ar' => 'ساعات رجالي', 'slug' => 'men-watches',
                        'children' => [
                            ['en' => 'Luxury Watches',  'ar' => 'ساعات فاخرة',  'slug' => 'luxury-watches-men'],
                            ['en' => 'Classic Watches', 'ar' => 'ساعات كلاسيك', 'slug' => 'classic-watches-men'],
                            ['en' => 'Automatic',       'ar' => 'أوتوماتيك',    'slug' => 'automatic-men'],
                            ['en' => 'Quartz',          'ar' => 'كوارتز',       'slug' => 'quartz-men'],
                            ['en' => 'Digital',         'ar' => 'ديجيتال',      'slug' => 'digital-men'],
                            ['en' => 'Fashion Watches', 'ar' => 'ساعات فاشون',  'slug' => 'fashion-watches-men'],
                        ],
                    ],
                    [
                        'en' => 'Ladies Watches', 'ar' => 'ساعات حريمي', 'slug' => 'ladies-watches',
                        'children' => [
                            ['en' => 'Luxury Watches',  'ar' => 'ساعات فاخرة',  'slug' => 'luxury-watches-ladies'],
                            ['en' => 'Classic Watches', 'ar' => 'ساعات كلاسيك', 'slug' => 'classic-watches-ladies'],
                            ['en' => 'Quartz',          'ar' => 'كوارتز',       'slug' => 'quartz-ladies'],
                            ['en' => 'Fashion Watches', 'ar' => 'ساعات فاشون',  'slug' => 'fashion-watches-ladies'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Watches', 'ar' => 'ساعات يونيسيكس', 'slug' => 'unisex-watches',
                        'children' => [
                            ['en' => 'Luxury Watches',  'ar' => 'ساعات فاخرة',  'slug' => 'luxury-watches-unisex'],
                            ['en' => 'Automatic',       'ar' => 'أوتوماتيك',    'slug' => 'automatic-unisex'],
                            ['en' => 'Quartz',          'ar' => 'كوارتز',       'slug' => 'quartz-unisex'],
                            ['en' => 'Digital',         'ar' => 'ديجيتال',      'slug' => 'digital-unisex'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 2. SMART WATCHES
            // ══════════════════════════════════════════
            [
                'en' => 'Smart Watches', 'ar' => 'ساعات ذكية', 'slug' => 'smart-watches',
                'children' => [
                    [
                        'en' => 'Men Smart Watches',    'ar' => 'ساعات ذكية رجالي',    'slug' => 'smart-watches-men',
                        'children' => [
                            ['en' => 'Android Smart Watch', 'ar' => 'أندرويد',  'slug' => 'android-smart-men'],
                            ['en' => 'Sports Smart Watch',  'ar' => 'رياضية',   'slug' => 'sports-smart-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Smart Watches',  'ar' => 'ساعات ذكية حريمي',    'slug' => 'smart-watches-women',
                        'children' => [
                            ['en' => 'Fashion Smart Watch', 'ar' => 'فاشون',    'slug' => 'fashion-smart-women'],
                            ['en' => 'Android Smart Watch', 'ar' => 'أندرويد',  'slug' => 'android-smart-women'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Smart Watches', 'ar' => 'ساعات ذكية يونيسيكس', 'slug' => 'smart-watches-unisex',
                        'children' => [
                            ['en' => 'Smart Watch', 'ar' => 'ساعة ذكية', 'slug' => 'smart-watch-unisex'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 3. WALL CLOCKS  (no gender)
            // ══════════════════════════════════════════
            [
                'en' => 'Wall Clocks', 'ar' => 'ساعات حائط', 'slug' => 'wall-clocks',
                'children' => [
                    [
                        'en' => 'Classic Wall Clocks', 'ar' => 'ساعات حائط كلاسيك', 'slug' => 'wall-clocks-classic',
                        'children' => [
                            ['en' => 'Round',   'ar' => 'دائرية',  'slug' => 'wall-clock-round'],
                            ['en' => 'Square',  'ar' => 'مربعة',   'slug' => 'wall-clock-square'],
                        ],
                    ],
                    [
                        'en' => 'Modern Wall Clocks',  'ar' => 'ساعات حائط مودرن',   'slug' => 'wall-clocks-modern',
                        'children' => [
                            ['en' => 'Minimalist', 'ar' => 'مينيمال', 'slug' => 'wall-clock-minimalist'],
                            ['en' => 'Decorative', 'ar' => 'ديكورية', 'slug' => 'wall-clock-decorative'],
                        ],
                    ],
                    [
                        'en' => 'Digital Wall Clocks', 'ar' => 'ساعات حائط ديجيتال', 'slug' => 'wall-clocks-digital',
                        'children' => [
                            ['en' => 'LED Clock', 'ar' => 'ساعة LED', 'slug' => 'wall-clock-led'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 4. BAGS  (sub-gender in L2)
            // ══════════════════════════════════════════
            [
                'en' => 'Bags', 'ar' => 'شنط وحقائب', 'slug' => 'bags',
                'children' => [
                    [
                        'en' => 'Women Bags', 'ar' => 'شنط حريمي', 'slug' => 'women-bags',
                        'children' => [
                            ['en' => 'Handbag',      'ar' => 'حقيبة يد',    'slug' => 'handbag'],
                            ['en' => 'Shoulder Bag', 'ar' => 'حقيبة كتف',   'slug' => 'shoulder-bag'],
                            ['en' => 'Crossbody Bag','ar' => 'حقيبة كروس',  'slug' => 'crossbody-bag'],
                            ['en' => 'Tote Bag',     'ar' => 'توتي باج',    'slug' => 'tote-bag'],
                            ['en' => 'Backpack',     'ar' => 'شنطة ظهر',    'slug' => 'backpack-women'],
                            ['en' => 'Satchel',      'ar' => 'ساتشيل',      'slug' => 'satchel-women'],
                            ['en' => 'Waist Bag',    'ar' => 'حقيبة خصر',   'slug' => 'waist-bag-women'],
                            ['en' => 'Hobo',         'ar' => 'هوبو',        'slug' => 'hobo'],
                            ['en' => 'Shopper/Tote', 'ar' => 'شوبر',        'slug' => 'shopper-tote'],
                            ['en' => 'THBAGS',       'ar' => 'THBAGS',      'slug' => 'thbags'],
                        ],
                    ],
                    [
                        'en' => 'Men Bags', 'ar' => 'شنط رجالي', 'slug' => 'men-bags',
                        'children' => [
                            ['en' => 'Backpack',     'ar' => 'شنطة ظهر',    'slug' => 'backpack-men'],
                            ['en' => 'Shoulder Bag', 'ar' => 'حقيبة كتف',   'slug' => 'shoulder-bag-men'],
                            ['en' => 'Satchel Bag',  'ar' => 'ساتشيل',      'slug' => 'satchel-men'],
                            ['en' => 'Waist Bag',    'ar' => 'حقيبة خصر',   'slug' => 'waist-bag-men'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 5. WALLETS
            // ══════════════════════════════════════════
            [
                'en' => 'Wallets', 'ar' => 'محافظ', 'slug' => 'wallets',
                'children' => [
                    [
                        'en' => 'Men Wallets',   'ar' => 'محافظ رجالي',   'slug' => 'men-wallets',
                        'children' => [
                            ['en' => 'Leather Wallet', 'ar' => 'محفظة جلد',   'slug' => 'leather-wallet-men'],
                            ['en' => 'Fabric Wallet',  'ar' => 'محفظة قماش', 'slug' => 'fabric-wallet-men'],
                            ['en' => 'Card Holder',    'ar' => 'حامل بطاقات', 'slug' => 'card-holder-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Wallets', 'ar' => 'محافظ حريمي',   'slug' => 'women-wallets',
                        'children' => [
                            ['en' => 'Leather Wallet', 'ar' => 'محفظة جلد',   'slug' => 'leather-wallet-women'],
                            ['en' => 'Fabric Wallet',  'ar' => 'محفظة قماش', 'slug' => 'fabric-wallet-women'],
                            ['en' => 'Card Holder',    'ar' => 'حامل بطاقات', 'slug' => 'card-holder-women'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Wallets','ar' => 'محافظ يونيسيكس','slug' => 'unisex-wallets',
                        'children' => [
                            ['en' => 'Travel Wallet',  'ar' => 'محفظة سفر',  'slug' => 'travel-wallet'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 6. BELTS
            // ══════════════════════════════════════════
            [
                'en' => 'Belts', 'ar' => 'أحزمة', 'slug' => 'belts',
                'children' => [
                    [
                        'en' => 'Men Belts',   'ar' => 'أحزمة رجالي', 'slug' => 'men-belts',
                        'children' => [
                            ['en' => 'Leather Belt', 'ar' => 'حزام جلد',   'slug' => 'leather-belt-men'],
                            ['en' => 'Canvas Belt',  'ar' => 'حزام كانفاس','slug' => 'canvas-belt-men'],
                            ['en' => 'Woven Belt',   'ar' => 'حزام منسوج', 'slug' => 'woven-belt-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Belts', 'ar' => 'أحزمة حريمي', 'slug' => 'women-belts',
                        'children' => [
                            ['en' => 'Leather Belt', 'ar' => 'حزام جلد',   'slug' => 'leather-belt-women'],
                            ['en' => 'Fabric Belt',  'ar' => 'حزام قماش',  'slug' => 'fabric-belt-women'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 7. CAPS
            // ══════════════════════════════════════════
            [
                'en' => 'Caps', 'ar' => 'كاب', 'slug' => 'caps',
                'children' => [
                    [
                        'en' => 'Men Caps',    'ar' => 'كاب رجالي',    'slug' => 'men-caps',
                        'children' => [
                            ['en' => 'Baseball Cap', 'ar' => 'بيسبول', 'slug' => 'baseball-cap-men'],
                            ['en' => 'Snapback',     'ar' => 'سناب باك','slug' => 'snapback-men'],
                            ['en' => 'Bucket Hat',   'ar' => 'باكيت',   'slug' => 'bucket-hat-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Caps',  'ar' => 'كاب حريمي',   'slug' => 'women-caps',
                        'children' => [
                            ['en' => 'Baseball Cap', 'ar' => 'بيسبول', 'slug' => 'baseball-cap-women'],
                            ['en' => 'Bucket Hat',   'ar' => 'باكيت',   'slug' => 'bucket-hat-women'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Caps', 'ar' => 'كاب يونيسيكس','slug' => 'unisex-caps',
                        'children' => [
                            ['en' => 'Snapback',   'ar' => 'سناب باك', 'slug' => 'snapback-unisex'],
                            ['en' => 'Beanie',     'ar' => 'بيني',      'slug' => 'beanie-unisex'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 8. BRACELETS
            // ══════════════════════════════════════════
            [
                'en' => 'Bracelets', 'ar' => 'أساور', 'slug' => 'bracelets',
                'children' => [
                    [
                        'en' => 'Men Bracelets',   'ar' => 'أساور رجالي',   'slug' => 'men-bracelets',
                        'children' => [
                            ['en' => 'Leather Bracelet', 'ar' => 'سوار جلد',   'slug' => 'leather-bracelet-men'],
                            ['en' => 'Metal Bracelet',   'ar' => 'سوار معدن',  'slug' => 'metal-bracelet-men'],
                            ['en' => 'Beaded Bracelet',  'ar' => 'سوار خرز',   'slug' => 'beaded-bracelet-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Bracelets', 'ar' => 'أساور حريمي',  'slug' => 'women-bracelets',
                        'children' => [
                            ['en' => 'Leather Bracelet', 'ar' => 'سوار جلد',   'slug' => 'leather-bracelet-women'],
                            ['en' => 'Metal Bracelet',   'ar' => 'سوار معدن',  'slug' => 'metal-bracelet-women'],
                            ['en' => 'Fabric Bracelet',  'ar' => 'سوار قماش',  'slug' => 'fabric-bracelet-women'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Bracelets','ar' => 'أساور يونيسيكس','slug' => 'unisex-bracelets',
                        'children' => [
                            ['en' => 'Nato Bracelet',    'ar' => 'سوار ناتو',  'slug' => 'nato-bracelet'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 9. PERFUMES
            // ══════════════════════════════════════════
            [
                'en' => 'Perfumes', 'ar' => 'عطور', 'slug' => 'perfumes',
                'children' => [
                    [
                        'en' => 'Men Perfumes',    'ar' => 'عطور رجالي',    'slug' => 'men-perfumes',
                        'children' => [
                            ['en' => 'Eau de Parfum',   'ar' => 'إيو دو بارفيم', 'slug' => 'men-edp'],
                            ['en' => 'Eau de Toilette', 'ar' => 'إيو دو تواليت', 'slug' => 'men-edt'],
                            ['en' => 'Body Spray',      'ar' => 'بودي سبراي',    'slug' => 'men-body-spray'],
                        ],
                    ],
                    [
                        'en' => 'Women Perfumes',  'ar' => 'عطور حريمي',   'slug' => 'women-perfumes',
                        'children' => [
                            ['en' => 'Eau de Parfum',   'ar' => 'إيو دو بارفيم', 'slug' => 'women-edp'],
                            ['en' => 'Eau de Toilette', 'ar' => 'إيو دو تواليت', 'slug' => 'women-edt'],
                            ['en' => 'Body Spray',      'ar' => 'بودي سبراي',    'slug' => 'women-body-spray'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Perfumes', 'ar' => 'عطور يونيسيكس','slug' => 'unisex-perfumes',
                        'children' => [
                            ['en' => 'Eau de Parfum',   'ar' => 'إيو دو بارفيم', 'slug' => 'unisex-edp'],
                            ['en' => 'Eau de Toilette', 'ar' => 'إيو دو تواليت', 'slug' => 'unisex-edt'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 10. ELECTRONICS
            // ══════════════════════════════════════════
            [
                'en' => 'Electronics', 'ar' => 'إلكترونيات', 'slug' => 'electronics',
                'children' => [
                    [
                        'en' => 'Power & Charging', 'ar' => 'طاقة وشحن', 'slug' => 'power-charging',
                        'children' => [
                            ['en' => 'Power Bank',       'ar' => 'باور بانك',    'slug' => 'power-bank'],
                            ['en' => 'Charger',          'ar' => 'شاحن',         'slug' => 'charger'],
                            ['en' => 'USB',              'ar' => 'USB',          'slug' => 'usb'],
                        ],
                    ],
                    [
                        'en' => 'Audio', 'ar' => 'صوتيات', 'slug' => 'audio',
                        'children' => [
                            ['en' => 'Earphones',        'ar' => 'سماعات أذن',  'slug' => 'earphones'],
                            ['en' => 'Headphones',       'ar' => 'هيدفون',      'slug' => 'headphones'],
                        ],
                    ],
                    [
                        'en' => 'Phone Accessories', 'ar' => 'إكسسوارات موبايل', 'slug' => 'phone-accessories',
                        'children' => [
                            ['en' => 'Phone Holder',     'ar' => 'حامل موبايل',  'slug' => 'phone-holder'],
                            ['en' => 'Screen Protector', 'ar' => 'حماية شاشة',   'slug' => 'screen-protector'],
                            ['en' => 'Organiser',        'ar' => 'أورجانيسر',    'slug' => 'organiser'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 11. ACCESSORIES & SPARE PARTS
            //     (Pens, Straps, Watch Boxes, Socks,
            //      Sun Glasses, Leather Goods)
            // ══════════════════════════════════════════
            [
                'en' => 'Accessories & Spare Parts', 'ar' => 'إكسسوارات وقطع غيار', 'slug' => 'accessories-spare-parts',
                'children' => [
                    [
                        'en' => 'Straps', 'ar' => 'أشرطة ساعات', 'slug' => 'straps-sub',
                        'children' => [
                            ['en' => 'Leather Strap', 'ar' => 'سير جلد',   'slug' => 'leather-strap'],
                            ['en' => 'Metal Strap',   'ar' => 'سير معدن',  'slug' => 'metal-strap'],
                            ['en' => 'Rubber Strap',  'ar' => 'سير مطاط',  'slug' => 'rubber-strap'],
                            ['en' => 'Nato Strap',    'ar' => 'سير ناتو',  'slug' => 'nato-strap'],
                            ['en' => 'Satin Strap',   'ar' => 'سير ساتان', 'slug' => 'satin-strap'],
                        ],
                    ],
                    [
                        'en' => 'Watch Boxes', 'ar' => 'صناديق ساعات', 'slug' => 'watch-boxes-sub',
                        'children' => [
                            ['en' => 'Single Watch Box', 'ar' => 'صندوق ساعة واحدة', 'slug' => 'single-watch-box'],
                            ['en' => 'Multi Watch Box',  'ar' => 'صندوق متعدد',       'slug' => 'multi-watch-box'],
                        ],
                    ],
                    [
                        'en' => 'Pens', 'ar' => 'أقلام', 'slug' => 'pens-sub',
                        'children' => [
                            ['en' => 'Ballpoint Pen',    'ar' => 'قلم حبر',   'slug' => 'ballpoint-pen'],
                            ['en' => 'Rollerball Pen',   'ar' => 'رولربول',   'slug' => 'rollerball-pen'],
                            ['en' => 'Fountain Pen',     'ar' => 'قلم حبر حر','slug' => 'fountain-pen'],
                        ],
                    ],
                    [
                        'en' => 'Sun Glasses', 'ar' => 'نظارات شمس', 'slug' => 'sun-glasses-sub',
                        'children' => [
                            ['en' => 'Men Sun Glasses',    'ar' => 'نظارات رجالي',    'slug' => 'sun-glasses-men'],
                            ['en' => 'Women Sun Glasses',  'ar' => 'نظارات حريمي',    'slug' => 'sun-glasses-women'],
                            ['en' => 'Unisex Sun Glasses', 'ar' => 'نظارات يونيسيكس', 'slug' => 'sun-glasses-unisex'],
                        ],
                    ],
                    [
                        'en' => 'Socks', 'ar' => 'شراب', 'slug' => 'socks-sub',
                        'children' => [
                            ['en' => 'Men Socks',   'ar' => 'شراب رجالي', 'slug' => 'men-socks'],
                            ['en' => 'Women Socks', 'ar' => 'شراب حريمي', 'slug' => 'women-socks'],
                        ],
                    ],
                    [
                        'en' => 'Leather Goods', 'ar' => 'جلديات', 'slug' => 'leather-goods',
                        'children' => [
                            ['en' => 'Leather Item', 'ar' => 'منتج جلدي', 'slug' => 'leather-item'],
                        ],
                    ],
                    [
                        'en' => 'Satin', 'ar' => 'ساتان', 'slug' => 'satin-sub',
                        'children' => [
                            ['en' => 'Satin Item', 'ar' => 'منتج ساتان', 'slug' => 'satin-item'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 12. BUNDLES
            // ══════════════════════════════════════════
            [
                'en' => 'Bundles', 'ar' => 'باندل', 'slug' => 'bundles',
                'children' => [
                    [
                        'en' => 'Men Bundles',   'ar' => 'باندل رجالي',   'slug' => 'men-bundles',
                        'children' => [
                            ['en' => 'Watch Set',      'ar' => 'سيت ساعة',      'slug' => 'watch-set-men'],
                            ['en' => 'Accessory Set',  'ar' => 'سيت إكسسوار',   'slug' => 'accessory-set-men'],
                            ['en' => 'Gift Set',       'ar' => 'سيت هدية',      'slug' => 'gift-set-men'],
                        ],
                    ],
                    [
                        'en' => 'Women Bundles', 'ar' => 'باندل حريمي',  'slug' => 'women-bundles',
                        'children' => [
                            ['en' => 'Watch Set',      'ar' => 'سيت ساعة',      'slug' => 'watch-set-women'],
                            ['en' => 'Accessory Set',  'ar' => 'سيت إكسسوار',   'slug' => 'accessory-set-women'],
                            ['en' => 'Gift Set',       'ar' => 'سيت هدية',      'slug' => 'gift-set-women'],
                        ],
                    ],
                    [
                        'en' => 'Unisex Bundles','ar' => 'باندل يونيسيكس','slug' => 'unisex-bundles',
                        'children' => [
                            ['en' => 'Mixed Set',      'ar' => 'سيت مكسد',      'slug' => 'mixed-set'],
                        ],
                    ],
                ],
            ],

            // ══════════════════════════════════════════
            // 13. OUTLET / TOYS / UNCATEGORIZED
            // ══════════════════════════════════════════
            [
                'en' => 'Outlet', 'ar' => 'أوتليت', 'slug' => 'outlet',
                'children' => [
                    [
                        'en' => 'Outlet Watches',     'ar' => 'ساعات أوتليت',     'slug' => 'outlet-watches',
                        'children' => [
                            ['en' => 'Outlet Men Watches',    'ar' => 'رجالي أوتليت',   'slug' => 'outlet-men-watches'],
                            ['en' => 'Outlet Ladies Watches', 'ar' => 'حريمي أوتليت',   'slug' => 'outlet-ladies-watches'],
                        ],
                    ],
                    [
                        'en' => 'Outlet Accessories', 'ar' => 'إكسسوارات أوتليت', 'slug' => 'outlet-accessories',
                        'children' => [
                            ['en' => 'Outlet Bags',    'ar' => 'شنط أوتليت',   'slug' => 'outlet-bags'],
                            ['en' => 'Outlet Wallets', 'ar' => 'محافظ أوتليت', 'slug' => 'outlet-wallets'],
                        ],
                    ],
                ],
            ],
            [
                'en' => 'Toys', 'ar' => 'ألعاب', 'slug' => 'toys',
                'children' => [
                    [
                        'en' => 'Kids Toys', 'ar' => 'ألعاب أطفال', 'slug' => 'kids-toys',
                        'children' => [
                            ['en' => 'Educational Toys', 'ar' => 'ألعاب تعليمية', 'slug' => 'educational-toys'],
                            ['en' => 'Action Figures',   'ar' => 'شخصيات',        'slug' => 'action-figures'],
                        ],
                    ],
                ],
            ],
            [
                'en' => 'Uncategorized', 'ar' => 'غير مصنف', 'slug' => 'uncategorized',
                'children' => [
                    [
                        'en' => 'General', 'ar' => 'عام', 'slug' => 'uncategorized-general',
                        'children' => [
                            ['en' => 'Other', 'ar' => 'أخرى', 'slug' => 'other'],
                        ],
                    ],
                ],
            ],

        ];

        foreach ($tree as $s1 => $main) {
            $m = $this->make($main, null, 1, $s1);
            foreach (($main['children'] ?? []) as $s2 => $sub) {
                $s = $this->make($sub, $m->id, 2, $s2);
                foreach (($sub['children'] ?? []) as $s3 => $type) {
                    $this->make($type, $s->id, 3, $s3);
                }
            }
        }

        $this->command->info('✅ Seeded ' . Category::count() . ' categories.');
    }

    private function make(array $d, ?int $parentId, int $level, int $sort): Category
    {
        $cat = Category::create([
            'parent_id'  => $parentId,
            'level'      => $level,
            'slug'       => $d['slug'],
            'is_active'  => true,
            'sort_order' => $sort,
        ]);
        $cat->translateOrNew('en')->name = $d['en'];
        $cat->translateOrNew('ar')->name = $d['ar'];
        $cat->save();
        return $cat;
    }
}