/*
 * A PRODUCT'S <title> AND META DESCRIPTION, from the catalogue (2026-11-14, SEO Phase 1 item 3). A TWIN.
 *
 * The title: brand + model name + model number + gender + type, in the page's language, never a word the record does
 * not hold — "Hugo Boss Grand Prix 1513477 Men's Chronograph Watch" / «ساعة هوجو بوس جراند بري 1513477 كرونوغراف رجالي».
 * The type is the product's sub-category (a watch's Chronograph, Casual, Diver…; a belt's Belts → "Belt").
 *
 *  - A product with neither a model number nor a model name (most electronics) keeps ITS OWN title — a generated
 *    one would read "Joyroom Charger" for twenty chargers — with its brand added where the title lacks it: in front
 *    in English, after the first word in Arabic, as the generated Arabic titles place it («شاحن JR-TCF02» →
 *    «شاحن جوي روم JR-TCF02»; Search Console's Joyroom duplicates were these thin Arabic titles).
 *  - A product the shop titles "Copy" or "Style" is left EXACTLY as it is (Michael): `null`, and the caller keeps its
 *    old title and description.
 *
 * The description (≤ 155 characters, a template per family): what it is, one or two attributes from the record, the
 * price, the warranty the product carries, delivery within 24 hours, cash on delivery, 14-day free returns. Never a
 * free-shipping threshold. Pieces are dropped to fit: the pre-sale price, then the attributes, then the warranty.
 */

const MAX_DESCRIPTION = 155

const t = (v, lang) => (v && typeof v === 'object' ? v[lang] || v.en || '' : v || '').toString().replace(/\s+/g, ' ').trim()
const AR_DIGITS = (s) => String(s).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[Number(d)])

// the shop names the title ends with
export const SHOP_NAMES = {
  watchizer: { en: 'Watchizer', ar: 'واتشايزر' },
  brandfashion: { en: 'Brand Fashion', ar: 'براند فاشون' },
}

// a sub-category name as the NOUN of the title, for the families whose sub-category is the thing itself; a watch is
// always a "Watch" and its sub-category is its type
const NOUNS = {
  bags: ['Bag', 'حقيبة'], belts: ['Belt', 'حزام'], wallets: ['Wallet', 'محفظة'], perfumes: ['Perfume', 'عطر'],
  sunglasses: ['Sunglasses', 'نظارة شمسية'], jewelry: ['Jewellery', 'مجوهرات'], bracelets: ['Bracelet', 'سوار'],
  keychains: ['Keychain', 'ميدالية مفاتيح'], caps: ['Cap', 'قبعة'], slippers: ['Slippers', 'شبشب'], shoes: ['Shoes', 'حذاء'],
  socks: ['Socks', 'شراب'], scarves: ['Scarf', 'وشاح'], ties: ['Tie', 'كرافتة'], cufflinks: ['Cufflinks', 'أزرار أكمام'],
  pen: ['Pen', 'قلم'], 'power-banks': ['Power Bank', 'باور بانك'], 'chargers-cables': ['Charger', 'شاحن'],
  'earphones-headphones': ['Earphones', 'سماعة'], 'phone-holders': ['Phone Holder', 'حامل هاتف'],
  'screen-protectors': ['Screen Protector', 'واقي شاشة'],
}
const FAMILY_NOUNS = { watch: ['Watch', 'ساعة'], bag: ['Bag', 'حقيبة'], wallet: ['Wallet', 'محفظة'], perfume: ['Perfume', 'عطر'] }

// gender, as a title says it; two or more genders (or Unisex) = unisex
const GENDER = { men: ["Men's", 'رجالي'], women: ["Women's", 'نسائي'], kids: ["Kids'", 'للأطفال'], unisex: ['Unisex', 'للجنسين'] }

const slugOf = (s) => String(s || '').toLowerCase().replace(/&/g, ' ').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')

/** True for a product the shop titles "Copy" or "Style" — left exactly as it is. */
export function isCopyOrStyle(product) {
  return /\b(copy|style)\b/i.test(t(product?.title, 'en')) || /(كوبي|ستايل)/.test(t(product?.title, 'ar'))
}

function genderOf(product) {
  const names = [...new Set((product?.attributes?.genders || []).map((g) => slugOf(t(g?.name, 'en'))).filter(Boolean))]
  if (names.length === 1) return GENDER[names[0]] || null
  if (names.length > 1 || names.includes('unisex')) return GENDER.unisex
  return null
}

/** The product's sub-category (depth 2): the primary one, else the first. */
function subCategoryOf(product) {
  const cats = (product?.categories || []).filter((c) => Number(c?.depth) === 2)
  return cats.find((c) => c.is_primary) || cats[0] || null
}

/** [noun, type] in both languages, or null when the record does not say what the product is. */
function kindOf(product) {
  const sub = subCategoryOf(product)
  if (product?.family === 'watch') {
    if (!sub) return { noun: FAMILY_NOUNS.watch, type: null }
    const en = t(sub.name, 'en').replace(/\s*watch(es)?$/i, '')
    // «ساعات كاجوال» → «كاجوال»: the noun is already «ساعة»
    const ar = t(sub.name, 'ar').replace(/^ساعات\s+/, '').replace(/^ساعة\s+/, '')
    return { noun: FAMILY_NOUNS.watch, type: en ? [en, ar || null] : null }
  }
  const noun = (sub && NOUNS[slugOf(t(sub.name, 'en'))]) || FAMILY_NOUNS[product?.family] || null
  return noun ? { noun, type: null } : null
}

const modelNumberOf = (product) => String(product?.model_number || '').trim().toUpperCase() || null

// the catalogue's placeholder brand («غير محدد») names no maker: it is left out of a title (2026-11-14 sample)
const isPlaceholderBrand = (product) => /^(generic|no brand|unbranded)$/i.test(t(product?.brand?.name, 'en'))

const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/**
 * The model NAME as a title can use it: without the brand and the model number it often repeats ("Tommy Hilfiger
 * Owen" → "Owen"; "Hugo Boss 1513878" → ""), and dropped when what is left is a marketplace phrase rather than a
 * name (over 24 characters: "Longchamp Energy L Handbag").
 */
function modelNameOf(product, lang, number) {
  let m = t(product?.model_name, lang)
  if (!m) return ''
  for (const cut of [t(product?.brand?.name, 'en'), t(product?.brand?.name, 'ar'), number].filter(Boolean)) {
    m = m.replace(new RegExp(escape(cut), 'gi'), ' ')
  }
  m = m.replace(/\s+/g, ' ').replace(/^[\s\-–—,|]+|[\s\-–—,|]+$/g, '').trim()
  // «طقم محفظة وحزام من تومي هيلفيغر» without the brand ends in a dangling «من»
  m = m.replace(/\s+(من|by|from)$/i, '').trim()
  return m.length > 24 ? '' : m
}

/**
 * The words of the title, without the shop name; null when the caller keeps the product's own title (Copy/Style)
 * or when nothing can be built.
 */
export function productName(product, lang) {
  if (!product || isCopyOrStyle(product)) return null
  const ar = lang === 'ar'
  const placeholder = isPlaceholderBrand(product)
  const brandEn = placeholder ? '' : t(product.brand?.name, 'en')
  const brand = placeholder ? '' : t(product.brand?.name, lang) || brandEn
  const number = modelNumberOf(product)
  const model = modelNameOf(product, lang, number)
  const kind = kindOf(product)

  // nothing that tells two products of a brand apart: the product's own title, with its brand — in Arabic just
  // before the model code («سماعة رأس لاسلكي جوي روم JR-JH1»; after the first word split «سماعة رأس»), else at the end
  if (!number && !model) {
    const own = t(product.title, lang).replace(/^(generic|غير محدد)\s+/i, '')
    if (!own) return null
    const has = [brand, brandEn].some((b) => b && own.toLowerCase().includes(b.toLowerCase()))
    if (has || !brand) return own
    if (!ar) return `${brand} ${own}`
    const words = own.split(' ')
    const code = words.findIndex((w) => /\d/.test(w) && /[A-Za-z]/.test(w))
    return code > 0 ? [...words.slice(0, code), brand, ...words.slice(code)].join(' ') : `${own} ${brand}`
  }
  if (!kind) return null
  const gender = genderOf(product)
  const parts = ar
    ? [kind.noun[1], brand, model, number, kind.type?.[1], gender?.[1]]
    : [brand, model, number, gender?.[0], kind.type?.[0], kind.noun[0]]
  return parts.filter(Boolean).join(' ').replace(/\s+/g, ' ').trim()
}

/** The <title>: the name and the shop. */
export function productTitle(product, lang, shop) {
  const name = productName(product, lang)
  return name ? `${name} | ${SHOP_NAMES[shop]?.[lang === 'ar' ? 'ar' : 'en'] || ''}`.replace(/\s\|\s$/, '') : null
}

/**
 * The product's material as structured data says it (2026-11-14, SEO Phase 1 item 2): a bag's or a wallet's
 * `material`, else a watch's case and band materials ("Stainless Steel / Leather"), from v2's specs, in the page's
 * language — exactly what the specification table shows. null when the record names none.
 */
export function productMaterial(product, lang) {
  const specs = Array.isArray(product?.specs) ? product.specs : []
  const name = (key) => {
    const v = specs.find((r) => r?.key === key)?.value
    return v && typeof v === 'object' ? t(v.name || v, lang) : ''
  }
  // core serves a family's material under its stored key, `material_id` (as a named reference)
  const own = name('material_id') || name('material')
  if (own) return own
  return [...new Set([name('case_material'), name('band_material')].filter(Boolean))].join(' / ') || null
}

// What a photo shows, as an alt text says it (2026-11-14, SEO Phase 1 item 9; core's config catalog.image_views)
const VIEW_WORDS = {
  front: ['front', 'من الأمام'], side: ['side view', 'من الجانب'], back: ['back', 'من الخلف'],
  dial: ['dial close-up', 'الميناء عن قرب'], clasp: ['clasp', 'القفل'], wrist: ['on the wrist', 'على المعصم'],
  worn: ['worn', 'أثناء الارتداء'], box: ['box and papers', 'العلبة والأوراق'], inside: ['inside', 'من الداخل'],
  detail: ['detail', 'تفصيلة'],
}

/**
 * ONE photo's alt text (2026-11-14, SEO Phase 1 item 9: the baseline found the same alt on every photo — "… - image
 * 2", "… - image 3" — and Brand Fashion's photos after the first with alt=""). In this order, and only from the record:
 *  1. the team's own alt text for this photo, in the page's language (the dashboard's gallery);
 *  2. the product's name and what the photo shows ("Hugo Boss 1513477 Men's Chronograph Watch, on the wrist");
 *  3. the name and the colour of the variant the photo belongs to;
 *  4. the name alone for the first photo, then "photo 2 of 5".
 * `image` is one of v2's product images ({ alt_text, view, variant_id }); `product` is v2's product.
 */
export function imageAlt(product, image, index, total, lang) {
  const ar = lang === 'ar'
  const own = t(image?.alt_text, lang)
  // an English page never takes Arabic letters as its alt (a row typed into the wrong box)
  if (own && (ar || !/\p{Script=Arabic}/u.test(own))) return own
  const name = productName(product, lang) || t(product?.title, lang)
  const sep = ar ? '، ' : ', '
  const view = VIEW_WORDS[image?.view]
  if (view) return `${name}${sep}${view[ar ? 1 : 0]}`
  const variant = image?.variant_id != null ? (product?.variants || []).find((v) => v?.id === image.variant_id) : null
  const colour = t(variant?.color?.name, lang)
  // a photo's place, so two photos of one colour never share an alt (2026-11-14: three black ones did)
  const place = ar ? `صورة ${index + 1} من ${total}` : `photo ${index + 1} of ${total}`
  if (colour) return [name, ar ? colour : colour.toLowerCase(), index ? place : ''].filter(Boolean).join(sep)
  return index ? `${name}${sep}${place}` : name
}

/** One or two attributes the record holds, in the page's language. */
function attributesOf(product, lang) {
  const out = []
  if (product?.family === 'watch') {
    // a feature that IS the type is not said twice ("Chronograph Watch: chronograph")
    const type = (kindOf(product)?.type || []).map((x) => String(x || '').toLowerCase())
    for (const f of product?.attributes?.features || []) {
      const v = t(f?.name, lang)
      if (!v || type.includes(v.toLowerCase())) continue
      out.push(lang === 'ar' ? v : v.toLowerCase())
      if (out.length === 2) break
    }
  } else {
    const colours = [...(product?.attributes?.colors?.main || []), ...(product?.attributes?.colors?.band || [])]
    const c = colours.map((x) => t(x?.name, lang)).filter(Boolean)[0]
    if (c) out.push(lang === 'ar' ? c : c.toLowerCase())
  }
  return out
}

const egp = (n) => Math.round(Number(n)).toLocaleString('en-US')

/**
 * The meta description; null for a Copy/Style product (the caller keeps its own). `price` = { now, was } in EGP
 * (was only for a valid sale); `warrantyYears` = the warranty core says the product carries.
 */
export function productDescription(product, lang, { price = null, warrantyYears = null } = {}) {
  const name = productName(product, lang)
  if (!name) return null
  const ar = lang === 'ar'
  const attrs = attributesOf(product, lang)
  const years = Number(warrantyYears)
  const warranty = Number.isFinite(years) && years > 0
    ? ar ? `ضمان ${years === 1 ? 'سنة واحدة' : years === 2 ? 'سنتين' : `${AR_DIGITS(years)} سنوات`}` : `${years}-year warranty`
    : null
  const now = Number(price?.now)
  const was = Number(price?.was)
  const priceText = (withWas) => now > 0
    ? ar
      ? `السعر ${egp(now)} ج.م${withWas && was > now ? ` بدلًا من ${egp(was)}` : ''}`
      : `EGP ${egp(now)}${withWas && was > now ? ` (was ${egp(was)})` : ''}`
    : null
  const promise = ar
    ? 'توصيل خلال ٢٤ ساعة، دفع عند الاستلام، استرجاع مجاني ١٤ يومًا.'
    : 'Delivery in 24 hours, cash on delivery, free 14-day returns.'

  // what goes first when it does not fit: the price before the sale, then the attributes one by one, then the
  // warranty (the brief's list — attributes, warranty, the promise, the price — has no pre-sale price in it)
  const build = (attrCount, withWas, withWarranty, label = name) => {
    const shown = attrs.slice(0, attrCount)
    const head = shown.length ? `${label}: ${shown.join(ar ? '، ' : ', ')}.` : label.endsWith('…') ? label : `${label}.`
    const mid = [priceText(withWas), withWarranty ? warranty : null].filter(Boolean).join(ar ? '، ' : ', ')
    return [head, mid ? `${mid}.` : '', promise].filter(Boolean).join(' ')
  }
  for (const [a, was, w] of [[2, true, true], [2, false, true], [1, false, true], [0, true, true], [0, false, true], [0, false, false]]) {
    const s = build(a, was, w)
    if (s.length <= MAX_DESCRIPTION) return s
  }
  // still too long (a very long own title): the NAME is shortened at a word, so the price, the warranty and the
  // promise stay whole
  const room = MAX_DESCRIPTION - build(0, false, true, '').length - 1
  const short = name.slice(0, Math.max(room, 0)).replace(/\s+\S*$/, '').replace(/[\s,،\-–—]+$/, '')
  return build(0, false, true, `${short}…`).slice(0, MAX_DESCRIPTION)
}
