<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Lookup / reference models
use App\Models\CategoryType;
use App\Models\SubType;
use App\Models\Category;
use App\Models\Grade;
use App\Models\Brand;
use App\Models\Color;
use App\Models\ClosureType;
use App\Models\DisplayType;
use App\Models\Feature;
use App\Models\Gender;
use App\Models\Material;
use App\Models\Shape;
use App\Models\MovementType;
use App\Models\NewColor;
use App\Models\NewSize;

/**
 * MasterDataSeeder
 * ────────────────────────────────────────────────────────────────────────────
 * Seeds EVERY lookup / reference table the dashboard "Create Product" form (and
 * the Product-Variant screens) depends on, in BOTH English and Arabic, so that
 * a data-entry person opens the form with every dropdown already populated.
 *
 * Only IMAGES and PRODUCTS are intentionally left empty. Image columns are set
 * to NULL everywhere.
 *
 * The seeder is IDEMPOTENT — each row is checked before insert (by its English
 * name / slug), so re-running never duplicates data. Everything runs inside a
 * single DB transaction.
 *
 * Run on the server with:
 *     php artisan db:seed --class=MasterDataSeeder
 */
class MasterDataSeeder extends Seeder
{
    /** Tally of rows actually created this run (per section). */
    private array $summary = [];

    public function run(): void
    {
        DB::transaction(function () {
            $this->summary['Category Types'] = $this->seedTranslated(CategoryType::class, 'category_type_name', $this->categoryTypes());
            $this->summary['Sub Types']      = $this->seedTranslated(SubType::class, 'sub_type_name', $this->subTypes(), ['image' => null]);
            $this->summary['Categories']     = $this->seedCategories($this->categories());
            $this->summary['Grades']         = $this->seedGrades($this->grades());
            $this->summary['Brands']         = $this->seedTranslated(Brand::class, 'brand_name', $this->brands(), ['image' => null]);
            $this->summary['Colors']         = $this->seedColors($this->colors());
            $this->summary['Closure Types']  = $this->seedTranslated(ClosureType::class, 'closure_type_name', $this->closureTypes());
            $this->summary['Display Types']  = $this->seedTranslated(DisplayType::class, 'display_type_name', $this->displayTypes());
            $this->summary['Features']       = $this->seedTranslated(Feature::class, 'feature_name', $this->features());
            $this->summary['Genders']        = $this->seedTranslated(Gender::class, 'gender_name', $this->genders());
            $this->summary['Materials']      = $this->seedTranslated(Material::class, 'material_name', $this->materials());
            $this->summary['Shapes']         = $this->seedTranslated(Shape::class, 'shape_name', $this->shapes());
            $this->summary['Movement Types'] = $this->seedTranslated(MovementType::class, 'movement_type_name', $this->movementTypes());
            $this->summary['Variant Colors'] = $this->seedNewColors($this->newColors());
            $this->summary['Variant Sizes']  = $this->seedNewSizes($this->newSizes());
        });

        $this->printSummary();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GENERIC SEEDERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Seed a simple Astrotomic-translatable lookup (one translated string field,
     * en + ar). Idempotent by the English value.
     *
     * @param  array<int, array{0:string,1:string}>  $rows  [ [en, ar], ... ]
     * @param  array<string, mixed>  $extra  non-translated columns (e.g. image)
     */
    private function seedTranslated(string $modelClass, string $attr, array $rows, array $extra = []): int
    {
        $created = 0;
        foreach ($rows as [$en, $ar]) {
            if ($modelClass::whereTranslation($attr, $en)->exists()) {
                continue;
            }
            $model = new $modelClass();
            foreach ($extra as $col => $val) {
                $model->{$col} = $val;
            }
            $model->translateOrNew('en')->{$attr} = $en;
            $model->translateOrNew('ar')->{$attr} = $ar;
            $model->save();
            $created++;
        }
        return $created;
    }

    /**
     * Grades carry an extra translated `description`.
     *
     * @param  array<int, array{0:string,1:string,2:string,3:string}>  $rows
     */
    private function seedGrades(array $rows): int
    {
        $created = 0;
        foreach ($rows as [$en, $ar, $descEn, $descAr]) {
            if (Grade::whereTranslation('grade_name', $en)->exists()) {
                continue;
            }
            $grade = new Grade();
            $grade->image = null;
            $grade->translateOrNew('en')->grade_name  = $en;
            $grade->translateOrNew('en')->description  = $descEn;
            $grade->translateOrNew('ar')->grade_name   = $ar;
            $grade->translateOrNew('ar')->description   = $descAr;
            $grade->save();
            $created++;
        }
        return $created;
    }

    /**
     * Colors carry a non-translated `color_value` (hex) + translated color_name.
     *
     * @param  array<int, array{0:string,1:string,2:string}>  $rows  [en, ar, hex]
     */
    private function seedColors(array $rows): int
    {
        $created = 0;
        foreach ($rows as [$en, $ar, $hex]) {
            if (Color::whereTranslation('color_name', $en)->exists()) {
                continue;
            }
            $color = new Color();
            $color->color_value = $hex;
            $color->translateOrNew('en')->color_name = $en;
            $color->translateOrNew('ar')->color_name = $ar;
            $color->save();
            $created++;
        }
        return $created;
    }

    /**
     * Categories (restructured table): slug, parent_id, level, is_active,
     * sort_order, image(NULL) + translated name & description. All rows here are
     * top-level parents → parent_id = null, level = 1 (matches Category::scopeMain
     * and the admin product form's whereNull('parent_id')->where('level',1)).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function seedCategories(array $rows): int
    {
        $created = 0;
        foreach ($rows as $row) {
            if (Category::where('slug', $row['slug'])->exists()) {
                continue;
            }
            $cat = new Category();
            $cat->parent_id  = null;
            $cat->level      = 1;
            $cat->slug       = $row['slug'];
            $cat->image      = null;
            $cat->is_active  = true;
            $cat->sort_order = $row['sort_order'];
            $cat->translateOrNew('en')->name        = $row['name_en'];
            $cat->translateOrNew('en')->description  = $row['description_en'];
            $cat->translateOrNew('ar')->name         = $row['name_ar'];
            $cat->translateOrNew('ar')->description   = $row['description_ar'];
            $cat->save();
            $created++;
        }
        return $created;
    }

    /**
     * Variant colors — plain (non-translatable) new_colors table.
     *
     * @param  array<int, array{0:string,1:string,2:string}>  $rows  [en, ar, hex]
     */
    private function seedNewColors(array $rows): int
    {
        $created = 0;
        foreach ($rows as [$en, $ar, $hex]) {
            $model = NewColor::firstOrCreate(
                ['name_en' => $en],
                ['name_ar' => $ar, 'hex' => $hex, 'is_active' => true]
            );
            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Variant sizes — plain (non-translatable) new_sizes table.
     *
     * @param  array<int, array{0:string,1:string,2:string}>  $rows  [en, ar, type]
     */
    private function seedNewSizes(array $rows): int
    {
        $created = 0;
        foreach ($rows as [$en, $ar, $type]) {
            $model = NewSize::firstOrCreate(
                ['name_en' => $en, 'type' => $type],
                ['name_ar' => $ar, 'is_active' => true]
            );
            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }
        return $created;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DATA
    // ═══════════════════════════════════════════════════════════════════════

    /** 1. Category Types (top level — Watches vs Fashion). */
    private function categoryTypes(): array
    {
        return [
            ['Watches', 'ساعات'],
            ['Fashion', 'أزياء وإكسسوارات'],
        ];
    }

    /** 2. Sub Types — watch types + fashion types. */
    private function subTypes(): array
    {
        return [
            // Watch sub types
            ['Diver', 'ساعات غوص'],
            ['Chronograph', 'كرونوغراف'],
            ['Dress', 'ساعات كلاسيكية'],
            ['Sport', 'ساعات رياضية'],
            ['Pilot', 'ساعات طيارين'],
            ['GMT', 'جي إم تي'],
            ['Field', 'ساعات ميدانية'],
            ['Racing', 'ساعات سباق'],
            ['Skeleton', 'ساعات هيكلية'],
            ['Tourbillon', 'توربيون'],
            ['Military', 'ساعات عسكرية'],
            ['Casual', 'ساعات كاجوال'],
            ['Smart Watch', 'ساعات ذكية'],
            ['Digital Watch', 'ساعات رقمية'],
            // Fashion sub types
            ['Caps', 'قبعات'],
            ['Belts', 'أحزمة'],
            ['Wallets', 'محافظ'],
            ['Bags', 'حقائب'],
            ['Perfumes', 'عطور'],
            ['Sunglasses', 'نظارات شمسية'],
            ['Jewelry', 'مجوهرات'],
            ['Bracelets', 'أساور'],
            ['Scarves', 'أوشحة'],
            ['Keychains', 'ميداليات مفاتيح'],
            ['Ties', 'كرافتات'],
            ['Cufflinks', 'أزرار أكمام'],
            ['Pen', 'أقلام'],
        ];
    }

    /** 3. Categories (restructured table, with slugs + SEO descriptions). */
    private function categories(): array
    {
        return [
            [
                'name_en' => 'Luxury Watches', 'name_ar' => 'ساعات فاخرة', 'slug' => 'luxury-watches',
                'description_en' => "Discover authentic luxury timepieces from the world's most prestigious brands",
                'description_ar' => 'اكتشف ساعات فاخرة أصلية من أرقى الماركات العالمية', 'sort_order' => 1,
            ],
            [
                'name_en' => 'Sport Watches', 'name_ar' => 'ساعات رياضية', 'slug' => 'sport-watches',
                'description_en' => 'Performance timepieces built for adventure and endurance',
                'description_ar' => 'ساعات أداء صُممت للمغامرة والتحمل', 'sort_order' => 2,
            ],
            [
                'name_en' => 'Classic Watches', 'name_ar' => 'ساعات كلاسيكية', 'slug' => 'classic-watches',
                'description_en' => 'Timeless dress watches for every occasion',
                'description_ar' => 'ساعات كلاسيكية خالدة لكل المناسبات', 'sort_order' => 3,
            ],
            [
                'name_en' => 'Fashion Accessories', 'name_ar' => 'إكسسوارات أزياء', 'slug' => 'fashion-accessories',
                'description_en' => 'Premium fashion accessories from top designer brands',
                'description_ar' => 'إكسسوارات أزياء فاخرة من أفضل ماركات المصممين', 'sort_order' => 4,
            ],
            [
                'name_en' => 'Men Collection', 'name_ar' => 'مجموعة رجالية', 'slug' => 'men-collection',
                'description_en' => 'Curated collection for the modern gentleman',
                'description_ar' => 'مجموعة مختارة للرجل العصري', 'sort_order' => 5,
            ],
            [
                'name_en' => 'Women Collection', 'name_ar' => 'مجموعة نسائية', 'slug' => 'women-collection',
                'description_en' => 'Elegant timepieces and accessories for women',
                'description_ar' => 'ساعات وإكسسوارات أنيقة للسيدات', 'sort_order' => 6,
            ],
        ];
    }

    /** 4a. Grades (marketing tiers) — with descriptions. */
    private function grades(): array
    {
        return [
            ['Haute Horlogerie', 'صناعة الساعات الراقية', 'The pinnacle of watchmaking craftsmanship', 'قمة الحرفية في صناعة الساعات'],
            ['Luxury', 'فاخرة', 'Premium luxury timepieces from iconic maisons', 'ساعات فاخرة متميزة من أشهر الدور'],
            ['Premium', 'متميزة', 'High-quality watches with excellent craftsmanship', 'ساعات عالية الجودة بحرفية ممتازة'],
            ['Classic', 'كلاسيكية', 'Timeless elegant designs that never go out of style', 'تصاميم أنيقة خالدة لا تخرج عن الموضة'],
            ['Sport & Adventure', 'رياضية ومغامرات', 'Built for performance under pressure', 'صُممت للأداء تحت الضغط'],
            ['Fashion & Lifestyle', 'موضة وستايل', 'Trendy watches and accessories for everyday style', 'ساعات وإكسسوارات عصرية للستايل اليومي'],
            ['Entry Level', 'فئة اقتصادية', 'Quality watches at accessible prices', 'ساعات بجودة عالية وأسعار مناسبة'],
            ['Limited Edition', 'إصدار محدود', 'Rare and collectible timepieces in limited quantities', 'ساعات نادرة وقابلة للتجميع بكميات محدودة'],
            ['Best Seller', 'الأكثر مبيعاً', 'Our most popular products loved by customers', 'أكثر المنتجات شعبية ومحبوبة من العملاء'],
            ['Hot Deal', 'عرض ساخن', "Amazing deals you can't miss", 'عروض مذهلة لا تفوتها'],
            ['New Arrival', 'وصل حديثاً', 'Latest additions to our collection', 'أحدث الإضافات لمجموعتنا'],
            ['Featured', 'مميز', 'Hand-picked by our experts', 'مختار بعناية من خبرائنا'],
            ['Limited Offer', 'عرض محدود', 'Available for a limited time only', 'متاح لفترة محدودة فقط'],
            ['Flash Sale', 'تخفيض سريع', 'Quick deals with limited quantities', 'عروض سريعة بكميات محدودة'],
        ];
    }

    /** 4b. Brands. */
    private function brands(): array
    {
        return [
            // Luxury watches
            ['Rolex', 'رولكس'],
            ['Patek Philippe', 'باتيك فيليب'],
            ['Audemars Piguet', 'أوديمار بيغيه'],
            ['Vacheron Constantin', 'فاشرون كونستانتين'],
            ['Omega', 'أوميغا'],
            ['Cartier', 'كارتييه'],
            ['Breitling', 'بريتلينغ'],
            ['TAG Heuer', 'تاغ هوير'],
            ['Hublot', 'هوبلو'],
            ['Zenith', 'زينيث'],
            ['Jaeger-LeCoultre', 'جيجر لوكولتر'],
            ['Panerai', 'بانيراي'],
            ['Blancpain', 'بلانكبان'],
            ['Breguet', 'بريغيه'],
            ['Piaget', 'بياجيه'],
            ['Chopard', 'شوبارد'],
            ['IWC Schaffhausen', 'آي دبليو سي شافهاوزن'],
            ['Ulysse Nardin', 'أوليس ناردين'],
            ['Richard Mille', 'ريتشارد ميل'],
            ['Franck Muller', 'فرانك مولر'],
            ['Bell & Ross', 'بيل أند روس'],
            ['Tudor', 'تيودور'],
            // Fashion watches
            ['Tommy Hilfiger', 'تومي هيلفيغر'],
            ['Calvin Klein', 'كالفن كلاين'],
            ['Hugo Boss', 'هوغو بوس'],
            ['Armani Exchange', 'أرماني إكستشينج'],
            ['Emporio Armani', 'إمبوريو أرماني'],
            ['Michael Kors', 'مايكل كورس'],
            ['Fossil', 'فوسيل'],
            ['Diesel', 'ديزل'],
            ['Guess', 'جيس'],
            ['DKNY', 'دي كي إن واي'],
            ['Coach', 'كوتش'],
            ['Lacoste', 'لاكوست'],
            ['Police', 'بوليس'],
            ['Cerruti 1881', 'شيروتي 1881'],
            ['Pierre Cardin', 'بيير كاردان'],
            ['Versace', 'فيرساتشي'],
            ['Gucci', 'غوتشي'],
            ['Burberry', 'بربري'],
            ['Kenneth Cole', 'كينيث كول'],
            ['Anne Klein', 'آن كلاين'],
            ['Olivia Burton', 'أوليفيا بيرتون'],
            ['Just Cavalli', 'جست كافالي'],
            ['Nautica', 'نوتيكا'],
            ['Ted Baker', 'تيد بيكر'],
            ['Maserati', 'مازيراتي'],
            ['Philipp Plein', 'فيليب بلاين'],
            ['Karl Lagerfeld', 'كارل لاغرفيلد'],
            ['Marc Jacobs', 'مارك جيكوبس'],
            ['Esprit', 'إسبريت'],
            // Japanese
            ['Casio', 'كاسيو'],
            ['G-Shock', 'جي شوك'],
            ['Baby-G', 'بيبي جي'],
            ['Edifice', 'إيديفيس'],
            ['Seiko', 'سيكو'],
            ['Grand Seiko', 'غراند سيكو'],
            ['Citizen', 'سيتيزن'],
            ['Orient', 'أورينت'],
            ['Q&Q', 'كيو أند كيو'],
            ['Lorus', 'لوروس'],
            ['Alba', 'ألبا'],
            ['Pulsar', 'بولسار'],
            // Swiss
            ['Tissot', 'تيسو'],
            ['Longines', 'لونجين'],
            ['Rado', 'رادو'],
            ['Mido', 'ميدو'],
            ['Certina', 'سيرتينا'],
            ['Hamilton', 'هاميلتون'],
            ['Victorinox', 'فيكتورينوكس'],
            ['Swatch', 'سواتش'],
            ['Mondaine', 'موندين'],
            ['Frederique Constant', 'فريدريك كونستانت'],
            ['Alpina', 'ألبينا'],
            ['Oris', 'أوريس'],
        ];
    }

    /** 5. Colors — [en, ar, hex] (hex → color_value). */
    private function colors(): array
    {
        return [
            ['Black', 'أسود', '#111111'],
            ['White', 'أبيض', '#FFFFFF'],
            ['Silver', 'فضي', '#C0C0C0'],
            ['Gold', 'ذهبي', '#FFD700'],
            ['Rose Gold', 'ذهبي وردي', '#B76E79'],
            ['Blue', 'أزرق', '#1F3A5F'],
            ['Navy', 'كحلي', '#000080'],
            ['Brown', 'بني', '#4A2C1A'],
            ['Green', 'أخضر', '#1B4332'],
            ['Red', 'أحمر', '#CC0000'],
            ['Pink', 'وردي', '#FF69B4'],
            ['Purple', 'بنفسجي', '#800080'],
            ['Orange', 'برتقالي', '#FF6600'],
            ['Yellow', 'أصفر', '#FFD700'],
            ['Gray', 'رمادي', '#808080'],
            ['Beige', 'بيج', '#F5F5DC'],
            ['Multi Color', 'متعدد الألوان', '#000000'],
        ];
    }

    /** 6. Closure Types. */
    private function closureTypes(): array
    {
        return [
            ['Buckle', 'مشبك'],
            ['Folding Clasp', 'مشبك قابل للطي'],
            ['Deployment Clasp', 'مشبك نشر'],
            ['Push Button Clasp', 'مشبك بزر ضغط'],
            ['Hook Buckle', 'مشبك خطاف'],
            ['Jewelry Clasp', 'مشبك مجوهرات'],
            ['Magnetic', 'مغناطيسي'],
            ['Velcro', 'فيلكرو'],
            ['Pin Buckle', 'مشبك دبوس'],
            ['Butterfly Clasp', 'مشبك فراشة'],
        ];
    }

    /** 7. Display Types. */
    private function displayTypes(): array
    {
        return [
            ['Analog', 'تناظري'],
            ['Digital', 'رقمي'],
            ['Analog-Digital', 'تناظري-رقمي'],
        ];
    }

    /** 8. Features. */
    private function features(): array
    {
        return [
            ['Water Resistant', 'مقاوم للماء'],
            ['Chronograph', 'كرونوغراف'],
            ['Date Display', 'عرض التاريخ'],
            ['Day & Date', 'اليوم والتاريخ'],
            ['Luminous Hands', 'عقارب مضيئة'],
            ['Scratch Resistant', 'مقاوم للخدش'],
            ['Sapphire Crystal', 'كريستال ياقوتي'],
            ['Automatic', 'أوتوماتيكي'],
            ['Quartz', 'كوارتز'],
            ['GPS', 'جي بي إس'],
            ['Alarm', 'منبه'],
            ['Stopwatch', 'ساعة إيقاف'],
            ['Bluetooth', 'بلوتوث'],
            ['Heart Rate Monitor', 'مراقب نبضات القلب'],
            ['Sleep Tracking', 'تتبع النوم'],
        ];
    }

    /** 9. Genders. */
    private function genders(): array
    {
        return [
            ['Men', 'رجالي'],
            ['Women', 'نسائي'],
            ['Unisex', 'للجنسين'],
            ['Kids', 'أطفال'],
        ];
    }

    /** 10. Materials. */
    private function materials(): array
    {
        return [
            ['Stainless Steel', 'ستانلس ستيل'],
            ['Leather', 'جلد'],
            ['Silicone', 'سيليكون'],
            ['Rubber', 'مطاط'],
            ['Ceramic', 'سيراميك'],
            ['Titanium', 'تيتانيوم'],
            ['Resin', 'ريزين'],
            ['Nylon', 'نايلون'],
            ['Mesh Steel', 'ستيل شبكي'],
            ['Plastic', 'بلاستيك'],
        ];
    }

    /** 11. Shapes. */
    private function shapes(): array
    {
        return [
            ['Round', 'دائري'],
            ['Square', 'مربع'],
            ['Rectangle', 'مستطيل'],
            ['Oval', 'بيضاوي'],
            ['Cushion', 'وسادة'],
            ['Tonneau', 'برميلي'],
            ['Octagonal', 'ثماني الأضلاع'],
        ];
    }

    /** 12. Movement Types. */
    private function movementTypes(): array
    {
        return [
            ['Quartz', 'كوارتز'],
            ['Automatic', 'أوتوماتيكي'],
            ['Mechanical', 'ميكانيكي'],
            ['Solar', 'شمسي'],
            ['Kinetic', 'حركي'],
            ['Eco Drive', 'إيكو درايف'],
        ];
    }

    /**
     * Variant colors (new_colors) — powers the Product-Variant color dropdown.
     * Mirrors the main color palette so both selectors are ready.
     */
    private function newColors(): array
    {
        return [
            ['Black', 'أسود', '#111111'],
            ['White', 'أبيض', '#FFFFFF'],
            ['Silver', 'فضي', '#C0C0C0'],
            ['Gold', 'ذهبي', '#FFD700'],
            ['Rose Gold', 'ذهبي وردي', '#B76E79'],
            ['Blue', 'أزرق', '#1F3A5F'],
            ['Navy', 'كحلي', '#000080'],
            ['Brown', 'بني', '#4A2C1A'],
            ['Green', 'أخضر', '#1B4332'],
            ['Red', 'أحمر', '#CC0000'],
            ['Pink', 'وردي', '#FF69B4'],
            ['Purple', 'بنفسجي', '#800080'],
            ['Orange', 'برتقالي', '#FF6600'],
            ['Yellow', 'أصفر', '#FFD700'],
            ['Gray', 'رمادي', '#808080'],
            ['Beige', 'بيج', '#F5F5DC'],
        ];
    }

    /**
     * Variant sizes (new_sizes) — powers the Product-Variant size dropdown.
     * Types allowed: clothing / shoes / watch / general.
     */
    private function newSizes(): array
    {
        return [
            // Watch case sizes (mm)
            ['28mm', '28 مم', 'watch'],
            ['32mm', '32 مم', 'watch'],
            ['36mm', '36 مم', 'watch'],
            ['38mm', '38 مم', 'watch'],
            ['40mm', '40 مم', 'watch'],
            ['41mm', '41 مم', 'watch'],
            ['42mm', '42 مم', 'watch'],
            ['44mm', '44 مم', 'watch'],
            ['45mm', '45 مم', 'watch'],
            ['46mm', '46 مم', 'watch'],
            // Clothing
            ['XS', 'إكس إس', 'clothing'],
            ['S', 'إس', 'clothing'],
            ['M', 'إم', 'clothing'],
            ['L', 'إل', 'clothing'],
            ['XL', 'إكس إل', 'clothing'],
            ['XXL', 'إكس إكس إل', 'clothing'],
            ['XXXL', 'ثري إكس إل', 'clothing'],
            // Shoes (EU)
            ['40', '40', 'shoes'],
            ['41', '41', 'shoes'],
            ['42', '42', 'shoes'],
            ['43', '43', 'shoes'],
            ['44', '44', 'shoes'],
            ['45', '45', 'shoes'],
            // General
            ['One Size', 'مقاس واحد', 'general'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // OUTPUT
    // ═══════════════════════════════════════════════════════════════════════

    private function printSummary(): void
    {
        $parts = [];
        foreach ($this->summary as $label => $count) {
            $parts[] = "{$count} {$label}";
        }
        $this->command?->info('✅ MasterDataSeeder complete. Seeded (new rows this run): ' . implode(', ', $parts) . '.');
        $this->command?->info('   Images left NULL (data-entry will add). No products seeded.');
    }
}
