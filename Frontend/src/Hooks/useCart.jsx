import { useSyncExternalStore } from 'react'
import { cartStore } from '../Store/cartStore'

// Single source of truth lives in cartStore.js — re-exported here so existing
// consumers can keep importing `getItemKey` from this hook.
export { getItemKey } from '../Store/cartStore'

const useCart = () => {
  const cart = useSyncExternalStore(cartStore.subscribe, cartStore.getSnapshot)

  return {
    cart,
    addItem: cartStore.addItem,
    updateQuantity: cartStore.updateQuantity,
    removeItem: cartStore.removeItem,
    clearCart: cartStore.clearCart,
  }
}

export default useCart
