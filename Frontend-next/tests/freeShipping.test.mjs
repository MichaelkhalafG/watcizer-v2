import { describe, expect, it } from 'vitest'
import { freeShippingOf, shippingCharged } from '../src/lib/freeShipping'

describe('free shipping over the threshold (the same rule as core)', () => {
  it('is off without a positive threshold', () => {
    for (const over of [null, undefined, 0, '0', -5, 'x']) expect(freeShippingOf(over, 50000)).toEqual({ over: null, free: false, remaining: null })
  })
  it('just under, exactly at and above 10,000', () => {
    expect(freeShippingOf(10000, 9999.99)).toEqual({ over: 10000, free: false, remaining: 0.01 })
    expect(freeShippingOf(10000, 10000)).toEqual({ over: 10000, free: true, remaining: 0 })
    expect(freeShippingOf('10000.00', 12500)).toEqual({ over: 10000, free: true, remaining: 0 })
  })
  it('compares in cents, so a float sum that lands on the threshold ships free', () => {
    expect(freeShippingOf(10000, 0.1 + 0.2 + 9999.7).free).toBe(true)
  })
  it('charges the governorate only when the order does not ship free', () => {
    expect(shippingCharged(85, freeShippingOf(10000, 9000))).toBe(85)
    expect(shippingCharged(85, freeShippingOf(10000, 10000))).toBe(0)
    expect(shippingCharged(null, null)).toBe(0)
  })
})
