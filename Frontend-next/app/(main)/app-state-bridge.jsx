'use client'

import { useEffect } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import { useAuthStore } from '@/src/Store/authStore'
import { useShippingStore } from '@/src/Store/shippingStore'
import { useShippingPrices } from '@/src/Hooks/useShippingPrices'
import { useCatalog } from '@/src/Hooks/queries/useCatalog'
import { useOffers } from '@/src/Hooks/queries/useOffers'
import { fetchWishList } from '@/src/Context/api'

// Headless effect runner — replaces the cross-cutting effects that used to live
// in <MyProvider>. Mounted INSIDE the (main) layout's HydrationBoundary (where
// MyProvider sat), so it reads the already-hydrated catalog/offers/shipping
// queries and writes derived selections into the focused Zustand stores. Renders
// nothing (mirrors <AuthHydrator/>/<HtmlDirSync/> in app/providers.jsx).
export default function AppStateBridge() {
  const language = useUIStore((s) => s.language)
  const user_id = useAuthStore((s) => s.userId)

  const setShippingid = useShippingStore((s) => s.setShippingid)
  const setShipping = useShippingStore((s) => s.setShipping)
  const setShippingName = useShippingStore((s) => s.setShippingName)
  const setwishList = useUIStore((s) => s.setWishList)

  const shippingPrices = useShippingPrices()
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()

  // Default shipping selection once the city list resolves (verbatim from
  // MyProvider). Deps are [shippingPrices, language] only — it does NOT re-run on
  // a user's city change, so it never clobbers their selection.
  useEffect(() => {
    if (shippingPrices.length > 0) {
      const defaultShipping = shippingPrices[0]
      setShippingid(defaultShipping.id)
      setShipping(defaultShipping.Price.toString())
      setShippingName(
        language === 'ar' ? defaultShipping.GovernorateAr : defaultShipping.GovernorateEn,
      )
    } else {
      setShippingid('')
      setShipping('')
    }
  }, [shippingPrices, language, setShippingid, setShipping, setShippingName])

  // Fetch the wishlist once a user is present (verbatim from MyProvider).
  useEffect(() => {
    if (user_id) {
      fetchWishList(user_id, products, offers, language, setwishList)
    }
  }, [user_id, offers, products, language, setwishList])

  return null
}
