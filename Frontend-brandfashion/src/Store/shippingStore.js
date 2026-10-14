import { create } from 'zustand'

// Shipping selection (formerly held in MyProvider's useState). The DERIVED city
// price list is NOT stored here — it comes straight off the useShipping() query
// via the useShippingPrices() hook (server data lives in TanStack Query). This
// store only owns the user's current selection:
//   • shippingid    — selected city id (string)
//   • shipping      — selected city price (string, used in cart total)
//   • shippingname  — localized city name
//
// Plain client state with static initial values → SSR-safe (no window access),
// same idiom as uiStore.js. The default selection is applied by <AppStateBridge/>
// once the city list resolves (mirrors MyProvider's old effect).
export const useShippingStore = create((set) => ({
  shippingid: '',
  shipping: '',
  shippingname: '',
  setShippingid: (id) => set({ shippingid: id }),
  setShipping: (price) => set({ shipping: price }),
  setShippingName: (name) => set({ shippingname: name }),
}))

export default useShippingStore
