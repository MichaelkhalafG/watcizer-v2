import { notFound } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerListing } from '@/src/lib/serverCatalog'
import { requestLang } from '@/src/lib/requestLang'
import { readListingState, toParams, toV2Query } from '@/src/lib/bfListing'
import { bfListingMetadata, listingQueryOf, listingTitle } from '@/src/lib/bfListingSeo'
import BfListing from '@/src/Components/Listing/BfListing'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { SITE } from '@/src/lib/requestLang'

// B4 — a Brand Fashion category page (spec v2.9 §10.2), on the v2 listing (DIVERGED from Frontend-next
// 2026-10-17, V2-READ-HALF / B4). `[category]` is v2's slug: a depth-1 path (`watches`) or a depth-2 slug
// (`diver`, which v2 resolves to `watches/diver`). A slug core does not know is a real 404; a category with
// nothing in it is 200 + `noindex, follow` while it is empty (the indexing rule of §10.2).
export const revalidate = 300

async function load(params, searchParams) {
  const { category } = await params
  const usp = toParams(await searchParams)
  const state = readListingState(usp, { category })
  const qs = toV2Query(state, 'en')
  let listing = null
  let failed = false
  try {
    listing = await getServerListing(qs)   // null (MISS) when core answers 404: no such category
  } catch {
    failed = true
  }
  return { category, usp, state, qs, listing, failed }
}

export async function generateMetadata({ params, searchParams }) {
  const { category, usp, listing, failed } = await load(params, searchParams)
  if (!listing && !failed) return {}
  const { urlLang } = await requestLang()
  const node = listing?.v2?.meta?.category || null
  return bfListingMetadata({
    title: listingTitle({ category: node, lang: urlLang }) || category,
    total: listing?.total ?? 0,
    answered: listing != null,
    image: listing?.v2?.data?.[0]?.image?.src ?? null,
    pathname: `/category/${category}`,
    query: listingQueryOf(usp.toString()),
    lang: urlLang,
  })
}

export default async function CategoryPage({ params, searchParams }) {
  const { category, qs, listing, failed } = await load(params, searchParams)
  if (!listing && !failed) notFound()
  const { urlLang } = await requestLang()
  const node = listing?.v2?.meta?.category || null
  const parent = node?.depth > 1 ? listing?.v2?.data?.[0]?.primary_category ?? null : null
  const crumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: urlLang === 'ar' ? 'الرئيسية' : 'Home', item: SITE },
      { '@type': 'ListItem', position: 2, name: listingTitle({ category: node, lang: urlLang }), item: `${SITE}/category/${category}` },
    ],
  }
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], listing)
  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(crumbLd) }} />
      <BfListing route={{ category, parent: parent && parent.path !== node?.path ? parent : null }} />
    </HydrationBoundary>
  )
}
