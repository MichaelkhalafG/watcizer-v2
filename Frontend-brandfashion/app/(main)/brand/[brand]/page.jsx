import { FacetPage, facetMetadataFor } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params }) {
  const { brand } = await params
  return facetMetadataFor({ facet: { brand }, pathname: `/brand/${brand}` })
}

export default async function BrandListingPage({ params }) {
  const { brand } = await params
  return <FacetPage facet={{ brand }} pathname={`/brand/${brand}`} />
}
