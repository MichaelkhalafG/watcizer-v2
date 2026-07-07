import { notFound, permanentRedirect } from 'next/navigation'
import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import {
  getServerCatalog,
  findProductInCatalog,
  fetchProductByName,
} from '@/src/lib/serverCatalog'
import { buildProductSeo } from '@/src/lib/detailSeo'
import { toSlug } from '@/src/utils/slugs'
import ProductDetailClient from '@/src/Components/Product/ProductDetailClient'

// ISR: server-render the PDP (with product data + JSON-LD in the HTML for SEO),
// cache, revalidate every 5 min — matching the products query staleTime.
export const revalidate = 300

// Resolve the product ONCE per request. The catalog getters are React-cached, so
// generateMetadata + the page body share ONE upstream fetch. Catalog hit first
// (english slug / numeric id), then the by-name endpoint for legacy raw-title URLs.
async function resolveProduct(param) {
  try {
    const { productsEn, ratings, tables } = await getServerCatalog()
    const inCatalog = findProductInCatalog(productsEn, param)
    if (inCatalog) return { product: inCatalog, ratings, tables }
  } catch {
    // catalog unreachable → fall through to the by-name endpoint
  }
  const byName = await fetchProductByName(param)
  if (!byName) return null
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
  return { product: byName, ratings: [], tables: null }
}

export async function generateMetadata({ params }) {
  const { slug } = await params
  const resolved = await resolveProduct(slug)
  // notFound() HERE (in generateMetadata, before the page streams) is what sets a
  // real 404 status. The route has a loading.jsx boundary, so once the page body
  // starts streaming the status is locked at 200 — calling notFound() only in the
  // page renders the 404 UI but keeps the 200. Metadata runs first, so this 404s.
  if (!resolved) notFound()
  return buildProductSeo(resolved.product, {
    ratings: resolved.ratings,
    tables: resolved.tables,
  }).metadata
}

export default async function ProductPage({ params }) {
  const { slug } = await params
  const resolved = await resolveProduct(slug)
  if (!resolved) notFound()

  const { product, ratings, tables } = resolved
  const { canonicalPath, productLd, breadcrumbLd } = buildProductSeo(product, { ratings, tables })

  // Canonical redirect (replaces ProductDetail's client-side <Navigate>): any
  // non-canonical URL — numeric id, legacy raw title — 308s to /product/{slug}.
  if (canonicalPath && canonicalPath !== `/product/${slug}`) {
    permanentRedirect(canonicalPath)
  }

  // Seed a QueryClient from the SAME cached catalog fetch (no extra network) so
  // ProductDetailClient resolves the product from hydrated cache with no refetch.
  const qc = new QueryClient()
  try {
    const { tables: tbl, ratings: r, productsEn, productsAr } = await getServerCatalog()
    qc.setQueryData(['tables'], tbl)
    qc.setQueryData(['products'], { ratings: r, productsEn, productsAr })
  } catch {
    // catalog was unreachable server-side → client fetches + resolves by-name
  }

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      {/* Product + BreadcrumbList structured data — in the initial HTML so
          scrapers see it without executing JS (the core SEO deliverable). */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(productLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }}
      />
      <ProductDetailClient param={slug} isOffer={false} />
    </HydrationBoundary>
  )
}
