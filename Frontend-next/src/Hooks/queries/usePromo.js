'use client'
import { createContext, createElement, useContext, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import publicHttp from '../../Context/publicApi'
import { STOREFRONT_CODE } from '../../lib/env'
import { clockOffset, isLive, msLeft, withProgress, hotModeOn, HOT_MODE_KEY, offerOf, slimCampaigns, subscribeClock, noClock, readClock, readServerClock } from '../../lib/campaign'
import { slimMenu } from '../../lib/menuData'

// The data of the twelve updates' storefront surfaces (2026-11-05). A TWIN.
//
//   useCampaigns()          the live Hot Deals / Flash Sale campaigns (Part 2), with the server-clock offset
//   useCampaignProducts()   every product of one live campaign, for its page
//   useLiveCampaign()       one presentation's campaign, and it turns null AT its end without a refetch
//   useCampaignStart()      asks again AT the next scheduled start, so an open page gets a sale at its first second
//   LiveOffersProvider      reads the live campaigns ONCE for every product card under it (useProductOffer)
//   useHotDealsMode()       the header switch (Part 2.4)
//   useMenu()               the mega menu (Part 4)
//   usePaymentBadges()      the payment badges (Part 7)
//
// Every request is ONE template literal on the header-less client: no preflight, and core's allow-list test
// reads the storefront's calls from source.

export const useCampaigns = () =>
  useQuery({
    queryKey: ['campaigns', STOREFRONT_CODE],
    queryFn: async () => {
      const sent = Date.now()
      const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/campaigns`)
      const body = data?.data || {}
      return { ...slimCampaigns(body), offset: clockOffset(body.now, sent, Date.now()) }
    },
    // a campaign can start or be switched off at any minute; the END needs no refetch (useLiveCampaign).
    // Fresh for as long as the poll's own interval: a page that mounts between two polls reads the cache instead of
    // asking again (2026-11-05: at 30 s every second navigation re-asked on arrival).
    staleTime: 60 * 1000,
    refetchInterval: 60 * 1000,
    refetchOnWindowFocus: true,
    retry: 1,
  })

/**
 * The live campaign of one presentation, or null — and null from the very second it ends.
 *
 * A timer is set for the end moment on the SERVER's clock, so the slider, the page and the nav chip all
 * leave together when the countdown reads zero; the next refetch then confirms it. Nothing restarts.
 */
export function useLiveCampaign(presentation) {
  const { data, refetch, isLoading } = useCampaigns()
  const campaign = data?.[presentation] || null
  const offset = data?.offset || 0
  // "now" as the render knows it: the moment of mount, moved forward by the timer below at the campaign's end
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!campaign) return undefined
    const left = msLeft(campaign.ends_at, Date.now(), offset)
    // setTimeout holds at most ~24.8 days; a longer campaign is simply re-armed by the next refetch.
    // A campaign that is ALREADY over when it arrives (a stale cache) is dropped on the next tick.
    const id = setTimeout(
      () => {
        setNow(Date.now())
        refetch()
      },
      Math.min(Math.max(left, 0) + 50, 2 ** 31 - 1),
    )
    return () => clearTimeout(id)
  }, [campaign, offset, refetch])

  const live = isLive(campaign, now, offset) ? campaign : null
  return { campaign: live, offset, isLoading }
}

/**
 * An open page gets a sale FROM ITS FIRST SECOND (the follow-up of 2026-11-06): core names the next scheduled
 * start (`next_start_at`), and at that instant on the server's clock the campaigns are asked for again — once
 * for each start. Until now a start reached an open page with the next poll, up to a minute late. Mounted ONCE
 * per page (the header's top switch); `enabled` is for the second copy of that component.
 */
export function useCampaignStart(enabled = true) {
  const { data, refetch } = useCampaigns()
  const next = data?.next_start_at || null
  const offset = data?.offset || 0
  const asked = useRef(null)
  useEffect(() => {
    if (!enabled || !next || asked.current === next) return undefined
    const left = msLeft(next, Date.now(), offset)
    if (left > 2 ** 31 - 1) return undefined // further than a timer holds: a later poll re-arms it
    const id = setTimeout(() => {
      asked.current = next
      refetch()
    }, left + 300)
    return () => clearTimeout(id)
  }, [enabled, next, offset, refetch])
}

/** Every product of a live campaign (at most 300, four pages), with core's sold/left measurement attached. */
export const useCampaignProducts = (presentation, campaign) =>
  useQuery({
    queryKey: ['campaign-products', STOREFRONT_CODE, presentation, campaign?.id ?? null],
    enabled: !!campaign,
    queryFn: async () => {
      const out = []
      for (let page = 1; page <= 4; page += 1) {
        const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/products?campaign=${presentation}&per_page=96&page=${page}`)
        out.push(...(Array.isArray(data?.data) ? data.data : []))
        if (page >= (Number(data?.meta?.last_page) || 1)) break
      }
      return out
    },
    select: (cards) => withProgress(cards, campaign?.progress),
    staleTime: 60 * 1000,
    retry: 1,
  })

// ── Hot Deals mode: one value in localStorage, read through an external store so every component agrees ──
const listeners = new Set()
const readStored = () => {
  try {
    return window.localStorage.getItem(HOT_MODE_KEY)
  } catch {
    return null
  }
}
const subscribe = (fn) => {
  listeners.add(fn)
  window.addEventListener('storage', fn)
  return () => {
    listeners.delete(fn)
    window.removeEventListener('storage', fn)
  }
}
const writeStored = (value) => {
  try {
    if (value == null) window.localStorage.removeItem(HOT_MODE_KEY)
    else window.localStorage.setItem(HOT_MODE_KEY, String(value))
  } catch {
    /* private mode: the switch still works for this page view through the listeners below */
  }
  listeners.forEach((fn) => fn())
}

/**
 * `{ available, on, set }`. `available` — a Hot Deals campaign is live (the switch shows only then).
 * `on` — the shopper turned the mode on FOR THIS campaign. The server render is always "off": the stored
 * value is the browser's, and the first client render must match the HTML.
 */
export function useHotDealsMode() {
  const { campaign } = useLiveCampaign('hot_deals')
  const stored = useSyncExternalStore(subscribe, readStored, () => null)
  const on = hotModeOn(stored, campaign)

  // the whole shop's theme hangs on one attribute, so no component needs to know about the others
  useEffect(() => {
    const root = document.documentElement
    if (on) root.setAttribute('data-hot-deals', 'on')
    else root.removeAttribute('data-hot-deals')
    // …and the BROWSER'S OWN BAR on a phone (theme-color) burns with the header, and goes back to the shop's colour
    // when the mode is turned off (the review of 2026-11-05: "the whole phone chrome"). The shop's own colour is
    // kept on the tag itself, so whichever component turns the mode off restores it.
    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta) {
      if (on) {
        if (!meta.dataset.shop) meta.dataset.shop = meta.getAttribute('content') || ''
        meta.setAttribute('content', getComputedStyle(root).getPropertyValue('--pm-fire-4').trim() || '#a80a14')
      } else if (meta.dataset.shop !== undefined) {
        meta.setAttribute('content', meta.dataset.shop)
        delete meta.dataset.shop
      }
    }
  }, [on])

  return {
    available: !!campaign,
    on,
    campaign,
    set: (next) => writeStored(next && campaign ? campaign.id : null),
  }
}

/**
 * The live offer a product is in (items 41 and 42): `{ kind: 'flash' | 'hot', left }` — `left` in ms, or null until
 * the browser's clock is known — and null when it is in none, or from the second its offer ends. A product in both
 * is the Flash Sale's. A card that is in no offer subscribes to nothing; the ones that are share the page's one
 * clock, and the campaigns themselves are the query every surface already shares (one request a minute).
 */
export function useProductOffer(productId) {
  // THE CARD DOES NOT TICK (the performance pass, 2026-11-05): the first cut gave the card the time left, so every
  // card in an offer — the whole card — was rendered again every second (ten cards on a grid, on a phone at a
  // quarter of the speed: a second of main-thread time in the first eight). The card only needs to know WHICH offer
  // it is in and when that ends. The strip and the note read the page's one clock themselves (`useOfferLeft`), and
  // only they re-render.
  //
  // …AND THE CARD DOES NOT ASK (the follow-up of 2026-11-06). Each card called the campaigns query three times and
  // set two timers to learn this — 249 query observers and 166 timers on Watchizer's home of 83 cards, made while
  // the page becomes interactive. `LiveOffersProvider` (the main layout) does it once; a card reads its value.
  const live = useContext(LiveOffers)
  const offer = live ? offerOf(productId, live) : null
  return offer ? { kind: offer.kind, ends_at: offer.ends_at, offset: live.offset } : null
}

const LiveOffers = createContext(null)

/**
 * The live campaigns, read ONCE for every product card and product page under it: which Flash Sale and which Hot
 * Deals campaign is live (each drops out at its last second, by `useLiveCampaign`'s one timer) and the server
 * clock's offset. It stands inside the layout's HydrationBoundary, so the server render has the campaigns and a
 * card's strip is in the HTML, as before. A card outside it is in no offer.
 */
export function LiveOffersProvider({ children }) {
  const { data } = useCampaigns()
  const flash = useLiveCampaign('flash_sale').campaign
  const hot = useLiveCampaign('hot_deals').campaign
  const offset = data?.offset || 0
  const value = useMemo(() => ({ flash_sale: flash, hot_deals: hot, offset }), [flash, hot, offset])
  return createElement(LiveOffers.Provider, { value }, children)
}

/**
 * THE CAMPAIGN EFFECTS START WHEN THE PAGE HAS LOADED (the performance pass, 2026-11-05). Measured: painting the header's
 * Hot Deals switch with its glow and its running animations held the FIRST PAINT of every page by 130 to 200 ms (806 ms
 * without the switch, 1002 with it, 874 with only its effects off). The switch, the campaign sliders and the
 * campaign pages are therefore drawn FLAT at first — the same shapes, colours and text — and their animations, glows
 * and filters start one frame after the load event, through one attribute on <html> that promo.css reads. A page
 * reached by a click inside the shop already has it, so nothing waits there.
 */
export function useEffectsAfterLoad() {
  useEffect(() => {
    const root = document.documentElement
    if (root.hasAttribute('data-pm-fx')) return undefined
    const go = () => requestAnimationFrame(() => root.setAttribute('data-pm-fx', 'on'))
    if (document.readyState === 'complete') {
      go()
      return undefined
    }
    window.addEventListener('load', go, { once: true })
    return () => window.removeEventListener('load', go)
  }, [])
}

/** The time an offer has left, in ms, on the page's one clock — null until the browser's clock is known. */
export function useOfferLeft(offer) {
  const now = useSyncExternalStore(offer ? subscribeClock : noClock, readClock, readServerClock)
  return offer && now ? Math.max(0, msLeft(offer.ends_at, now, offer.offset || 0)) : null
}

// THE MENU, READY BEFORE THE FIRST HOVER WITHOUT RIDING IN EVERY PAGE (item 36; the performance pass, 2026-11-05):
// asked for once when the page has loaded (the menu component mounts with the page), slimmed to what the panels
// draw, and KEPT FOR THE SESSION — in the query cache across the shop's own navigations and in sessionStorage
// across full page loads, so from the second page on it is there before the page has finished loading. Ten
// minutes later it is asked for again, behind the copy the shopper already has.
const MENU_KEEP = `menu:${STOREFRONT_CODE}`
const keptMenu = () => {
  if (typeof window === 'undefined') return undefined
  try {
    const kept = JSON.parse(window.sessionStorage.getItem(MENU_KEEP) || 'null')
    return kept && kept.at && kept.menu?.slim ? kept : undefined
  } catch {
    return undefined
  }
}

export const useMenu = (enabled = true) =>
  useQuery({
    queryKey: ['menu', STOREFRONT_CODE],
    enabled,
    queryFn: async () => {
      const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/menu`)
      // the same slim shape the home's server render carries (src/lib/menuData.js)
      const menu = slimMenu(data?.data || null)
      try {
        if (menu) window.sessionStorage.setItem(MENU_KEEP, JSON.stringify({ at: Date.now(), menu }))
      } catch {
        /* private mode or a full store: the query cache still holds it for this visit */
      }
      return menu
    },
    initialData: () => keptMenu()?.menu,
    initialDataUpdatedAt: () => keptMenu()?.at,
    staleTime: 10 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: 1,
  })

export const usePaymentBadges = () =>
  useQuery({
    queryKey: ['payment-badges', STOREFRONT_CODE],
    queryFn: async () => {
      const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/payment-badges`)
      return Array.isArray(data?.data) ? data.data : []
    },
    // which methods a shop offers changes when the team changes it, not by the minute: the footer, the cart and the
    // checkout share ONE answer for ten minutes instead of each asking again on arrival
    staleTime: 10 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: 1,
  })
