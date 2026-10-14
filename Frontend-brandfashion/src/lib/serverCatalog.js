import { cache } from 'react'
import serverHttp from './serverFetch'
import { tablesQueryFn } from '../Hooks/queries/useTables'
import { offersQueryFn } from '../Hooks/queries/useOffers'
import { navQueryFn } from '../Hooks/queries/useNav'
import { homeQueryFn } from '../Hooks/queries/useListing'
import { listingQueryFn } from './listingRequest'
import { toSlug } from '../utils/slugs'
import { transformProductData } from '../utils/transformProduct'
import { coreRead, MISS, CoreUnavailableError } from './coreRead'

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
//
// Every read goes through `coreRead` (2026-09-30): a 404/422 from core is a real "no such thing";
// a 429, 5xx, timeout or dropped connection is retried once, then answered from this process's last
// good copy, and only then thrown as CoreUnavailableError — which the pages let reach their error
// boundary (a 5xx) instead of calling notFound(). A core failure never becomes a 404 again.

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
    _navPromise = coreRead('nav', navQueryFn(serverHttp)).catch((e) => {
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
    _tablesPromise = coreRead('tables', tablesQueryFn(serverHttp))
      .then((tables) => {
        if (tables === MISS) throw new CoreUnavailableError('tables', new Error('no tables'))
        return tables
      })
      .catch((e) => {
        _tablesPromise = null
        throw e
      })
  }
  return _tablesPromise
})

// ONE product for the product page, by URL param — numeric id or english slug — resolved by core
// (`catalog/product`, the storefront's old findProductInCatalog rule) instead of loading the whole
// catalogue to search it (C-1 stage 4). Returns core's raw cards payload ({ products: [row],
// ratings, images }), or null when core says no visible product matches. THROWS
// CoreUnavailableError when core could not answer — never null for that. Cached per request.
export const getServerProduct = cache(async (param) => {
  if (!param) return null
  let decoded = param
  try {
    decoded = decodeURIComponent(param)
  } catch {
    // a malformed escape — look it up as typed
  }
  const data = await coreRead(`product:${decoded}`, async () => {
    const res = await serverHttp.get(`catalog/product?slug=${encodeURIComponent(decoded)}`)
    return res.data
  })
  return data?.products?.length ? data : null
})

// The product page's ENGLISH card (what SEO / JSON-LD / the canonical URL are built from) plus the
// raw payload the client transforms into the shopper's language. null when core found nothing;
// throws (from getServerProduct) when core could not answer.
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
// payload, which HomeClient transforms in the shopper's language. On failure: the last good copy
// (coreRead), else null — the page then renders without rails and the browser fetches them (never a
// 404; the home page cannot be "not found").
export const getServerHome = cache(async () => {
  try {
    return await coreRead('home', homeQueryFn(serverHttp))
  } catch {
    return null
  }
})

// One listing page from core (`catalog/listing`), by its request string — shared by a listing
// route's generateMetadata (its count and preview image) and the page (its prefetch) in ONE request.
// Throws when core could not answer and there is no last good copy; the callers then let the
// browser fetch it (the listing is never "not found").
export const getServerListing = cache(async (qs) => coreRead(`listing:${qs}`, listingQueryFn(qs, serverHttp)))

// A resolved product card's text in `lang` (S-AR stage 1): the same raw row core sent, transformed
// in that language — for the Arabic page's title, description and JSON-LD. null without a payload.
export const localizedProduct = ({ payload, tables } = {}, lang) => {
  if (!payload?.products?.length) return null
  return transformProductData(payload.products, tables || {}, payload.ratings || [], payload.images || [], lang)[0] ?? null
}

// Transformed offers array (same shape as the useOffers hook).
export const getServerOffers = cache(async () => coreRead('offers', offersQueryFn(serverHttp)))

// By-name fallback for legacy raw-title URLs / catalog misses — the same endpoint
// ProductDetail falls back to on the client. Returns the RAW product (untransformed),
// null when core says there is none (404), and THROWS when core could not answer.
export const fetchProductByName = cache(async (param) => {
  let decoded = param
  try {
    decoded = decodeURIComponent(param)
  } catch {
    // a malformed escape — look it up as typed
  }
  const product = await coreRead(`byname:${decoded}`, async () => {
    const res = await serverHttp.get(`products/by-name/${encodeURIComponent(decoded)}`)
    return res.data?.product || null
  })
  return product || null
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
