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
  // the gallery reads `images` as strings or `{ image }`; v2 serves objects with a `src`
  row.images = (p.images || []).map((i) => i?.src).filter(Boolean)
  if (!row.image && row.images.length) row.image = row.images[0]

  return {
    // the compat-shaped payload every existing consumer reads
    payload: { products: [row], ratings: [], images: [], meta: p.meta || null },
    // and what only v2 carries
    specs: p.specs || [],
    variants: p.variants || [],
    breadcrumb: p.breadcrumb || [],
    related: (page.related || []).map(legacyRow),
    v2: page,
  }
}
