import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerListing } from '@/src/lib/serverCatalog'
import { requestLang } from '@/src/lib/requestLang'
import { readListingState, toParams, toV2Query } from '@/src/lib/bfListing'
import { bfListingMetadata, listingQueryOf, listingTitle } from '@/src/lib/bfListingSeo'
import BfListing from '@/src/Components/Listing/BfListing'

// B4 — Brand Fashion's /listing (spec v2.9 §10.2), on the v2 listing (DIVERGED from Frontend-next 2026-10-17,
// V2-READ-HALF / B4). The URL is the state (src/lib/bfListing.js); this page prefetches the same query key the
// client builds, so the first render carries the first page and its facet block. A query listing is a
// shopper's tool: `noindex, follow`, self canonical (bfListingSeo.js).
export const revalidate = 300

async function load(searchParams) {
  const usp = toParams(await searchParams)
  const state = readListingState(usp)
  const qs = toV2Query(state, 'en')
  let listing = null
  try {
    listing = await getServerListing(qs)
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry
  }
  return { usp, state, qs, listing }
}

export async function generateMetadata({ searchParams }) {
  const { usp, state, listing } = await load(searchParams)
  const { urlLang } = await requestLang()
  return bfListingMetadata({
    title: listingTitle({ q: state.q, lang: urlLang }),
    total: listing?.total ?? 0,
    answered: listing != null,
    image: listing?.v2?.data?.[0]?.image?.src ?? null,
    pathname: '/listing',
    query: listingQueryOf(usp.toString()),
    lang: urlLang,
  })
}

export default async function ListingPage({ searchParams }) {
  const { qs, listing } = await load(searchParams)
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], listing)
  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <BfListing route={{}} />
    </HydrationBoundary>
  )
}
