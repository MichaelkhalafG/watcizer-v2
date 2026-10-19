import { cache } from 'react'
import serverHttp from '@/src/lib/serverFetch'
import { coreRead } from '@/src/lib/coreRead'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import OffersClient from './OffersClient'

// /offers and /ar/offers — Brand Fashion's OFFERS (round 5, 2026-10-18, Michael): the offers created in the
// dashboard (core's promotions, `/manage/promotions`), read from `GET /api/v2/{storefront}/offers` — the rules
// running NOW for this storefront, exactly as the checkout's engine would consider them. Not a discount listing:
// a product's own discount is "Sale" (`/listing?sale=1`, core's `on_sale`). The page is honest: with no offer
// running it says so and is kept out of the index; it never fills itself with products to look populated.
// It replaces the copied app's `/offers` → `/listing?offers=true` redirect (next.config.js).

export const dynamic = 'force-dynamic'

// A core failure is not "no offers": coreRead retries, falls back to the last good copy, else throws to the
// error page (a 5xx). Only a definite miss (404/422) reads as an empty list.
const readOffers = cache(async () => {
  const data = await coreRead('v2-offers', async () => {
    const { data: body } = await serverHttp.get(`v2/${STOREFRONT_CODE}/offers`)
    return body
  })
  return Array.isArray(data?.data) ? data.data : []
})

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const ar = urlLang === 'ar'
  const offers = await readOffers()
  return {
    title: ar ? 'العروض | Brand Fashion' : 'Offers | Brand Fashion',
    description: ar ? 'العروض الجارية الآن في Brand Fashion.' : 'The offers running now at Brand Fashion.',
    alternates: alternatesFor('/offers', urlLang),
    robots: offers.length ? undefined : { index: false, follow: true },
  }
}

export default async function OffersPage() {
  const offers = await readOffers()
  return <OffersClient offers={offers} />
}
