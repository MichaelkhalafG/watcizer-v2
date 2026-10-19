// Shared (de)serialization of listing filters ↔ URL query params, so Nav,
// CategoryTiles and the Listing page all speak the same URL language.
//
// URLs use readable SLUGS (e.g. ?brand=rolex), resolved against `tables`.
// Bare numeric ids are still accepted on parse (backward compatible).
//
// NOTE on types:
//   brand/subType/category/dialColor/bandColor/material/movement → table ids
//     (serialized as slugs; resolved back to ids via `tables`)
//   gender → English NAME string ('Men'/'Women'…), not a table id, so it stays
//     a readable name verbatim (no slug round-trip table exists for genders).

import {
  brandSlug,
  subTypeSlug,
  categorySlug,
  colorSlug,
  materialSlug,
  movementSlug,
  shapeSlug,
  displayTypeSlug,
  fromSlug,
} from './slugs'

const PRICE_MAX = 99999999
// The Sale section's minimum discounts (2026-10-19); any other value in a URL is no minimum.
export const DISCOUNTS = [20, 30, 40]

// filters: { brands, subTypes, categories, genders, dialColors, bandColors,
//            materials, movements, offers, price }
// extra:   { q, sort, page }
// tables:  the MyContext tables object (for id → slug). May be null/empty during
//          initial async load — then ids are emitted as a fallback.
export function buildListingParams(filters = {}, extra = {}, tables = null) {
  const p = new URLSearchParams()

  const appendSlugged = (key, ids, list, slugFn) => {
    ;(ids || []).forEach((id) => {
      const item = list?.find((i) => i.id === id)
      p.append(key, item ? slugFn(item) : String(id))
    })
  }

  appendSlugged('brand', filters.brands, tables?.brands, brandSlug)
  appendSlugged('subType', filters.subTypes, tables?.subTypes, subTypeSlug)
  appendSlugged('category', filters.categories, tables?.categoryTypes, categorySlug)
  ;(filters.genders || []).forEach((g) => p.append('gender', g)) // readable name verbatim
  appendSlugged('dialColor', filters.dialColors, tables?.colors, colorSlug)
  appendSlugged('bandColor', filters.bandColors, tables?.colors, colorSlug)
  appendSlugged('material', filters.materials, tables?.materials, materialSlug)
  appendSlugged('movement', filters.movements, tables?.movementTypes, movementSlug)
  appendSlugged('shape', filters.shapes, tables?.shapes, shapeSlug)
  appendSlugged('displayType', filters.displayTypes, tables?.displayTypes, displayTypeSlug)
  ;(filters.grades || []).forEach((g) => p.append('grade', g)) // grade id (numeric)
  // Brand Fashion (DIVERGED 2026-10-18, round 5): SALE has its own key — a product's own discount, core's
  // `on_sale`. `offers` is not a listing filter here: Offers are the dashboard's offers, a page (/offers).
  if (filters.sale) p.set('sale', '1')
  // The Sale page's two keys (2026-10-19, Michael): the minimum discount (20 / 30 / 40 %, the sidebar's Sale
  // section — v2's `min_discount`) and in-stock only (the deals band's "See all": 30 %+ and in stock).
  if (filters.sale && DISCOUNTS.includes(Number(filters.discount))) p.set('discount', String(filters.discount))
  if (filters.inStock) p.set('inStock', '1')

  const price = filters.price || [0, PRICE_MAX]
  if (Number(price[0]) > 0) p.set('minPrice', price[0])
  if (Number(price[1]) < PRICE_MAX) p.set('maxPrice', price[1])

  const { q, sort, page } = extra
  if (q) p.set('q', q)
  if (sort && sort !== 'default') p.set('sort', sort)
  if (page && Number(page) > 1) p.set('page', page)

  return p
}

export function parseListingParams(searchParams, tables = null) {
  // Resolve each token to an id: a bare number is taken as-is (back-compat),
  // otherwise it's treated as a slug and matched against the table.
  const resolveIds = (key, list, slugFn) =>
    searchParams.getAll(key).flatMap((tok) => {
      const t = (tok || '').trim()
      if (/^\d+$/.test(t)) return [Number(t)]
      const item = fromSlug(t, list, slugFn)
      return item ? [item.id] : []
    })

  const discount = DISCOUNTS.includes(Number(searchParams.get('discount'))) ? Number(searchParams.get('discount')) : 0
  return {
    filters: {
      brands: resolveIds('brand', tables?.brands, brandSlug),
      subTypes: resolveIds('subType', tables?.subTypes, subTypeSlug),
      categories: resolveIds('category', tables?.categoryTypes, categorySlug),
      genders: searchParams.getAll('gender'), // English name strings — keep as-is
      dialColors: resolveIds('dialColor', tables?.colors, colorSlug),
      bandColors: resolveIds('bandColor', tables?.colors, colorSlug),
      materials: resolveIds('material', tables?.materials, materialSlug),
      movements: resolveIds('movement', tables?.movementTypes, movementSlug),
      shapes: resolveIds('shape', tables?.shapes, shapeSlug),
      displayTypes: resolveIds('displayType', tables?.displayTypes, displayTypeSlug),
      grades: searchParams.getAll('grade').map(Number).filter((n) => !Number.isNaN(n)),
      offers: false, // Brand Fashion: never a listing filter (see buildListingParams)
      // a minimum discount is a Sale page whether or not the URL also says sale=1
      sale: ['1', 'true'].includes(searchParams.get('sale') || '') || discount > 0,
      discount,
      inStock: ['1', 'true'].includes(searchParams.get('inStock') || ''),
      price: [
        Number(searchParams.get('minPrice') || 0),
        Number(searchParams.get('maxPrice') || PRICE_MAX),
      ],
    },
    q: searchParams.get('q') || '',
    sort: searchParams.get('sort') || 'default',
    page: Number(searchParams.get('page') || 1),
  }
}

// Order-independent string key for comparing two param sets (loop guard).
export function paramsKey(params) {
  return [...params.entries()].map(([k, v]) => `${k}=${v}`).sort().join('&')
}
