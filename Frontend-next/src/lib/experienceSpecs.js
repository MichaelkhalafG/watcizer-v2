import { fromV2Product, specRowsIn } from './v2Product'
import { transformProductData } from '../utils/transformProduct'
import { buildProductSeo } from './detailSeo'
import { PRODUCT_ID, CAPTION_KEYS, CAPTION_FEATURE_IDS, COPY } from '../content/grandPrix1513477'

/*
 * The experience page's model: core's v2 product answer → what the page renders (2026-10-27, the brand model 2026-10-28).
 *
 * Pure functions over the recorded answer, so they are unit-tested (tests/experience.test.mjs) and so the
 * page cannot print a figure that is not in the product record. Three rules, each with a test:
 *
 *   • THE DATABASE WINS, AND ONLY THE DATABASE PRINTS. A spec row the record holds is shown as the record
 *     holds it, label and unit included. A key the record does not hold renders nothing — no placeholder.
 *   • THE PRICE CONTRACT IS THE SHOP'S. `sale` counts only when 0 < sale < selling — the same rule the cart,
 *     the checkout and the card follow (catalogPrice); the page cannot quote a sale the cart would refuse.
 *   • THE CART LINE IS THE PRODUCT PAGE'S, FIELD FOR FIELD. Express stock before market, the colour finish
 *     as its hexes joined by '/', the English name for analytics — so the line this page adds is the line
 *     the product page adds, and the existing AddToCart event fires from the store exactly as it does there.
 */

const txt = (o, lang) => (o && typeof o === 'object' ? (o[lang] ?? o.en ?? '') : o == null ? '' : String(o))

const unitText = (unit, lang) => (!unit ? '' : typeof unit === 'string' ? unit : unit.name?.[lang] || unit.name?.en || unit.code || '')

/** One recorded spec's value in `lang`, unit appended; '' when the row carries nothing. */
export const specText = (spec, lang) => {
  if (!spec) return ''
  const v = spec.value
  if (v === null || v === undefined || v === '') return ''
  let value
  if (typeof v === 'object') value = txt(v, lang)
  else if (typeof v === 'boolean') value = v ? (lang === 'ar' ? 'نعم' : 'Yes') : lang === 'ar' ? 'لا' : 'No'
  else value = String(v)
  if (!value) return ''
  if (lang === 'ar') value = arabicDigits(value) // one digit system on the Arabic page (Michael, 2026-10-29)
  const unit = unitText(spec.unit, lang)
  return unit ? `${value} ${unit}` : value
}

/** Latin digits → Arabic-Indic, the digits the Arabic storefront prints its prices in. */
export const arabicDigits = (s) => String(s).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[Number(d)])

/** The recorded specs keyed by their spec key. */
export const specByKey = (specs) => Object.fromEntries((Array.isArray(specs) ? specs : []).filter((s) => s && s.key).map((s) => [s.key, s]))

const both = (fn) => ({ en: fn('en'), ar: fn('ar') })

/** A recorded spec as a fact row the page prints: `{ key, label: {en, ar}, value: {en, ar} }`, or null when empty. */
const factOf = (spec) => {
  if (!spec) return null
  const value = both((l) => specText(spec, l))
  if (!value.en && !value.ar) return null
  return { key: spec.key, label: { en: spec.label?.en || spec.key, ar: spec.label?.ar || spec.label?.en || spec.key }, value }
}

const colourNames = (list) => both((l) => (Array.isArray(list) ? list : []).map((c) => txt(c?.name, l)).filter(Boolean).join(l === 'ar' ? '، ' : ', '))

/** The finish the cart line records for a colour role: the hexes in order, joined by '/', or null (the product page's rule). */
const finishOf = (list) => {
  const hexes = (Array.isArray(list) ? list : []).map((c) => c?.hex).filter(Boolean)
  return hexes.length ? hexes.join('/') : null
}

/**
 * The page's model from core's v2 product answer, or null when the answer is not THIS product.
 * @param {object} page  `{ product, related, locale }` as `GET /api/v2/watchizer/products/{slug}` answers
 */
export function experienceModel(page) {
  const p = page?.product
  if (!p || Number(p.id) !== PRODUCT_ID) return null

  const amount = Number(p.price?.amount || 0)
  const saleAmount = Number(p.price?.sale_amount || 0)
  const hasSale = saleAmount > 0 && saleAmount < amount
  const final = hasSale ? saleAmount : amount
  const discountPct = hasSale ? Math.round((1 - saleAmount / amount) * 100) : 0

  const express = Number(p.stock?.express || 0)
  const market = Number(p.stock?.market || 0)

  const byKey = specByKey(p.specs)
  const colours = p.attributes?.colors || {}
  const features = (Array.isArray(p.attributes?.features) ? p.attributes.features : []).map((f) => ({ id: Number(f?.id), name: both((l) => txt(f?.name, l)) }))
  const featureRows = (ids) => (ids || []).map((id) => features.find((f) => f.id === id)).filter(Boolean)

  const rowsFor = (keys) => (keys || []).map((k) => factOf(byKey[k])).filter(Boolean)
  const colourRow = (key, label, list) => {
    const value = colourNames(list)
    return value.en || value.ar ? { key, label, value } : null
  }
  const dialColour = colourRow('dial_colour', { en: 'Dial colour', ar: 'لون المينا' }, colours.dial)
  const bandColour = colourRow('band_colour', { en: 'Band colour', ar: 'لون السوار' }, [...(colours.band || []), ...(colours.main || [])])

  // The facts each caption prints — recorded specs and recorded colours only, in CAPTION_KEYS order.
  const captions = {}
  for (const id of Object.keys(CAPTION_KEYS)) {
    const facts = rowsFor(CAPTION_KEYS[id])
    if (id === 'bracelet' && bandColour) facts.splice(1, 0, bandColour)
    if (id === 'dial' && dialColour) facts.unshift(dialColour)
    captions[id] = { facts, features: featureRows(CAPTION_FEATURE_IDS[id]) }
  }

  const images = (Array.isArray(p.images) ? p.images : [])
    .filter((i) => i && typeof i.src === 'string' && i.src)
    .map((i) => ({ src: i.src, srcset: Array.isArray(i.srcset) ? i.srcset : [], cover: !!i.is_cover }))
    .sort((a, b) => Number(b.cover) - Number(a.cover))

  return {
    id: Number(p.id),
    slug: String(p.slug || ''),
    productPath: `/product/${p.slug}`,
    title: both((l) => txt(p.title, l)),
    brand: both((l) => txt(p.brand?.name, l)),
    modelNumber: p.model_number ? String(p.model_number) : '',
    price: { amount, saleAmount, hasSale, final, discountPct, currency: p.price?.currency || 'EGP' },
    stock: { express, market, inStock: express > 0 || market > 0, total: express + market },
    finish: { dial: finishOf(colours.dial), band: finishOf([...(colours.band || []), ...(colours.main || [])]) },
    images,
    captions,
    features,
    warrantyYears: p.warranty_years ? Number(p.warranty_years) : null,
    // the product page's table, row for row; on the Arabic page every figure in Arabic-Indic digits except the model
    // number, which is part of the English product name and stays Latin (Michael, 2026-10-29)
    specRows: both((l) => {
      const rows = specRowsIn(p.specs, l, p)
      if (l !== 'ar') return rows
      const modelNumber = p.model_number ? String(p.model_number) : null
      return rows.map((r) => (typeof r.value === 'string' && r.value !== modelNumber ? { ...r, value: arabicDigits(r.value) } : r))
    }),
  }
}

/**
 * The cart line this page adds — the product page's payload for a product without variants, field for field.
 * `name` is for analytics only (the store resolves the real name); it is the English title, as there.
 */
export const cartLineFor = (m, quantity = 1) => ({
  product_id: m.id,
  quantity,
  piece_price: m.price.final,
  type_stock: m.stock.express > 0 ? 'Express' : 'Market',
  color_band: m.finish.band,
  color_dial: m.finish.dial,
  name: m.title.en,
})

/**
 * The Product structured data of THIS page = the product page's, by construction: the same adapter, the same
 * transform and the same builder the product route runs (app/(main)/product/[slug]/page.jsx → resolveFromV2),
 * over the same v2 answer and the same lookup tables. Its `offers.url` names the product page, where the
 * offer lives; this page's own address goes in its breadcrumb, never into a second, competing offer.
 */
export function productSeoFor(page, lang, tables = {}) {
  const adapted = fromV2Product(page)
  if (!adapted) return null
  const [product] = transformProductData(adapted.payload.products, tables, adapted.payload.ratings, adapted.payload.images, 'en')
  if (!product) return null
  const localized = transformProductData(adapted.payload.products, tables, adapted.payload.ratings, adapted.payload.images, lang)[0] ?? null
  return buildProductSeo(product, { ratings: [], tables, lang, localized, meta: adapted.payload.meta })
}

/**
 * The home banner's facts (2026-10-29): the price, the stock and three facts — the case size, the chronograph, the
 * glass — all from the record, as plain data the server hands to the banner (no fetch in the browser, no shift).
 * Null when the answer is not this product: the banner then shows its words without price, stock or facts.
 * @param {object} page `{ product }` as core's v2 product answer
 */
export function bannerModel(page) {
  const m = experienceModel(page)
  if (!m) return null
  const byKey = specByKey(page.product.specs)
  const fact = (spec) => (spec ? both((l) => specText(spec, l)) : null)
  const chrono = m.features.find((f) => f.id === 2) || null // core's feature 2 is Chronograph
  const facts = [fact(byKey.case_size), chrono ? chrono.name : null, fact(byKey.glass_material)].filter((f) => f && f.en && f.ar)
  return {
    price: { final: m.price.final, amount: m.price.amount, hasSale: m.price.hasSale, currency: m.price.currency },
    stock: { total: m.stock.total, inStock: m.stock.inStock },
    facts,
    productPath: m.productPath,
  }
}

/**
 * The "with your watch" lines (the client's facts, 2026-10-29): the product's OWN warranty length, the warranty card and
 * sales invoice, the brand box and catalogue — or [] when the record carries no warranty length, and nothing renders.
 * @param {{ warrantyYears: number|null }} model
 * @param {'en'|'ar'} lang
 */
export function boxLines(model, lang) {
  const n = Number(model?.warrantyYears)
  const box = COPY[lang]?.box
  if (!box || !Number.isFinite(n) || n <= 0) return []
  return [box.warranty(n), box.card, box.packaging]
}
