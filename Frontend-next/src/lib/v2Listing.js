import publicHttp from '../Context/publicApi'
import { STOREFRONT_CODE } from './env'
import { categorySlug, subTypeSlug } from '../utils/slugs'

// The listing on core's v2 API — `/api/v2/{storefront}/products`.
//
// A TWIN since 2026-10-22, when Watchizer's listing followed Brand Fashion here (V2-READ-HALF, Michael's
// decision of 2026-10-17: both storefronts move to v2, Brand Fashion pilots). One file serves both shops:
// it maps whatever filter keys a sidebar holds, and a key a shop does not use simply never arrives.
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
//  • category: PATHS, comma-separated. Every selected sub-type (else every selected category) is sent, as its
//    slug, and core answers their union — which is what a multi-select sub-type section has always meant on
//    Watchizer. A storefront slug is USUALLY toSlug(English legacy name); where it is not — Chargers & Cables
//    is `chargers`, Earphones & Headphones is `audio`, measured 2026-10-18 — the tables carry the node's
//    `v2_slug` (useTables.js). A bare depth-2 slug resolves.
//  • colour: TWO sections, one per role on `catalog_product_color` — `dial_color` and `band_color` — which is
//    what compat always served and what a watch shop needs, since a black dial and a brown strap are
//    different facts about one watch. There is no third role in the data: a product that carries ONE plain
//    colour (a dress, a bag) has it stored under `band`, because that is the column the dashboard writes, so
//    a fashion result counts band colours and no dial colours at all. Core therefore needs no idea of
//    product kind, and neither does this file; the only thing that cannot be decided here is what to CALL a
//    band section that is alone on the page, and that is a label, decided in SideBar.jsx from the same
//    emptiness. v2's merged `color` key still filters a colour in any role for anything that sends it.
//  • SALE → v2's `on_sale=1`, a valid discount only. Both shops' keys are accepted: Brand Fashion calls it
//    `sale` (round 5 separated it from Offers, which are the dashboard's promotions and a page of their own);
//    Watchizer has always called the same idea `offers` in its listing URL, and that spelling is kept so no
//    bookmarked Watchizer link changes meaning.
//  • movement, shape and display type (2026-10-22): sent as id lists, counted by core. They exist because
//    Watchizer's sidebar has had them since launch and the move must not remove a working filter from a live
//    shop. Brand Fashion's sidebar holds none of them, so it sends none.
//  • sort `default` is v2's `available` (§19-5: in stock first, then today's order, then product id).

const PRICE_MAX = 99999999
const PER_PAGE = 24
const SORTS = { default: 'available', price_asc: 'price_asc', price_desc: 'price_desc', newest: 'newest', rating: 'rating' }

/** True when the filter state needs the lookup tables to become a v2 request (a category or sub-type id → slug). */
export function listingNeedsTables(filters = {}) {
  return (filters?.categories?.length || 0) + (filters?.subTypes?.length || 0) > 0
}

/** The v2 path a legacy table row is addressed by: its own `v2_slug` where the two differ, else its name's slug. */
const pathOf = (item, slugFn) => (item ? item.v2_slug || slugFn(item) : null)

/**
 * The category paths v2 takes: every selected SUB-TYPE, else every selected category — by slug, via the tables.
 * Several are a union; one behaves exactly as a single path always has.
 */
function categoryPaths(filters, tables) {
  const subIds = filters.subTypes || []
  if (subIds.length) {
    return subIds
      .map((id) => pathOf((tables?.subTypes || []).find((i) => i.id === id), subTypeSlug))
      .filter(Boolean)
  }
  return (filters.categories || [])
    .map((id) => pathOf((tables?.categoryTypes || []).find((i) => i.id === id), categorySlug))
    .filter(Boolean)
}

/** The filter state → the v2 listing query string (the query key). `lang` matters only for a search. */
export function v2ListingRequest({ filters = {}, q = '', sort = 'default', page = 1, perPage = PER_PAGE, lang } = {}, tables = null) {
  const p = new URLSearchParams()
  const categories = categoryPaths(filters, tables)
  if (categories.length) p.set('category', categories.join(','))
  const list = (key, values) => {
    const v = [...new Set((values || []).filter((x) => x !== '' && x != null))]
    if (v.length) p.set(key, v.join(','))
  }
  list('brand', filters.brands) // ids — v2 takes a brand by slug or by id
  list('gender', filters.genders) // English names — v2 takes a gender by id or by English name
  list('dial_color', filters.dialColors)
  list('band_color', filters.bandColors)
  list('material', filters.materials)
  list('grade', filters.grades)
  list('movement', filters.movements)
  list('shape', filters.shapes)
  list('display_type', filters.displayTypes)
  // one idea, two spellings: `sale` on Brand Fashion, `offers` on Watchizer (see the header)
  const onSale = filters.sale || filters.offers
  if (onSale) p.set('on_sale', '1')
  // the Sale section's minimum discount (core rounds as the card prints it) and the in-stock key (2026-10-19)
  if (onSale && Number(filters.discount) > 0) p.set('min_discount', String(filters.discount))
  if (filters.inStock) p.set('in_stock', '1')
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

/**
 * A v2 card → the legacy row shape `transformProductData` reads. EXPORTED since 2026-10-23, because the
 * product page needs the same mapping for ONE product (see v2Product.js) and two copies of it would drift.
 */
// A v2 card → the legacy row shape transformProductData reads (ids, translations[], flat prices and stock),
// with every v2 field kept on the row. `stock` becomes the express number the legacy card reads; the v2
// stock object stays reachable through the payload's `v2.data`.
export function legacyRow(card) {
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

/**
 * The v2 facet block → the legacy keys SideBar and SmartSuggestions look counts up by.
 *
 * The two CATEGORY sections are NOT mapped here. v2 counts a node by its own id and slug, while a sidebar
 * checkbox carries the LEGACY id — and the table that joins the two is loaded by `useTables`, which this
 * function has no business waiting for. Threading it in would also put the tables inside the listing's query
 * key, so a listing cached before they arrived would be a different cache entry from the same listing after.
 * They are filled instead by `withLegacyCategoryFacets` below, from the raw page under `v2`, in the hook's
 * `select` — where the tables are already to hand and the rule still lives in exactly one place.
 */
function legacyFacets(facets) {
  if (!facets) return undefined
  const byId = (rows) => Object.fromEntries((rows || []).map((r) => [String(r.id), r.count]))

  return {
    brands: byId(facets.brand),
    genders: Object.fromEntries((facets.gender || []).map((r) => [r.name?.en ?? String(r.id), r.count])),
    grades: byId(facets.grade),
    materials: byId(facets.material),
    dialColors: byId(facets.dial_color),
    bandColors: byId(facets.band_color),
    movements: byId(facets.movement),
    shapes: byId(facets.shape),
    displayTypes: byId(facets.display_type),
    // the two category maps are filled by `withLegacyCategoryFacets` at render, where the tables are
    categories: {},
    subTypes: {},
    in_stock: facets.in_stock,
    price: facets.price,
  }
}

/**
 * The two legacy CATEGORY count maps, folded onto a listing payload.
 *
 * v2's `facets.category` is the result's products per visible menu node, in tree order; the sidebar and
 * SmartSuggestions look a count up by the LEGACY id their checkbox carries. The join is the request's, in
 * reverse: a table row is addressed by its `v2_slug` where that differs from its English name's slug
 * (Chargers & Cables is `chargers`, Earphones & Headphones is `audio` — measured 2026-10-18), a depth-1 node
 * by its path and a depth-2 node by its slug. A node with no legacy twin stays out, as the menu leaves it out.
 *
 * WITH A CATEGORY SET, only that root's own children are mapped: v2 clears the category filter when counting
 * the category section, so it also counts the other families' sub-types, and a Watches page must not offer
 * Bags as a sub-type with a count.
 *
 * Called from `useListing`'s `select`, so the server-prefetched page and the client's refetch read the same
 * way — and called from ONE place, so the rule does not drift between the two shops.
 */
export function withLegacyCategoryFacets(data, tables) {
  const rows = data?.v2?.facets?.category
  if (!Array.isArray(rows) || !tables) return data
  const rootPath = data?.v2?.meta?.category?.path?.split('/')[0] || null
  const find = (items, slugFn, slug) => (items || []).find((i) => pathOf(i, slugFn) === slug)
  const categories = {}
  const subTypes = {}
  for (const n of rows) {
    if (n.depth === 1) {
      const ct = find(tables.categoryTypes, categorySlug, n.path)
      if (ct) categories[String(ct.id)] = n.count
    } else if (n.depth === 2 && (!rootPath || n.path.startsWith(`${rootPath}/`))) {
      const st = find(tables.subTypes, subTypeSlug, n.slug)
      if (st) subTypes[String(st.id)] = n.count
    }
  }
  return { ...data, facets: { ...(data.facets || {}), categories, subTypes } }
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
