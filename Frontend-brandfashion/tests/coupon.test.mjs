import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { couponMessage, couponQuery, discountLabel, isHeldRefusal, payable, promotionOf, rewardTitle, setStoredCoupon, storedCoupon, subscribeCoupon, wheelNote } from '../src/lib/coupon'

// The coupon on the storefront (M1u, 2026-10-30): the kept code, the cart answer's promotion read defensively, the
// words shown, the amount shown to pay — and the GA4 events carrying the code and the per-unit discount in GA4's own
// fields. A TWIN (both apps). Nothing here prices a discount: core does, and these tests hold that the page only
// reads what core sent.

const store = new Map()
Object.defineProperty(globalThis, 'localStorage', {
  value: { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) },
  configurable: true,
  writable: true,
})
beforeEach(() => store.clear())

describe('the kept code', () => {
  it('is kept, trimmed, sent on every cart request, and forgotten when emptied — and tells its readers', () => {
    const heard = vi.fn()
    const stop = subscribeCoupon(heard)
    expect(storedCoupon()).toBeNull()
    expect(couponQuery()).toBe('')
    setStoredCoupon('  Black1000 ')
    expect(storedCoupon()).toBe('Black1000')
    expect(couponQuery()).toBe('?coupon=Black1000')
    setStoredCoupon('')
    expect(storedCoupon()).toBeNull()
    expect(heard).toHaveBeenCalledTimes(2)
    stop()
  })
})

describe("the cart answer's promotion", () => {
  const totals = {
    promotion: {
      discount: 2000,
      applied: [{ rule_id: 9, code: 'Black1000', label_en: 'Black Friday', label_ar: 'الجمعة البيضاء', amount: 2000 }],
      lines: [{ item_id: 41, amount: 2000 }],
      coupon: { code: 'Black1000', status: 'applied', messages: { en: 'Code applied.', ar: 'تم تطبيق الكود.' } },
    },
  }

  it('is read as core sent it, per line', () => {
    const p = promotionOf(totals)
    expect(p.discount).toBe(2000)
    expect(p.lines).toEqual({ 41: 2000 })
    expect(p.coupon.status).toBe('applied')
  })

  it('reads an older core (no promotion) as no discount, never as an error', () => {
    expect(promotionOf({ count: 1 })).toEqual({ available: false, discount: 0, applied: [], lines: {}, coupon: null, rewards: [] })
    expect(promotionOf(null).discount).toBe(0)
  })

  it('has the coupon field only when core sends a promotion block — an empty one counts, an older core has none', () => {
    expect(promotionOf(totals).available).toBe(true)
    expect(promotionOf({ promotion: { discount: 0, applied: [], lines: [], coupon: null } }).available).toBe(true)
    expect(promotionOf({ count: 1 }).available).toBe(false)
    expect(promotionOf({ promotion: null }).available).toBe(false)
    expect(promotionOf(null).available).toBe(false)
  })

  it("shows the rule's own words in the page's language, and core's sentence for a code", () => {
    const p = promotionOf(totals)
    expect(discountLabel(p.applied, true)).toBe('الجمعة البيضاء')
    expect(discountLabel(p.applied, false)).toBe('Black Friday')
    expect(discountLabel([], true)).toBe('الخصم')
    expect(couponMessage(p.coupon, true)).toBe('تم تطبيق الكود.')
  })

  it('shows goods − discount + delivery, never below zero', () => {
    expect(payable(8000, 2000, 85)).toBe(6085)
    expect(payable(900, 1000, 0)).toBe(0)
  })
})

describe('GA4 carries the code and the discount in its own fields', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    delete globalThis.window
  })

  it('begin_checkout and purchase', async () => {
    vi.resetModules()
    vi.stubEnv('NEXT_PUBLIC_GA4_MEASUREMENT_ID', 'G-TEST12345')
    const gtag = vi.fn()
    globalThis.window = { gtag, fbq: vi.fn(), ttq: { track: vi.fn() } }
    const px = await import('../src/scripts/pixels')

    px.trackInitiateCheckout({ value: 6000, coupon: 'Black1000', contents: [{ id: 41, quantity: 2, price: 4000, discount: 1000 }] })
    expect(gtag).toHaveBeenCalledWith('event', 'begin_checkout', {
      currency: 'EGP',
      value: 6000,
      coupon: 'Black1000',
      items: [{ item_id: '41', price: 4000, quantity: 2, discount: 1000 }],
    })

    px.trackPurchase({ orderNumber: 'WZ-1', value: 6000, shipping: 85, coupon: 'Black1000', contents: [{ id: 41, quantity: 2, price: 4000 }] })
    expect(gtag).toHaveBeenCalledWith('event', 'purchase', expect.objectContaining({ value: 6000, shipping: 85, coupon: 'Black1000' }))
  })

  it('sends no coupon field when there is none', async () => {
    vi.resetModules()
    vi.stubEnv('NEXT_PUBLIC_GA4_MEASUREMENT_ID', 'G-TEST12345')
    const gtag = vi.fn()
    globalThis.window = { gtag, fbq: vi.fn(), ttq: { track: vi.fn() } }
    const px = await import('../src/scripts/pixels')
    px.trackInitiateCheckout({ value: 100, contents: [{ id: 1, quantity: 1, price: 100 }] })
    expect(gtag.mock.calls[0][2]).not.toHaveProperty('coupon')
  })
})

describe("the wheel's kinds on a code (2026-11-09)", () => {
  it('names the gift a rule grants, and nothing from an older core', () => {
    const p = promotionOf({ promotion: { discount: 0, applied: [], lines: [], coupon: null, rewards: [{ product_id: 25652, quantity: 1, title: { en: 'Asad 100 ml', ar: null } }, { product_id: 0 }, null] } })
    expect(p.rewards).toHaveLength(1)
    expect(rewardTitle(p.rewards[0], false)).toBe('Asad 100 ml')
    expect(rewardTitle(p.rewards[0], true)).toBe('Asad 100 ml')
    expect(promotionOf({ promotion: { discount: 0, applied: [], lines: [], coupon: null } }).rewards).toEqual([])
  })
  it("words the note by kind: a friend's invite, a gift, a bound code", () => {
    const bound = { code: 'SPIN-A', status: 'applied', wheel: { kind: 'code', phone_hint: '010•••••678' } }
    expect(wheelNote(bound, false)).toContain('010•••••678')
    expect(wheelNote({ ...bound, wheel: { ...bound.wheel, kind: 'invite' } }, false)).toContain('first order')
    expect(wheelNote({ ...bound, wheel: { ...bound.wheel, kind: 'invite' } }, false)).not.toContain('010•••••678')
    expect(wheelNote({ ...bound, wheel: { ...bound.wheel, kind: 'gift' } }, false)).toContain('gift')
    expect(wheelNote({ ...bound, wheel: { ...bound.wheel, kind: 'gift' } }, true)).toContain('هديتك')
    expect(wheelNote({ code: 'X', status: 'applied' }, false)).toBe('')
  })
  it('holds a code the shopper can put right here, forgets the rest', () => {
    const w = { phone_hint: '010•••••678' }
    for (const status of ['phone_mismatch', 'invite_own', 'invite_not_first', 'invite_used']) expect(isHeldRefusal({ status, wheel: w })).toBe(true)
    expect(isHeldRefusal({ status: 'no_eligible_items', wheel: w })).toBe(true)
    expect(isHeldRefusal({ status: 'no_eligible_items' })).toBe(false)
    for (const status of ['invalid', 'expired', 'wheel_used', 'gift_out_of_stock', 'not_best', 'minimum_not_reached']) expect(isHeldRefusal({ status, wheel: w })).toBe(false)
    // one offer per order (2026-11-10): a code refused because the cart holds a campaign item is FORGOTTEN, never held
    expect(isHeldRefusal({ status: 'on_offer' })).toBe(false)
    expect(isHeldRefusal({ status: 'on_offer', wheel: w })).toBe(false)
    expect(isHeldRefusal(null)).toBe(false)
  })
})
