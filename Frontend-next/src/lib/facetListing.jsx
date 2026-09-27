import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import serverHttp from './serverFetch'
import { listingRequest, listingQueryFn } from './listingRequest'
import { parseListingParams } from '../utils/listingParams'
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
// It carries NO catalogue copy (C-1 stages 1 and 3, 2026-09-27): stage 1 removed the FULL second
// copy it used to embed (14.2 MB of HTML), stage 3 the layout's trimmed one. It gets the first page
// and the facet counts from core's `catalog/listing`, like /listing.
export async function FacetPage({ facet, pathname }) {
  const { tables, filters, ok } = await facetContext(facet)
  if (!ok) notFound()

  const seedParams = buildListingSeed(tables, filters)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname })

  // The first page and its facet counts from core (C-1 stage 3), keyed exactly as ListingClient
  // will key it: from the seed string it parses, not from `filters` directly.
  const qc = new QueryClient()
  const seed = parseListingParams(new URLSearchParams(seedParams), tables)
  const qs = listingRequest({ filters: seed.filters, q: seed.q, sort: seed.sort, page: seed.page, lang: 'en' })
  try {
    qc.setQueryData(['listing', qs], await listingQueryFn(qs, serverHttp)())
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry.
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      <Suspense fallback={null}>
        <ListingClient seedParams={seedParams} />
      </Suspense>
    </HydrationBoundary>
  )
}
