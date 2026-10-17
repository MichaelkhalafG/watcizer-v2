import { notFound } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerListing } from '@/src/lib/serverCatalog'
import { getServerV2Meta } from '@/src/lib/serverV2'
import { requestLang } from '@/src/lib/requestLang'
import { readListingState, toParams, toV2Query } from '@/src/lib/bfListing'
import { bfListingMetadata, listingQueryOf } from '@/src/lib/bfListingSeo'
import { txt } from '@/src/lib/cardData'
import BfListing from '@/src/Components/Listing/BfListing'

// B4 — a Brand Fashion brand page (spec v2.9 §10.2), on the v2 listing (DIVERGED from Frontend-next
// 2026-10-17, V2-READ-HALF / B4). `[brand]` is v2's brand slug. A slug no brand carries is a real 404; a brand
// with nothing in it yet is 200 + `noindex, follow` (the client may stock it tomorrow — §10.2's rule).
export const revalidate = 300

async function load(params, searchParams) {
  const { brand } = await params
  const usp = toParams(await searchParams)
  const state = readListingState(usp, { brand })
  const qs = toV2Query(state, 'en')
  let listing = null
  let failed = false
  try {
    listing = await getServerListing(qs)
  } catch {
    failed = true
  }
  // the brand's name (and whether it exists at all) from v2 meta — the listing's facets name only brands with matches
  let brandRow = null
  try {
    const meta = await getServerV2Meta()
    brandRow = (meta?.brands || []).find((b) => b.slug === brand) || null
  } catch {
    // meta unreachable: the page still renders from the listing; the title falls back to the slug
  }
  return { brand, usp, state, qs, listing, failed, brandRow }
}

export async function generateMetadata({ params, searchParams }) {
  const { brand, usp, listing, failed, brandRow } = await load(params, searchParams)
  if (!brandRow && !failed && (listing?.total ?? 0) === 0) return {}
  const { urlLang } = await requestLang()
  return bfListingMetadata({
    title: txt(brandRow?.name, urlLang) || brand,
    total: listing?.total ?? 0,
    answered: listing != null,
    image: listing?.v2?.data?.[0]?.image?.src ?? null,
    pathname: `/brand/${brand}`,
    query: listingQueryOf(usp.toString()),
    lang: urlLang,
  })
}

export default async function BrandPage({ params, searchParams }) {
  const { brand, qs, listing, failed, brandRow } = await load(params, searchParams)
  if (!brandRow && !failed && (listing?.total ?? 0) === 0) notFound()
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], listing)
  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <BfListing route={{ brand, brandName: brandRow?.name || null }} />
    </HydrationBoundary>
  )
}
