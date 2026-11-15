import { beforeEach, describe, expect, it } from 'vitest'
import { PICKUP_OFF, inLang, pickupFrom, readyWindowFor, storeNames } from '../src/lib/pickup'
import { couponParams, couponQuery, setCouponFulfilment, setCouponPhone, setStoredCoupon } from '../src/lib/coupon'

// "Pick up in store" on the storefront (click & collect, 2026-11-14): core's answer read defensively (anything odd is
// "not offered", so the delivery checkout is unchanged), the window the slowest line decides, and the cart preview asked
// as a pickup only while a code is kept. A TWIN (both apps).

const store = new Map()
Object.defineProperty(globalThis, 'localStorage', {
  value: { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) },
  configurable: true,
  writable: true,
})
beforeEach(() => {
  store.clear()
  setCouponPhone('')
  setCouponFulfilment(false)
})

const answer = {
  data: {
    enabled: true,
    stores: [
      { id: 3, name: { en: 'Arkadia Mall', ar: 'أركاديا مول' }, address: { en: 'Corniche El Nil', ar: 'كورنيش النيل' }, hours: { en: 'Every day', ar: 'يوميًا' }, map_url: 'https://maps.app.goo.gl/x', phone: '01551096234' },
      { id: 4, name: { en: 'Heliopolis', ar: 'مصر الجديدة' }, address: { en: '90 El-Thawra Street', ar: '٩٠ شارع الثورة' }, hours: { en: null, ar: null }, map_url: null, phone: null },
    ],
    hold_hours: 48,
    windows: {
      express: { kind: 'express', min: 1, max: 2, sentence: { en: 'Ready for pickup in 1–2 business days', ar: 'جاهز للاستلام خلال ١–٢ يوم عمل' } },
      market: { kind: 'market', min: 4, max: 7, sentence: { en: 'Ready for pickup in 4–7 business days', ar: 'جاهز للاستلام خلال ٤–٧ أيام عمل' } },
    },
  },
}

describe("core's answer", () => {
  it('is offered only when switched on with at least one store', () => {
    const p = pickupFrom(answer)
    expect(p.enabled).toBe(true)
    expect(p.stores.map((s) => s.id)).toEqual([3, 4])
    expect(p.hold_hours).toBe(48)
    expect(pickupFrom(answer.data).enabled).toBe(true)
    expect(pickupFrom({ data: { ...answer.data, enabled: false } })).toBe(PICKUP_OFF)
    expect(pickupFrom({ data: { ...answer.data, stores: [] } })).toBe(PICKUP_OFF)
    expect(pickupFrom(null)).toBe(PICKUP_OFF)
    expect(pickupFrom('<html>')).toBe(PICKUP_OFF)
  })

  it("names the stores and picks the page's language", () => {
    const p = pickupFrom(answer)
    expect(storeNames(p, false)).toBe('Arkadia Mall · Heliopolis')
    expect(storeNames(p, true)).toBe('أركاديا مول · مصر الجديدة')
    expect(inLang({ en: 'A', ar: '' }, true)).toBe('A')
    expect(inLang(null, true)).toBe('')
  })
})

describe('the ready window', () => {
  it('is the slowest line: one sourced line makes the order wait for it', () => {
    const { windows } = pickupFrom(answer)
    expect(readyWindowFor([{ type_stock: 'Express' }, { type_stock: 'Express' }], windows).kind).toBe('express')
    expect(readyWindowFor([{ type_stock: 'Express' }, { type_stock: 'Market' }], windows).kind).toBe('market')
    expect(readyWindowFor([{ bucket: 'Market' }], windows).max).toBe(7)
    expect(readyWindowFor([], windows).kind).toBe('express')
    expect(readyWindowFor([{ type_stock: 'Express' }], null)).toBeNull()
  })
})

describe('the cart preview on a pickup', () => {
  it('is asked as a pickup only while a code is kept, beside the phone', () => {
    setCouponFulfilment(true)
    expect(couponQuery()).toBe('')
    expect(couponParams()).toEqual({})
    setStoredCoupon('SHIPFREE')
    expect(couponParams()).toEqual({ coupon: 'SHIPFREE', fulfilment: 'pickup' })
    expect(couponQuery()).toBe('?coupon=SHIPFREE&fulfilment=pickup')
    setCouponPhone('01012345678')
    expect(couponQuery()).toBe('?coupon=SHIPFREE&phone=01012345678&fulfilment=pickup')
    setCouponFulfilment(false)
    expect(couponParams()).toEqual({ coupon: 'SHIPFREE', phone: '01012345678' })
  })
})
