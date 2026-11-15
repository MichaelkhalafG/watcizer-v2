import { FacetPage, facetMetadataFor, pageOf } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params, searchParams }) {
  const { subtype } = await params
  return facetMetadataFor({ facet: { subType: subtype }, pathname: `/subtypes/${subtype}`, page: pageOf(await searchParams) })
}

export default async function SubtypeListingPage({ params, searchParams }) {
  const { subtype } = await params
  return <FacetPage facet={{ subType: subtype }} pathname={`/subtypes/${subtype}`} page={pageOf(await searchParams)} />
}
