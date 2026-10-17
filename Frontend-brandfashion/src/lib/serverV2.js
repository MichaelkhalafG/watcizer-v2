import { cache } from 'react'
import serverHttp from './serverFetch'
import { coreRead, MISS } from './coreRead'
import { STOREFRONT_CODE } from './env'

// SERVER-ONLY v2 reads for Brand Fashion's pages (2026-10-17, V2-READ-HALF): the same `coreRead` discipline
// as serverCatalog.js — a 404/422 is a real miss (MISS), a failure is retried once, then the last good copy,
// then CoreUnavailableError. React `cache()` dedupes generateMetadata + the page within ONE request; the
// small shared reads (meta, home) also keep a process-level memo (5-min TTL) like the tables and the nav.

const TTL = 300_000

function memo(key, fn) {
  let promise = null
  let at = 0
  return cache(async () => {
    const now = Date.now()
    if (!promise || now - at >= TTL) {
      at = now
      promise = coreRead(key, fn).catch((e) => {
        promise = null
        throw e
      })
    }
    return promise
  })
}

/** v2 meta: storefront, brands, grades, filter lookups, the menu tree, banners. */
export const getServerV2Meta = memo('v2:meta', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/meta`)).data)

/** v2 home: the rails with cards, the deals band, the category entries, the banner. */
export const getServerV2Home = memo('v2:home', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/home`)).data)

/** ONE product detail by slug — MISS (null) when core says there is none, throws when core could not answer. */
export const getServerV2Product = cache(async (slug) => {
  const data = await coreRead(`v2:product:${slug}`, async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}`)).data)
  return data === MISS ? null : data
})

/** The PDP's two rails for a slug — null on a miss or a failure (a rail is never worth an error page). */
export const getServerV2Related = cache(async (slug) => {
  try {
    const data = await coreRead(`v2:related:${slug}`, async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}/related`)).data)
    return data === MISS ? null : data
  } catch {
    return null
  }
})
