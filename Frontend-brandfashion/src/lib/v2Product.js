import { legacyRow } from './v2Listing'

// ONE product from core's v2 API — `/api/v2/{storefront}/products/{slug}` — adapted to the shape the product
// page and ProductDetailClient already read (2026-10-23, slice 1's second half).
//
// A TWIN: both storefronts can read it, as they do v2Listing.js. Watchizer's product page uses it; Brand
// Fashion's own product page is BfProductPage and reads v2 directly, but its OFFER page renders the same
// ProductDetailClient on compat, which is why nothing here is required of either shop.
//
// WHY AN ADAPTER AND NOT A REWRITE. ProductDetailClient is a twin of ~1,300 lines, rendered by three routes:
// Watchizer's product page (moving here), Watchizer's offer page and Brand Fashion's offer page (both still on
// compat, offers being slice 6). Rewriting it to read v2 would have changed what the two offer pages see.
// Mapping v2 onto the shape it already reads changes nothing for them, which is the same decision, and the
// same reason, as v2Listing.js for the listing.
//
// WHAT v2 GIVES THAT COMPAT DID NOT, and why this removes a request:
//   • compat needed TWO reads — the server's `catalog/product` for the card and SEO, then the BROWSER's
//     `products/{id}` for specs, gallery and descriptions. v2's detail payload is the superset of both, so
//     the server hands the whole thing down and the browser asks for nothing.
//   • `specs[]` arrives already built and labelled by core: `{key, label:{en,ar}, value, unit}`. It is passed
//     through RAW, not localized here, because the shopper can switch language without a refetch and the
//     component holds that state.
//   • `variants[]`, `breadcrumb[]` and `meta` come with it.

/** A v2 unit block (or a bare code) as the suffix to print after a value. */
const unitText = (unit, lang) => {
  if (!unit) return ''
  if (typeof unit === 'string') return unit
  return unit.name?.[lang] || unit.name?.en || unit.code || ''
}

/** A v2 spec value in one language: a localized object, or a scalar that is the same in both. */
const specValue = (value, lang) => {
  if (value === null || value === undefined) return ''
  if (typeof value === 'object') return value[lang] ?? value.en ?? ''
  // Core serves a flag as a word per locale since 2026-10-23. This branch is for a core that predates that,
  // so a rolled-back deploy prints Yes rather than the literal "true".
  if (typeof value === 'boolean') return value ? (lang === 'ar' ? 'نعم' : 'Yes') : lang === 'ar' ? 'لا' : 'No'
  return String(value)
}

/** A list of v2 lookup refs, or one localized object, as a readable value. */
const refText = (v, lang) => {
  if (!v) return ''
  const one = (x) => (typeof x === 'object' ? (x?.name?.[lang] ?? x?.name?.en ?? x?.[lang] ?? x?.en ?? '') : String(x ?? ''))
  return (Array.isArray(v) ? v.map(one) : [one(v)]).filter(Boolean).join(lang === 'ar' ? '، ' : ', ')
}

/**
 * v2's `specs[]` → the `{ label, value }` rows the product page's spec table renders, in `lang`.
 *
 * The rows keep core's ORDER (watch specs first, then the family specs), which is the order the dashboard's
 * own form presents them in.
 *
 * THEN FIVE ROWS THAT ARE NOT IN `specs[]` AT ALL, and have to be, or the table loses them (measured
 * 2026-10-23, before the move): Dial colour, Band colour, Country, Stone and Warranty. Core serves every one
 * of them — colours under `attributes.colors` by role, the other three as their own fields — it just does not
 * call them specs, because Brand Fashion renders the colours as SWATCHES and would show them twice.
 *
 * It is a real loss, not a nicety: 936 of Watchizer's 1,014 visible products carry a colour row and 523 carry
 * a warranty. So the composition of the table is done here, per storefront, over data core already sends.
 * The five land at the END rather than interleaved with their materials as they were on compat — same rows,
 * same values, five of them further down; a one-line reorder if that matters.
 */
export function specRowsIn(specs, lang, product = null) {
  const isAr = lang === 'ar'
  const l = isAr ? 'ar' : 'en'
  const out = []
  const add = (label, value) => {
    if (label && value !== '' && value !== null && value !== undefined) out.push({ label, value })
  }
  /*
   * THE EIGHT ROWS COMPAT'S TABLE OPENED WITH — Brand, Category, Type, Gender, Grade, Model Name, Model
   * Number, Features — in compat's order, BEFORE core's specs (2026-10-21, found by diffing the live page's
   * table against what compat's `products/{id}` drew: core's `specs[]` are the watch and family specs, and
   * these eight live elsewhere in the same payload — `brand`, `categories`, `primary_category`,
   * `attributes.genders`, `grade`, `model_name`, `model_number`, `attributes.features`). The live table had
   * 17 rows where compat drew 25; the eight were lost in the move, not by a decision.
   *
   * compat's Category was the category TYPE (Watches) and its Type the sub-type (Chronograph); v2's
   * depth-1 node and primary node are the same two names.
   */
  if (product) {
    const cats = Array.isArray(product.categories) ? product.categories : []
    const top = cats.find((c) => c?.depth === 1) || null
    const primary = product.primary_category || cats.find((c) => c?.is_primary) || null
    add(isAr ? 'الماركة' : 'Brand', refText(product.brand, l))
    add(isAr ? 'الفئة' : 'Category', refText(top, l))
    if (primary && (!top || primary.id !== top.id)) add(isAr ? 'النوع' : 'Type', refText(primary, l))
    add(isAr ? 'الجنس' : 'Gender', refText(product.attributes?.genders, l))
    add(isAr ? 'التصنيف' : 'Grade', refText(product.grade, l))
    add(isAr ? 'اسم الموديل' : 'Model Name', refText(product.model_name, l))
    add(isAr ? 'رقم الموديل' : 'Model Number', product.model_number || '')
    add(isAr ? 'المميزات' : 'Features', refText(product.attributes?.features, l))
  }
  for (const row of specs || []) {
    const label = row?.label?.[l] || row?.label?.en || ''
    const value = specValue(row?.value, l)
    if (!label || value === '' || value === null) continue
    const unit = unitText(row?.unit, l)
    add(label, unit ? `${value} ${unit}` : value)
  }
  if (!product) return out
  const colors = product.attributes?.colors || {}
  add(isAr ? 'لون الميناء' : 'Dial Colour', refText(colors.dial, l))
  add(isAr ? 'لون السوار' : 'Band Colour', refText(colors.band, l))
  add(isAr ? 'اللون' : 'Colour', refText(colors.main, l))
  add(isAr ? 'بلد المنشأ' : 'Country', refText(product.country, l))
  add(isAr ? 'الحجر' : 'Stone', refText(product.stone, l))
  if (product.warranty_years) add(isAr ? 'الضمان' : 'Warranty', `${product.warranty_years} ${isAr ? 'سنة' : 'yrs'}`)
  return out
}

/**
 * A v2 product detail page → what the product route needs.
 *
 * `payload` is compat's cards shape — `{ products: [row], ratings, images }` — so `transformProductData`,
 * `localizedProduct`, `buildProductSeo` and `useCardsOf` all keep working byte for byte. Everything v2 adds
 * rides alongside it.
 */
export function fromV2Product(page) {
  const p = page?.product
  if (!p) return null

  const row = legacyRow(p)
  /*
   * WHAT THE PAGE READS OFF THE LEGACY ROW, AND WHERE IT WAS LOST (2026-10-21, a live regression).
   *
   * `transformProductData` is what every consumer runs over this payload, and it does NOT keep a row's own
   * `images`: it rebuilds them from the payload's `images` argument — compat's gallery rows, `{product_id,
   * image}` — and `getColors` / the translations likewise rebuild colours and texts from the row's compat
   * fields. The first cut set `row.images` (overwritten to `[]`), left the colour pivots empty and the
   * translations at title + short text, so the live product page drew ONE picture, no swatches and the
   * short description where the long one had been. Measured on the live page and against compat's answer
   * for the same product (8 images, two finishes with hex, a 654-character description): every one of
   * these is in v2's answer, under its own name, and is mapped here into the shape the transform rebuilds
   * from.
   */
  const txt = (o, locale) => (o && typeof o === 'object' ? o[locale] ?? o.en ?? '' : o ?? '')
  const refName = (r, locale) => (r && typeof r === 'object' ? txt(r.name, locale) : '')
  row.translations = ['en', 'ar'].map((locale) => ({
    locale,
    product_title: txt(p.title, locale),
    short_description: txt(p.short_description, locale),
    long_description: txt(p.long_description, locale),
    model_name: txt(p.model_name, locale),
    country: Array.isArray(p.country) ? p.country.map((c) => refName(c, locale)).filter(Boolean).join(', ') : refName(p.country, locale),
    stone: Array.isArray(p.stone) ? p.stone.map((c) => refName(c, locale)).filter(Boolean).join(', ') : refName(p.stone, locale),
  }))
  // a colour ref → compat's pivot row: `getColors` reads id, color_value and the translations' color_name
  const colourRow = (c) => ({
    id: c?.id ?? null,
    color_id: c?.id ?? null,
    color_value: c?.hex ?? null,
    name: txt(c?.name, 'en'),
    name_ar: txt(c?.name, 'ar'),
    color_name_en: txt(c?.name, 'en'),
    color_name_ar: txt(c?.name, 'ar'),
    translations: ['en', 'ar'].map((locale) => ({ locale, color_id: c?.id ?? null, color_name: txt(c?.name, locale) })),
  })
  const colours = p.attributes?.colors || {}
  row.dial_color = (colours.dial || []).map(colourRow)
  // a non-watch product's one colour is stored under `band` (the schema wart, backlog 2026-10-23), which is
  // exactly what the page's fashion branch reads as the product's single colour
  row.band_color = [...(colours.band || []), ...(colours.main || [])].map(colourRow)
  // a lookup ref → compat's pivot row with translations: `transformProductData` reads `translations[].feature_name`
  const named = (refs, key) =>
    (Array.isArray(refs) ? refs : []).map((r) => ({
      id: r?.id ?? null,
      [key]: txt(r?.name, 'en'),
      translations: ['en', 'ar'].map((locale) => ({ locale, [key]: txt(r?.name, locale) })),
    }))
  row.feature = named(p.attributes?.features, 'feature_name')
  row.gender = named(p.attributes?.genders, 'gender_name')
  // `isFashion` tests the category TYPE's name; compat's was "Fashion", v2's family is the same classification
  row.category_type_name = p.family ?? null
  row.average_rate = p.rating?.avg ?? null
  // the gallery, in the ONE shape the transform rebuilds `images` from: compat's rows, `{ product_id, image }`.
  // v2 lists the cover first (compat listed the gallery rows and the cover separately); the page dedups by url.
  const gallery = (p.images || []).filter((i) => i?.src).map((i) => ({ id: i.id ?? null, product_id: p.id, image: i.src, is_cover: !!i.is_cover }))
  row.images = gallery.map((i) => i.image)
  if (!row.image && row.images.length) row.image = row.images[0]

  return {
    // the compat-shaped payload every existing consumer reads
    payload: { products: [row], ratings: [], images: gallery, meta: p.meta || null },
    // and what only v2 carries
    specs: p.specs || [],
    variants: p.variants || [],
    breadcrumb: p.breadcrumb || [],
    related: (page.related || []).map(legacyRow),
    v2: page,
  }
}
