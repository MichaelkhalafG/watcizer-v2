'use client'
import { useEffect, useState, useSyncExternalStore } from 'react'
import useCart from '@/src/Hooks/useCart'
import { cartStore } from '@/src/Store/cartStore'
import CartModal from '@/src/Components/Cart/CartModal'

// Global "just added to cart" drawer host (DIVERGED 2026-10-19). Watchizer's opens the drawer whenever the
// cart's line count rises — and the first client render's count rises from the server render's 0 to the
// tab's cached lines, so the drawer opened by itself on every page load with a non-empty cart. Here it
// opens on the store's own signal: a CONFIRMED add that created a new line (`newLineAt`).
export default function CartModalHost() {
  const { cart } = useCart()
  const newLineAt = useSyncExternalStore(
    cartStore.subscribe,
    () => cartStore.getExtraSnapshot().newLineAt ?? 0,
    () => 0,
  )
  const [open, setOpen] = useState(false)

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- opened by a store event, not by render
    if (newLineAt) setOpen(true)
  }, [newLineAt])

  return <CartModal open={open} onClose={() => setOpen(false)} cart={cart} />
}
