import { describe, expect, it } from 'vitest'
import { createElement as h } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { LiveOffersProvider, useProductOffer } from '../src/Hooks/queries/usePromo'
import { STOREFRONT_CODE } from '../src/lib/env'

/*
 * ONE READER OF THE LIVE OFFERS FOR EVERY CARD (the follow-up of 2026-11-06). A TWIN.
 *
 * A product card used to ask the campaigns query three times and set two timers to learn whether it is in a live
 * offer. `LiveOffersProvider` now does that once, in the main layout, and a card reads its value. These tests render
 * cards as the SERVER renders them — the campaigns seeded in the query cache, as the layout seeds them — and hold what
 * item 41 asked for: a product in the live Flash Sale or Hot Deals campaign says so, in the HTML; one in both is the
 * Flash Sale's; one in neither, or in a campaign that is over, says nothing; and the cards make no query observer.
 */

const HOUR = 3_600_000
const iso = (ms) => new Date(ms).toISOString()
const campaign = (id, ids, endsIn) => ({ id, name: 'Sale', starts_at: iso(Date.now() - HOUR), ends_at: iso(Date.now() + endsIn), count: ids.length, cards: [], progress: {}, product_ids: ids })

function Card({ id }) {
  const offer = useProductOffer(id)
  return h('li', { 'data-id': id, 'data-offer': offer ? offer.kind : 'none', 'data-ends': offer ? offer.ends_at : '' })
}

const render = (campaigns, ids, { provider = true } = {}) => {
  const qc = new QueryClient()
  qc.setQueryData(['campaigns', STOREFRONT_CODE], { ...campaigns, offset: 0 })
  const cards = h('ul', null, ids.map((id) => h(Card, { key: id, id })))
  const html = renderToStaticMarkup(h(QueryClientProvider, { client: qc }, provider ? h(LiveOffersProvider, null, cards) : cards))
  const offers = Object.fromEntries([...html.matchAll(/data-id="(\d+)" data-offer="(\w+)"/g)].map((m) => [Number(m[1]), m[2]]))
  return { html, offers, qc }
}

describe('a card\'s live offer, from the one provider', () => {
  it('says which offer a product is in — in the server HTML — and the Flash Sale wins over Hot Deals', () => {
    const flash = campaign(7, [11, 12], HOUR)
    const hot = campaign(8, [12, 13], 2 * HOUR)
    const { offers, html } = render({ flash_sale: flash, hot_deals: hot }, [11, 12, 13, 14])
    expect(offers).toEqual({ 11: 'flash', 12: 'flash', 13: 'hot', 14: 'none' })
    expect(html).toContain(`data-id="13" data-offer="hot" data-ends="${hot.ends_at}"`)
    expect(html).toContain(`data-id="11" data-offer="flash" data-ends="${flash.ends_at}"`)
  })

  it('says nothing for a campaign that is over, or when none is live', () => {
    const over = campaign(7, [11], -1000)
    expect(render({ flash_sale: over, hot_deals: null }, [11]).offers).toEqual({ 11: 'none' })
    expect(render({ flash_sale: null, hot_deals: null }, [11]).offers).toEqual({ 11: 'none' })
    expect(render({}, [11]).offers).toEqual({ 11: 'none' })
  })

  it('a card outside the provider is in no offer, and does not throw', () => {
    const { offers } = render({ flash_sale: campaign(7, [11], HOUR), hot_deals: null }, [11], { provider: false })
    expect(offers).toEqual({ 11: 'none' })
  })

  it('eighty cards read one value: the same answer for each, whatever their number', () => {
    const ids = Array.from({ length: 80 }, (_, i) => 100 + i)
    const flash = campaign(7, ids.filter((id) => id % 2 === 0), HOUR)
    const { offers } = render({ flash_sale: flash, hot_deals: null }, ids)
    expect(Object.values(offers).filter((o) => o === 'flash')).toHaveLength(40)
    expect(Object.values(offers).filter((o) => o === 'none')).toHaveLength(40)
  })
})
