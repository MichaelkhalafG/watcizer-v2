<?php

namespace App\Elegance;

/**
 * THE ELEGANCE PAGE'S CONTENT (eleganceeg.com, 2026-11-21): every word and every link of the company page, in English
 * and Arabic, in one place - the view renders it, the tests read it, the plan's link table is printed from it.
 *
 * Nothing here is invented. The texts were approved by Michael; every fact is one the shops publish (the source of
 * each: core/docs/elegance/ELEGANCE_LANDING_PLAN_20261121.md §2). Every link follows his rule - watches and every
 * brand's own page on watchizereg.com; bags, wallets, perfumes, jewellery, belts, electronics and the rest of fashion
 * (brand + category pages included) on brandfashionegy.com; the other shop when the rule's page is not live; no link
 * when neither is - and every URL was checked live on 21 Nov in both languages (200, indexable, canonical to itself,
 * no hidden brand; products: the photo we show is on the page). The `fallback` flag marks the two exceptions.
 *
 * Static data only: no request, no database, no input. The page reads nothing else.
 *
 * i18n-exempt-file: the public company page's own STOREFRONT text, both languages side by side, rendered by
 * resources/views/elegance/home.blade.php at /ar (ElegancePageTest renders it) - never dashboard text.
 */
final class EleganceContent
{
    public const WATCHIZER = 'https://watchizereg.com';

    public const BRAND_FASHION = 'https://www.brandfashionegy.com';

    public const MAP_HELIOPOLIS = 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7';

    public const MAP_ARKADIA = 'https://maps.app.goo.gl/jA9owX6N3L6bCpXP8';

    /** Bumped with every change of the page's static files, so a browser never mixes an old stylesheet with new markup. */
    public const ASSET_VERSION = '20261121';

    /**
     * The products shown, each linking to its own page. kind: watch (Watchizer) or fashion (Brand Fashion).
     * [key => [kind, shop, path, caption EN, caption AR, image widths]]
     */
    private const PRODUCTS = [
        'hugo-boss-legacy' => ['watch', 'wz', '/product/boss-legacy-mens-watch-with-black-dial-and-silver-bracelet-hb1513671', 'BOSS Legacy', 'ساعة بوس ليجاسي', [480, 800, 1200]],
        'hugo-boss-navigator' => ['watch', 'wz', '/product/boss-navigator-mens-watch-with-charcoal-dial-and-black-mesh-bracelet-hb1513674', 'BOSS Navigator', 'ساعة بوس نافيجيتور', [480, 800]],
        'hugo-boss-intensity' => ['watch', 'wz', '/product/boss-intensity-mens-watch-with-charcoal-dial-and-silver-bracelet-hb1513680', 'BOSS Intensity', 'ساعة بوس إنتنسيتي', [480, 800]],
        'hugo-boss-automatic' => ['watch', 'wz', '/product/boss-mens-automatic-watch-black-dial-black-strap-hb1514120', 'BOSS Automatic', 'ساعة بوس أوتوماتيك', [480, 800]],
        'emporio-armani-renato' => ['watch', 'wz', '/product/emporio-armani-mens-renato-three-hand-date-black-tone-stainless-steel-watch-ar11276', 'Emporio Armani Renato', 'ساعة إمبوريو أرماني ريناتو', [480, 800]],
        'emporio-armani-ar11015' => ['watch', 'wz', '/product/emporio-armani-watch-for-men-ar11015', 'Emporio Armani AR11015', 'ساعة إمبوريو أرماني AR11015', [480, 800]],
        // Watchizer sells it as MK5566 (the old mk00006 slug answers 308 there): its live page, checked 21 Nov
        'michael-kors-mk00006' => ['watch', 'wz', '/product/michael-kors-mk5566-pearl-light-blue-dial-silver-stainless-steel-bracelet-watch', 'Michael Kors MK5566', 'ساعة مايكل كورس MK5566', [480, 800]],
        'tommy-hilfiger-kane' => ['watch', 'wz', '/product/tommy-hilfiger-kane-mens-watch-1710401-blue-dial-silver-stainless-steel-bracelet', 'Tommy Hilfiger Kane', 'ساعة تومي هيلفيغر كين', [480, 800]],
        'tory-burch-bag' => ['fashion', 'bf', '/product/tory-burch-light-cream-women-shoulder-bag-159379-100', 'Tory Burch shoulder bag', 'حقيبة كتف من توري بورش', [480, 800, 1200]],
        'pinko-bag' => ['fashion', 'bf', '/product/pinko-white-women-crossbody-bag-100941a0gk-z14q', 'Pinko crossbody bag', 'حقيبة كروس بودي من بينكو', [480, 800]],
        'coach-bag' => ['fashion', 'bf', '/product/coach-dark-brown-women-shoulder-bag-cad75-b4mpl', 'Coach shoulder bag', 'حقيبة كتف من كوتش', [480, 800]],
        'coach-wallet' => ['fashion', 'bf', '/product/coach-womens-signature-canvas-floral-heart-bifold-wallet-c7653-burgundy', 'Coach bifold wallet', 'محفظة نسائية من كوتش', [480, 800]],
        'tommy-hilfiger-backpack' => ['fashion', 'bf', '/product/tommy-hilfiger-mens-black-front-pocket-backpack', 'Tommy Hilfiger backpack', 'حقيبة ظهر من تومي هيلفيغر', [480, 800]],
        'guess-tote' => ['fashion', 'bf', '/product/guess-womens-handbag-large-size-pink-bag0061', 'Guess handbag', 'حقيبة يد من جيس', [480, 800]],
        'tommy-hilfiger-briefcase' => ['fashion', 'bf', '/product/tommy-hilfiger-dark-grey-and-black-laptop-briefcase-with-shoulder-strap', 'Tommy Hilfiger laptop briefcase', 'شنطة لابتوب تومي هيلفيجر', [480, 800]],
        'tommy-hilfiger-belt' => ['fashion', 'bf', '/product/tommy-hilfiger-leather-brown-belt-with-box-for-men', 'Tommy Hilfiger leather belt', 'حزام جلدي من تومي هيلفيغر', [480, 800]],
        'emporio-armani-perfume' => ['fashion', 'bf', '/product/stronger-with-you-intensely-eau-de-parfum-men-perfume-100ml', 'Stronger With You Intensely', 'عطر سترونغر ويذ يو إنتنسلي', [480, 800]],
        'joyroom-power-bank' => ['fashion', 'bf', '/product/joyroom-jr-qp192-20000mah-225w-fast-charging-powerbank-with-lcd-display', 'Joyroom power bank', 'باور بانك جوي روم', [480, 800]],
    ];

    /** The categories, each with a photo of ours: [key => [kind, shop, path, label EN, label AR (the shop's own), image]] */
    private const CATEGORIES = [
        'watches' => ['watch', 'wz', '/category/watches', 'Watches', 'ساعات', 'tommy-hilfiger-kane'],
        'chronograph' => ['watch', 'wz', '/subtypes/chronograph', 'Chronograph', 'كرونوغراف', 'hugo-boss-navigator'],
        'automatic' => ['watch', 'wz', '/subtypes/automatic', 'Automatic', 'اوتوماتيك', 'hugo-boss-automatic'],
        'fashion' => ['fashion', 'bf', '/category/fashion', 'Fashion', 'أزياء وإكسسوارات', 'pinko-bag'],
        'bags' => ['fashion', 'bf', '/subtypes/bags', 'Bags', 'حقائب', 'coach-bag'],
        'wallets' => ['fashion', 'bf', '/subtypes/wallets', 'Wallets', 'محافظ', 'coach-wallet'],
        'belts' => ['fashion', 'bf', '/subtypes/belts', 'Belts', 'أحزمة', 'tommy-hilfiger-belt'],
        'bracelets' => ['fashion', 'bf', '/subtypes/bracelets', 'Bracelets', 'أساور', 'michael-kors-bangle'],
        'perfumes' => ['fashion', 'bf', '/subtypes/perfumes', 'Perfumes', 'عطور', 'emporio-armani-perfume'],
        'electronics' => ['fashion', 'bf', '/category/electronics', 'Electronics', 'إلكترونيات', 'joyroom-power-bank'],
    ];

    /** Brand + category pages: [key => [house, shop, path, label EN, label AR]] (the shops' own page titles) */
    private const BRAND_CATEGORIES = [
        'hugo-boss-mens-watches' => ['watchizer', 'wz', '/brand/hugo-boss/watches/men', "Hugo Boss men's watches", 'ساعات هوغو بوس للرجال'],
        'michael-kors-womens-watches' => ['watchizer', 'wz', '/brand/michael-kors/watches/women', "Michael Kors women's watches", 'ساعات مايكل كورس للنساء'],
        'guess-womens-watches' => ['watchizer', 'wz', '/brand/guess/watches/women', "Guess women's watches", 'ساعات جيس للنساء'],
        'tommy-hilfiger-mens-watches' => ['watchizer', 'wz', '/brand/tommy-hilfiger/watches/men', "Tommy Hilfiger men's watches", 'ساعات تومي هيلفيغر للرجال'],
        'coach-bags' => ['brandfashion', 'bf', '/brand/coach/bags', 'Coach bags', 'حقائب كوتش'],
        'guess-bags' => ['brandfashion', 'bf', '/brand/guess/bags', 'Guess bags', 'حقائب جيس'],
        'tommy-hilfiger-bags' => ['brandfashion', 'bf', '/brand/tommy-hilfiger/bags', 'Tommy Hilfiger bags', 'حقائب تومي هيلفيغر'],
        'coach-wallets' => ['brandfashion', 'bf', '/brand/coach/wallets', 'Coach wallets', 'محافظ كوتش'],
        'calvin-klein-wallets' => ['brandfashion', 'bf', '/brand/calvin-klein/wallets', 'Calvin Klein wallets', 'محافظ كالفن كلاين'],
        // Brand Fashion's page is noindex: Watchizer's (fallback)
        'tommy-hilfiger-wallets' => ['brandfashion', 'wz', '/brand/tommy-hilfiger/wallets', 'Tommy Hilfiger wallets', 'محافظ تومي هيلفيغر'],
    ];

    /** The brand wall: [slug => [name, shop or null (no live page), the logo's width at 48 px high, or null: no clean logo]]; brand pages go to Watchizer. */
    private const BRANDS = [
        'tissot' => ['Tissot', 'wz', 131], 'michael-kors' => ['Michael Kors', 'wz', 72], 'tommy-hilfiger' => ['Tommy Hilfiger', 'wz', 64],
        // its catalogue logo file is damaged: the tile sets the name
        'calvin-klein' => ['Calvin Klein', 'wz', null],
        'coach' => ['Coach', 'wz', 288], 'guess' => ['Guess', 'wz', 55], 'hugo-boss' => ['Hugo Boss', 'wz', 398],
        'emporio-armani' => ['Emporio Armani', 'wz', 514], 'armani-exchange' => ['Armani Exchange', 'wz', 94], 'fossil' => ['Fossil', 'wz', 98],
        'lacoste' => ['Lacoste', 'wz', 100], 'diesel' => ['Diesel', 'wz', 160], 'tory-burch' => ['Tory Burch', 'wz', 53],
        'just-cavalli' => ['Just Cavalli', 'wz', 156], 'pinko' => ['Pinko', 'wz', 76], 'maserati' => ['Maserati', 'wz', 120],
        'g-shock' => ['G-Shock', 'wz', 49],
        // Watchizer's page is noindex: Brand Fashion's (fallback)
        'seiko' => ['Seiko', 'bf', 228],
        // noindex on both shops: shown, not linked
        'casio' => ['Casio', null, 252],
    ];

    /** The products each house's display case shows, the large one first. */
    private const HOUSE_PIECES = [
        'watchizer' => ['hugo-boss-navigator', 'hugo-boss-intensity', 'emporio-armani-renato', 'michael-kors-mk00006', 'hugo-boss-automatic', 'emporio-armani-ar11015', 'tommy-hilfiger-kane'],
        'brandfashion' => ['pinko-bag', 'coach-bag', 'guess-tote', 'coach-wallet', 'tommy-hilfiger-backpack', 'tommy-hilfiger-briefcase', 'emporio-armani-perfume'],
    ];

    /** The boutique window in the hero. */
    private const WINDOW = ['tory-burch-bag', 'hugo-boss-legacy', 'coach-wallet'];

    private const FALLBACKS = ['tommy-hilfiger-wallets', 'seiko'];

    private const TEXT = [
        'en' => [
            'title' => 'Elegance - the company behind Watchizer and Brand Fashion',
            'description' => 'Elegance is an Egyptian retail group for original watches and accessories, the company behind Watchizer and Brand Fashion. Office: 90 El Thawra Street, Heliopolis, Cairo.',
            'skip' => 'Skip to the content', 'nav_label' => 'Sections', 'lang_label' => 'Language',
            'nav' => ['watchizer' => 'Watchizer', 'brandfashion' => 'Brand Fashion', 'categories' => 'Categories', 'brands' => 'Brands', 'contact' => 'Contact'],
            'tagline' => 'Original watches and accessories',
            'about' => 'Elegance is an Egyptian retail group for original watches and accessories, and the company behind two shops: Watchizer and Brand Fashion.',
            'entry_watchizer' => 'Watches for men and women', 'entry_brandfashion' => 'Bags, wallets and accessories', 'visit' => 'Visit',
            'window' => 'From our catalogue',
            'ribbon' => ['Selling watches and accessories in Egypt since 2013', 'Two stores in Cairo', 'Delivery to all 27 governorates', "Up to 3 years' warranty on watches"],
            'watchizer_story' => 'Watchizer has sold watches and accessories in Egypt since 2013, online and in two stores in Cairo.',
            'watchizer_facts' => ['An authorized dealer for Hugo Boss.', '900K+ people follow Watchizer on Facebook, Instagram and TikTok.', 'Pay in cash on delivery, or online by card.'],
            'brandfashion_story' => 'Brand Fashion has sold watches, bags and accessories in Egypt since 2013, online and in two stores in Cairo.',
            'brandfashion_facts' => ['Watches for men and women, bags, wallets, belts, jewellery and perfumes.', '800K+ people follow Brand Fashion on Facebook, Instagram and TikTok.', 'Pay in cash when your order arrives, after you inspect it.'],
            'shop_by' => 'Shop by brand',
            'categories_h' => 'Shop by category', 'categories_p' => 'Watches at Watchizer; bags, wallets and the rest of fashion at Brand Fashion.',
            'at_watchizer' => 'At Watchizer', 'at_brandfashion' => 'At Brand Fashion',
            'brands_h' => 'The brands we carry', 'brands_p' => 'A selection of the international names in our catalogue.',
            'why_h' => 'Why shop with us',
            'why' => [
                ['Original watches and accessories', 'Watchizer is an authorized dealer for Hugo Boss, and most of our watches carry their brand agent’s warranty.'],
                ["Up to 3 years' warranty", 'The length for each watch is on its product page and its warranty card. Within the warranty, our stores replace the battery and size the bracelet free of charge.'],
                ['Two stores in Cairo', 'Arkadia Mall and 90 El-Thawra Street, Heliopolis, open every day 10:00–24:00.'],
                ['Delivery across Egypt', 'To all 27 governorates, within 24 hours.'],
                ['Inspect before you pay', 'With cash on delivery, open your order and check it in front of the courier before paying.'],
                ['14 days to return or exchange', 'And we pay the return shipping.'],
            ],
            'contact_h' => 'Contact', 'contact_p' => 'Our office, and the two stores where both shops welcome you.',
            'address' => ['Elegance', '90 El Thawra Street, ground floor', 'Heliopolis, Cairo, Egypt'], 'map' => 'Open in Google Maps',
            'phone' => 'Phone', 'email' => 'E-mail', 'stores_h' => 'Our stores', 'store_map' => 'Map',
            'arkadia' => ['Arkadia Mall', '2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont, Cairo'],
            'heliopolis' => ['Heliopolis', '90 El-Thawra Street, Heliopolis'], 'hours' => 'Open every day, 10:00–24:00.',
            'footer' => '© 2026 Elegance', 'footer_nav' => 'Our shops', 'watchizer' => 'Watchizer', 'brandfashion' => 'Brand Fashion',
            'og_locale' => 'en_US',
        ],
        'ar' => [
            'title' => 'إليجانس - الشركة المالكة لواتشايزر وبراند فاشون',
            'description' => 'إليجانس مجموعة تجزئة مصرية للساعات والإكسسوارات الأصلية، والشركة المالكة لواتشايزر وبراند فاشون. المكتب: ٩٠ شارع الثورة، مصر الجديدة، القاهرة.',
            'skip' => 'انتقل إلى المحتوى', 'nav_label' => 'الأقسام', 'lang_label' => 'اللغة',
            'nav' => ['watchizer' => 'واتشايزر', 'brandfashion' => 'براند فاشون', 'categories' => 'الأقسام', 'brands' => 'الماركات', 'contact' => 'تواصل معنا'],
            'tagline' => 'ساعات وإكسسوارات أصلية',
            'about' => 'إليجانس مجموعة تجزئة مصرية للساعات والإكسسوارات الأصلية، والشركة المالكة لمتجرين: واتشايزر وبراند فاشون.',
            'entry_watchizer' => 'ساعات رجالية ونسائية', 'entry_brandfashion' => 'حقائب ومحافظ وإكسسوارات', 'visit' => 'زيارة',
            'window' => 'من تشكيلتنا',
            'ribbon' => ['نبيع الساعات والإكسسوارات في مصر منذ ٢٠١٣', 'متجران في القاهرة', 'توصيل إلى ٢٧ محافظة', 'ضمان على الساعات حتى ٣ سنوات'],
            'watchizer_story' => 'تبيع واتشايزر الساعات والإكسسوارات في مصر منذ ٢٠١٣، أونلاين وفي متجرين في القاهرة.',
            'watchizer_facts' => ['وكيل معتمد لهوغو بوس.', 'يتابع واتشايزر أكثر من ٩٠٠ ألف شخص على فيسبوك وإنستجرام وتيك توك.', 'الدفع نقداً عند الاستلام، أو بالبطاقة أونلاين.'],
            'brandfashion_story' => 'تبيع براند فاشون الساعات والحقائب والإكسسوارات في مصر منذ ٢٠١٣، أونلاين وفي متجرين في القاهرة.',
            'brandfashion_facts' => ['ساعات رجالية ونسائية، وحقائب ومحافظ وأحزمة ومجوهرات وعطور.', 'يتابع براند فاشون أكثر من ٨٠٠ ألف شخص على فيسبوك وإنستجرام وتيك توك.', 'الدفع نقداً عند الاستلام، بعد معاينة طلبك.'],
            'shop_by' => 'تسوّق حسب الماركة',
            'categories_h' => 'تسوّق حسب القسم', 'categories_p' => 'الساعات في واتشايزر؛ والحقائب والمحافظ وباقي الأزياء في براند فاشون.',
            'at_watchizer' => 'في واتشايزر', 'at_brandfashion' => 'في براند فاشون',
            'brands_h' => 'الماركات التي نقدّمها', 'brands_p' => 'مختارات من الماركات العالمية في تشكيلتنا.',
            'why_h' => 'لماذا تتسوّق معنا',
            'why' => [
                ['ساعات وإكسسوارات أصلية', 'واتشايزر وكيل معتمد لهوغو بوس، ومعظم ساعاتنا تحمل ضمان وكيل الماركة.'],
                ['ضمان حتى ٣ سنوات', 'مدة الضمان لكل ساعة مذكورة في صفحتها وعلى بطاقة ضمانها. وخلال الضمان، تستبدل فروعنا البطارية وتضبط مقاس السوار مجاناً.'],
                ['متجران في القاهرة', 'أركاديا مول و٩٠ شارع الثورة، مصر الجديدة، مفتوحان يومياً من ١٠ صباحاً حتى منتصف الليل.'],
                ['توصيل في كل مصر', 'إلى ٢٧ محافظة، خلال ٢٤ ساعة.'],
                ['عاين قبل أن تدفع', 'عند الدفع نقداً عند الاستلام، افتح طلبك وعاينه أمام المندوب قبل أن تدفع.'],
                ['١٤ يوماً للإرجاع أو الاستبدال', 'ونتحمّل نحن شحن الإرجاع.'],
            ],
            'contact_h' => 'تواصل معنا', 'contact_p' => 'مكتبنا، والفرعان اللذان يستقبلك فيهما المتجران.',
            'address' => ['إليجانس', '٩٠ شارع الثورة، الدور الأرضي', 'مصر الجديدة، القاهرة، مصر'], 'map' => 'افتح في خرائط جوجل',
            'phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'stores_h' => 'فروعنا', 'store_map' => 'الخريطة',
            'arkadia' => ['أركاديا مول', 'الدور الثاني، كورنيش النيل، امتداد ماسبيرو، بجوار فندق فيرمونت، القاهرة'],
            'heliopolis' => ['مصر الجديدة', '٩٠ شارع الثورة، مصر الجديدة'], 'hours' => 'مفتوحان يومياً من ١٠ صباحاً حتى منتصف الليل.',
            'footer' => '© 2026 إليجانس', 'footer_nav' => 'متاجرنا', 'watchizer' => 'واتشايزر', 'brandfashion' => 'براند فاشون',
            'og_locale' => 'ar_EG',
        ],
    ];

    /** A shop's page in a language: the Arabic page is the same path under /ar. */
    public static function url(string $shop, string $path, string $locale): string
    {
        $base = $shop === 'bf' ? self::BRAND_FASHION : self::WATCHIZER;

        return $base.($locale === 'ar' ? '/ar' : '').$path;
    }

    /**
     * Everything the page shows, in one language.
     *
     * @return array<string, mixed>
     */
    public static function page(string $locale): array
    {
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $ar = $locale === 'ar';
        $product = function (string $key) use ($locale, $ar): array {
            [$kind, $shop, $path, $en, $arName, $widths] = self::PRODUCTS[$key];

            return ['key' => $key, 'kind' => $kind, 'url' => self::url($shop, $path, $locale), 'caption' => $ar ? $arName : $en, 'widths' => $widths];
        };
        $houses = [];
        foreach (self::HOUSE_PIECES as $house => $keys) {
            $chips = [];
            foreach (self::BRAND_CATEGORIES as [$owner, $shop, $path, $en, $arLabel]) {
                if ($owner === $house) {
                    $chips[] = ['url' => self::url($shop, $path, $locale), 'label' => $ar ? $arLabel : $en];
                }
            }
            $houses[$house] = ['pieces' => array_map($product, $keys), 'chips' => $chips, 'home' => self::url($house === 'watchizer' ? 'wz' : 'bf', '', $locale)];
        }
        $categories = [];
        foreach (self::CATEGORIES as $key => [$kind, $shop, $path, $en, $arLabel, $image]) {
            $categories[] = ['key' => $key, 'url' => self::url($shop, $path, $locale), 'label' => $ar ? $arLabel : $en, 'shop' => $shop, 'image' => $image];
        }
        $brands = [];
        foreach (self::BRANDS as $slug => [$name, $shop, $logo]) {
            $brands[] = ['slug' => $slug, 'name' => $name, 'url' => $shop === null ? null : self::url($shop, '/brand/'.$slug, $locale), 'logo' => $logo];
        }

        return [
            'locale' => $locale,
            'dir' => $ar ? 'rtl' : 'ltr',
            'text' => self::TEXT[$locale],
            'window' => array_map($product, self::WINDOW),
            'houses' => $houses,
            'categories' => $categories,
            'brands' => $brands,
            'shops' => ['watchizer' => self::url('wz', '', $locale), 'brandfashion' => self::url('bf', '', $locale)],
            'maps' => ['heliopolis' => self::MAP_HELIOPOLIS, 'arkadia' => self::MAP_ARKADIA],
            'version' => self::ASSET_VERSION,
        ];
    }

    /**
     * Every outbound link on the page, both languages, for the tests and the plan's link table.
     *
     * @return list<array{group: string, key: string, label: string, shop: string|null, fallback: bool, en: string|null, ar: string|null}>
     */
    public static function links(): array
    {
        $rows = [];
        $add = function (string $group, string $key, string $label, ?string $shop, ?string $path) use (&$rows): void {
            $rows[] = ['group' => $group, 'key' => $key, 'label' => $label, 'shop' => $shop, 'fallback' => in_array($key, self::FALLBACKS, true),
                'en' => $shop === null || $path === null ? null : self::url($shop, $path, 'en'), 'ar' => $shop === null || $path === null ? null : self::url($shop, $path, 'ar')];
        };
        $add('shop', 'watchizer', 'Watchizer', 'wz', '');
        $add('shop', 'brandfashion', 'Brand Fashion', 'bf', '');
        foreach (self::BRANDS as $slug => [$name, $shop]) {
            $add('brand', $slug, $name, $shop, '/brand/'.$slug);
        }
        foreach (self::CATEGORIES as $key => [, $shop, $path, $en]) {
            $add('category', $key, $en, $shop, $path);
        }
        foreach (self::BRAND_CATEGORIES as $key => [, $shop, $path, $en]) {
            $add('brand-category', $key, $en, $shop, $path);
        }
        // a product is linked where it is shown as a product (the window, a display case); a photo that only illustrates a
        // category (the belt, the power bank) links to that category instead
        $shown = array_merge(self::WINDOW, ...array_values(self::HOUSE_PIECES));
        foreach (self::PRODUCTS as $key => [, $shop, $path, $en]) {
            if (in_array($key, $shown, true)) {
                $add('product', $key, $en, $shop, $path);
            }
        }
        $rows[] = ['group' => 'map', 'key' => 'heliopolis', 'label' => 'Heliopolis (the office)', 'shop' => null, 'fallback' => false, 'en' => self::MAP_HELIOPOLIS, 'ar' => self::MAP_HELIOPOLIS];
        $rows[] = ['group' => 'map', 'key' => 'arkadia', 'label' => 'Arkadia Mall', 'shop' => null, 'fallback' => false, 'en' => self::MAP_ARKADIA, 'ar' => self::MAP_ARKADIA];

        return $rows;
    }
}
