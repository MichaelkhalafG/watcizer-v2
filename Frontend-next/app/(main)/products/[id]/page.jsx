import { notFound, permanentRedirect } from 'next/navigation'
import { getServerProductCard, fetchProductByName } from '@/src/lib/serverCatalog'
import { productUrl } from '@/src/utils/productUrl'

// Legacy id URL → canonical slug URL. Mirrors the SPA's /products/:id canonical
// redirect, but as a real server 308 (permanent) so scrapers/link equity follow.
export default async function ProductByIdPage({ params }) {
  const { id } = await params

  let product = null
  try {
    // One product from core by id (C-1 stage 4) — this used to load the whole catalogue.
    product = (await getServerProductCard(id))?.product ?? null
  } catch {
    // catalog unreachable → try the by-name endpoint below
  }
  if (!product) product = await fetchProductByName(id)
  if (!product) notFound()

  permanentRedirect(productUrl(product))
}
