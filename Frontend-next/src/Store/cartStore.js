import http from '../Context/api'
import { v2CartRead, v2CartSet, v2CartRemove } from '../lib/v2Cart'
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

/*
 * A LINE'S IDENTITY — product, variant and stock bucket together (slice 3, 2026-10-21).
 *
 * It was `product_{id}` alone, which is why two sizes of one shirt could not be two lines: a second add
 * found the first line and incremented it, silently, with the wrong size. Addition B worked around that by
 * refusing to merge when a variant was chosen; this removes the need for the workaround.
 *
 * THE BUCKET IS DELIBERATELY NOT PART OF THE KEY. Core keys its row by (cart, product, variant, bucket)
 * and will happily hold the same product twice, once per bucket — measured against the running API. But
 * that is NOT what this shop does today and not what the other one does either: compat merged by product,
 * Brand Fashion's v2 store (which has run this since 2026-10-19) keys by product and variant, and BOTH fix
 * a line's bucket at the first add and keep it on every later one. Putting the bucket in the key would
 * silently turn one line into two the first time a product's Express stock ran out mid-session — a
 * behaviour change on a live shop that nobody asked for. The bucket is preserved instead, by `serverKeyOf`.
 *
 * It is a COMPOSITE and not the server's line id on purpose: this store is optimistic, so a line exists
 * locally before the server has given it an id, and an id-based key could not be computed at that moment.
 * The server's id is carried on the line as `id` and is what DELETE and PATCH address.
 */
export const getItemKey = (item) => {
  if (item.product_id === null || item.product_id === undefined) return `offer_${item.offer_id}`

  return `product_${item.product_id}${item.variant_id ? `_v${item.variant_id}` : ''}`
}

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
// What the shopper is told when the server refused an add (2026-09-29). ONE sentence for every
// place that adds to the cart: the product card said nothing at all while the product page said
// this, and a refused card add looked like a button that did nothing.
export const refusedAddMessage = (result, isRTL) =>
  /exceeds available stock/i.test(result?.message || '')
    ? isRTL ? 'لا يوجد مخزون كافٍ لهذه الكمية' : 'Not enough stock for that quantity'
    : isRTL ? 'تعذّرت الإضافة إلى السلة. حاول مرة أخرى.' : "Couldn't add to cart. Please try again."

/*
 * ON v2 SINCE 2026-10-21 (slice 3). The contract is unchanged — absolute quantity in, a resolved
 * `{ ok, status, message }` out, never a rejection — so every caller, including the AddToCart pixel that
 * waits on `ok`, behaves exactly as before.
 *
 * What IS different: NO PRICE IS SENT. compat took `piece_price` and `total_price` from this browser and
 * stored them; v2 prices the line itself on every read, so a stale tab can no longer book an old price.
 * An OFFER line cannot be synced here at all — v2 has no offer in its cart — and compat's own cart is where
 * offers still live; production holds none, and the cart screen reports any it finds as `blocked`.
 */
const syncLine = (item, quantity) =>
  v2CartSet({
    productId: item.product_id ?? null,
    variantId: item.variant_id ?? null,
    bucket: item.type_stock ?? 'Express',
    quantity,
  })

// A line's SERVER key: the server keeps one row per (product, offer, colours, stock type). The
// tab keys a line by product/offer alone, so a repeat add MUST reuse the existing line's colours
// and stock type — a product card (no colours) adding to a line the product page created (with
// colours) used to make a SECOND server row for the same product.
/*
 * TAKE THE SERVER'S LINE ID ONTO THE LOCAL LINE after a successful sync (slice 3, 2026-10-21).
 *
 * The optimistic row is created with `id: Date.now()`, because at that moment the server has not seen it.
 * On compat that never mattered: removal was by PRODUCT. On v2 a line is deleted and updated BY ITS ID, so
 * a local line still carrying a timestamp could not be removed from the server at all — the row would have
 * survived every removal and inflated the next order's total, which is the exact phantom-row bug the
 * previous comment in `syncRemove` says was fixed once already.
 *
 * The server's answer carries the whole cart, so the line is found by the same key and its id adopted.
 */
const adoptServerId = (key, cart) => {
  const row = cart?.cart_item?.find((i) => getItemKey(i) === key)
  if (!row || !Number.isInteger(Number(row.id))) return
  updateCart((current) => ({
    ...current,
    cart_item: current.cart_item.map((i) => (getItemKey(i) === key ? { ...i, id: row.id } : i)),
  }))
}

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

/*
 * Server delete for a removed line — fire-and-forget, errors swallowed, as before.
 *
 * BY LINE ID since 2026-10-21. compat had no choice but to delete by PRODUCT, which removed every row for
 * that product in the cart; on v2 a line has its own id and only that line goes. That is what lets two
 * sizes of one product be two lines that can be removed independently, and it is why the key above could
 * stay a local composite: the id is carried on the line, and this is the only place that needs it.
 *
 * A line the server has never seen (an optimistic row whose sync has not landed, or an old local row from
 * before this change) has no server id; there is nothing to delete, and the next reconcile settles it.
 */
const syncRemove = (item) => {
  const id = Number(item?.id)
  if (!Number.isInteger(id) || id <= 0 || id > 1e12) return Promise.resolve()

  return v2CartRemove(id).then((r) => {
    if (!r.ok) console.warn('Cart remove sync refused:', r.code)
  })
}

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
    // The chosen variant (Addition B). It is part of the line's identity, not a decoration: two sizes of
    // one product are two lines, here and on the server.
    variant_id = null,
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
    let syncAs = { product_id, offer_id, variant_id, piece_price, type_stock, color_band, color_dial }
    updateCart((cart) => {
      const now = generateTimestamp()
      const items = [...cart.cart_item]
      const index = items.findIndex((i) => getItemKey(i) === getItemKey({ product_id, offer_id, variant_id }))
      if (index !== -1) {
        const existing = items[index]
        previous = existing
        // Same server row as the existing line: its colours and stock type, not this call's.
        syncAs = { product_id, offer_id, variant_id, piece_price: existing.piece_price, ...serverKeyOf(existing) }
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
          variant_id,
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
    const key = getItemKey({ product_id, offer_id, variant_id })
    // The server's answer wins: a refused add is rolled back in the tab, and the caller gets
    // { ok: false, message } to tell the shopper why (it used to say "Added to cart!").
    const synced = syncLine(syncAs, absoluteQty).then((result) => {
      if (!result.ok) restoreLine(key, previous)
      else adoptServerId(key, result.cart)
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

  /*
   * Validate stock and prices against the live catalogue; stores the server's totals and warnings.
   *
   * THERE IS NO `cart/validate` ON v2, and none is needed (slice 3, 2026-10-21): every read re-checks every
   * line and says per line what is wrong with it — `problem` is `unavailable`, `variant_required`,
   * `variant_unavailable` or `insufficient_stock` — and leaves a bad line out of the subtotal. So the
   * validation IS the read, and `v2Cart`'s adapter turns those per-line problems into the same `warnings`
   * array this already returned. `blocked` (an offer row v2 cannot price) becomes a warning too, because
   * the checkout refuses while one exists rather than writing an order without it.
   */
  validateCart: async () => {
    try {
      const cart = await v2CartRead()
      setExtra({ totals: cart.totals, warnings: cart.warnings })
      return { valid: cart.warnings.length === 0, warnings: cart.warnings, totals: cart.totals }
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
      server = (await v2CartRead()).cart_item
    } catch {
      return { refused, serverLines: 0, failed: true }
    }
    /*
     * Server rows the shopper cannot see are removed, one by one, by LINE ID.
     *
     * Core CAN hold the same product twice, once per bucket, while this tab shows one line for it (see
     * `getItemKey`). So when two server rows answer to one key, the one kept is the one whose bucket the
     * shopper's line actually has — not simply the first — and the other is deleted. The old compat code
     * compared `type_stock`, `color_band` and `color_dial` for the same reason; colours are gone from a v2
     * line, so the bucket is all that is left to tell them apart.
     */
    const wanted = new Map(lines.map((l) => [getItemKey(l), l.type_stock ?? null]))
    const keep = []
    for (const row of server) {
      const key = getItemKey(row)
      if (!wanted.has(key)) {
        await syncRemove(row)
        continue
      }
      const bucket = wanted.get(key)
      const already = keep.findIndex((k) => getItemKey(k) === key)
      if (already === -1) {
        keep.push(row)
      } else if (bucket !== null && row.type_stock === bucket && keep[already].type_stock !== bucket) {
        // a better match for the line the shopper has: keep this one instead and drop the other
        await syncRemove(keep[already])
        keep[already] = row
      } else {
        // a stray row that will not delete is left; the next read still judges it
        await syncRemove(row)
      }
    }
    writeCart({ ...(currentCart || readCart()), updated_at: generateTimestamp(), cart_item: keep })
    return { refused, serverLines: keep.length, failed: false }
  },

  // Hydrate the local cart from the server (used after login/merge). On v2 since 2026-10-21.
  fetchCart: async () => {
    try {
      const cart = await v2CartRead()
      writeCart({
        id: cart.id ?? 1,
        user_id: cart.user_id ?? null,
        created_at: generateTimestamp(),
        updated_at: generateTimestamp(),
        cart_item: cart.cart_item,
      })
      setExtra({ totals: cart.totals, warnings: cart.warnings })
      return cart
    } catch {
      return null
    }
  },

  // Merge the guest cart into the user cart after login, then hydrate locally.
  mergeGuestCart: async () => {
    try {
      const guest_token = localStorage.getItem(GUEST_KEY)
      if (!guest_token) return null
      /*
       * `cart/merge` STAYS ON COMPAT, deliberately (slice 3, 2026-10-21).
       *
       * v2 has no merge endpoint and needs none: the merge folds the guest's rows into the user's inside
       * the SAME `carts` / `cart_items` tables that v2 reads, so it keeps working whichever API is reading.
       * Listed, not invented. The same is true of `cart/recover`.
       *
       * What DOES change is the hydrate afterwards: compat's merge answers with compat's cart shape, which
       * carries the client-sent prices this storefront no longer trusts. So the merge is told to do its
       * work and the cart is then RE-READ from v2, priced by the server — one request more, and the money
       * on screen is the money core will charge.
       */
      await http.post('cart/merge', { guest_token })
      localStorage.removeItem(GUEST_KEY)
      const cart = await v2CartRead()
      writeCart({
        id: cart.id ?? 1,
        user_id: cart.user_id ?? null,
        created_at: generateTimestamp(),
        updated_at: generateTimestamp(),
        cart_item: cart.cart_item,
      })
      setExtra({ totals: cart.totals, warnings: cart.warnings })
      return cart
    } catch {
      return null
    }
  },
}
