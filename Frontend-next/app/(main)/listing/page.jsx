import { Suspense } from 'react'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import serverHttp from '@/src/lib/serverFetch'
import { listingRequest, listingQueryFn } from '@/src/lib/listingRequest'
import { getServerCatalog } from '@/src/lib/serverCatalog'
import { parseListingParams } from '@/src/utils/listingParams'
import { objectToSearchParams, listingMetadata, listingBreadcrumbLd } from '@/src/lib/listingSeo'
import ListingClient from './ListingClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'

// ISR knob (harmless — the page reads searchParams so it renders dynamically):
// keeps parity with the other routes' 5-min revalidate.
export const revalidate = 300

// Resolve the request context ONCE (getServerCatalog is React-cached, so
// generateMetadata + the page share ONE upstream fetch). searchParams is the URL
// source of truth → filters are parsed from it (resolving slugs via `tables`).
async function loadContext(searchParams) {
  const sp = await searchParams
  const usp = objectToSearchParams(sp)
  let tables = {}
  let productsEn = []
  try {
    const cat = await getServerCatalog()
    tables = cat.tables || {}
    productsEn = cat.productsEn || []
  } catch {
    // catalog unreachable server-side → client fetches; metadata degrades to the
    // "all products" defaults.
  }
  const { filters, q, sort, page } = parseListingParams(usp, tables)
  return { tables, productsEn, filters, q, sort, page }
}

export async function generateMetadata({ searchParams }) {
  const { tables, productsEn, filters, q } = await loadContext(searchParams)
  return listingMetadata({ tables, products: productsEn, filters, q, pathname: '/listing' })
}

export default async function ListingPage({ searchParams }) {
  const { tables, filters, q, sort, page } = await loadContext(searchParams)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname: '/listing' })

  // The first page and its facet counts, from core (C-1 stage 3) — with the SAME request builder
  // ListingClient uses, so its first render hits this data. The server renders in English (the
  // shop's language is applied on the client), hence lang 'en'.
  const qc = new QueryClient()
  const qs = listingRequest({ filters, q, sort, page, lang: 'en' })
  try {
    qc.setQueryData(['listing', qs], await listingQueryFn(qs, serverHttp)())
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry.
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      {/* BreadcrumbList structured data — in the initial HTML for scrapers. */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      {/* ListingClient reads useSearchParams() → wrap in Suspense. */}
      <Suspense fallback={null}>
        <ListingClient />
      </Suspense>
    </HydrationBoundary>
  )
}
