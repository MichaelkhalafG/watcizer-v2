import { FacetPage, facetMetadataFor, pageOf } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params, searchParams }) {
  const { category } = await params
  return facetMetadataFor({ facet: { category }, pathname: `/category/${category}`, page: pageOf(await searchParams) })
}

export default async function CategoryListingPage({ params, searchParams }) {
  const { category } = await params
  return <FacetPage facet={{ category }} pathname={`/category/${category}`} page={pageOf(await searchParams)} />
}
