import { FacetPage, facetMetadataFor } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params }) {
  const { subtype } = await params
  return facetMetadataFor({ facet: { subType: subtype }, pathname: `/subtypes/${subtype}` })
}

export default async function SubtypeListingPage({ params }) {
  const { subtype } = await params
  return <FacetPage facet={{ subType: subtype }} pathname={`/subtypes/${subtype}`} />
}
