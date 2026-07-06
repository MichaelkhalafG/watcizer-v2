import { FacetPage, facetMetadataFor } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params }) {
  const { grade } = await params
  return facetMetadataFor({ facet: { grade }, pathname: `/grade/${grade}` })
}

export default async function GradeListingPage({ params }) {
  const { grade } = await params
  return <FacetPage facet={{ grade }} pathname={`/grade/${grade}`} />
}
