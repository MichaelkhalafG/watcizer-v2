import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { getServerCatalog } from './serverCatalog'
import {
  resolveFacetFilters,
  buildListingSeed,
  listingMetadata,
  listingBreadcrumbLd,
} from './listingSeo'
import ListingClient from '@/app/(main)/listing/ListingClient'
import { safeJsonLd } from './safeJsonLd'

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
//
// It reads the catalogue from the (main) layout's HydrationBoundary, exactly as /listing does. It
// used to build its OWN query cache on top with the FULL, unprojected EN + AR catalogue (C-1 stage
// 1, 2026-09-27): every brand/category/sub-type/grade page carried a second copy of the whole
// catalogue, 14.2 MB of HTML against /listing's 3.7 MB, and that copy overwrote the projected one in
// the browser's cache.
export async function FacetPage({ facet, pathname }) {
  const { tables, filters, ok } = await facetContext(facet)
  if (!ok) notFound()

  const seedParams = buildListingSeed(tables, filters)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname })

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      <Suspense fallback={null}>
        <ListingClient seedParams={seedParams} />
      </Suspense>
    </>
  )
}
