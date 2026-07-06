import { FacetPage, facetMetadataFor } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params }) {
  const { category } = await params
  return facetMetadataFor({ facet: { category }, pathname: `/category/${category}` })
}

export default async function CategoryListingPage({ params }) {
  const { category } = await params
  return <FacetPage facet={{ category }} pathname={`/category/${category}`} />
}
