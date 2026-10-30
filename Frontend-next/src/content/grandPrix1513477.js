// THE HUGO BOSS GRAND PRIX 1513477 CAMPAIGN — Watchizer only (2026-10-27, the brand-supplied model 2026-10-28).
//
// One page for ONE watch — /experience/hugo-boss-grand-prix-1513477 (English) and /ar/experience/… (Arabic) — and
// the campaign hero on the home page that leads to it. Not a template: the slug, the id and the words below are
// this watch's. There is no Brand Fashion twin (recorded in Frontend-brandfashion/TWINS.txt).
//
// WHAT IS WRITTEN HERE AND WHAT IS NOT. This file holds the page's own words (both languages) and the paths of the
// brand's assets. Every SPECIFICATION VALUE the page shows — case size, materials, glass, thickness, bracelet,
// movement, colours, features, price, stock — is read from core's v2 answer for the product at render time
// (src/lib/experienceSpecs.js), never typed here. A value the record does not hold renders NOTHING: no
// placeholder, no figure from a brief, no claim about the movement's calibre or construction.

export const PRODUCT_SLUG = 'hugo-boss-grand-prix-1513477-mens-chronograph-watch-black-dial-silver-stainless-steel'

// The product the page is for. The API's answer must carry this id, or the page is a 404: a slug can be
// re-pointed on the dashboard, and this page must not quietly become another watch's.
export const PRODUCT_ID = 26101

export const PATH = '/experience/hugo-boss-grand-prix-1513477'

/*
 * The home banner's composition (2026-10-29, two options for Michael): 'a' — the words left, aligned to the watch's
 * centre, the facts and the price under the name, the watch large at centre-right (RECOMMENDED); 'b' — the watch in
 * the middle, the words on its left, the facts and the price in a column on its right. One word switches them.
 */
export const BANNER_LAYOUT = 'a'

/*
 * THE BRAND'S ASSETS, as supplied by the brand's representative (2026-10-28) and placed under public/experience/:
 *
 *   glb        the model, size-optimised only (core/docs/deploy/HERO_PAGE_20261028.md §A3: the exporter's 30 MB of
 *              duplicated material JSON dropped, identical vertices welded, textures re-encoded as WebP at 2048,
 *              Meshopt-compressed) — geometry, materials, markings and node names as supplied; proven unchanged
 *              by renders from the same cameras. Draws with the Meshopt decoder drei already ships.
 *   glbPhone   the same with textures at 1024, for viewports under 768 px.
 *   logo       the official logo file, byte for byte as supplied (a 1280 × 477 lossless WebP, dark ink on
 *              transparent). It is never redrawn; on the dark campaign surfaces it is shown through a CSS
 *              invert filter, the file untouched. A vector or larger file is owed from the brand (the record).
 *   poster     the first paint of the experience page (the watch small and centred) and, posterHero, of the home
 *              hero (the watch large): stills rendered from this GLB (never a photo from another retailer), AVIF +
 *              WebP + the fallback.
 *   env        the studio HDR the home hero already ships (512 × 256).
 */
export const ASSETS = {
  glb: '/experience/grand-prix-1513477.glb',
  glbPhone: '/experience/grand-prix-1513477-phone.glb',
  // the experience page's FIRST model (2026-10-29): the phone file's geometry, textures at 256 px, 471 KB — then the
  // full textures in place, from these manifests (material → slot → file)
  glbLite: '/experience/grand-prix-1513477-lite.glb',
  texPhone: '/experience/tex/phone.json',
  texWide: '/experience/tex/wide.json',
  // the experience page's first paint: a frame captured from the stage's own opening (atmosphere included)
  expPoster: { wide: { w: 2560, h: 1080, base: '/experience/exp-poster-wide' }, phone: { w: 390, h: 844, base: '/experience/exp-poster-phone' } },
  logo: '/experience/hugo-boss-logo.webp',
  logoWidth: 1280,
  logoHeight: 477,
  poster: '/experience/poster-opening.webp',
  posterAvif: '/experience/poster-opening.avif',
  posterPhone: '/experience/poster-opening-720.webp',
  posterPhoneAvif: '/experience/poster-opening-720.avif',
  // the home hero's own framing (the watch large, three-quarter) — the experience opens small and centred
  posterHero: '/experience/poster-hero.webp',
  posterHeroAvif: '/experience/poster-hero.avif',
  posterHeroPhone: '/experience/poster-hero-720.webp',
  posterHeroPhoneAvif: '/experience/poster-hero-720.avif',
  posterWidth: 1200,
  posterHeight: 1200,
  // the home banner's poster: stills captured from the banner's own canvas (same pose, light and grade), transparent
  // over the banner's background — the wide screen's two directions and the phone's — at 1× and 2×
  banner: {
    ltr: { w: 998, h: 540, base: '/experience/banner-desktop-ltr' },
    rtl: { w: 998, h: 540, base: '/experience/banner-desktop-rtl' },
    phone: { w: 390, h: 338, base: '/experience/banner-phone' },
  },
  env: '/studio_small_03_512.hdr',
}

/*
 * The spec keys (core's `specs[].key`) each SECTION's caption reads, in order — the DATABASE FIRST. A key the record
 * does not hold is skipped; a section with no recorded facts shows its title and line only.
 */
export const CAPTION_KEYS = {
  hero: [],
  case: ['case_material', 'case_size', 'case_shape', 'case_thickness'],
  dial: ['dial_display_type'],
  subdials: [],
  crown: [],
  bracelet: ['band_material', 'band_width', 'band_length', 'band_closure'],
  open: ['case_material'],
  movement: ['movement_type'],
  parts: [],
  together: [],
  specs: [],
  final: [],
}

// The features (core's `attributes.features`, by id) a section lists when the record carries them.
export const CAPTION_FEATURE_IDS = {
  dial: [2, 3], // Chronograph, Date Display
  subdials: [2, 12], // Chronograph, Stopwatch
  movement: [9], // Quartz
  open: [1], // Water Resistant (the depth rating is not a recorded spec; nothing is printed for it)
}

/*
 * THE GHOST WORDS — one per section, drawn inside the canvas BEHIND the watch (Atmosphere.jsx). A word, not a claim:
 * each is a plain name for what the section shows. Both languages, each set in its own display face.
 */
export const WORDS = {
  en: { case: 'Steel', dial: 'Dial', subdials: 'Chrono', crown: 'Press', bracelet: 'Links', open: 'Open', movement: 'Inside', parts: 'Apart', together: 'Whole', specs: '1513477' },
  ar: { case: 'ستيل', dial: 'المينا', subdials: 'كرونو', crown: 'اضغط', bracelet: 'وصلات', open: 'مفتوحة', movement: 'الداخل', parts: 'مفكّكة', together: 'كاملة', specs: '1513477' },
}

/*
 * THE CALLOUTS — a short label on a line that tracks the part on screen (the stage projects the part's position every
 * frame). Labels name the part, nothing more.
 */
export const CALLOUTS = {
  dial: [
    { anchor: 'dial', en: 'Dial', ar: 'المينا' },
    { anchor: 'tachy', en: 'Tachymeter ring', ar: 'مقياس التاكيمتر' },
  ],
  subdials: [
    { anchor: 'subdial6', en: 'Sub-dial', ar: 'عدّاد فرعي' },
    { anchor: 'hands', en: 'Chronograph seconds hand', ar: 'عقرب ثواني الكرونوغراف' },
  ],
  crown: [
    { anchor: 'crown', en: 'Crown', ar: 'التاج' },
    { anchor: 'pushers', en: 'Pusher', ar: 'الزر' },
  ],
  bracelet: [{ anchor: 'clasp', en: 'Clasp', ar: 'المشبك' }],
  open: [{ anchor: 'caseback', en: 'Caseback', ar: 'الغطاء الخلفي' }],
  movement: [{ anchor: 'movement', en: 'Movement', ar: 'الحركة' }],
  parts: [
    { anchor: 'crystal', en: 'Crystal', ar: 'الزجاج' },
    { anchor: 'bezel', en: 'Bezel', ar: 'الإطار' },
    { anchor: 'hands', en: 'Hands', ar: 'العقارب' },
    { anchor: 'crown', en: 'Crown', ar: 'التاج' },
  ],
}

/*
 * THE PAGE'S WORDS. Each section's caption describes WHAT IS ON SCREEN, in words that make no technical claim: no
 * figure, no calibre, no jewel count, no depth rating — the figures beside a caption are the record's.
 * `box` is NULL until the client answers what is in the box and the warranty terms: the section is not rendered.
 */
export const COPY = {
  en: {
    metaTitle: 'Hugo Boss Grand Prix 1513477, piece by piece | Watchizer',
    metaDescription:
      'The Hugo Boss Grand Prix 1513477 chronograph up close: the black dial, the tachymeter, the three sub-dials, the steel case and bracelet, the quartz movement — with the price and stock from Watchizer.',
    home: {
      eyebrow: 'Grand Prix collection',
      name: 'Grand Prix 1513477',
      line: 'A chronograph, piece by piece.',
      cta: 'Explore the watch',
      productLink: 'View product',
      logoAlt: 'Hugo Boss',
    },
    opening: { eyebrow: 'Grand Prix collection', line: 'Scroll to see it up close.' },
    /*
     * SOURCES (the record, core/docs/deploy/HERO_PAGE_20261028.md §C): [DB] the product record; [MODEL] what is marked or
     * modelled on the brand-supplied file; [BOSS] hugoboss.com's Grand Prix 44 listings. Nothing else.
     */
    sections: {
      hero: { eyebrow: 'Grand Prix collection', title: '', line: 'Chronograph · Tachymeter · Date' },
      case: { eyebrow: 'The case', title: 'Forty-four millimetres of steel', line: 'A round stainless steel case, eleven millimetres thin, in polished and brushed finishes, under a sapphire crystal.' , short: 'Round stainless steel, under sapphire crystal.' },
      dial: { eyebrow: 'The dial', title: 'Black, with a scale for speed', line: 'A black sunray dial with applied polished indexes, the date in its window, and a tachymeter scale around the edge to time speed over a measured distance.' , short: 'Black sunray dial, date window, tachymeter scale.' },
      subdials: { eyebrow: 'The chronograph', title: 'Three counters, one sweep', line: 'Running seconds, a thirty-minute counter and a twenty-four-hour register sit in the dial; the chronograph seconds hand sweeps from the centre. Start it, stop it, read it.' , short: 'Three counters; the chronograph hand sweeps from the centre.' },
      crown: { eyebrow: 'Crown & pushers', title: 'Start. Stop. Reset.', line: 'Two pushers flank the crown: the upper starts and stops the chronograph, the lower resets it. The crown sets the time and the date.' , short: 'The upper pusher starts and stops, the lower resets.' },
      bracelet: { eyebrow: 'The bracelet', title: 'Steel, link by link', line: 'A twenty-two millimetre stainless steel bracelet that closes with a folding clasp, the wordmark engraved on its cover.' , short: 'Steel links, a folding clasp, the wordmark engraved.' },
      open: { eyebrow: 'The caseback', title: 'Sealed against the day', line: 'A stainless steel caseback, marked water resistant to 3 ATM — thirty metres — as the watch carries it: fine for rain and a wash of the hands, not for swimming.' , short: 'Marked water resistant, 3 ATM — thirty metres.' },
      movement: { eyebrow: 'Quartz', title: 'Precise, and no winding', line: 'A quartz movement keeps the time with the steadiness quartz is known for, and asks nothing of you: no winding, no daily care.' , short: 'Quartz: steady, and no winding.' },
      parts: { eyebrow: 'Every part', title: 'Built in layers', line: 'Crystal and bezel over the dial, the hands on their pipes, the crown and pushers through the case, the bracelet on its spring bars — each part made for its place.' , short: 'Each part made for its place.' },
      together: { eyebrow: 'Back together', title: 'One watch, ready', line: 'Every part home. A chronograph for the wrist that is read at a glance and wears all day.' , short: 'Read at a glance, worn all day.' },
      specs: { eyebrow: 'Specification', title: 'The record', line: 'Every value below is the product record’s, as the product page prints it.' , short: 'The product record’s values.' },
      final: { eyebrow: 'Grand Prix collection', title: '', line: 'A steel chronograph with a tachymeter scale, under sapphire crystal.', short: 'A steel chronograph with a tachymeter, under sapphire crystal.' },
    },
    specs: { title: 'Specification', line: 'Every value below is the product record’s, as the product page prints it.' },
    /*
     * WITH YOUR WATCH (the client's facts, 2026-10-29): shown ONLY when the product record carries its own warranty
     * length (`warranty_years`, entered per product by data entry) — empty, and the block is not rendered. Which
     * warranty (the store's or the official agent's) is not recorded per product, so it is not stated.
     */
    box: {
      title: 'With your watch',
      warranty: (n) => `A ${n}-year warranty on the movement against manufacturing defects`,
      card: 'A warranty card and the sales invoice',
      packaging: 'The brand box and catalogue',
    },
    close: { title: 'Grand Prix 1513477', line: 'Add it to your cart, or go straight to checkout.' },
    labels: {
      price: 'Price',
      was: 'was',
      off: 'off',
      inStock: 'In stock',
      lowStock: (n) => `Only ${n} left`,
      soldOut: 'Out of Stock', // the product page's label, word for word
      addToCart: 'Add to cart',
      buyNow: 'Buy now',
      adding: 'Adding…',
      added: 'Added to your cart',
      viewCart: 'View cart',
      productPage: 'Product page',
      features: 'Features',
      dialColour: 'Dial colour',
      bandColour: 'Band colour',
      posterAlt: 'Hugo Boss Grand Prix 1513477, the assembled watch',
      stageAlt: 'The watch, up close as you scroll',
      bar: 'Buy this watch',
      scroll: 'Scroll',
      step: (n, of) => `Step ${n} of ${of}`,
    },
  },
  ar: {
    metaTitle: 'Hugo Boss Grand Prix 1513477 عن قرب | Watchizer',
    metaDescription:
      'ساعة Hugo Boss Grand Prix 1513477 الكرونوغراف عن قرب: المينا السوداء، مقياس التاكيمتر، العدادات الثلاثة، علبة وسوار الستانلس ستيل، وحركة الكوارتز — مع السعر والتوفر من Watchizer.',
    home: {
      eyebrow: 'مجموعة Grand Prix',
      name: 'Grand Prix 1513477',
      line: 'كرونوغراف، قطعةً قطعة.',
      cta: 'اكتشف الساعة',
      productLink: 'عرض المنتج',
      logoAlt: 'Hugo Boss',
    },
    opening: { eyebrow: 'مجموعة Grand Prix', line: 'مرّر لتراها عن قرب.' },
    sections: {
      hero: { eyebrow: 'مجموعة Grand Prix', title: '', line: 'كرونوغراف · تاكيمتر · تاريخ' },
      case: { eyebrow: 'العلبة', title: 'أربعة وأربعون ملّيمتراً من الستيل', line: 'علبة دائرية من الستانلس ستيل بسُمك أحد عشر ملّيمتراً، بين سطحٍ مصقول وآخر مفرّش، تحت كريستال ياقوتي.' , short: 'علبة ستانلس ستيل دائرية، تحت كريستال ياقوتي.' },
      dial: { eyebrow: 'المينا', title: 'سوداء، وبها مقياس للسرعة', line: 'مينا سوداء بلمسة شعاعية ومؤشرات مصقولة بارزة، ونافذة للتاريخ، ومقياس تاكيمتر على الحافة لقياس السرعة على مسافةٍ معلومة.' , short: 'مينا سوداء شعاعية، نافذة تاريخ، مقياس تاكيمتر.' },
      subdials: { eyebrow: 'الكرونوغراف', title: 'ثلاثة عدّادات، وعقربٌ يدور', line: 'ثوانٍ جارية، وعدّاد لثلاثين دقيقة، ومؤشر لأربعٍ وعشرين ساعة في المينا؛ وعقرب ثواني الكرونوغراف ينطلق من المركز. ابدأ، أوقف، واقرأ.' , short: 'ثلاثة عدّادات؛ عقرب الكرونوغراف ينطلق من المركز.' },
      crown: { eyebrow: 'التاج والأزرار', title: 'ابدأ. أوقف. أعد الضبط.', line: 'زرّان يحيطان بالتاج: العلوي يبدأ الكرونوغراف ويوقفه، والسفلي يعيده إلى الصفر. والتاج يضبط الوقت والتاريخ.' , short: 'الزر العلوي يبدأ ويوقف، والسفلي يعيد الضبط.' },
      bracelet: { eyebrow: 'السوار', title: 'ستيل، وصلةً وصلة', line: 'سوار من الستانلس ستيل بعرض اثنين وعشرين ملّيمتراً، يُغلق بمشبك قابل للطي، والعلامة محفورة على غطائه.' , short: 'وصلات ستيل، مشبك قابل للطي، والعلامة محفورة.' },
      open: { eyebrow: 'الغطاء الخلفي', title: 'مُحكم الإغلاق ليومٍ كامل', line: 'غطاء خلفي من الستانلس ستيل، محفورٌ عليه أنها مقاومة للماء حتى 3 ATM — ثلاثين متراً — كما تحمله الساعة: يكفي للمطر وغسل اليدين، لا للسباحة.' , short: 'محفورٌ عليه: مقاومة للماء 3 ATM — ثلاثون متراً.' },
      movement: { eyebrow: 'كوارتز', title: 'دقيقة، ولا تحتاج إلى تعبئة', line: 'حركة كوارتز تحفظ الوقت بالثبات الذي عُرف به الكوارتز، ولا تطلب منك شيئاً: لا تعبئة، ولا عناية يومية.' , short: 'كوارتز: ثابتة، ولا تحتاج إلى تعبئة.' },
      parts: { eyebrow: 'كل قطعة', title: 'مبنيّة طبقةً فوق طبقة', line: 'الزجاج والإطار فوق المينا، والعقارب على محاورها، والتاج والأزرار عبر العلبة، والسوار على مساميره — كلُّ قطعةٍ صُنعت لمكانها.' , short: 'كل قطعة صُنعت لمكانها.' },
      together: { eyebrow: 'تعود كما كانت', title: 'ساعة واحدة، جاهزة', line: 'كل قطعة في مكانها. كرونوغراف للمعصم يُقرأ بلمحة ويُلبس اليوم كله.' , short: 'تُقرأ بلمحة وتُلبس اليوم كله.' },
      specs: { eyebrow: 'المواصفات', title: 'السجل', line: 'كل قيمة هنا من سجل المنتج، كما تظهر في صفحة المنتج.' , short: 'قيم سجل المنتج.' },
      final: { eyebrow: 'مجموعة Grand Prix', title: '', line: 'كرونوغراف من الستيل بمقياس تاكيمتر، تحت كريستال ياقوتي.', short: 'كرونوغراف من الستيل بمقياس تاكيمتر، تحت كريستال ياقوتي.' },
    },
    specs: { title: 'المواصفات', line: 'كل قيمة هنا من سجل المنتج، كما تظهر في صفحة المنتج.' },
    box: {
      title: 'مع ساعتك',
      warranty: (n) => `ضمان ${n === 1 ? 'لمدة سنة' : n === 2 ? 'لمدة سنتين' : `لمدة ${String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[Number(d)])} سنوات`} على الحركة ضد عيوب الصناعة`,
      card: 'بطاقة ضمان وفاتورة البيع',
      packaging: 'علبة الماركة والكتالوج',
    },
    close: { title: 'Grand Prix 1513477', line: 'أضفها إلى سلتك، أو انتقل مباشرةً إلى الدفع.' },
    labels: {
      price: 'السعر',
      was: 'بدلاً من',
      off: 'خصم',
      inStock: 'متوفر',
      lowStock: (n) => `لم يتبقَّ سوى ${n}`,
      soldOut: 'غير متوفر', // the product page's label, word for word
      addToCart: 'أضف إلى السلة',
      buyNow: 'اشترِ الآن',
      adding: 'جارٍ الإضافة…',
      added: 'أُضيفت إلى سلتك',
      viewCart: 'عرض السلة',
      productPage: 'صفحة المنتج',
      features: 'المميزات',
      dialColour: 'لون المينا',
      bandColour: 'لون السوار',
      posterAlt: 'Hugo Boss Grand Prix 1513477، الساعة مجمّعة',
      stageAlt: 'الساعة عن قرب مع التمرير',
      bar: 'اشترِ هذه الساعة',
      scroll: 'مرّر',
      step: (n, of) => `الخطوة ${n} من ${of}`,
    },
  },
}
