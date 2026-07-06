import { cache } from 'react'
import serverHttp from './serverFetch'
import { tablesQueryFn } from '../Hooks/queries/useTables'
import { productsQueryFn } from '../Hooks/queries/useProducts'
import { offersQueryFn } from '../Hooks/queries/useOffers'
import { toSlug } from '../utils/slugs'

// SERVER-ONLY catalog access for the product/offer detail routes.
//
// Each getter is wrapped in React `cache()` so that generateMetadata() and the
// page component — two separate invocations within the SAME request — share ONE
// upstream fetch instead of hitting Laravel twice. The query fns are the exact
// same ones the client hooks use (tablesQueryFn / productsQueryFn / offersQueryFn),
// so the shape is identical and the client cache-hits after hydration.

// { tables, ratings, productsEn, productsAr }
export const getServerCatalog = cache(async () => {
  const tables = await tablesQueryFn(serverHttp)()
  const products = await productsQueryFn(tables, serverHttp)()
  return { tables, ...products }
})

// Transformed offers array (same shape as the useOffers hook).
export const getServerOffers = cache(async () => offersQueryFn(serverHttp)())

// Resolve a product from the catalog by URL param — numeric id OR english slug —
// mirroring ProductDetail's contextProduct resolver. Operates on the EN variant
// (slugs are built from the english title, and SEO/JSON-LD is english-canonical).
export const findProductInCatalog = (productsEn, param) => {
  if (!param) return null
  const list = productsEn || []
  if (!list.length) return null
  if (/^\d+$/.test(param)) return list.find((p) => p.id === Number(param)) || null
  const s = decodeURIComponent(param)
  return (
    list.find((p) => toSlug(p.name || '') === s) ||
    list.find((p) => toSlug(p.product_title || '') === s) ||
    null
  )
}

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
