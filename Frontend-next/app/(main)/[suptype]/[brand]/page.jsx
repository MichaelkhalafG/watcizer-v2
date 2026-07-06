import { FacetPage, facetMetadataFor } from '@/src/lib/facetListing'

// Legacy two-segment alias /[suptype]/[brand] → the sub-type + brand pre-filtered
// listing. Static sibling segments (product, products, offer, category, brand,
// subtypes, grade, preview) take routing precedence, so this only catches
// otherwise-unmatched two-segment paths — and FacetPage 404s unless BOTH segments
// resolve to a real sub-type and brand, gating it against arbitrary URLs.
export const revalidate = 300

export async function generateMetadata({ params }) {
  const { suptype, brand } = await params
  return facetMetadataFor({ facet: { suptype, brand }, pathname: `/${suptype}/${brand}` })
}

export default async function SuptypeBrandListingPage({ params }) {
  const { suptype, brand } = await params
  return <FacetPage facet={{ suptype, brand }} pathname={`/${suptype}/${brand}`} />
}
