import { FacetPage, facetMetadataFor, pageOf } from '@/src/lib/facetListing'
import { landingFacet } from '@/src/lib/landing'
import { reportedNotFound } from '@/src/lib/notFoundReport'

// A LANDING PAGE (SEO Phase 2, 2026-11-14): /brand/<brand>/<family>/<gender> — /brand/guess/bags/women.
// A TWIN. Which exist, and which may be indexed, is core's (src/lib/landing.js); any other segment is a 404.
export const revalidate = 300

export async function generateMetadata({ params, searchParams }) {
  const { brand, a, b } = await params
  const facet = landingFacet(brand, [a, b])
  if (!facet) return {}
  return facetMetadataFor({ facet, pathname: `/brand/${brand}/${a}/${b}`, page: pageOf(await searchParams) })
}

export default async function LandingPage({ params, searchParams }) {
  const { brand, a, b } = await params
  const facet = landingFacet(brand, [a, b])
  if (!facet) await reportedNotFound()
  return <FacetPage facet={facet} pathname={`/brand/${brand}/${a}/${b}`} page={pageOf(await searchParams)} />
}
