import publicHttp from '../Context/publicApi'
import { STOREFRONT_CODE } from './env'
import { categorySlug, subTypeSlug } from '../utils/slugs'

// Brand Fashion's listing on core's v2 API (2026-10-17, V2-READ-HALF — Michael's decision: both storefronts
// move to v2, Brand Fashion pilots). This file is NOT a twin: Watchizer still reads compat `catalog/listing`.
//
// Two halves, both pure:
//  • the REQUEST — the sidebar's filter state (legacy ids and English gender names) → the v2 query string,
//    which is the query key (the server page and the client build the same string, so the first render
//    hits the prefetched page). `facets=1` always: the sidebar needs the per-result counts.
//  • the RESPONSE — a v2 page → the payload the current listing consumers read (`products` rows for
//    transformProductData, `facets` keyed the way SideBar/SmartSuggestions look them up, `total`), WITH the
//    raw v2 page kept under `v2` for what reads v2 directly (BfProductCard, the B4 sidebar).
//
// What v2 expresses differently from compat — decided here, recorded in the backlog (B4-LISTING-REQUIREMENT):
//  • category: ONE path. The first selected sub-type (else the first category) is sent, as its slug
//    (storefront slugs equal toSlug(English legacy name) — 30 of 30 measured); a bare depth-2 slug resolves.
//    §10.2's Category section is a tree, so a single path is the design, not a loss.
//  • colour: v2 counts and filters a colour in ANY role (dial, band, main). Dial and band selections are
//    merged into `color`; the counts are shown under the legacy "Dial colour" section and the "Band colour"
//    section hides (no counts). B4 draws the spec's single Colour section.
//  • not served by v2, so those legacy sections hide and their selections are dropped from the request:
//    sub-type/category counts, offers (sale), movement, shape, display type.
//  • sort `default` is v2's `available` (§19-5: in stock first, then today's order, then product id).

const PRICE_MAX = 99999999
const PER_PAGE = 24
const SORTS = { default: 'available', price_asc: 'price_asc', price_desc: 'price_desc', newest: 'newest', rating: 'rating' }

/** True when the filter state needs the lookup tables to become a v2 request (a category or sub-type id → slug). */
export function listingNeedsTables(filters = {}) {
  return (filters?.categories?.length || 0) + (filters?.subTypes?.length || 0) > 0
}

// The ONE category path v2 takes: the first sub-type, else the first category — by slug, via the tables.
function categoryPath(filters, tables) {
  const subId = filters.subTypes?.[0]
  if (subId != null) {
    const item = (tables?.subTypes || []).find((i) => i.id === subId)
    return item ? subTypeSlug(item) : null
  }
  const catId = filters.categories?.[0]
  if (catId != null) {
    const item = (tables?.categoryTypes || []).find((i) => i.id === catId)
    return item ? categorySlug(item) : null
  }
  return null
}

/** The filter state → the v2 listing query string (the query key). `lang` matters only for a search. */
export function v2ListingRequest({ filters = {}, q = '', sort = 'default', page = 1, perPage = PER_PAGE, lang } = {}, tables = null) {
  const p = new URLSearchParams()
  const category = categoryPath(filters, tables)
  if (category) p.set('category', category)
  const list = (key, values) => {
    const v = [...new Set((values || []).filter((x) => x !== '' && x != null))]
    if (v.length) p.set(key, v.join(','))
  }
  list('brand', filters.brands) // ids — v2 takes a brand by slug or by id
  list('gender', filters.genders) // English names — v2 takes a gender by id or by English name
  list('color', [...(filters.dialColors || []), ...(filters.bandColors || [])])
  list('material', filters.materials)
  list('grade', filters.grades)
  const [min, max] = filters.price || [0, PRICE_MAX]
  if (Number(min) > 0) p.set('price_min', String(min))
  if (Number(max) < PRICE_MAX) p.set('price_max', String(max))
  const term = (q || '').trim()
  if (term) {
    p.set('q', term)
    // v2 searches one locale's index; the shopper's language picks it. Without a search the key has no
    // language, so switching language never refetches the listing (as before).
    if (lang === 'ar' || lang === 'en') p.set('locale', lang)
  }
  p.set('sort', SORTS[sort] || SORTS.default)
  if (Number(page) > 1) p.set('page', String(page))
  if (Number(perPage) !== PER_PAGE) p.set('per_page', String(perPage))
  p.set('facets', '1')
  return p.toString()
}

// A v2 card → the legacy row shape transformProductData reads (ids, translations[], flat prices and stock),
// with every v2 field kept on the row. `stock` becomes the express number the legacy card reads; the v2
// stock object stays reachable through the payload's `v2.data`.
function legacyRow(card) {
  const title = card.title || {}
  const short = card.short_description || {}
  return {
    ...card,
    brand_id: card.brand?.id ?? null,
    grade_id: card.grade?.id ?? null,
    category_type_id: null,
    sub_type_id: null,
    translations: ['en', 'ar'].map((locale) => ({
      locale,
      product_title: title[locale] ?? title.en ?? '',
      short_description: short[locale] ?? short.en ?? '',
    })),
    image: card.image?.src ?? null,
    stock: card.stock?.express ?? 0,
    market_stock: card.stock?.market ?? 0,
    in_stock: !!card.stock?.in_stock,
    selling_price: card.price?.amount ?? 0,
    sale_price_after_discount: card.price?.has_sale ? card.price.sale_amount : 0,
    percentage_discount: card.price?.has_sale ? card.price.discount_pct : 0,
    ratings_avg: card.rating?.avg ?? null,
    ratings_count: card.rating?.count ?? 0,
    gender: [],
    feature: [],
    dial_color: [],
    band_color: [],
    created_at: card.created_at,
    updated_at: card.updated_at,
  }
}

// The v2 facet block → the legacy keys SideBar and SmartSuggestions look counts up by.
function legacyFacets(facets) {
  if (!facets) return undefined
  const byId = (rows) => Object.fromEntries((rows || []).map((r) => [String(r.id), r.count]))
  return {
    brands: byId(facets.brand),
    genders: Object.fromEntries((facets.gender || []).map((r) => [r.name?.en ?? String(r.id), r.count])),
    grades: byId(facets.grade),
    materials: byId(facets.material),
    dialColors: byId(facets.color), // a colour in any role (see the header)
    bandColors: {},
    categories: {},
    subTypes: {},
    movements: {},
    shapes: {},
    displayTypes: {},
    in_stock: facets.in_stock,
    price: facets.price,
  }
}

/** A v2 listing page → the payload the listing consumers read, plus the raw page under `v2`. */
export function fromV2Listing(page) {
  const cards = Array.isArray(page?.data) ? page.data : []
  const meta = page?.meta || {}
  return {
    products: cards.map(legacyRow),
    ratings: [],
    images: [],
    facets: legacyFacets(page?.facets),
    total: Number(meta.total) || 0,
    page: meta.page,
    per_page: meta.per_page,
    last_page: meta.last_page,
    v2: page,
  }
}

export const v2ListingQueryFn =
  // The browser default is the header-less client (no CORS preflight); the server passes serverFetch.
  (qs, client = publicHttp) =>
  async () => {
    // One template literal (not a ternary): the allow-list test reads storefront calls from source.
    const { data } = await client.get(`v2/${STOREFRONT_CODE}/products?${qs}`)
    return fromV2Listing(data)
  }
