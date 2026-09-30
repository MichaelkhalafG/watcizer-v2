import { notFound, permanentRedirect } from 'next/navigation'
import { getServerProductCard, fetchProductByName, localizedProduct } from '@/src/lib/serverCatalog'
import { requestLang, localePath } from '@/src/lib/requestLang'
import { buildProductSeo } from '@/src/lib/detailSeo'
import { toSlug } from '@/src/utils/slugs'
import ProductDetailClient from '@/src/Components/Product/ProductDetailClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'

// ISR: server-render the PDP (with product data + JSON-LD in the HTML for SEO),
// cache, revalidate every 5 min — matching the products query staleTime.
export const revalidate = 300

// Resolve the product ONCE per request. The getters are React-cached, so generateMetadata + the page
// body share ONE upstream fetch. Core's slug/id lookup first, then the by-name endpoint for legacy
// raw-title URLs.
//
// null (→ 404) ONLY when core answered "no such product" to BOTH lookups (2026-09-30). When core could
// not answer (a 429 from its rate limit, a 5xx, a timeout) the CoreUnavailableError propagates to the
// error boundary: a 5xx, so Google comes back later instead of dropping a real product from its index.
async function resolveProduct(param) {
  let failure = null
  try {
    // ONE product from core (`catalog/product`, the same slug rule) — C-1 stage 4. The page used to
    // load the whole catalogue here and search it.
    const card = await getServerProductCard(param)
    if (card) return { product: card.product, ratings: card.ratings, tables: card.tables, payload: card.payload }
  } catch (err) {
    // core could not answer the slug lookup: the by-name endpoint may still find it, but its "no"
    // can no longer prove the product does not exist
    failure = err
  }
  let byName
  try {
    byName = await fetchProductByName(param)
  } catch (err) {
    throw failure || err
  }
  if (!byName) {
    if (failure) throw failure
    return null
  }
  // Strict match: the by-name endpoint can return a FUZZY/near match for an
  // unknown slug (a soft-200 of the wrong product). Only accept it when the
  // requested param genuinely identifies THIS product — its numeric id, or a slug
  // that normalizes to the product's own slug (so legit legacy raw-title URLs
  // still resolve, then canonical-redirect). Otherwise treat it as not-found.
  let decoded = param
  try {
    decoded = decodeURIComponent(param)
  } catch {
    // malformed %-escape → compare the raw param
  }
  const requested = toSlug(decoded)
  const identifies =
    (/^\d+$/.test(param) && Number(param) === Number(byName.id)) ||
    requested === toSlug(byName.name || '') ||
    requested === toSlug(byName.product_title || '') ||
    requested === toSlug(byName.name_en || '')
  if (!identifies) return null
  return { product: byName, ratings: [], tables: null, payload: null }
}

export async function generateMetadata({ params }) {
  const { slug } = await params
  const resolved = await resolveProduct(slug)
  // notFound() HERE (in generateMetadata, before the page streams) is what sets a
  // real 404 status. The route has a loading.jsx boundary, so once the page body
  // starts streaming the status is locked at 200 — calling notFound() only in the
  // page renders the 404 UI but keeps the 200. Metadata runs first, so this 404s.
  if (!resolved) notFound()
  // In the URL's language (S-AR stage 1): /ar/product/… gets the Arabic title, description and
  // JSON-LD, self-canonical, with hreflang to the English page.
  const { urlLang } = await requestLang()
  return buildProductSeo(resolved.product, {
    ratings: resolved.ratings,
    tables: resolved.tables,
    lang: urlLang,
    localized: localizedProduct(resolved, urlLang),
    meta: resolved.payload?.meta,
  }).metadata
}

export default async function ProductPage({ params }) {
  const { slug } = await params
  const resolved = await resolveProduct(slug)
  if (!resolved) notFound()

  const { product, ratings, tables, payload } = resolved
  const { urlLang } = await requestLang()
  const { canonicalPath, productLd, breadcrumbLd } = buildProductSeo(product, {
    ratings,
    tables,
    lang: urlLang,
    localized: localizedProduct(resolved, urlLang),
    meta: payload?.meta,
  })

  // Canonical redirect (replaces ProductDetail's client-side <Navigate>): any
  // non-canonical URL — numeric id, legacy raw title — 308s to /product/{slug},
  // keeping the /ar prefix on an Arabic URL.
  if (canonicalPath && canonicalPath !== `/product/${slug}`) {
    permanentRedirect(localePath(canonicalPath, urlLang))
  }

  // The (main) layout already hydrates the (light) catalog for every page, so this
  // page does NOT re-dehydrate it (that duplicated a ~9 MB copy — C-1). ProductDetailClient
  // resolves the slug from the layout-hydrated catalog, then fetches the FULL record by
  // id for specs/gallery. JSON-LD/metadata above use the server-side full catalog, so
  // SEO is unaffected.
  return (
    <>
      {/* Product + BreadcrumbList structured data — in the initial HTML so
          scrapers see it without executing JS (the core SEO deliverable). */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(productLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }}
      />
      {/* No catalogue here since C-1 stage 4: the page carries its ONE product (core's card). */}
      <ProductDetailClient param={slug} isOffer={false} productPayload={payload} />
    </>
  )
}
