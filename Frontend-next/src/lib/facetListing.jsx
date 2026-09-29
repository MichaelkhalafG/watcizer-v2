import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { listingRequest } from './listingRequest'
import { parseListingParams } from '../utils/listingParams'
import { getServerTables, getServerListing } from './serverCatalog'
import {
  resolveFacetFilters,
  buildListingSeed,
  listingMetadata,
  listingSummary,
  listingBreadcrumbLd,
} from './listingSeo'
import ListingClient from '@/app/(main)/listing/ListingClient'
import { safeJsonLd } from './safeJsonLd'

// Shared server logic for the facet alias routes (/brand/[brand],
// /category/[category], /subtypes/[subtype], /grade/[grade], /[suptype]/[brand]).
// Each resolves its slug segment(s) → the same ListingClient, seeded with the
// parsed facet. A slug that doesn't resolve to a real table row 404s (which also
// gates the greedy two-segment [suptype]/[brand] against arbitrary URLs).

// The getters are React-cached, so generateMetadata + the page share ONE fetch each. The listing is
// core's first page for the facet — the page's prefetch and the metadata's count and preview image
// (C-1 stage 4 slice D: no catalogue).
async function facetContext(facet) {
  let tables = {}
  try {
    tables = (await getServerTables()) || {}
  } catch {
    // tables unreachable → filters can't resolve; treated as not-found below
  }
  const { filters, ok } = resolveFacetFilters(tables, facet)
  if (!ok) return { tables, filters, ok }

  // Keyed exactly as ListingClient will key it: from the seed string it parses, not from `filters`.
  const seedParams = buildListingSeed(tables, filters)
  const seed = parseListingParams(new URLSearchParams(seedParams), tables)
  const qs = listingRequest({ filters: seed.filters, q: seed.q, sort: seed.sort, page: seed.page, lang: 'en' })
  let listing = null
  try {
    listing = await getServerListing(qs)
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry.
  }
  return { tables, filters, ok, seedParams, qs, listing }
}

// generateMetadata for a facet route (returns {} → the not-found response wins).
export async function facetMetadataFor({ facet, pathname }) {
  const { tables, filters, ok, listing } = await facetContext(facet)
  if (!ok) return {}
  return listingMetadata({ tables, ...listingSummary(listing, tables), filters, pathname })
}

// The facet page body: seeded, SSR-filtered ListingClient + BreadcrumbList JSON-LD.
//
// It carries NO catalogue copy (C-1 stages 1 and 3, 2026-09-27): stage 1 removed the FULL second
// copy it used to embed (14.2 MB of HTML), stage 3 the layout's trimmed one. It gets the first page
// and the facet counts from core's `catalog/listing`, like /listing.
export async function FacetPage({ facet, pathname }) {
  const { tables, filters, ok, seedParams, qs, listing } = await facetContext(facet)
  if (!ok) notFound()

  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname })

  // The first page and its facet counts from core (C-1 stage 3).
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], listing)

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
