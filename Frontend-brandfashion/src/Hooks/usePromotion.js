'use client'
import { useSyncExternalStore } from 'react'
import { cartStore } from '../Store/cartStore'
import { promotionOf } from '../lib/coupon'

// The promotion from the last cart answer the store holds (M1u). A TWIN. The snapshot is the store's own object, so
// the selector returns the same reference until the store writes a new answer.
const readTotals = () => cartStore.getExtraSnapshot()?.totals ?? null

/** What core says the cart's promotions and typed code take off, from the latest cart answer. */
export default function usePromotion() {
  const totals = useSyncExternalStore(cartStore.subscribe, readTotals, () => null)
  return promotionOf(totals)
}
