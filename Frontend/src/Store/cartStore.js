let listeners = new Set()
let currentCart = null

const CART_KEY = 'user_cart'

const generateTimestamp = () => new Date().toISOString()

export const getItemKey = (item) =>
  item.product_id !== null && item.product_id !== undefined
    ? `product_${item.product_id}`
    : `offer_${item.offer_id}`

const createEmptyCart = (userId = null) => ({
  id: 1,
  user_id: userId,
  created_at: generateTimestamp(),
  updated_at: generateTimestamp(),
  cart_item: [],
})

const notify = () => {
  for (const listener of listeners) {
    listener()
  }
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
  notify() // trigger subscribers
}

const updateCart = (updater) => {
  const updated = updater(currentCart || readCart())
  updated.updated_at = generateTimestamp()
  writeCart(updated)
}

export const cartStore = {
  getSnapshot: () => currentCart ?? (currentCart = readCart()), // ✅ Cached snapshot
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
    updateCart((cart) => {
      const now = generateTimestamp()

      const updatedItems = [...cart.cart_item]
      const index = updatedItems.findIndex(
        (item) => getItemKey(item) === getItemKey({ product_id, offer_id }),
      )

      if (index !== -1) {
        const existing = updatedItems[index]
        const newQty = existing.quantity + quantity
        updatedItems[index] = {
          ...existing,
          quantity: newQty,
          total_price: (newQty * parseFloat(existing.piece_price)).toFixed(2),
          updated_at: now,
        }
      } else {
        updatedItems.push({
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

      return { ...cart, cart_item: updatedItems }
    })
  },

  updateQuantity: (identifier, newQuantity) => {
    updateCart((cart) => {
      const updatedItems = cart.cart_item.map((item) =>
        getItemKey(item) === identifier
          ? {
              ...item,
              quantity: newQuantity,
              total_price: (parseFloat(item.piece_price) * newQuantity).toFixed(2),
              updated_at: generateTimestamp(),
            }
          : item,
      )
      return { ...cart, cart_item: updatedItems }
    })
  },

  removeItem: (identifier) => {
    updateCart((cart) => {
      const updatedItems = cart.cart_item.filter((item) => getItemKey(item) !== identifier)
      return { ...cart, cart_item: updatedItems }
    })
  },

  clearCart: () => {
    const cleared = createEmptyCart(currentCart?.user_id)
    writeCart(cleared)
  },
}
