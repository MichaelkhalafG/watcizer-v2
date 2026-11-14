import { create } from 'zustand'

// Shipping selection (formerly held in MyProvider's useState). The DERIVED city
// price list is NOT stored here — it comes straight off the useShipping() query
// via the useShippingPrices() hook (server data lives in TanStack Query). This
// store only owns the user's current selection:
//   • shippingid    — selected city id (string)
//   • shipping      — selected city price (string, used in cart total)
//   • shippingname  — localized city name
//   • pickup        — the checkout has "pick up in store" chosen (click & collect, 2026-11-14): no delivery fee anywhere
//                     on the page, the header's running total included. Set by the checkout, cleared when it is left.
//
// Plain client state with static initial values → SSR-safe (no window access),
// same idiom as uiStore.js. The default selection is applied by <AppStateBridge/>
// once the city list resolves (mirrors MyProvider's old effect).
export const useShippingStore = create((set) => ({
  shippingid: '',
  shipping: '',
  shippingname: '',
  pickup: false,
  setShippingid: (id) => set({ shippingid: id }),
  setPickup: (pickup) => set({ pickup: Boolean(pickup) }),
  setShipping: (price) => set({ shipping: price }),
  setShippingName: (name) => set({ shippingname: name }),
}))

export default useShippingStore
