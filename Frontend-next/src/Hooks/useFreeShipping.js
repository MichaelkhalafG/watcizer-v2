'use client'
import { useSyncExternalStore } from 'react'
import { cartStore } from '../Store/cartStore'
import { freeShippingOf } from '../lib/freeShipping'

// The threshold from the last cart answer the store holds (null until the first one). A TWIN.
const readOver = () => cartStore.getExtraSnapshot()?.totals?.shipping?.free_over ?? null

/** @param {number} subtotal the goods at the prices charged */
export default function useFreeShipping(subtotal) {
  const over = useSyncExternalStore(cartStore.subscribe, readOver, () => null)
  return freeShippingOf(over, subtotal)
}
