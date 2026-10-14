import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { listingRequest } from '@/src/lib/listingRequest'
import { getServerTables, getServerListing } from '@/src/lib/serverCatalog'
import { requestLang } from '@/src/lib/requestLang'
import { parseListingParams } from '@/src/utils/listingParams'
import { objectToSearchParams, listingMetadata, listingSummary, listingBreadcrumbLd, listingQuery } from '@/src/lib/listingSeo'
import ListingClient from './ListingClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'

// ISR knob (harmless — the page reads searchParams so it renders dynamically):
// keeps parity with the other routes' 5-min revalidate.
export const revalidate = 300

// Resolve the request context ONCE (the getters are React-cached, so generateMetadata + the page
// share ONE upstream fetch each). searchParams is the URL source of truth → filters are parsed from
// it (resolving slugs via `tables`). The listing itself is core's first page for that request — the
// page's prefetch and the metadata's count and preview image (C-1 stage 4 slice D: no catalogue).
async function loadContext(searchParams) {
  const sp = await searchParams
  const usp = objectToSearchParams(sp)
  let tables = {}
  try {
    tables = (await getServerTables()) || {}
  } catch {
    // tables unreachable server-side → the client fetches; metadata degrades to the defaults.
  }
  const { filters, q, sort, page } = parseListingParams(usp, tables)
  // The server renders in English (the shop's language is applied on the client), hence lang 'en'.
  const qs = listingRequest({ filters, q, sort, page, lang: 'en' })
  let listing = null
  try {
    listing = await getServerListing(qs)
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry.
  }
  return { tables, filters, q, sort, page, qs, listing, query: listingQuery(usp.toString()) }
}

export async function generateMetadata({ searchParams }) {
  const { tables, filters, listing, query } = await loadContext(searchParams)
  const { urlLang } = await requestLang()
  // `query` decides indexability: any listing-relevant parameter makes the page noindex, follow.
  return listingMetadata({ tables, ...listingSummary(listing, tables), filters, pathname: '/listing', query, lang: urlLang })
}

export default async function ListingPage({ searchParams }) {
  const { tables, filters, qs, listing } = await loadContext(searchParams)
  const { urlLang } = await requestLang()
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname: '/listing', lang: urlLang })

  // The first page and its facet counts, from core (C-1 stage 3) — with the SAME request builder
  // ListingClient uses, so its first render hits this data.
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], listing)

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      {/* BreadcrumbList structured data — in the initial HTML for scrapers. */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      {/* No <Suspense> here (2026-09-30). The page is dynamic, so useSearchParams() needs none; the
          boundary only made React send the finished grid as a separate block swapped in LATER — a
          page with an empty grid first, and on desktop the footer jumping down (CLS 0.50, measured). */}
      <ListingClient />
    </HydrationBoundary>
  )
}
