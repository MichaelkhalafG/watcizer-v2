import { notFound, permanentRedirect } from 'next/navigation'
import { getServerProductCard, fetchProductByName } from '@/src/lib/serverCatalog'
import { productUrl } from '@/src/utils/productUrl'
import { requestLang, localePath } from '@/src/lib/requestLang'

// Legacy id URL → canonical slug URL. Mirrors the SPA's /products/:id canonical
// redirect, but as a real server 308 (permanent) so scrapers/link equity follow.
export default async function ProductByIdPage({ params }) {
  const { id } = await params

  let product = null
  let failure = null
  try {
    // One product from core by id (C-1 stage 4) — this used to load the whole catalogue.
    product = (await getServerProductCard(id))?.product ?? null
  } catch (err) {
    // core could not answer → try the by-name endpoint, but its "no" cannot prove a 404 any more
    failure = err
  }
  if (!product) {
    try {
      product = await fetchProductByName(id)
    } catch (err) {
      throw failure || err
    }
  }
  // 404 only when core said "no such product" to both lookups; a failure reaches the error page (5xx).
  if (!product && failure) throw failure
  if (!product) notFound()

  // /ar/products/{id} → /ar/product/{slug} (S-AR stage 1).
  permanentRedirect(localePath(productUrl(product), (await requestLang()).urlLang))
}
