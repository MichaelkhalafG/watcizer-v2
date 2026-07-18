<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\Product;
use App\Models\Brand;
use App\Models\CategoryType;
use App\Models\SubType;
use App\Models\Grade;
use App\Models\Gender;
use App\Models\Color;
use App\Models\Material;
use App\Models\Shape;
use App\Models\MovementType;
use App\Models\DisplayType;
use App\Models\ClosureType;
use App\Models\SizeType;
use App\Models\Feature;
use App\Models\ProductVariant;
use App\Models\User;

/**
 * ExampleProductsSeeder
 * ────────────────────────────────────────────────────────────────────────────
 * Creates TWO fully-populated reference products the data-entry team can copy:
 *   1. WATCH   — Rolex Submariner Date 126610LN (every watch field filled)
 *   2. FASHION — Gucci GG Marmont Leather Belt   (watch-only fields left NULL)
 *
 * All lookup IDs (brand, sub type, colors, materials, size units, variant
 * colors/sizes …) are resolved DYNAMICALLY by name against the data seeded by
 * MasterDataSeeder — nothing is hard-coded. A few values the task references but
 * MasterDataSeeder doesn't ship (belt sizes 85/90/95cm, the "Sapphire Crystal"
 * glass material) are created on the fly so the examples are complete.
 *
 * Idempotent: skips a product whose seo_slug already exists.
 *
 * Run with:
 *   php artisan db:seed --class=ExampleProductsSeeder --force
 */
class ExampleProductsSeeder extends Seeder
{
    /** Admin user id used for created_by / updated_by. */
    private ?int $adminId = null;

    public function run(): void
    {
        $this->adminId = User::where('type', 'SuperAdmin')->value('id')
            ?? User::value('id');

        DB::transaction(function () {
            $this->createWatch();
            $this->createBelt();
        });

        $this->command?->info('✅ ExampleProductsSeeder complete. 2 reference products ready (watch + belt), with images and variants.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRODUCT 1 — WATCH (Rolex Submariner Date 126610LN)
    // ═══════════════════════════════════════════════════════════════════════
    private function createWatch(): void
    {
        $slug = 'rolex-submariner-date-126610ln';
        if (Product::where('seo_slug', $slug)->exists()) {
            $this->command?->warn("• Watch already exists ({$slug}) — skipped.");
            return;
        }

        $mm  = $this->sizeTypeId('mm');
        $atm = $this->sizeTypeId('ATM');

        $product = new Product();

        // ── Classification ────────────────────────────────
        $product->category_type_id = $this->categoryTypeId('Watches');
        $product->sub_type_id      = $this->subTypeId('Diver');
        $product->grade_id         = $this->gradeId('Luxury');
        $product->brand_id         = $this->brandId('Rolex');

        // ── Pricing / stock ───────────────────────────────
        $product->purchase_price            = 420000.00; // cost (task omits it; required, hidden from API)
        $product->selling_price             = 485000.00;
        $product->sale_price_after_discount = 459000.00;
        $product->percentage_discount       = 5;
        $product->stock                     = 3;
        $product->market_stock              = 1;
        $product->low_stock_threshold       = 2;

        // ── Watch specs ───────────────────────────────────
        $product->watch_movement_id             = $this->movementTypeId('Automatic');
        $product->band_material_id              = $this->materialId('Stainless Steel');           // case + band metal
        $product->case_size                     = 41;
        $product->case_size_type_id             = $mm;
        $product->case_thickness                = 12.5;
        $product->case_thickness_size_type_id   = $mm;
        $product->case_shape_id                 = $this->shapeId('Round');
        $product->dial_glass_material_id        = $this->materialId('Sapphire Crystal', 'كريستال ياقوتي'); // created if missing
        $product->dial_case_material_id         = $this->materialId('Stainless Steel');
        $product->water_resistance              = 300;
        $product->water_resistance_size_type_id = $atm;                                            // 300 m ≈ diving rating
        $product->band_closure_id               = $this->closureTypeId('Folding Clasp');
        $product->dial_display_type_id          = $this->displayTypeId('Analog');
        $product->band_width                    = 20;
        $product->band_width_size_type_id       = $mm;
        $product->band_length                   = null; // integrated Oyster bracelet — sized to wrist
        $product->band_size_type_id             = null;
        // Overall watch dimensions (illustrative — shows the fields to data entry)
        $product->watch_height                  = 48;   // lug-to-lug
        $product->watch_height_size_type_id     = $mm;
        $product->watch_width                   = 41;
        $product->watch_width_size_type_id      = $mm;
        $product->watch_length                  = 47;
        $product->watch_length_size_type_id     = $mm;
        $product->warranty_years                = 5;
        $product->watch_box                     = 1;
        $product->interchangeable_dial          = 0;
        $product->interchangeable_strap         = 0;

        // ── Identity / misc ───────────────────────────────
        $product->model_number   = '126610LN';
        $product->sku_unique     = 'ROL-SUB-126610LN';
        $product->wa_code        = 'ROLSUB126610';
        $product->average_rate   = 4.9;          // normally derived from ratings; sample value
        $product->image          = 'placeholder_watch_front.webp';
        $product->active         = 1;
        $product->created_by     = $this->adminId;
        $product->updated_by     = $this->adminId;

        // ── SEO ───────────────────────────────────────────
        $product->seo_title            = 'Rolex Submariner Date 126610LN | Buy in Egypt - Watchizer';
        $product->seo_slug             = $slug;
        $product->seo_meta_description = 'Shop authentic Rolex Submariner Date 126610LN in Egypt. 41mm Oystersteel case, black Cerachrom bezel, 300M water resistance, Calibre 3235 automatic movement. Free shipping across Egypt.';
        $product->search_keywords      = 'rolex, submariner, date, 126610LN, automatic, luxury watch, swiss, diving watch, ساعة رولكس, سابمارينر, ساعة غوص, ساعات فاخرة';

        // ── Extra (non-watch structured attributes) ───────
        $product->extra_attributes = json_encode([
            'bezel'         => 'Unidirectional Cerachrom',
            'calibre'       => '3235',
            'power_reserve' => '70 hours',
            'certification' => 'COSC Chronometer',
            'crystal'       => 'Scratch-resistant sapphire',
        ], JSON_UNESCAPED_UNICODE);

        // ── Translations (EN + AR) ────────────────────────
        $product->translateOrNew('en')->product_title = 'Rolex Submariner Date 126610LN Black Dial Oystersteel';
        $product->translateOrNew('ar')->product_title = 'ساعة رولكس سابمارينر ديت 126610LN قرص أسود أويستر ستيل';
        $product->translateOrNew('en')->short_description = 'Iconic automatic diving watch with 41mm Oystersteel case, unidirectional rotatable Cerachrom bezel, black dial with luminescent hour markers, and Oyster bracelet. Water resistant to 300 meters. Features the Calibre 3235 movement with 70-hour power reserve. Certified Swiss Chronometer (COSC).';
        $product->translateOrNew('ar')->short_description = 'ساعة غوص أوتوماتيكية أيقونية بهيكل أويستر ستيل 41 مم، إطار سيراكروم أحادي الاتجاه دوّار، قرص أسود بعلامات ساعات مضيئة، وسوار أويستر. مقاومة للماء حتى 300 متر. تعمل بحركة كاليبر 3235 باحتياطي طاقة 70 ساعة. كرونومتر سويسري معتمد (COSC).';
        $product->translateOrNew('en')->long_description = 'The Rolex Submariner Date 126610LN is the definitive reference in the world of luxury diving watches. Crafted from corrosion-resistant Oystersteel, the 41mm Oyster case is guaranteed waterproof to a depth of 300 metres (1,000 feet). Its unidirectional rotatable bezel with a black Cerachrom insert in ceramic allows divers to monitor immersion time safely, while the black dial with large Chromalight hour markers delivers exceptional legibility in the dark with a long-lasting blue glow. At its heart beats the Calibre 3235, a self-winding mechanical movement entirely developed and manufactured by Rolex, offering a 70-hour power reserve and certified as a Superlative Chronometer (COSC + Rolex in-house certification). Fitted on a robust Oyster bracelet with the Oysterlock folding safety clasp and Glidelock extension system. Delivered with a 5-year international guarantee.';
        $product->translateOrNew('ar')->long_description = 'ساعة رولكس سابمارينر ديت 126610LN هي المرجع الأول في عالم ساعات الغوص الفاخرة. مصنوعة من الأويستر ستيل المقاوم للتآكل، ويضمن هيكل الأويستر مقاس 41 مم مقاومة الماء حتى عمق 300 متر (1000 قدم). يتيح الإطار الدوّار أحادي الاتجاه مع بطانة سيراكروم سوداء من السيراميك للغوّاصين مراقبة وقت الغوص بأمان، بينما يوفر القرص الأسود بعلامات كروماlight الكبيرة وضوحًا استثنائيًا في الظلام بتوهج أزرق طويل الأمد. تنبض في قلبها حركة كاليبر 3235 الميكانيكية ذاتية التعبئة المطوّرة والمصنّعة بالكامل لدى رولكس، باحتياطي طاقة 70 ساعة ومعتمدة ككرونومتر فائق الدقة. مثبتة على سوار أويستر متين مع مشبك أويسترلوك الآمن القابل للطي ونظام التمديد جلايدلوك. تأتي بضمان دولي لمدة 5 سنوات.';
        $product->translateOrNew('en')->model_name = 'Submariner Date';
        $product->translateOrNew('ar')->model_name = 'سابمارينر ديت';
        $product->translateOrNew('en')->country = 'Switzerland';
        $product->translateOrNew('ar')->country = 'سويسرا';
        $product->translateOrNew('en')->stone = 'None';
        $product->translateOrNew('ar')->stone = 'لا يوجد';

        $product->save();

        // ── Pivots ────────────────────────────────────────
        $product->gender()->sync([$this->genderId('Men')]);
        $product->feature()->sync($this->featureIds([
            'Water Resistant', 'Date Display', 'Luminous Hands',
            'Scratch Resistant', 'Sapphire Crystal', 'Automatic',
        ]));
        $product->dialColor()->sync($this->colorIds(['Black', 'Blue', 'Green']));
        $product->bandColor()->sync($this->colorIds(['Silver', 'Black']));

        // ── Images (sort column = task's "sort_order") ────
        $this->addImages($product, [
            ['image' => 'placeholder_watch_front.webp', 'is_cover' => 1, 'sort' => 1, 'alt_en' => 'Rolex Submariner front dial', 'alt_ar' => 'واجهة ساعة رولكس سابمارينر'],
            ['image' => 'placeholder_watch_side.webp',  'is_cover' => 0, 'sort' => 2, 'alt_en' => 'Rolex Submariner side profile', 'alt_ar' => 'جانب ساعة رولكس سابمارينر'],
            ['image' => 'placeholder_watch_back.webp',  'is_cover' => 0, 'sort' => 3, 'alt_en' => 'Rolex Submariner case back', 'alt_ar' => 'ظهر ساعة رولكس سابمارينر'],
        ]);

        // ── Variants (flat: name / price / stock / sku) ──
        $this->addVariants($product, [
            ['name' => 'Black / 41mm', 'price' => 485000, 'stock' => 2, 'sku' => 'ROL-SUB-BLK-41'],
            ['name' => 'Blue / 41mm',  'price' => 492000, 'stock' => 1, 'sku' => 'ROL-SUB-BLU-41'],
            ['name' => 'Green / 41mm', 'price' => 498000, 'stock' => 1, 'sku' => 'ROL-SUB-GRN-41'],
        ]);

        $this->command?->info('• Created WATCH: Rolex Submariner Date 126610LN (+3 images, +3 variants).');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRODUCT 2 — FASHION (Gucci GG Marmont Leather Belt)
    // ═══════════════════════════════════════════════════════════════════════
    private function createBelt(): void
    {
        $slug = 'gucci-gg-marmont-reversible-belt-black-brown';
        if (Product::where('seo_slug', $slug)->exists()) {
            $this->command?->warn("• Belt already exists ({$slug}) — skipped.");
            return;
        }

        $product = new Product();

        // ── Classification ────────────────────────────────
        $product->category_type_id = $this->categoryTypeId('Fashion');
        $product->sub_type_id      = $this->subTypeId('Belts');
        $product->grade_id         = $this->gradeId('Fashion & Lifestyle');
        $product->brand_id         = $this->brandId('Gucci');

        // ── Pricing / stock ───────────────────────────────
        $product->purchase_price            = 12000.00; // cost (task omits it; required, hidden from API)
        $product->selling_price             = 18500.00;
        $product->sale_price_after_discount = 15725.00;
        $product->percentage_discount       = 15;
        $product->stock                     = 8;
        $product->market_stock              = 3;
        $product->low_stock_threshold       = 3;

        // ── Fashion specs (watch-only fields intentionally NULL) ──
        $product->band_material_id  = $this->materialId('Leather');
        $product->band_closure_id   = $this->closureTypeId('Pin Buckle');
        $product->warranty_years    = 1;
        // Watch-specific fields left NULL for a fashion item:
        $product->case_size         = null;
        $product->watch_movement_id = null;
        $product->dial_display_type_id = null;
        $product->watch_box         = null;
        $product->interchangeable_dial  = null;
        $product->interchangeable_strap = null;

        // ── Identity / misc ───────────────────────────────
        $product->model_number   = 'GG-MARMONT-4CM';
        $product->sku_unique     = 'GUC-MARMONT-BELT-4CM';
        $product->wa_code        = 'GUCBELTMARMONT';
        $product->average_rate   = 4.7;          // normally derived from ratings; sample value
        $product->image          = 'placeholder_belt_front.webp';
        $product->active         = 1;
        $product->created_by     = $this->adminId;
        $product->updated_by     = $this->adminId;

        // ── SEO ───────────────────────────────────────────
        $product->seo_title            = 'Gucci GG Marmont Belt Black/Brown | Buy in Egypt - Watchizer';
        $product->seo_slug             = $slug;
        $product->seo_meta_description = 'Shop authentic Gucci GG Marmont reversible leather belt in Egypt. Premium calfskin, Double G buckle, 4cm width. Black and brown reversible design. Free shipping across Egypt.';
        $product->search_keywords      = 'gucci, belt, GG marmont, reversible, leather, حزام غوتشي, جي جي مارمونت, حزام جلد, أحزمة ماركات, اكسسوارات رجالية';

        // ── Extra (belt structured attributes) ────────────
        $product->extra_attributes = json_encode([
            'width_cm'   => 4,
            'reversible' => true,
            'buckle'     => 'Antiqued gold-tone Double G',
            'material'   => 'Calfskin leather',
            'made_in'    => 'Italy',
            'includes'   => 'Dust bag + box',
        ], JSON_UNESCAPED_UNICODE);

        // ── Translations (EN + AR) ────────────────────────
        $product->translateOrNew('en')->product_title = 'Gucci GG Marmont Reversible Leather Belt 4cm Black/Brown';
        $product->translateOrNew('ar')->product_title = 'حزام غوتشي جي جي مارمونت جلد طبيعي وجهين 4 سم أسود/بني';
        $product->translateOrNew('en')->short_description = 'Iconic Gucci GG Marmont reversible belt in premium calfskin leather. Features the signature Double G buckle in antiqued gold-tone hardware. Black on one side, brown on the other — two belts in one. Width: 4cm. Made in Italy. Comes with Gucci dust bag and box.';
        $product->translateOrNew('ar')->short_description = 'حزام غوتشي جي جي مارمونت الأيقوني بوجهين من الجلد الطبيعي الفاخر. يتميز بإبزيم Double G المميز بلمسة ذهبية عتيقة. أسود من جهة وبني من الأخرى — حزامين في واحد. العرض: 4 سم. صنع في إيطاليا. يأتي مع كيس غوتشي وعلبة.';
        $product->translateOrNew('en')->long_description = 'The Gucci GG Marmont reversible belt is a wardrobe essential that combines Italian craftsmanship with the House\'s most recognisable emblem. Crafted from supple calfskin leather, it is fully reversible — wear it black or flip it to brown to suit any outfit, effectively giving you two belts in one. The belt is finished with the iconic Double G buckle in an antiqued gold-tone metal, a nod to the archival hardware of the 1970s. Measuring 4cm in width, it is a versatile unisex piece that pairs equally well with tailoring and casual denim. Proudly made in Italy and delivered with the signature Gucci dust bag and gift box, backed by a 1-year guarantee.';
        $product->translateOrNew('ar')->long_description = 'حزام غوتشي جي جي مارمونت القابل للعكس هو قطعة أساسية في خزانة الملابس تجمع بين الحرفية الإيطالية وأشهر شعارات الدار. مصنوع من جلد العجل الناعم، وهو قابل للعكس بالكامل — ارتديه باللون الأسود أو اقلبه إلى البني ليناسب أي إطلالة، مما يمنحك عمليًا حزامين في واحد. يُزيَّن الحزام بإبزيم Double G الأيقوني بلمسة معدنية ذهبية عتيقة، في إشارة إلى تصاميم السبعينيات. بعرض 4 سم، وهو قطعة متعددة الاستخدامات للجنسين تتناسب مع الملابس الرسمية والجينز الكاجوال. صُنع بفخر في إيطاليا ويأتي مع كيس غوتشي المميز وعلبة الهدية، مدعومًا بضمان لمدة عام.';
        $product->translateOrNew('en')->model_name = 'GG Marmont';
        $product->translateOrNew('ar')->model_name = 'جي جي مارمونت';
        $product->translateOrNew('en')->country = 'Italy';
        $product->translateOrNew('ar')->country = 'إيطاليا';
        $product->translateOrNew('en')->stone = 'None';
        $product->translateOrNew('ar')->stone = 'لا يوجد';

        $product->save();

        // ── Pivots ────────────────────────────────────────
        $product->gender()->sync([$this->genderId('Unisex')]);
        // No features for a fashion item (task: leave empty).
        $product->feature()->sync([]);
        // Not a watch → no dial colours.
        $product->dialColor()->sync([]);
        $product->bandColor()->sync($this->colorIds(['Black', 'Brown', 'Navy']));

        // ── Images ────────────────────────────────────────
        $this->addImages($product, [
            ['image' => 'placeholder_belt_front.webp',  'is_cover' => 1, 'sort' => 1, 'alt_en' => 'Gucci GG Marmont belt front', 'alt_ar' => 'واجهة حزام غوتشي جي جي مارمونت'],
            ['image' => 'placeholder_belt_detail.webp', 'is_cover' => 0, 'sort' => 2, 'alt_en' => 'Gucci Double G buckle detail', 'alt_ar' => 'تفاصيل إبزيم غوتشي Double G'],
            ['image' => 'placeholder_belt_box.webp',    'is_cover' => 0, 'sort' => 3, 'alt_en' => 'Gucci belt box and dust bag', 'alt_ar' => 'علبة وكيس حزام غوتشي'],
        ]);

        // ── Variants (flat: name / price / stock / sku) ──
        $this->addVariants($product, [
            ['name' => 'Black / 85cm', 'price' => 18500, 'stock' => 3, 'sku' => 'GUC-BELT-BLK-85'],
            ['name' => 'Black / 90cm', 'price' => 18500, 'stock' => 2, 'sku' => 'GUC-BELT-BLK-90'],
            ['name' => 'Black / 95cm', 'price' => 18500, 'stock' => 2, 'sku' => 'GUC-BELT-BLK-95'],
            ['name' => 'Brown / 85cm', 'price' => 18500, 'stock' => 3, 'sku' => 'GUC-BELT-BRN-85'],
            ['name' => 'Brown / 90cm', 'price' => 18500, 'stock' => 2, 'sku' => 'GUC-BELT-BRN-90'],
            ['name' => 'Brown / 95cm', 'price' => 18500, 'stock' => 1, 'sku' => 'GUC-BELT-BRN-95'],
        ]);

        $this->command?->info('• Created FASHION: Gucci GG Marmont Belt (+3 images, +6 variants).');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS — dynamic lookup resolution (by translated name)
    // ═══════════════════════════════════════════════════════════════════════

    private function addImages(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $product->product_image()->create($row);
        }
    }

    /**
     * Insert product variants. The product_variants table is a flat structure:
     *   id, product_id, name, price, stock, sku, image
     * (no color_id/size_id/price_modifier). We assign properties directly rather
     * than via create() because the model's $fillable still lists the old columns.
     *
     * @param  array<int, array{name:string,price:float|int,stock:int,sku:string}>  $rows
     */
    private function addVariants(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $variant = new ProductVariant();
            $variant->product_id = $product->id;
            $variant->name       = $row['name'];
            $variant->price      = $row['price'];
            $variant->stock      = $row['stock'];
            $variant->sku        = $row['sku'];
            $variant->image      = null;
            $variant->save();
        }
    }

    // ── Translatable lookups (throw if the reference data is missing) ──────

    private function categoryTypeId(string $en): int
    {
        return $this->req(CategoryType::whereTranslation('category_type_name', $en)->value('id'), "CategoryType '{$en}'");
    }

    private function subTypeId(string $en): int
    {
        return $this->req(SubType::whereTranslation('sub_type_name', $en)->value('id'), "SubType '{$en}'");
    }

    private function gradeId(string $en): int
    {
        return $this->req(Grade::whereTranslation('grade_name', $en)->value('id'), "Grade '{$en}'");
    }

    private function brandId(string $en): int
    {
        return $this->req(Brand::whereTranslation('brand_name', $en)->value('id'), "Brand '{$en}'");
    }

    private function genderId(string $en): int
    {
        return $this->req(Gender::whereTranslation('gender_name', $en)->value('id'), "Gender '{$en}'");
    }

    private function shapeId(string $en): int
    {
        return $this->req(Shape::whereTranslation('shape_name', $en)->value('id'), "Shape '{$en}'");
    }

    private function movementTypeId(string $en): int
    {
        return $this->req(MovementType::whereTranslation('movement_type_name', $en)->value('id'), "MovementType '{$en}'");
    }

    private function displayTypeId(string $en): int
    {
        return $this->req(DisplayType::whereTranslation('display_type_name', $en)->value('id'), "DisplayType '{$en}'");
    }

    private function closureTypeId(string $en): int
    {
        return $this->req(ClosureType::whereTranslation('closure_type_name', $en)->value('id'), "ClosureType '{$en}'");
    }

    private function sizeTypeId(string $en): int
    {
        return $this->req(SizeType::whereTranslation('size_type_name', $en)->value('id'), "SizeType unit '{$en}'");
    }

    /** @return int[] */
    private function featureIds(array $names): array
    {
        return array_map(fn ($n) => $this->req(Feature::whereTranslation('feature_name', $n)->value('id'), "Feature '{$n}'"), $names);
    }

    /** Old `colors` table (dial/band pivots). @return int[] */
    private function colorIds(array $names): array
    {
        return array_map(fn ($n) => $this->req(Color::whereTranslation('color_name', $n)->value('id'), "Color '{$n}'"), $names);
    }

    /**
     * Material — resolve by EN name; create it (with the supplied AR name) if it
     * doesn't exist yet (e.g. "Sapphire Crystal", which MasterDataSeeder omits).
     */
    private function materialId(string $en, ?string $ar = null): int
    {
        $id = Material::whereTranslation('material_name', $en)->value('id');
        if ($id) {
            return $id;
        }
        if ($ar === null) {
            return $this->req(null, "Material '{$en}'");
        }
        $material = new Material();
        $material->translateOrNew('en')->material_name = $en;
        $material->translateOrNew('ar')->material_name = $ar;
        $material->save();
        return $material->id;
    }

    /** Guard: fail loudly with a clear message if reference data is missing. */
    private function req($value, string $what): int
    {
        if (empty($value)) {
            throw new \RuntimeException("ExampleProductsSeeder: {$what} not found. Run MasterDataSeeder first.");
        }
        return (int) $value;
    }
}
