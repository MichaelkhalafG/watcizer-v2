import { notFound, permanentRedirect } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { getServerCatalog, getServerOffers, findOfferInCatalog } from '@/src/lib/serverCatalog'
import { buildOfferSeo } from '@/src/lib/detailSeo'
import ProductDetailClient from '@/src/Components/Product/ProductDetailClient'

export const revalidate = 300

// Resolve the offer ONCE per request (cached getters shared with generateMetadata).
// Offers resolve from the offers list (no single-offer endpoint); the linked main
// product is looked up for the SEO gallery.
async function resolveOffer(param) {
  try {
    const offers = await getServerOffers()
    const offer = findOfferInCatalog(offers, param)
    if (!offer) return null
    let offerProduct = null
    try {
      const { productsEn } = await getServerCatalog()
      offerProduct = (productsEn || []).find((p) => p.id === offer.main_product_id) || null
    } catch {
      // catalog optional for the offer page (only enriches the image gallery)
    }
    return { offer, offerProduct }
  } catch {
    return null
  }
}

export async function generateMetadata({ params }) {
  const { slug } = await params
  // notFound() HERE (in generateMetadata, before the page streams past its
  // loading.jsx boundary) is what sets a real 404 status for unknown offers —
  // calling it only in the page body renders the 404 UI but leaves the streamed
  // status at 200. Offer resolution is already strict (findOfferInCatalog matches
  // an exact slug or numeric id — there's no fuzzy by-name fallback like products).
  const resolved = await resolveOffer(slug)
  if (!resolved) notFound()
  return buildOfferSeo(resolved.offer, resolved.offerProduct).metadata
}

export default async function OfferPage({ params }) {
  const { slug } = await params
  const resolved = await resolveOffer(slug)
  if (!resolved) notFound()

  const { offer, offerProduct } = resolved
  const { canonicalPath, productLd, breadcrumbLd } = buildOfferSeo(offer, offerProduct)

  // Canonical redirect: non-canonical URL (numeric id, raw name) → /offer/{slug}.
  if (canonicalPath && canonicalPath !== `/offer/${slug}`) {
    permanentRedirect(canonicalPath)
  }

  // Seed ONLY offers here (the (main) layout doesn't hydrate offers). The catalog is
  // already hydrated by the layout, so we don't re-dehydrate it (that duplicated a
  // ~9 MB copy — C-1). ProductDetailClient resolves the offer's main product from the
  // layout-hydrated catalog (card fields); when offers are seeded, the offer detail
  // should fetch that product's full record by id like the product page does.
  const qc = new QueryClient()
  try {
    const offers = await getServerOffers()
    qc.setQueryData(['offers'], offers)
  } catch {
    /* client refetches */
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(productLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }}
      />
      <ProductDetailClient param={slug} isOffer={true} />
    </HydrationBoundary>
  )
}
