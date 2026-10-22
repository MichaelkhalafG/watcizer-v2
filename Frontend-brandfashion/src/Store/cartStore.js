import http from '../Context/api'
import { trackAddToCart, trackRemoveFromCart } from '../scripts/pixels'
import { API_BASE, STOREFRONT_CODE } from '../lib/env'
import { useAuthStore } from './authStore'

// ── Brand Fashion's cart is core's v2 cart (DIVERGED 2026-10-19, B7) ───────────────────────────
// Watchizer's twin keeps a tab copy in sessionStorage and syncs each line to compat's add_to_cart;
// this one asks core's v2 routes (/api/v2/{code}/cart, cart/items, cart/items/{id}), which re-check
// and re-price EVERY line on every answer and tag the cart with this storefront. The tab copy is a
// display cache of the last answer, as on Watchizer; the server's answer always replaces it.
//
// The interface is Watchizer's, name for name, so the twin call sites (the header count, the drawer,
// the recovery page, the login merge, the confirmation page) are unchanged: a line still carries
// product_id / quantity / piece_price / total_price / type_stock, plus what v2 adds — the line's
// server id, its variant, the product card, `available` and `problem`.

let listeners = new Set()
let currentCart = null
let extra = { totals: null, warnings: [], lastRemoved: null, newLineAt: 0 }
let undoTimer = null
let hydrated = false
let merging = null

const CART_KEY = 'bf_cart_v2'
const GUEST_KEY = 'wz_guest_token'
const V2 = `v2/${STOREFRONT_CODE}`

const generateTimestamp = () => new Date().toISOString()

// A line is a product, or a product's VARIANT: two sizes of one shirt are two lines (v2 keys a line by
// product, variant and stock bucket). Watchizer's key has no variant part because its lines have none.
export const getItemKey = (item) =>
  item.product_id !== null && item.product_id !== undefined
    ? `product_${item.product_id}${item.variant_id ? `_v${item.variant_id}` : ''}`
    : `offer_${item.offer_id}`

// Guest token (mirrors api.jsx) — the same `wz_guest_token` the HTTP client sends as X-Guest-Token.
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
  id: null,
  user_id: userId,
  created_at: generateTimestamp(),
  updated_at: generateTimestamp(),
  cart_item: [],
})

const notify = () => {
  for (const listener of listeners) listener()
}

const readCart = () => {
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
  try {
    sessionStorage.setItem(CART_KEY, JSON.stringify(cart))
  } catch {
    // a full or blocked storage only loses the display cache; the server keeps the cart
  }
  notify()
}

const updateCart = (updater) => {
  const updated = updater(currentCart || readCart())
  updated.updated_at = generateTimestamp()
  writeCart(updated)
}

const setExtra = (patch) => {
  extra = { ...extra, ...patch }
  notify()
}

// What the shopper is told when core refused a write — ONE sentence per reason for every place that
// adds (Watchizer's rule of 2026-09-29). v2 answers a CODE; the stock code keeps Watchizer's message
// text too, so the twin pages that read `message` still recognise it.
const REFUSALS = {
  insufficient_stock: ['Not enough stock for that quantity', 'لا يوجد مخزون كافٍ لهذه الكمية'],
  variant_required: ['Choose a size or colour first', 'اختر المقاس أو اللون أولاً'],
  variant_not_allowed: ['This item has no options to choose', 'هذا المنتج بلا خيارات'],
  variant_mismatch: ['That option is no longer available', 'هذا الخيار لم يعد متوفراً'],
  unavailable: ['This item is no longer available', 'هذا المنتج لم يعد متوفراً'],
}
export const refusedAddMessage = (result, isRTL) => {
  const pair =
    REFUSALS[result?.code] ||
    (/exceeds available stock/i.test(result?.message || '') ? REFUSALS.insufficient_stock : null)
  if (pair) return isRTL ? pair[1] : pair[0]
  return isRTL ? 'تعذّرت الإضافة إلى السلة. حاول مرة أخرى.' : "Couldn't add to cart. Please try again."
}

// A v2 line as the twin pages read a cart line. `seen_price` is the unit price the shopper last saw for
// it (the card's, at the add); the cart says "Price updated" when core's current price differs (§10.4).
const toLine = (it, cartId) => {
  const unit = it.unit_price == null ? null : Number(it.unit_price)
  return {
    id: it.id,
    server: true,
    cart_id: cartId,
    product_id: it.product?.id ?? null,
    offer_id: null,
    variant_id: it.variant?.id ?? null,
    quantity: it.quantity,
    piece_price: (unit ?? 0).toFixed(2),
    total_price: Number(it.line_total ?? 0).toFixed(2),
    type_stock: it.bucket ?? 'Express',
    color_band: null,
    color_dial: null,
    product: it.product ?? null,
    variant: it.variant ?? null,
    available: Number(it.available ?? 0),
    problem: it.problem ?? null,
    seen_price: unit,
  }
}

const problemMessage = (line) =>
  line.problem === 'insufficient_stock'
    ? line.available > 0
      ? `Only ${line.available} available`
      : 'Requested quantity exceeds available stock'
    : line.problem
      ? 'This product is no longer available'
      : null

// Replace the tab's copy with core's answer (the `data` of every v2 cart response).
const applyPayload = (payload) => {
  const before = new Map((currentCart || readCart()).cart_item.map((l) => [getItemKey(l), l]))
  const lines = (payload?.items || []).map((it) => {
    const line = toLine(it, payload.id)
    return { ...line, seen_price: before.get(getItemKey(line))?.seen_price ?? line.seen_price }
  })
  writeCart({ ...(currentCart || readCart()), id: payload?.id ?? null, updated_at: generateTimestamp(), cart_item: lines })
  setExtra({
    totals: payload?.totals ?? null,
    warnings: lines.filter((l) => l.problem).map((l) => ({ item_id: l.id, message: problemMessage(l) })),
  })
  return lines
}

// One v2 write or read. It never rejects: { ok, status, code, message, data }.
const send = (method, path, body) =>
  http
    .request({ method, url: `${V2}/${path}`, data: body, headers: { Accept: 'application/json' } })
    .then((res) => ({ ok: true, status: res.status, code: null, message: null, data: res.data?.data ?? null }))
    .catch((err) => {
      const code = err?.response?.data?.error ?? null
      return {
        ok: false,
        status: err?.response?.status ?? 0,
        code,
        message: code === 'insufficient_stock' ? 'Requested quantity exceeds available stock' : code,
        data: null,
      }
    })

// Set ONE line to an absolute quantity (v2's POST cart/items is a set, keyed by product, variant and
// bucket). A line that exists keeps its bucket; a new one lets core choose (Express while it has the units).
const setLine = (line, quantity) =>
  send('post', 'cart/items', {
    product_id: line.product_id,
    variant_id: line.variant_id ?? null,
    bucket: line.server ? line.type_stock : null,
    quantity,
  })

// The AddToCart event fires on a CONFIRMED add only, for the delta (Watchizer's rule).
const trackConfirmedAdd = (synced, { id, name, unitPrice, added }) =>
  synced.then((result) => {
    if (result.ok && added > 0) {
      trackAddToCart({ id, name, value: parseFloat(unitPrice) * added, quantity: added })
    } else if (result.ok && added < 0) {
      // a DECREASE is GA4's remove_from_cart for what left (2026-10-22); the pixels have no event for it
      trackRemoveFromCart({ id, name, value: parseFloat(unitPrice) * -added, quantity: -added })
    }
    return result
  })

const restoreLine = (key, previous) => {
  updateCart((cart) => {
    const others = cart.cart_item.filter((i) => getItemKey(i) !== key)
    return { ...cart, cart_item: previous ? [...others, previous] : others }
  })
}

const settle = (key, previous) => (result) => {
  if (result.ok && result.data) applyPayload(result.data)
  else if (!result.ok) restoreLine(key, previous)
  return result
}

const OK = { ok: true, status: 200, code: null, message: null, data: null }
const SERVER_EMPTY_CART = createEmptyCart()
const SERVER_EMPTY_EXTRA = { totals: null, warnings: [], lastRemoved: null, newLineAt: 0 }

export const cartStore = {
  getSnapshot: () => currentCart ?? (currentCart = readCart()),
  getExtraSnapshot: () => extra,
  getServerSnapshot: () => SERVER_EMPTY_CART,
  getExtraServerSnapshot: () => SERVER_EMPTY_EXTRA,
  subscribe: (callback) => {
    listeners.add(callback)
    // The first subscriber in the browser reads core's cart once: the tab copy is only a cache, and a
    // cart changed in another tab, or emptied by an order, shows as it is.
    if (!hydrated && typeof window !== 'undefined') {
      hydrated = true
      cartStore.fetchCart()
    }
    return () => listeners.delete(callback)
  },

  addItem: ({ product_id = null, quantity = 1, piece_price, variant_id = null, name = undefined }) => {
    // v2 has no offer lines (an offer is a page of products, round 5): refused before anything moves.
    if (product_id == null) return Promise.resolve({ ...OK, ok: false, status: 0, code: 'unavailable' })
    const key = getItemKey({ product_id, variant_id })
    const cart = currentCart || readCart()
    const previous = cart.cart_item.find((i) => getItemKey(i) === key) || null
    const absolute = (previous?.quantity ?? 0) + quantity
    const unit = Number.isFinite(parseFloat(piece_price)) ? parseFloat(piece_price) : 0
    updateCart((c) => {
      const items = c.cart_item.filter((i) => getItemKey(i) !== key)
      const line = previous
        ? { ...previous, quantity: absolute, total_price: (absolute * parseFloat(previous.piece_price)).toFixed(2) }
        : {
            id: `local-${Date.now()}`,
            server: false,
            cart_id: c.id,
            product_id,
            offer_id: null,
            variant_id,
            quantity,
            piece_price: unit.toFixed(2),
            total_price: (unit * quantity).toFixed(2),
            type_stock: 'Express',
            color_band: null,
            color_dial: null,
            product: null,
            variant: null,
            available: 0,
            problem: null,
            seen_price: unit,
          }
      return { ...c, cart_item: [...items, line] }
    })
    const synced = setLine(previous || { product_id, variant_id, server: false }, absolute)
      .then(settle(key, previous))
      .then((r) => {
        // a NEW line, confirmed: the drawer host's signal (a quantity added to an existing line is not one)
        if (r.ok && !previous) setExtra({ newLineAt: Date.now() })
        return r
      })
    return trackConfirmedAdd(synced, { id: product_id, name, unitPrice: piece_price, added: quantity })
  },

  updateQuantity: (identifier, newQuantity) => {
    const cart = currentCart || readCart()
    const previous = cart.cart_item.find((i) => getItemKey(i) === identifier)
    if (!previous) return Promise.resolve(OK)
    if (newQuantity < 1) {
      cartStore.removeItem(identifier)
      return Promise.resolve(OK)
    }
    updateCart((c) => ({
      ...c,
      cart_item: c.cart_item.map((i) =>
        getItemKey(i) === identifier
          ? { ...i, quantity: newQuantity, total_price: (parseFloat(i.piece_price) * newQuantity).toFixed(2) }
          : i,
      ),
    }))
    const synced = setLine(previous, newQuantity).then(settle(identifier, previous))
    return trackConfirmedAdd(synced, {
      id: previous.product_id,
      unitPrice: previous.piece_price,
      added: newQuantity - previous.quantity,
    })
  },

  removeItem: (identifier) => {
    let removed = null
    updateCart((cart) => {
      removed = cart.cart_item.find((i) => getItemKey(i) === identifier) || null
      return { ...cart, cart_item: cart.cart_item.filter((i) => getItemKey(i) !== identifier) }
    })
    if (!removed) return
    // GA4 remove_from_cart for the whole line (2026-10-22)
    trackRemoveFromCart({ id: removed.product_id ?? null, value: parseFloat(removed.piece_price) * removed.quantity, quantity: removed.quantity })
    if (removed.server) {
      send('delete', `cart/items/${removed.id}`).then((r) => {
        if (r.ok && r.data) applyPayload(r.data)
        else if (!r.ok && r.status !== 404) cartStore.fetchCart()
      })
    }
    if (undoTimer) clearTimeout(undoTimer)
    setExtra({ lastRemoved: removed })
    undoTimer = setTimeout(() => setExtra({ lastRemoved: null }), 5000)
  },

  undoRemove: () => {
    const item = extra.lastRemoved
    if (!item) return
    if (undoTimer) clearTimeout(undoTimer)
    setExtra({ lastRemoved: null })
    updateCart((cart) =>
      cart.cart_item.some((i) => getItemKey(i) === getItemKey(item)) ? cart : { ...cart, cart_item: [...cart.cart_item, item] },
    )
    // the removed line's own bucket, so the line comes back as it was
    setLine(item, item.quantity).then(settle(getItemKey(item), null))
  },

  // After an order: core's checkout has already emptied the server cart inside the order's transaction.
  clearCart: () => {
    writeCart(createEmptyCart(currentCart?.user_id))
    setExtra({ totals: null, warnings: [] })
  },

  // Core's verdict on the cart as it stands: every line re-checked and re-priced.
  validateCart: async () => {
    const r = await send('get', 'cart')
    if (!r.ok || !r.data) return { valid: true, warnings: [], failed: true }
    const lines = applyPayload(r.data)
    return {
      valid: lines.every((l) => !l.problem),
      warnings: extra.warnings,
      totals: { item_count: r.data.totals?.count ?? 0, subtotal: r.data.totals?.subtotal ?? 0 },
      failed: false,
    }
  },

  // Watchizer's twin heals a drifted server cart here; on v2 the server cart IS the cart, so this is a read.
  reconcile: async () => {
    const r = await send('get', 'cart')
    if (!r.ok || !r.data) return { refused: [], serverLines: 0, failed: true }
    const lines = applyPayload(r.data)
    return { refused: [], serverLines: lines.length, failed: false }
  },

  // The prices the shopper has now seen (the cart page, on the way to checkout): "Price updated" clears.
  acknowledgePrices: () => {
    updateCart((cart) => ({
      ...cart,
      cart_item: cart.cart_item.map((l) => ({ ...l, seen_price: Number(l.piece_price) })),
    }))
  },

  fetchCart: async () => {
    const r = await send('get', 'cart')
    if (r.ok && r.data) applyPayload(r.data)
    return r.ok ? r.data : null
  },

  // The guest cart into the account's, after a sign-in (Watchizer's rule: quantities ADD). The guest
  // cart is read with the guest token alone — the HTTP client now sends the JWT, which names the
  // account's cart — and each line is set on the account's cart, then removed from the guest's.
  // One merge at a time: the sign-in below starts one, and the login page asks for one in the same tick — both
  // get the same promise (two concurrent merges would add the guest's quantities twice).
  mergeGuestCart: () => {
    merging ??= mergeOnce().finally(() => {
      merging = null
    })
    return merging
  },
}

const mergeOnce = async () => {
  if (typeof window === 'undefined') return null
  const guestToken = localStorage.getItem(GUEST_KEY)
  if (!guestToken) return null
  let guest
  try {
    const res = await fetch(`${API_BASE}/${V2}/cart`, {
      headers: { Accept: 'application/json', 'X-Guest-Token': guestToken },
      cache: 'no-store',
    })
    guest = res.ok ? (await res.json())?.data : null
  } catch {
    return null
  }
  const guestLines = (guest?.items || []).filter((it) => it.product?.id)
  if (!guestLines.length) {
    localStorage.removeItem(GUEST_KEY)
    await cartStore.fetchCart()
    return null
  }
  const mine = await send('get', 'cart')
  if (!mine.ok) return null
  const lineKey = (it) => `${it.product?.id}_${it.variant?.id ?? ''}_${it.bucket}`
  const have = new Map((mine.data?.items || []).map((it) => [lineKey(it), it]))
  let merged = 0
  for (const it of guestLines) {
    const own = have.get(lineKey(it))
    const body = {
      product_id: it.product.id,
      variant_id: it.variant?.id ?? null,
      bucket: it.bucket,
      quantity: Math.min(99, (own?.quantity ?? 0) + it.quantity),
    }
    let r = await send('post', 'cart/items', body)
    // more than the stock holds: as many as it holds, never fewer than the account already had
    if (!r.ok && r.code === 'insufficient_stock' && it.available > (own?.quantity ?? 0)) {
      r = await send('post', 'cart/items', { ...body, quantity: it.available })
    }
    if (!r.ok) continue
    merged += 1
    try {
      await fetch(`${API_BASE}/${V2}/cart/items/${it.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-Guest-Token': guestToken },
      })
    } catch {
      // the guest line stays behind; the guest token is dropped below, so nobody reads it again
    }
  }
  localStorage.removeItem(GUEST_KEY)
  const data = await cartStore.fetchCart()
  return merged > 0 ? { cart: data } : null
}

// Sign-in and sign-out, wherever they happen (the login page, registration, Google's callback): on v2 the server's
// cart IS the cart, so a guest who signs in must bring their cart along — Watchizer's twin only merges on the login
// page, and its tab copy hides the gap; here a shopper who registered would have watched their cart empty. The
// session read on page load (the auth store's `hydrated` flipping) is not a sign-in and merges nothing.
if (typeof window !== 'undefined') {
  let was = useAuthStore.getState()
  useAuthStore.subscribe((now) => {
    const prev = was
    was = now
    if (!prev.hydrated) return
    const before = prev.userId ?? null
    const after = now.userId ?? null
    if (before === after) return
    if (after != null && before == null) cartStore.mergeGuestCart()
    else cartStore.fetchCart()
  })
}
