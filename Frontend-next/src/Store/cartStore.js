import http from '../Context/api'
import { trackAddToCart } from '../scripts/pixels'

let listeners = new Set()
let currentCart = null
// Extra reactive state (kept separate from the cart snapshot so existing
// consumers that read `cart` are unaffected). Exposed via getExtraSnapshot.
let extra = { totals: null, warnings: [], lastRemoved: null }
let undoTimer = null

const CART_KEY = 'user_cart'
const GUEST_KEY = 'wz_guest_token'

const generateTimestamp = () => new Date().toISOString()

export const getItemKey = (item) =>
  item.product_id !== null && item.product_id !== undefined
    ? `product_${item.product_id}`
    : `offer_${item.offer_id}`

// Guest token (mirrors api.jsx) — ensure one exists for guest cart operations.
// SSR guard: no localStorage on the server → no token to mint there.
export const getGuestToken = () => {
  if (typeof window === 'undefined') return null
  let t = localStorage.getItem(GUEST_KEY)
  if (!t) {
    t = crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
    localStorage.setItem(GUEST_KEY, t)
  }
  return t
}

const createEmptyCart = (userId = null) => ({
  id: 1,
  user_id: userId,
  created_at: generateTimestamp(),
  updated_at: generateTimestamp(),
  cart_item: [],
})

const notify = () => {
  for (const listener of listeners) listener()
}

const readCart = () => {
  // SSR guard: no sessionStorage on the server → render an empty cart; the client
  // reads the real persisted cart after hydration (useSyncExternalStore).
  if (typeof window === 'undefined') return createEmptyCart()
  try {
    const raw = sessionStorage.getItem(CART_KEY)
    return raw ? JSON.parse(raw) : createEmptyCart()
  } catch {
    return createEmptyCart()
  }
}

const writeCart = (cart) => {
  currentCart = cart
  sessionStorage.setItem(CART_KEY, JSON.stringify(cart))
  notify()
}

const updateCart = (updater) => {
  const updated = updater(currentCart || readCart())
  updated.updated_at = generateTimestamp()
  writeCart(updated)
}

// Replace `extra` (new object identity) so useSyncExternalStore re-renders.
const setExtra = (patch) => {
  extra = { ...extra, ...patch }
  notify()
}

// ── The cart's source of truth is the SERVER cart (2026-09-28) ─────────────────────────────
// This tab's copy (sessionStorage `user_cart`) is a display cache of it. It used to drift:
// a write the server REFUSED (e.g. "Requested quantity exceeds available stock") left the tab
// saying the item was added while the server kept the old quantity, and the checkout's
// validation then judged a cart the shopper could not see. Now a refused write is rolled back
// in the tab and reported, and the checkout reconciles the two before it validates
// (`reconcile`). Do not add a third copy.

// Server sync for one line. The backend AddToCart OVERWRITES the line quantity to the value
// sent, so we always pass the absolute quantity. It never rejects: it resolves to
// { ok, status, message } — `message` is the server's own sentence when it refused.
// `ok` is what the AddToCart pixel event waits on: an add the API refused is not a cart
// addition, and counting it teaches the ad auction to chase people whose basket never changed.
const syncLine = (item, quantity) =>
  http
    .post('add_to_cart', {
      product_id: item.product_id ?? null,
      offer_id: item.offer_id ?? null,
      quantity,
      piece_price: parseFloat(item.piece_price),
      total_price: quantity * parseFloat(item.piece_price),
      type_stock: item.type_stock ?? 'Market',
      color_band: item.color_band ?? null,
      color_dial: item.color_dial ?? null,
    })
    .then(() => ({ ok: true, status: 200, message: null }))
    .catch((err) => {
      console.warn('Cart sync failed:', err)
      return {
        ok: false,
        status: err?.response?.status ?? 0,
        message: err?.response?.data?.message ?? null,
      }
    })

// A line's SERVER key: the server keeps one row per (product, offer, colours, stock type). The
// tab keys a line by product/offer alone, so a repeat add MUST reuse the existing line's colours
// and stock type — a product card (no colours) adding to a line the product page created (with
// colours) used to make a SECOND server row for the same product.
const serverKeyOf = (item) => ({
  type_stock: item.type_stock ?? 'Market',
  color_band: item.color_band ?? null,
  color_dial: item.color_dial ?? null,
})

// The one place AddToCart is fired. Both write paths land here — a new line from
// addItem, and an INCREASE from updateQuantity (the drawer's "+", and the PDP when
// the item is already in the cart, which used to fire nothing at all). `added` is
// the delta, so the value reflects this action rather than the whole line, and a
// DECREASE fires nothing: removing two of something is not an add.
const trackConfirmedAdd = (synced, { id, name, unitPrice, added }) =>
  synced.then((result) => {
    if (result.ok && added > 0) {
      trackAddToCart({ id, name, value: parseFloat(unitPrice) * added, quantity: added })
    }
    return result
  })

// Put the tab's copy of ONE line back as it was before a refused write.
const restoreLine = (key, previous) => {
  updateCart((cart) => {
    const others = cart.cart_item.filter((i) => getItemKey(i) !== key)
    return { ...cart, cart_item: previous ? [...others, previous].sort((a, b) => a.id - b.id) : others }
  })
}

// Server delete for a removed line. Mirrors syncLine: fire-and-forget, errors are
// swallowed. The line is keyed by product_id/offer_id (same as getItemKey), so the
// backend removes every cart_items row for that product/offer in this cart. This is
// what keeps the DB cart in sync — before, removal was local-only, leaving phantom
// rows that inflated the next order's server total ("Order total mismatch").
const syncRemove = (item) =>
  http
    .post('remove_from_cart', {
      product_id: item.product_id ?? null,
      offer_id: item.offer_id ?? null,
    })
    .catch((err) => console.warn('Cart remove sync failed:', err))

// Stable references for the SSR render (useSyncExternalStore requires a
// getServerSnapshot; returning a fresh object each call would loop forever).
const SERVER_EMPTY_CART = createEmptyCart()
const SERVER_EMPTY_EXTRA = { totals: null, warnings: [], lastRemoved: null }

export const cartStore = {
  getSnapshot: () => currentCart ?? (currentCart = readCart()),
  getExtraSnapshot: () => extra,
  // Server snapshots: no web storage on the server, so render empty/neutral.
  getServerSnapshot: () => SERVER_EMPTY_CART,
  getExtraServerSnapshot: () => SERVER_EMPTY_EXTRA,
  subscribe: (callback) => {
    listeners.add(callback)
    return () => listeners.delete(callback)
  },

  addItem: ({
    product_id = null,
    offer_id = null,
    quantity = 1,
    piece_price,
    type_stock = 'Market',
    color_band = null,
    color_dial = null,
    // Optional, for analytics only — the cart itself resolves names from the catalog.
    // Passing it makes the event readable in Meta's Events Manager, where a bare id is
    // not something a human can check a report against.
    name = undefined,
  }) => {
    let absoluteQty = quantity
    let previous = null
    let syncAs = { product_id, offer_id, piece_price, type_stock, color_band, color_dial }
    updateCart((cart) => {
      const now = generateTimestamp()
      const items = [...cart.cart_item]
      const index = items.findIndex((i) => getItemKey(i) === getItemKey({ product_id, offer_id }))
      if (index !== -1) {
        const existing = items[index]
        previous = existing
        // Same server row as the existing line: its colours and stock type, not this call's.
        syncAs = { product_id, offer_id, piece_price: existing.piece_price, ...serverKeyOf(existing) }
        const newQty = existing.quantity + quantity
        absoluteQty = newQty
        items[index] = {
          ...existing,
          quantity: newQty,
          total_price: (newQty * parseFloat(existing.piece_price)).toFixed(2),
          updated_at: now,
        }
      } else {
        items.push({
          id: Date.now(),
          cart_id: cart.id,
          product_id,
          offer_id,
          quantity,
          piece_price: parseFloat(piece_price).toFixed(2),
          total_price: (quantity * parseFloat(piece_price)).toFixed(2),
          type_stock,
          color_band,
          color_dial,
          created_at: now,
          updated_at: now,
        })
      }
      return { ...cart, cart_item: items }
    })
    const key = getItemKey({ product_id, offer_id })
    // The server's answer wins: a refused add is rolled back in the tab, and the caller gets
    // { ok: false, message } to tell the shopper why (it used to say "Added to cart!").
    const synced = syncLine(syncAs, absoluteQty).then((result) => {
      if (!result.ok) restoreLine(key, previous)
      return result
    })
    // Return the sync promise so callers can await it for a pending/disabled UI.
    // The AddToCart event rides on it rather than firing here: see trackConfirmedAdd.
    return trackConfirmedAdd(synced, {
      id: product_id ?? offer_id,
      name,
      unitPrice: piece_price,
      added: quantity,
    })
  },

  updateQuantity: (identifier, newQuantity) => {
    let synced = null
    let previousQty = 0
    let previous = null
    updateCart((cart) => {
      const items = cart.cart_item.map((item) => {
        if (getItemKey(item) === identifier) {
          previousQty = item.quantity
          previous = item
          synced = { ...item, quantity: newQuantity }
          return {
            ...item,
            quantity: newQuantity,
            total_price: (parseFloat(item.piece_price) * newQuantity).toFixed(2),
            updated_at: generateTimestamp(),
          }
        }
        return item
      })
      return { ...cart, cart_item: items }
    })
    // backend overwrites to absolute qty; return the promise for a pending UI
    if (!synced) return Promise.resolve({ ok: true, status: 200, message: null })
    const result = syncLine(synced, newQuantity).then((r) => {
      if (!r.ok) restoreLine(identifier, previous)
      return r
    })
    return trackConfirmedAdd(result, {
      id: synced.product_id ?? synced.offer_id,
      unitPrice: synced.piece_price,
      added: newQuantity - previousQty,
    })
  },

  removeItem: (identifier) => {
    let removed = null
    updateCart((cart) => {
      removed = cart.cart_item.find((i) => getItemKey(i) === identifier) || null
      return { ...cart, cart_item: cart.cart_item.filter((i) => getItemKey(i) !== identifier) }
    })
    if (removed) {
      // Delete the row from the DB cart too (was local-only before, which left
      // phantom cart_items that inflated the next order's server total). Undo
      // re-adds the line via syncLine, which recreates the row.
      syncRemove(removed)
      if (undoTimer) clearTimeout(undoTimer)
      setExtra({ lastRemoved: removed })
      undoTimer = setTimeout(() => setExtra({ lastRemoved: null }), 5000)
    }
  },

  undoRemove: () => {
    const item = extra.lastRemoved
    if (!item) return
    if (undoTimer) clearTimeout(undoTimer)
    updateCart((cart) => {
      if (cart.cart_item.some((i) => getItemKey(i) === getItemKey(item))) return cart
      return { ...cart, cart_item: [...cart.cart_item, item] }
    })
    setExtra({ lastRemoved: null })
    syncLine(item, item.quantity)
  },

  clearCart: () => {
    writeCart(createEmptyCart(currentCart?.user_id))
    setExtra({ totals: null, warnings: [] })
  },

  // Validate stock/prices against the live catalog; stores server totals+warnings.
  validateCart: async () => {
    try {
      const { data } = await http.post('cart/validate', {})
      setExtra({ totals: data?.totals ?? null, warnings: data?.warnings ?? [] })
      return data
    } catch {
      // The check itself could not run (network, server error). `failed` lets the checkout step
      // SAY so and offer a retry (2026-09-28) instead of silently treating it as valid.
      return { valid: true, warnings: [], failed: true }
    }
  },

  // Make the SERVER cart match what the shopper sees, then rebuild this tab from the server's
  // answer (2026-09-28, checkout fix 2). Called right before the checkout validates, so an old
  // drift — a line stored at an old price, a quantity the server never accepted, a stray second
  // row for the same product, an expired guest cart — is healed instead of blocking checkout.
  // `priceOf(line)` gives the line's CURRENT price (the card's rule); lines are sent at it.
  // Resolves to { refused: [{ key, message }], serverLines: number, failed: bool }.
  reconcile: async (priceOf) => {
    const lines = (currentCart || readCart()).cart_item
    const refused = []
    for (const line of lines) {
      const price = priceOf ? priceOf(line) : null
      const item = price ? { ...line, piece_price: price } : line
      const r = await syncLine(item, line.quantity)
      if (!r.ok) refused.push({ key: getItemKey(line), message: r.message, status: r.status })
    }
    let server
    try {
      const { data } = await http.get('me/cart')
      server = Array.isArray(data?.cart_item) ? data.cart_item : []
    } catch {
      return { refused, serverLines: 0, failed: true }
    }
    // Server rows the shopper cannot see (another product, or a second row for the same one
    // under different colours) are removed, one by one, by id.
    const wanted = new Map(lines.map((l) => [getItemKey(l), serverKeyOf(l)]))
    const keep = []
    for (const row of server) {
      const want = wanted.get(getItemKey(row))
      const sameRow = want && ['type_stock', 'color_band', 'color_dial'].every((k) => (row[k] ?? null) === (want[k] ?? null))
      if (sameRow && !keep.some((k) => getItemKey(k) === getItemKey(row))) keep.push(row)
      else {
        try {
          await http.delete(`delete_cart/${row.id}`)
        } catch {
          // A stray row that will not delete is left; validation still judges it.
        }
      }
    }
    writeCart({ ...(currentCart || readCart()), updated_at: generateTimestamp(), cart_item: keep })
    return { refused, serverLines: keep.length, failed: false }
  },

  // Hydrate the local cart from the server (used after login/merge).
  fetchCart: async () => {
    try {
      const { data } = await http.get('me/cart')
      if (data && Array.isArray(data.cart_item)) {
        writeCart({
          id: data.id ?? 1,
          user_id: data.user_id ?? null,
          created_at: generateTimestamp(),
          updated_at: generateTimestamp(),
          cart_item: data.cart_item,
        })
      }
      setExtra({ totals: data?.totals ?? null, warnings: data?.warnings ?? [] })
      return data
    } catch {
      return null
    }
  },

  // Merge the guest cart into the user cart after login, then hydrate locally.
  mergeGuestCart: async () => {
    try {
      const guest_token = localStorage.getItem(GUEST_KEY)
      if (!guest_token) return null
      const { data } = await http.post('cart/merge', { guest_token })
      const cart = data?.cart
      if (cart && Array.isArray(cart.cart_item)) {
        writeCart({
          id: cart.id ?? 1,
          user_id: cart.user_id ?? null,
          created_at: generateTimestamp(),
          updated_at: generateTimestamp(),
          cart_item: cart.cart_item,
        })
        setExtra({ totals: cart.totals ?? null, warnings: cart.warnings ?? [] })
      }
      localStorage.removeItem(GUEST_KEY)
      return data
    } catch {
      return null
    }
  },
}
