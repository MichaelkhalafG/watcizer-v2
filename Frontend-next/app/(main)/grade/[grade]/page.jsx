import { FacetPage, facetMetadataFor, pageOf } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params, searchParams }) {
  const { grade } = await params
  return facetMetadataFor({ facet: { grade }, pathname: `/grade/${grade}`, page: pageOf(await searchParams) })
}

export default async function GradeListingPage({ params, searchParams }) {
  const { grade } = await params
  return <FacetPage facet={{ grade }} pathname={`/grade/${grade}`} page={pageOf(await searchParams)} />
}
