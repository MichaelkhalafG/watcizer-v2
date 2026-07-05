'use client'
import { useEffect, useState } from 'react'
import useCart from '@/src/Hooks/useCart'
import CartModal from '@/src/Components/Cart/CartModal'

// Global "just added to cart" drawer host — replicates the auto-open logic from
// Frontend App.jsx MainApp: open the drawer whenever the cart line count rises.
export default function CartModalHost() {
  const { cart } = useCart()
  const [open, setOpen] = useState(false)
  const [prevCount, setPrevCount] = useState(cart?.cart_item?.length || 0)

  useEffect(() => {
    const count = cart?.cart_item?.length || 0
    if (count > prevCount) setOpen(true)
    setPrevCount(count)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cart?.cart_item?.length])

  return <CartModal open={open} onClose={() => setOpen(false)} cart={cart} />
}
