import { describe, expect, it } from 'vitest'
import { restoreFromLink, withoutRestore } from '../src/lib/cartRestore.js'

// The abandoned-cart e-mail's button (2026-11-24): a guest's cart comes back on another browser, never over a cart this
// browser is filling, never for a signed-in shopper.
const store = (token = null) => {
  const m = new Map(token ? [['wz_guest_token', token]] : [])
  return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, v), m }
}
const T = '0b5c6a1e-1111-4222-8333-944444444444'

describe('the cart link of the abandoned-cart e-mail', () => {
  it('switches an empty browser to the e-mailed cart', async () => {
    const s = store('aaaaaaaa-1111-4222-8333-944444444444')
    expect(await restoreFromLink('?restore=12&s=abc', { storage: s, restore: async () => ({ guest_token: T }), read: async () => ({ cart_item: [] }) })).toBe(true)
    expect(s.getItem('wz_guest_token')).toBe(T)
  })
  it('keeps a cart this browser is filling, a signed-in shopper, a bad link and the same cart', async () => {
    const full = store('aaaaaaaa-1111-4222-8333-944444444444')
    expect(await restoreFromLink('?restore=12&s=abc', { storage: full, restore: async () => ({ guest_token: T }), read: async () => ({ cart_item: [{ id: 1 }] }) })).toBe(false)
    expect(full.getItem('wz_guest_token')).not.toBe(T)
    expect(await restoreFromLink('?restore=12&s=abc', { storage: store(), loggedIn: true, restore: async () => ({ guest_token: T }) })).toBe(false)
    expect(await restoreFromLink('?restore=12&s=abc', { storage: store(), restore: async () => null })).toBe(false)
    expect(await restoreFromLink('?restore=12&s=abc', { storage: store(T), restore: async () => ({ guest_token: T }) })).toBe(false)
    expect(await restoreFromLink('', { storage: store(), restore: async () => ({ guest_token: T }) })).toBe(false)
  })
  it('drops the two parameters from the address and keeps the rest', () => {
    expect(withoutRestore('?restore=12&s=abc')).toBe('')
    expect(withoutRestore('?restore=12&s=abc&utm_source=mail')).toBe('?utm_source=mail')
  })
})
