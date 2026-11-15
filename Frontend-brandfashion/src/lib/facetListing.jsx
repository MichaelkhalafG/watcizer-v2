import { permanentRedirect } from 'next/navigation'
import { reportedNotFound } from '@/src/lib/notFoundReport'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { listingRequest, packListing } from './listingRequest'
import { parseListingParams } from '../utils/listingParams'
import { getServerTables, getServerListing } from './serverCatalog'
import { requestLang } from './requestLang'
import {
  resolveFacetFilters,
  buildListingSeed,
  listingMetadata,
  listingSummary,
  listingBreadcrumbLd,
  cleanPath,
  facetRedirectPath,
} from './listingSeo'
import { localePath } from '../utils/localePath'
import ListingClient from '@/app/(main)/listing/ListingClient'
import FacetIntro from '@/src/Components/FacetIntro/FacetIntro'
import FacetLead from '@/src/Components/FacetIntro/FacetLead'
import { getServerBlogs } from './serverBlogs'
import { archiveOf } from './blogTopics'
import { getServerV2Meta } from './serverV2'
import { dealerBrands, dealerSentence } from './dealers'
import { SHOP_NAME } from './siteAddress'
import { landingName, landingSegments } from './landing'
import { safeJsonLd } from './safeJsonLd'
// Brand Fashion (2026-10-18, the visual reset, Part 1b): Watchizer's listing components, Brand Fashion's skin.
import '@/src/styles/bf-listing-skin.css'

// Shared server logic for the facet alias routes (/brand/[brand],
// /category/[category], /subtypes/[subtype], /grade/[grade], /[suptype]/[brand]).
// Each resolves its slug segment(s) → the same ListingClient, seeded with the
// parsed facet. A slug that doesn't resolve to a real table row 404s (which also
// gates the greedy two-segment [suptype]/[brand] against arbitrary URLs).

// The getters are React-cached, so generateMetadata + the page share ONE fetch each. The listing is
// core's first page for the facet — the page's prefetch and the metadata's count and preview image
// (C-1 stage 4 slice D: no catalogue).
// The page a facet route was asked for (?page=N, 2026-11-14 item 6): a whole number from 1 to 500, else 1.
export function pageOf(searchParams) {
  const n = Number.parseInt(String(searchParams?.page ?? ''), 10)
  return Number.isFinite(n) && n > 1 && n <= 500 ? n : 1
}

async function facetContext(facet, page = 1) {
  // Tables unreachable → THROW (the error page, a 5xx), never "not found" (2026-09-30): without the
  // tables no slug resolves, and that used to turn every brand/category page into a 404.
  const tables = (await getServerTables()) || {}
  const { filters, ok } = resolveFacetFilters(tables, facet)
  if (!ok) return { tables, filters, ok }

  // Keyed exactly as ListingClient will key it: from the seed string it parses, not from `filters`.
  const base = buildListingSeed(tables, filters)
  const seedParams = page > 1 ? (base ? `${base}&page=${page}` : `page=${page}`) : base
  const seed = parseListingParams(new URLSearchParams(seedParams), tables)
  // the v2 request builder takes the tables: a category id becomes the slug v2 addresses it by (2026-10-22)
  const qs = listingRequest({ filters: seed.filters, q: seed.q, sort: seed.sort, page: seed.page, lang: 'en' }, tables)
  let listing = null
  try {
    listing = await getServerListing(qs)
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry.
  }
  return { tables, filters, ok, seedParams, qs, listing }
}

// A request whose segment is not the clean slug is sent to the clean path, permanently (2026-10-22; the owner's
// decision — see facetRedirectPath). Called from generateMetadata, which runs before the page streams, so the
// response really is a redirect and not a 200 with a redirect inside it (the same reason the product page 404s
// there). The two-segment route has no clean form and is never redirected.
function redirectToCleanPath(pathname, tables, filters, urlLang) {
  const kind = pathname.split('/')[1] || ''
  const to = facetRedirectPath(pathname, cleanPath(kind, tables, filters, pathname))
  if (to) permanentRedirect(localePath(to, urlLang))
}

// A landing page names itself in its H1 (2026-11-14): "Guess Women's Bags" / «حقائب Guess للنساء»; any other page: null
function landingHeading(tables, filters) {
  if (landingSegments(filters).length === 0) return null
  const b = (filters.brands || []).length === 1 ? (tables.brands || []).find((i) => i.id === filters.brands[0]) : null
  if (!b) return null
  const nameOf = (lang) => b.translations?.find((t) => t.locale === lang)?.brand_name || b.brand_name || ''
  return { en: landingName(nameOf('en'), filters, 'en'), ar: landingName(nameOf('ar') || nameOf('en'), filters, 'ar') }
}

// A page number past the listing's last page is a 404, not an empty page (a soft 404) — only when core answered.
function pastTheEnd(listing, page) {
  return page > 1 && !!listing && (listing?.v2?.data || []).length === 0
}

// generateMetadata for a facet route (returns {} → the not-found response wins).
export async function facetMetadataFor({ facet, pathname, page = 1 }) {
  const { tables, filters, ok, listing } = await facetContext(facet, page)
  if (!ok || pastTheEnd(listing, page)) return {}
  const { urlLang } = await requestLang()
  redirectToCleanPath(pathname, tables, filters, urlLang)
  return listingMetadata({ tables, ...listingSummary(listing, tables), filters, pathname, page, lang: urlLang })
}

// The facet page body: seeded, SSR-filtered ListingClient + BreadcrumbList JSON-LD.
//
// It carries NO catalogue copy (C-1 stages 1 and 3, 2026-09-27): stage 1 removed the FULL second
// copy it used to embed (14.2 MB of HTML), stage 3 the layout's trimmed one. It gets the first page
// and the facet counts from core's `catalog/listing`, like /listing.
export async function FacetPage({ facet, pathname, page = 1 }) {
  const { tables, filters, ok, seedParams, qs, listing } = await facetContext(facet, page)
  if (!ok || pastTheEnd(listing, page)) await reportedNotFound()

  const { urlLang } = await requestLang()
  redirectToCleanPath(pathname, tables, filters, urlLang)
  const breadcrumbLd = listingBreadcrumbLd({ tables, filters, pathname, lang: urlLang })
  const summary = listingSummary(listing, tables)
  // a brand page (and its landing pages) shows the brand's latest articles (SEO Phase 3 item 4); no articles, or core
  // could not say: none shown, the page is never held up for them
  const articles = facet?.brand ? archiveOf(await getServerBlogs().catch(() => []), 'brand', facet.brand).slice(0, 3) : []
  // authorized dealer for this brand (Michael, 2026-11-14): the brand switch of THIS shop, nothing else
  const meta = facet?.brand ? await getServerV2Meta().catch(() => null) : null
  const dealerBrand = facet?.brand ? dealerBrands(meta?.data ?? meta).filter((b) => b.slug === facet.brand) : []
  const dealer = dealerSentence(dealerBrand, urlLang, SHOP_NAME)

  // The first page and its facet counts from core (C-1 stage 3).
  const qc = new QueryClient()
  // packed: core's page once — the browser rebuilds the rest (v2Listing.js `packListing`, 2026-11-06)
  if (listing) qc.setQueryData(['listing', qs], packListing(listing))

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      {/* No <Suspense> here (2026-09-30). The page is dynamic, so useSearchParams() needs none; the
          boundary only made React send the finished grid as a separate block swapped in LATER — a
          page with an empty grid first, and on desktop the footer jumping down (CLS 0.50, measured). */}
      <ListingClient
        seedParams={seedParams}
        heading={landingHeading(tables, filters)}
        lead={<FacetLead context={summary.context} landings={summary.landings} articles={articles} brand={facet?.brand} dealer={dealer} lang={urlLang} />}
      />
      <FacetIntro context={summary.context} lang={urlLang} />
    </HydrationBoundary>
  )
}
