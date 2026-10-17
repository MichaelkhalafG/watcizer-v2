import { STOREFRONT_CODE } from './env'
import { txt } from './cardData'

// B4 — the Brand Fashion listing's URL state (spec v2.9 §10.2, 2026-10-17). Pure, used by the server pages
// and the client alike. The URL speaks v2's vocabulary directly — no legacy id round-trip through the tables:
//
//   /category/{path}  → the category page (the path is v2's, e.g. watches or watches/diver)
//   /brand/{slug}     → the brand page
//   /listing          → everything, or a search (?q=)
//   ?brand=rolex,tommy-hilfiger  ?gender=Men,Women  ?color=1,3  ?material=2  ?grade=4
//   ?price_min=1000&price_max=5000  ?in_stock=1  ?q=…  ?sort=price_asc|price_desc|newest|rating  ?page=2
//
// "default" sort is v2's `available` (§19-5). `facets=1` always: the sidebar is drawn from the per-result
// counts of the CURRENT response. A clean route (category/brand) is indexable; any query listing is a tool
// (§10.2's indexing rules are applied by the pages, not here).

export const PAGE_SIZE = 24
export const LIST_KEYS = ['brand', 'gender', 'color', 'material', 'grade']
export const SORTS = ['default', 'price_asc', 'price_desc', 'newest', 'rating']
const V2_SORT = { default: 'available', price_asc: 'price_asc', price_desc: 'price_desc', newest: 'newest', rating: 'rating' }

const list = (v) =>
  String(v ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)

/** URLSearchParams (or a plain object from Next's searchParams) + the route's fixed parts → the listing state. */
export function readListingState(searchParams, { category = null, brand = null } = {}) {
  const sp = searchParams instanceof URLSearchParams ? searchParams : toParams(searchParams)
  const get = (k) => sp.get(k)
  const state = {
    category: category || null,
    brand: brand ? [brand] : list(get('brand')),
    gender: list(get('gender')),
    color: list(get('color')),
    material: list(get('material')),
    grade: list(get('grade')),
    price_min: numOrNull(get('price_min')),
    price_max: numOrNull(get('price_max')),
    in_stock: get('in_stock') === '1',
    q: (get('q') || '').trim(),
    sort: SORTS.includes(get('sort')) ? get('sort') : 'default',
    page: Math.max(1, parseInt(get('page') || '1', 10) || 1),
    // the route fixes these; a chip for them is not removable on that route
    fixed: { category: !!category, brand: !!brand },
  }
  return state
}

export function toParams(obj) {
  const sp = new URLSearchParams()
  for (const [k, v] of Object.entries(obj || {})) {
    if (Array.isArray(v)) v.forEach((x) => sp.append(k, String(x)))
    else if (v != null) sp.set(k, String(v))
  }
  return sp
}

const numOrNull = (v) => {
  if (v == null || v === '') return null
  const n = Number(v)
  return Number.isFinite(n) && n >= 0 ? n : null
}

/** The state → the v2 query string (the query key). `lang` matters only for a search. */
export function toV2Query(state, lang = 'en') {
  const p = new URLSearchParams()
  if (state.category) p.set('category', state.category)
  for (const k of LIST_KEYS) if (state[k]?.length) p.set(k, state[k].join(','))
  if (state.price_min != null) p.set('price_min', String(state.price_min))
  if (state.price_max != null) p.set('price_max', String(state.price_max))
  if (state.in_stock) p.set('in_stock', '1')
  if (state.q) {
    p.set('q', state.q)
    if (lang === 'ar' || lang === 'en') p.set('locale', lang)
  }
  p.set('sort', V2_SORT[state.sort] || V2_SORT.default)
  if (state.page > 1) p.set('page', String(state.page))
  p.set('facets', '1')
  return p.toString()
}

/** The state → the page's own URL (search part), without the route-fixed parts. */
export function toSearch(state) {
  const p = new URLSearchParams()
  for (const k of LIST_KEYS) {
    if (k === 'brand' && state.fixed?.brand) continue
    if (state[k]?.length) p.set(k, state[k].join(','))
  }
  if (state.price_min != null) p.set('price_min', String(state.price_min))
  if (state.price_max != null) p.set('price_max', String(state.price_max))
  if (state.in_stock) p.set('in_stock', '1')
  if (state.q) p.set('q', state.q)
  if (state.sort && state.sort !== 'default') p.set('sort', state.sort)
  if (state.page > 1) p.set('page', String(state.page))
  const s = p.toString()
  return s ? `?${s}` : ''
}

/** True when any shopper-chosen filter is on (the route's fixed parts do not count). */
export function hasFilters(state) {
  return (
    LIST_KEYS.some((k) => (k === 'brand' && state.fixed?.brand ? false : state[k]?.length > 0)) ||
    state.price_min != null ||
    state.price_max != null ||
    state.in_stock
  )
}

/** The state with every shopper-chosen filter cleared (route-fixed parts and the search kept). */
export function clearFilters(state) {
  return { ...state, brand: state.fixed?.brand ? state.brand : [], gender: [], color: [], material: [], grade: [], price_min: null, price_max: null, in_stock: false, page: 1 }
}

/** Toggle one value in a list filter → a new state on page 1. */
export function toggleValue(state, key, value) {
  const v = String(value)
  const cur = state[key] || []
  const next = cur.includes(v) ? cur.filter((x) => x !== v) : [...cur, v]
  return { ...state, [key]: next, page: 1 }
}

/**
 * The empty state's reason and the ONE-CHIP fix (§10.2): which single filter, removed, would show products.
 * Read from the facet block of the EMPTY result — each section is counted with its own filter cleared, so the
 * sum of a section's counts is what removing that chip would show (exact for single-valued brand and grade,
 * a floor for multi-valued gender and colour — a product with two colours counts twice). The price bounds
 * ignore the price filter, so with a price filter on, `price.min` is where the matches start.
 */
export function emptyFix(state, facets) {
  if (!facets) return null
  const sum = (rows) => (rows || []).reduce((a, r) => a + (Number(r.count) || 0), 0)
  const candidates = []
  for (const key of LIST_KEYS) {
    if (key === 'brand' && state.fixed?.brand) continue
    if (state[key]?.length) candidates.push({ key, count: sum(facets[key]), exact: key === 'brand' || key === 'grade' })
  }
  if (state.in_stock) candidates.push({ key: 'in_stock', count: Number(facets.in_stock?.in ?? 0) + Number(facets.in_stock?.out ?? 0), exact: true })
  if (state.price_min != null || state.price_max != null) {
    const min = facets.price?.min
    candidates.push({ key: 'price', count: min != null ? 1 : 0, exact: false, startsAt: min != null ? Number(min) : null })
  }
  const best = candidates.filter((c) => c.count > 0).sort((a, b) => b.count - a.count)[0]
  return best || null
}

export const v2ListingPath = (qs) => `v2/${STOREFRONT_CODE}/products?${qs}`

/** The page's h1: the category or brand name, "Results for …", or "All products". */
export function listingTitle({ category = null, brandName = null, q = '', lang = 'en' }) {
  if (q) return lang === 'ar' ? `نتائج البحث عن "${q}"` : `Results for "${q}"`
  if (category?.name) return txt(category.name, lang)
  if (brandName) return brandName
  return lang === 'ar' ? 'كل المنتجات' : 'All products'
}
