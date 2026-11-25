import { v2CartRead, v2CartRestore } from './v2Cart'

const KEY = 'wz_guest_token'

/**
 * The abandoned-cart e-mail's button (2026-11-24, D4): `/cart?restore=<id>&s=<signature>` brings a GUEST's cart back on
 * this browser. Core checks the link (the reminder's own, under 14 days old, this shop, still a guest cart) and answers
 * the cart's token; the browser then uses that token. Never for a signed-in shopper (their cart is the account's), and
 * never over a cart this browser is already filling. Returns true when the cart was switched (the page re-reads it).
 */
export async function restoreFromLink(search, { read = v2CartRead, restore = v2CartRestore, storage = globalThis.localStorage, loggedIn = false } = {}) {
  const params = new URLSearchParams(search || '')
  const r = params.get('restore')
  const s = params.get('s')
  if (!r || !s || loggedIn || !storage) return false
  const found = await restore(r, s)
  const token = found?.guest_token
  if (typeof token !== 'string' || token === '' || storage.getItem(KEY) === token) return false
  let current = null
  try {
    current = await read()
  } catch {
    current = null
  }
  if ((current?.cart_item || []).length > 0) return false
  storage.setItem(KEY, token)
  return true
}

/** The page's own address without the link's two parameters (kept out of history and analytics). */
export function withoutRestore(search) {
  const params = new URLSearchParams(search || '')
  params.delete('restore')
  params.delete('s')
  const rest = params.toString()
  return rest ? `?${rest}` : ''
}
