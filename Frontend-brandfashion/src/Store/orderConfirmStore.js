import { create } from 'zustand'

// Order-confirmation handoff. react-router carried the placed-order payload via
// navigate(state); Next's router.push doesn't carry state the same way, so
// Checkout writes the order here and OrderConfirmation reads + clears it.
export const useOrderConfirmStore = create((set) => ({
  orderData: null,
  setOrderData: (data) => set({ orderData: data }),
  clearOrderData: () => set({ orderData: null }),
}))

export default useOrderConfirmStore
