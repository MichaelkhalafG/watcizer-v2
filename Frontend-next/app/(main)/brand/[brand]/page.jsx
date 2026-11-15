import { FacetPage, facetMetadataFor, pageOf } from '@/src/lib/facetListing'

export const revalidate = 300

export async function generateMetadata({ params, searchParams }) {
  const { brand } = await params
  return facetMetadataFor({ facet: { brand }, pathname: `/brand/${brand}`, page: pageOf(await searchParams) })
}

export default async function BrandListingPage({ params, searchParams }) {
  const { brand } = await params
  return <FacetPage facet={{ brand }} pathname={`/brand/${brand}`} page={pageOf(await searchParams)} />
}
