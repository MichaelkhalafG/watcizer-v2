import { cache } from 'react'
import serverHttp from './serverFetch'
import { tablesQueryFn } from '../Hooks/queries/useTables'
import { offersQueryFn } from '../Hooks/queries/useOffers'
import { navQueryFn } from '../Hooks/queries/useNav'
import { homeQueryFn } from '../Hooks/queries/useListing'
import { listingQueryFn } from './listingRequest'
import { toSlug } from '../utils/slugs'
import { transformProductData } from '../utils/transformProduct'

// SERVER-ONLY catalogue access for the pages' server renders.
//
// Each getter is wrapped in React `cache()` so that generateMetadata() and the
// page component — two separate invocations within the SAME request — share ONE
// upstream fetch instead of hitting Laravel twice. The query fns are the exact
// same ones the client hooks use, so the shape is identical and the client
// cache-hits after hydration.
//
// No page loads the whole catalogue any more (C-1 stage 4, slice D removed the
// last `getServerCatalog`): each asks core for exactly what it shows.

// CROSS-REQUEST memo for the small, shared reads (nav, tables). React `cache()`
// alone only dedupes within ONE request, and serverHttp is axios (which bypasses
// Next's fetch Data Cache), so a process-level memo of the in-flight/resolved
// promise (5-min TTL) keeps them from being re-fetched on every navigation. The
// promise is cleared on error so the next request retries.
const CATALOG_TTL = 300_000 // 5 min — parity with the routes' `revalidate = 300`

// The header menu's facts (C-1 stage 2) — a few KB, memoised like the catalogue.
let _navPromise = null
let _navAt = 0
export const getServerNav = cache(async () => {
  const now = Date.now()
  if (!_navPromise || now - _navAt >= CATALOG_TTL) {
    _navAt = now
    _navPromise = navQueryFn(serverHttp)().catch((e) => {
      _navPromise = null
      throw e
    })
  }
  return _navPromise
})

// The lookup tables alone (C-1 stage 4) — what the (main) layout needs. It used to fetch the whole
// catalogue on the server just to take the tables out of it. Memoised like nav.
let _tablesPromise = null
let _tablesAt = 0
export const getServerTables = cache(async () => {
  const now = Date.now()
  if (!_tablesPromise || now - _tablesAt >= CATALOG_TTL) {
    _tablesAt = now
    _tablesPromise = tablesQueryFn(serverHttp)().catch((e) => {
      _tablesPromise = null
      throw e
    })
  }
  return _tablesPromise
})

// ONE product for the product page, by URL param — numeric id or english slug — resolved by core
// (`catalog/product`, the storefront's old findProductInCatalog rule) instead of loading the whole
// catalogue to search it (C-1 stage 4). Returns core's raw cards payload ({ products: [row],
// ratings, images }) or null when no visible product matches. Cached per request.
export const getServerProduct = cache(async (param) => {
  if (!param) return null
  let decoded = param
  try {
    decoded = decodeURIComponent(param)
  } catch {
    // a malformed escape — look it up as typed
  }
  try {
    const { data } = await serverHttp.get(`catalog/product?slug=${encodeURIComponent(decoded)}`)
    return data?.products?.length ? data : null
  } catch {
    return null
  }
})

// The product page's ENGLISH card (what SEO / JSON-LD / the canonical URL are built from) plus the
// raw payload the client transforms into the shopper's language. null when core found nothing.
export const getServerProductCard = cache(async (param) => {
  const payload = await getServerProduct(param)
  if (!payload) return null
  let tables = {}
  try {
    tables = await getServerTables()
  } catch {
    // the card still renders from its own row; names from the tables fall back as they always did
  }
  const [product] = transformProductData(payload.products, tables, payload.ratings || [], payload.images || [], 'en')
  return product ? { product, payload, ratings: payload.ratings || [], tables } : null
})

// The home page's rails with their cards (C-1 stage 4 slice C, core's `catalog/home`) — the raw
// payload, which HomeClient transforms in the shopper's language. null on failure: the page then
// renders without rails and the client retries. Not memoised across requests: the page's own
// `revalidate = 300` is the cache, and the featured rail's random pick should change with it.
export const getServerHome = cache(async () => {
  try {
    return await homeQueryFn(serverHttp)()
  } catch {
    return null
  }
})

// One listing page from core (`catalog/listing`), by its request string — shared by a listing
// route's generateMetadata (its count and preview image) and the page (its prefetch) in ONE request.
export const getServerListing = cache(async (qs) => listingQueryFn(qs, serverHttp)())

// Transformed offers array (same shape as the useOffers hook).
export const getServerOffers = cache(async () => offersQueryFn(serverHttp)())

// By-name fallback for legacy raw-title URLs / catalog misses — the same endpoint
// ProductDetail falls back to on the client. Returns the RAW product (untransformed)
// or null on 404 / error. Cached so metadata + page don't double-fetch.
export const fetchProductByName = cache(async (param) => {
  try {
    const { data } = await serverHttp.get(
      `products/by-name/${encodeURIComponent(decodeURIComponent(param))}`,
    )
    return data?.product || null
  } catch {
    return null
  }
})

// Resolve an offer from the offers array by URL param — numeric id OR english
// slug — mirroring ProductDetail's offer resolver.
export const findOfferInCatalog = (offers, param) => {
  if (!param) return null
  const list = offers || []
  if (!list.length) return null
  if (/^\d+$/.test(param)) return list.find((o) => o.id === Number(param)) || null
  const s = decodeURIComponent(param)
  return (
    list.find((o) => toSlug(o.offer_name_en || '') === s) ||
    list.find((o) => toSlug(o.offer_name_ar || '') === s) ||
    null
  )
}
