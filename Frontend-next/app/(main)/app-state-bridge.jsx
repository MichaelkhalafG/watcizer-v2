'use client'

import { useEffect } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import { useShippingStore } from '@/src/Store/shippingStore'
import { useShippingPrices } from '@/src/Hooks/useShippingPrices'

// Headless effect runner — replaces the cross-cutting effects that used to live
// in <MyProvider>. Mounted INSIDE the (main) layout's HydrationBoundary (where
// MyProvider sat), so it reads the already-hydrated catalog/offers/shipping
// queries and writes derived selections into the focused Zustand stores. Renders
// nothing (mirrors <AuthHydrator/>/<HtmlDirSync/> in app/providers.jsx).
export default function AppStateBridge() {
  const language = useUIStore((s) => s.language)

  const setShippingid = useShippingStore((s) => s.setShippingid)
  const setShipping = useShippingStore((s) => s.setShipping)
  const setShippingName = useShippingStore((s) => s.setShippingName)

  const shippingPrices = useShippingPrices()

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

  // No wishlist fetch here any more (batch 1, 2026-09-26). The wishlist was deleted (Phase 0, G6)
  // and `/all_wishlist` no longer exists, so every page view by a signed-in shopper logged a failed
  // request. No offers either: `useOffers` answers an empty list without asking.


  return null
}
