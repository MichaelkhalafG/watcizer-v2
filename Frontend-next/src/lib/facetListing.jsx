import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog } from './serverCatalog'
import {
  resolveFacetFilters,
  buildListingSeed,
  listingMetadata,
  listingBreadcrumbLd,
} from './listingSeo'
import ListingClient from '@/app/(main)/listing/ListingClient'

// Shared server logic for the facet alias routes (/brand/[brand],
// /category/[category], /subtypes/[subtype], /grade/[grade], /[suptype]/[brand]).
// Each resolves its slug segment(s) → the same ListingClient, seeded with the
// parsed facet. A slug that doesn't resolve to a real table row 404s (which also
// gates the greedy two-segment [suptype]/[brand] against arbitrary URLs).

// getServerCatalog is React-cached, so generateMetadata + the page share ONE fetch.
async function facetContext(facet) {
  let tables = {}
  let productsEn = []
  try {
    const cat = await getServerCatalog()
    tables = cat.tables || {}
    productsEn = cat.productsEn || []
  } catch {
    // catalog unreachable → filters can't resolve; treated as not-found below
  }
  const { filters, ok } = resolveFacetFilters(tables, facet)
  return { tables, productsEn, filters, ok }
}

// generateMetadata for a facet route (returns {} → the not-found response wins).
export async function facetMetadataFor({ facet, pathname }) {
  const { tables, productsEn, filters, ok } = await facetContext(facet)
  if (!ok) return {}
  return listingMetadata({ tables, products: productsEn, filters, pathname })
}

// The facet page body: seeded, SSR-filtered ListingClient + BreadcrumbList JSON-LD.
export async function FacetPage({ facet, pathname }) {
  const { tables, filters, ok } = await facetContext(facet)
  if (!ok) notFound()

  const seedParams = buildListingSeed(tables, filters)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname })

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
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }}
      />
      <Suspense fallback={null}>
        <ListingClient seedParams={seedParams} />
      </Suspense>
    </HydrationBoundary>
  )
}
