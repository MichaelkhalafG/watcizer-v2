import { describe, expect, it } from 'vitest'
import { liveFields, livePrice, livePrices, liveVariantPrice, rowPrice, slimCampaigns, slimPrices } from '../src/lib/campaign'

// Campaign prices on the storefront (2026-11-10, a twin): every price follows the campaigns answer to the second.

const REG = { amount: 3000, sale_amount: 2150, has_sale: true, discount_pct: 28, currency: 'EGP' }
const BAKED = { amount: 3000, sale_amount: 1500, has_sale: true, discount_pct: 50, currency: 'EGP', regular: REG, campaign: { presentation: 'hot_deals', ends_at: '2026-11-10T20:00:00+02:00' } }
const ANSWER = { 7: { amount: 3000, sale_amount: 1500, discount_pct: 50, has_sale: true, presentation: 'hot_deals', ends_at: 'x' } }

describe('campaign prices on a page', () => {
  it('keeps from the answer only what a page needs, and drops a campaign’s prices the second it is no longer live', () => {
    const slim = slimPrices(ANSWER)
    expect(slim).toEqual({ 7: { sale_amount: 1500, discount_pct: 50, presentation: 'hot_deals' } })
    expect(slimPrices(undefined)).toBeNull()
    expect(slimCampaigns({ now: 'n', prices: ANSWER }).prices).toEqual(slim)
    expect(livePrices(slim, { hot_deals: { id: 1 }, flash_sale: null })).toEqual(slim)
    expect(livePrices(slim, { hot_deals: null, flash_sale: null })).toEqual({})
    expect(livePrices(null, {})).toBeNull()
  })

  it('shows the campaign price while live — on a card held from before the start too — and the regular price once it is over', () => {
    const live = { 7: { sale_amount: 1500, discount_pct: 50, presentation: 'hot_deals' } }
    // a card rendered before the start: the answer names a campaign price → it shows
    expect(livePrice(REG, 7, live)).toMatchObject({ sale_amount: 1500, has_sale: true, discount_pct: 50, amount: 3000 })
    // a card core priced during the campaign, held after the end: the answer no longer names it → its regular price
    expect(livePrice(BAKED, 7, {})).toBe(REG)
    // still live: the campaign price
    expect(livePrice(BAKED, 7, live)).toMatchObject({ sale_amount: 1500 })
    // not known yet (no campaigns answer): untouched
    expect(livePrice(BAKED, 7, null)).toBe(BAKED)
    // a product with no campaign price and no regular: the same object
    expect(livePrice(REG, 8, live)).toBe(REG)
  })

  it('does the same for the legacy rows the cards and the product page read, and shifts a variant by its own difference', () => {
    const row = { id: 7, selling_price: 3000, sale_price_after_discount: 1500, percentage_discount: 50, price_regular: REG }
    expect(rowPrice(row)).toMatchObject({ amount: 3000, sale_amount: 1500, has_sale: true, regular: REG })
    expect(liveFields(row, {})).toMatchObject({ selling_price: 3000, sale_price_after_discount: 2150, percentage_discount: 28 })
    const plain = { id: 9, selling_price: 2000, sale_price_after_discount: 0, percentage_discount: 0 }
    expect(liveFields(plain, {})).toBe(plain)
    expect(liveFields(plain, { 9: { sale_amount: 1600, discount_pct: 20, presentation: 'flash_sale' } })).toMatchObject({ sale_price_after_discount: 1600, percentage_discount: 20 })
    // a variant 200 dearer than the product
    const variant = { amount: 3200, sale_amount: 1700, has_sale: true, discount_pct: 47 }
    expect(liveVariantPrice(variant, BAKED, 7, {})).toMatchObject({ amount: 3200, sale_amount: 2350, has_sale: true, discount_pct: 27 })
    expect(liveVariantPrice(variant, BAKED, 7, null)).toBe(variant)
  })
})
