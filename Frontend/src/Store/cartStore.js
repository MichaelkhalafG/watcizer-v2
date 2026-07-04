import http from '../Context/api'

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
export const getGuestToken = () => {
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

// Fire-and-forget server sync for one line. The backend AddToCart OVERWRITES the
// line quantity to the value sent, so we always pass the absolute quantity.
const syncLine = (item, quantity) => {
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
    .catch((err) => console.warn('Cart sync failed:', err))
}

export const cartStore = {
  getSnapshot: () => currentCart ?? (currentCart = readCart()),
  getExtraSnapshot: () => extra,
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
  }) => {
    let absoluteQty = quantity
    updateCart((cart) => {
      const now = generateTimestamp()
      const items = [...cart.cart_item]
      const index = items.findIndex((i) => getItemKey(i) === getItemKey({ product_id, offer_id }))
      if (index !== -1) {
        const existing = items[index]
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
    syncLine({ product_id, offer_id, piece_price, type_stock, color_band, color_dial }, absoluteQty)
  },

  updateQuantity: (identifier, newQuantity) => {
    let synced = null
    updateCart((cart) => {
      const items = cart.cart_item.map((item) => {
        if (getItemKey(item) === identifier) {
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
    if (synced) syncLine(synced, newQuantity) // backend overwrites to absolute qty
  },

  removeItem: (identifier) => {
    let removed = null
    updateCart((cart) => {
      removed = cart.cart_item.find((i) => getItemKey(i) === identifier) || null
      return { ...cart, cart_item: cart.cart_item.filter((i) => getItemKey(i) !== identifier) }
    })
    if (removed) {
      if (undoTimer) clearTimeout(undoTimer)
      setExtra({ lastRemoved: removed })
      undoTimer = setTimeout(() => setExtra({ lastRemoved: null }), 5000)
    }
    // NOTE: there is no reliable server delete-by-attributes endpoint, so removal
    // is local-only; the line is reconciled at checkout / next merge.
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
      return { valid: true, warnings: [] }
    }
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
