import { describe, expect, it } from 'vitest'
import {
  arrangeDeals,
  barOf,
  categoryChips,
  dealLineOf,
  LOW_STOCK,
  lowStockText,
  clockOffset,
  countdownParts,
  countdownText,
  discountSteps,
  hotModeOn,
  isLive,
  money,
  msLeft,
  savedOn,
  withProgress,
  clockText,
} from '../src/lib/campaign.js'

// The rules of the campaign surfaces (the twelve updates, Part 2, 2026-11-05). A TWIN.
// Real end, real numbers: these are the two things the brief makes a hard requirement.

const card = (id, pct, pay, top = 'watches', stock = 5) => ({
  id,
  price: { amount: 1000, sale_amount: pay, discount_pct: pct, has_sale: pct > 0 },
  primary_category: { path: `${top}/x`, depth: 2, name: { en: 'X', ar: 'س' } },
  stock: { express: stock, market: 0, in_stock: stock > 0 },
})

describe('the countdown', () => {
  const end = '2026-11-05T12:00:00+02:00'
  const at = (iso) => Date.parse(iso)

  it('counts to the real end on the server clock, not the device clock', () => {
    // the device is 90 s behind the server: the offset puts it right
    const offset = clockOffset('2026-11-05T10:00:00+02:00', at('2026-11-05T09:58:30+02:00'), at('2026-11-05T09:58:30+02:00'))
    expect(offset).toBe(90_000)
    expect(msLeft(end, at('2026-11-05T09:58:30+02:00'), offset)).toBe(2 * 3600 * 1000)
  })

  it('stops at zero and never goes negative or restarts', () => {
    expect(msLeft(end, at('2026-11-05T12:00:00+02:00'))).toBe(0)
    expect(msLeft(end, at('2026-11-06T12:00:01+02:00'))).toBe(0)
    expect(countdownText(0)).toBe('00:00:00')
  })

  it('is live until the end and not one millisecond after', () => {
    const campaign = { id: 4, ends_at: end }
    expect(isLive(campaign, at('2026-11-05T11:59:59+02:00'))).toBe(true)
    expect(isLive(campaign, at('2026-11-05T12:00:00+02:00'))).toBe(false)
    expect(isLive(null, 0)).toBe(false)
    expect(isLive({ id: 4, ends_at: 'not a date' }, 0)).toBe(false)
  })

  it('shows days only when there are any', () => {
    expect(countdownParts((2 * 3600 + 14 * 60 + 58) * 1000)).toMatchObject({ d: 0, h: 2, m: 14, s: 58 })
    expect(countdownText((2 * 3600 + 14 * 60 + 58) * 1000)).toBe('02:14:58')
    expect(countdownText((3 * 86400 + 3600) * 1000)).toBe('3d 01:00:00')
  })

  it('ignores a payload without a usable clock', () => {
    expect(clockOffset(undefined, 1, 2)).toBe(0)
  })
})

describe('the progress bar', () => {
  it('exists only when core measured the campaign start', () => {
    expect(barOf(null)).toBeNull()
    expect(barOf(undefined)).toBeNull()
    expect(barOf({ start: 0, sold: 0, left: 4, pct: 0 })).toBeNull()
  })

  it('prints core\'s measurement and nothing derived from a discount', () => {
    expect(barOf({ start: 65, sold: 52, left: 13, pct: 80 })).toEqual({ state: 'selling', pct: 80, left: 13, sold: 52 })
    expect(barOf({ start: 10, sold: 14, left: 0, pct: 140 })).toEqual({ state: 'sold_out', pct: 100, left: 0, sold: 14 })
  })

  // the review of 2026-11-05: a card read "50% sold" and "Sold out" at once
  it('never lets the bar and the stock label contradict each other', () => {
    // a unit can still be bought: a share, never 100 %
    expect(barOf({ start: 4, sold: 6, left: 3, pct: 150 })).toEqual({ state: 'selling', pct: 99, left: 3, sold: 6 })
    // nothing left and the campaign sold every unit: a full bar
    expect(barOf({ start: 4, sold: 4, left: 0, pct: 100, state: 'sold_out' })).toEqual({ state: 'sold_out', pct: 100, left: 0, sold: 4 })
    // nothing left, but not by this deal's sales (an adjustment): NO share is claimed
    expect(barOf({ start: 4, sold: 2, left: 0, pct: 50 })).toEqual({ state: 'unavailable', pct: null, left: 0, sold: 2 })
    expect(barOf({ start: 4, sold: 2, left: 0, pct: null, state: 'unavailable' }).pct).toBeNull()
    for (const start of [1, 4, 9]) for (const sold of [0, 2, 4, 12]) for (const left of [0, 1, 7]) {
      const bar = barOf({ start, sold, left, pct: Math.round((sold / start) * 100) })
      if (bar.state === 'selling') expect(bar.left > 0 && bar.pct < 100).toBe(true)
      else if (bar.state === 'sold_out') expect(bar.left === 0 && bar.pct === 100).toBe(true)
      else expect(bar.left === 0 && bar.pct === null).toBe(true)
    }
  })

  it('writes the header clock at one length whatever the time left', () => {
    expect(clockText(9 * 3600e3 + 59 * 60e3 + 59e3)).toBe('09:59:59')
    expect(clockText(10 * 3600e3)).toBe('10:00:00')
    expect(clockText(34 * 3600e3 + 5 * 60e3 + 9e3)).toBe('34:05:09') // the days are carried into the hours
    expect(clockText(0)).toBe('00:00:00')
  })

  it('attaches the measurement to the page cards by product id, and leaves the others bare', () => {
    const out = withProgress([card(1, 30, 700), card(2, 30, 700)], { 1: { start: 10, sold: 4, left: 6, pct: 40 } })
    expect(out[0].campaign).toEqual({ start: 10, sold: 4, left: 6, pct: 40 })
    expect(out[1].campaign).toBeNull()
  })
})

describe('the page filters and sort', () => {
  const cards = [card(1, 20, 800), card(2, 55, 450, 'fashion'), card(3, 35, 650, 'watches', 0), card(4, 35, 640, 'watches', 2)]

  it('offers a chip per category the campaign has, busiest first', () => {
    expect(categoryChips(cards).map((c) => [c.key, c.count])).toEqual([
      ['watches', 3],
      ['fashion', 1],
    ])
  })

  it('offers only the discount steps that split the list', () => {
    expect(discountSteps(cards)).toEqual([30, 40, 50])                      // 20%+ is every card
    expect(discountSteps([card(1, 10, 900)])).toEqual([])
  })

  it('sorts the whole campaign by the biggest discount by default', () => {
    expect(arrangeDeals(cards).map((c) => c.id)).toEqual([2, 3, 4, 1])
    expect(arrangeDeals(cards, { sort: 'price_asc' }).map((c) => c.id)).toEqual([2, 4, 3, 1])
    expect(arrangeDeals(cards, { sort: 'price_desc' }).map((c) => c.id)).toEqual([1, 3, 4, 2])
  })

  it('puts the scarcest in-stock product first and a sold-out one last', () => {
    expect(arrangeDeals(cards, { sort: 'ending' }).map((c) => c.id)).toEqual([4, 1, 2, 3])
  })

  it('filters by category and minimum discount together', () => {
    expect(arrangeDeals(cards, { category: 'watches', minDiscount: 30 }).map((c) => c.id)).toEqual([3, 4])
    expect(arrangeDeals(cards, { category: 'fashion', minDiscount: 60 })).toEqual([])
  })
})

describe('prices', () => {
  it('prints a price as the shop does in each language, Western digits in both', () => {
    expect(money(3900, 'en')).toBe('EGP 3,900')
    expect(money(3900, 'ar')).toBe('3,900 ج.م')
    expect(money(1249.5, 'en')).toBe('EGP 1,249.5')
  })

  it('says what is saved only on a valid sale', () => {
    expect(savedOn({ amount: 2400, sale_amount: 950, has_sale: true })).toBe(1450)
    expect(savedOn({ amount: 2400, sale_amount: 950, has_sale: false })).toBe(0)
    expect(savedOn(undefined)).toBe(0)
  })
})

describe('Hot Deals mode', () => {
  it('is on only for the campaign it was switched on for', () => {
    expect(hotModeOn('7', { id: 7 })).toBe(true)
    expect(hotModeOn('7', { id: 8 })).toBe(false)     // a NEW campaign does not inherit an old switch
    expect(hotModeOn('7', null)).toBe(false)          // the campaign ended: the mode ends with it
    expect(hotModeOn(null, { id: 7 })).toBe(false)
  })
})

describe('the line under a deal card (2026-11-10: no empty bar, honest urgency)', () => {
  const live = (sold, left, start = 10) => barOf({ start, sold, left, pct: Math.round((sold / start) * 100), state: left > 0 ? 'selling' : sold >= start ? 'sold_out' : 'unavailable' })
  it('shows a NEW DEAL badge, no bar, when nothing sold since the restock', () => {
    expect(dealLineOf(live(0, 9))).toEqual({ kind: 'new', left: 9, low: null })
  })
  it('adds the stock line to the badge when few are left', () => {
    expect(dealLineOf(live(0, 3))).toEqual({ kind: 'new', left: 3, low: 3 })
  })
  it('draws the real bar with the stock line as its caption once one unit sold and few are left', () => {
    expect(dealLineOf(live(7, 3))).toEqual({ kind: 'bar', sold: 7, left: 3, pct: 70, low: 3 })
  })
  it('draws the real bar as before when sold and stock is above the threshold', () => {
    expect(dealLineOf(live(2, 8))).toEqual({ kind: 'bar', sold: 2, left: 8, pct: 20, low: null })
    expect(dealLineOf(live(2, LOW_STOCK + 1)).low).toBe(null)
    expect(dealLineOf(live(2, LOW_STOCK)).low).toBe(LOW_STOCK)
  })
  it('keeps sold out as it was, and draws nothing without a measurement (never a made-up value)', () => {
    expect(dealLineOf(live(10, 0))).toEqual({ kind: 'all_sold', sold: 10 })
    expect(dealLineOf(live(3, 0))).toEqual({ kind: 'gone' })
    expect(dealLineOf(live(2, 8), false)).toEqual({ kind: 'gone' })
    expect(dealLineOf(null)).toEqual({ kind: 'none' })
    expect(dealLineOf(barOf({ start: 0, sold: 0, left: 4 }))).toEqual({ kind: 'none' })
  })
  it('says how few are left, the Arabic counted 1 / 2 / 3-5', () => {
    expect(lowStockText(1, 'en')).toBe('Only 1 left')
    expect(lowStockText(4, 'en')).toBe('Only 4 left')
    expect(lowStockText(1, 'ar')).toBe('باقي قطعة واحدة بس')
    expect(lowStockText(2, 'ar')).toBe('باقي قطعتين بس')
    expect(lowStockText(3, 'ar')).toBe('باقي 3 قطع بس')
    expect(lowStockText(5, 'ar')).toBe('باقي 5 قطع بس')
  })
})
