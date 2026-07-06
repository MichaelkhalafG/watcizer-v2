import { Suspense } from 'react'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog } from '@/src/lib/serverCatalog'
import { parseListingParams } from '@/src/utils/listingParams'
import { objectToSearchParams, listingMetadata, listingBreadcrumbLd } from '@/src/lib/listingSeo'
import ListingClient from './ListingClient'

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
  const { filters, q } = parseListingParams(usp, tables)
  return { tables, productsEn, filters, q }
}

export async function generateMetadata({ searchParams }) {
  const { tables, productsEn, filters, q } = await loadContext(searchParams)
  return listingMetadata({ tables, products: productsEn, filters, q, pathname: '/listing' })
}

export default async function ListingPage({ searchParams }) {
  const { tables, filters } = await loadContext(searchParams)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname: '/listing' })

  // Seed a QueryClient from the SAME cached catalog fetch so ListingClient reads
  // hydrated tables/products and server-renders the filtered count + first page.
  const qc = new QueryClient()
  try {
    const { tables: tbl, ratings, productsEn, productsAr } = await getServerCatalog()
    qc.setQueryData(['tables'], tbl)
    qc.setQueryData(['products'], { ratings, productsEn, productsAr })
  } catch {
    // catalog was unreachable → client fetches + shows the inline error/retry.
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      {/* BreadcrumbList structured data — in the initial HTML for scrapers. */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }}
      />
      {/* ListingClient reads useSearchParams() → wrap in Suspense. */}
      <Suspense fallback={null}>
        <ListingClient />
      </Suspense>
    </HydrationBoundary>
  )
}
