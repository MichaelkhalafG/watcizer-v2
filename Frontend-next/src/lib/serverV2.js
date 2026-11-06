import { cache } from 'react'
import serverHttp from './serverFetch'
import { coreRead, heldValue, MISS, HOLD } from './coreRead'
import { campaignHoldRules } from './campaign'
import { STOREFRONT_CODE } from './env'

// SERVER-ONLY v2 reads — a TWIN since 2026-10-23, when Watchizer's product page followed Brand Fashion here
// (V2-READ-HALF; Brand Fashion piloted it on 2026-10-17). Same `coreRead` discipline as serverCatalog.js: a
// 404/422 is a real miss (MISS), a failure is retried once, then the last good copy, then
// CoreUnavailableError. React `cache()` dedupes generateMetadata + the page within ONE request; the small
// shared reads (meta, home) also keep a process-level memo (5-min TTL) like the tables and the nav.
//
// WHICH SHOP USES WHAT. Brand Fashion reads all four. Watchizer imports only the two PRODUCT readers: its
// home page and its menu are deliberately still on compat (slice 2 is out of this wave), so the presence of
// `getServerV2Meta` and `getServerV2Home` in this file is not evidence that they moved — they are Brand
// Fashion's, and they are here rather than in a second file because one file cannot drift from itself.

const TTL = 300_000

// STALE WHILE REVALIDATE (the performance pass, 2026-11-05). The first cut awaited a fresh read whenever the value was
// older than its `ttl` — so with the campaigns read (30 s) in the layout, most page requests paid a core round trip
// before their first byte (measured: +150 to +350 ms TTFB against the old build). A value we already have is now
// ANSWERED AT ONCE and refreshed behind the request; only the first request after a start, or one that finds the
// value older than `stale` (a server that sat idle), waits for core. Safe for what uses it: a campaign that ended
// since is dropped by the browser on arrival and one that started is picked up by the browser's own request within
// the minute (useCampaigns); the menu and the home rails are at most one request behind. `stale` is twice the
// lifetime unless a read names its own (the campaigns and the home do).
// The rule itself is coreRead.js `heldValue` (moved there on 2026-11-06 so it can be tested); React's `cache()`
// around it makes generateMetadata and the page share one answer within a request.
function memo(key, fn, ttl = TTL, stale = ttl * 2, rules = {}) {
  return cache(heldValue(key, fn, { ttl, stale, ...rules }))
}

/** v2 meta: storefront, brands, grades, filter lookups, the menu tree, banners. */
export const getServerV2Meta = memo('v2:meta', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/meta`)).data)

/**
 * The live campaigns (Hot Deals, Flash Sale) — for the (main) layout, so the header switch, the phone strip and the
 * home sliders are in the server HTML instead of arriving later and pushing the page down (measured 2026-11-05: CLS
 * 0.27 on a computer and 0.93 on a phone without it). Process-cached for 30 seconds, not five minutes: a campaign
 * starts and stops. The browser still asks again itself and hides a campaign at its real end.
 *
 * A START OR AN END VOIDS THE HELD COPY AT THAT SECOND (the follow-up of 2026-11-06). Both instants are in the
 * answer — each live campaign's `ends_at` and core's `next_start_at` — on core's clock, which the answer also
 * carries (`now`). From the earlier of them (`nextChangeAt`) the copy is not served: the request waits for core,
 * so the first page rendered after a sale ends carries no strip, switch, slider or campaign page of it, and the
 * first one after a sale starts carries them all. If core cannot be asked at that moment, the last good copy is
 * handed out WITHOUT whatever has ended. The rules are campaign.js `campaignHoldRules`; the test is
 * tests/campaignHold.test.mjs. What is still found by the 30 seconds, not at once: a change made by hand on the
 * dashboard (a campaign switched off, its dates moved, a product taken out).
 */
export const getServerV2Campaigns = memo('v2:campaigns', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/campaigns`)).data, 30_000, 300_000, campaignHoldRules)

/** v2 home: the rails with cards, the deals band, the category entries, the banner. */
// The menu (item 36, 2026-11-05): the same for every visitor, so one read per five minutes serves every page render.
export const getServerV2Menu = memo('v2:menu', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/menu`)).data)

// THE HOME'S WINDOW, NOT THE MENU'S (2026-11-06): this read kept the default lifetime — five minutes as the answer
// and up to ten while being refreshed — so a card on Brand Fashion's home could be ten minutes old. It is the
// home's window now, the same one Watchizer's home reads with (coreRead.js HOLD.home): 30 s, two minutes at most.
export const getServerV2Home = memo('v2:home', async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/home`)).data, HOLD.home.fresh, HOLD.home.stale)

/** ONE product detail by slug — MISS (null) when core says there is none, throws when core could not answer. */
export const getServerV2Product = cache(async (slug) => {
  const data = await coreRead(`v2:product:${slug}`, async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}`)).data, HOLD.product)
  return data === MISS ? null : data
})

/**
 * The same product for the HOME's campaign banner (its live price, stock and facts): the home's window, not a product
 * page's, so the home's first byte does not wait for core on this read either (coreRead.js, HELD READS).
 */
export const getServerV2ProductForHome = cache(async (slug) => {
  const data = await coreRead(`v2:product:${slug}`, async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}`)).data, HOLD.home)
  return data === MISS ? null : data
})

/** The PDP's two rails for a slug — null on a miss or a failure (a rail is never worth an error page). */
export const getServerV2Related = cache(async (slug) => {
  try {
    const data = await coreRead(`v2:related:${slug}`, async () => (await serverHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}/related`)).data, HOLD.product)
    return data === MISS ? null : data
  } catch {
    return null
  }
})
