import { reportedNotFound } from '@/src/lib/notFoundReport'
import { getServerV2Product, getServerV2Related, getServerShippingFees } from '@/src/lib/serverV2'
import { offerShippingDetails, offerReturnPolicy, offerWarranty } from '@/src/lib/shopSchema'
import { requestLang, localePath, SITE } from '@/src/lib/requestLang'
import { txt } from '@/src/lib/cardData'
import BfProductPage from '@/src/Components/Product/BfProductPage'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { categoryPage } from '@/src/lib/categoryHref'
import { productName, productDescription, productMaterial } from '@/src/lib/productWords'
import VerifiedReviews from '@/src/Components/Reviews/VerifiedReviews'
import { reviewsLd } from '@/src/lib/reviewsLd'

// B5 — Brand Fashion's product page (spec v2.9 §10.3), on v2 `products/{slug}` + `products/{slug}/related`
// (DIVERGED from Frontend-next 2026-10-17, V2-READ-HALF / B5). `[slug]` is v2's product slug — the one every
// Brand Fashion card links to. A slug core does not know is a 404 (this shop is not indexed yet; the legacy
// raw-title redirect of the copied page is not carried — recorded). Metadata comes from v2's own `meta`
// (the team's words win), the Product and BreadcrumbList JSON-LD from the same payload.
export const revalidate = 300

async function load(params) {
  const { slug } = await params
  const detail = await getServerV2Product(slug)   // null = core said no such product; a core failure throws (5xx, never a 404)
  if (!detail) return { slug, detail: null, related: null }
  const related = await getServerV2Related(slug)
  return { slug, detail, related }
}

export async function generateMetadata({ params }) {
  const { slug, detail } = await load(params)
  if (!detail) return {}
  const { urlLang } = await requestLang()
  const p = detail.product
  // THE TITLE AND DESCRIPTION FROM THE CATALOGUE (2026-11-14, SEO Phase 1 item 3; src/lib/productWords.js, a twin):
  // brand + model name + model number + gender + type, and a description per family within 155 characters. A
  // Copy/Style product (null) keeps the lines it had: v2's meta, else its own title and short description. The
  // baseline's "Rolex fashion piece | Brand Fashion" was v2's meta title.
  const price = p.price || {}
  const now = price.has_sale && price.sale_amount ? Number(price.sale_amount) : Number(price.amount)
  // …except a meta title a PERSON wrote in the dashboard (Michael 2026-11-14, D7: core's `meta.title_by_hand`)
  const built = (p.meta?.title_by_hand?.[urlLang] && txt(p.meta?.title, urlLang)) || productName(p, urlLang)
  const title = built || txt(p.meta?.title, urlLang) || txt(p.title, urlLang)
  const description =
    productDescription(p, urlLang, { price: { now, was: price.has_sale ? Number(price.amount) : null }, warrantyYears: p.warranty_years }) ||
    txt(p.meta?.description, urlLang) || txt(p.short_description, urlLang) || ''
  const path = `/product/${slug}`
  const images = (p.images || []).map((i) => i.src).filter(Boolean)
  return {
    title: `${title} | ${urlLang === 'ar' ? 'براند فاشون' : 'Brand Fashion'}`,
    description,
    alternates: {
      canonical: `${SITE}${localePath(path, urlLang)}`,
      languages: { en: `${SITE}${path}`, ar: `${SITE}${localePath(path, 'ar')}`, 'x-default': `${SITE}${path}` },
    },
    openGraph: { title, description, url: `${SITE}${localePath(path, urlLang)}`, images: images.slice(0, 1).map((url) => ({ url })) },
    // kept from search engines (2026-11-14, core's SearchExposure — the dashboard's switch, else the rule): on sale,
    // never indexed; its links are still followed
    ...(p.search === 'hidden' ? { robots: { index: false, follow: true } } : {}),
    // this product's, never the layout's (2026-11-14)
    twitter: { card: 'summary_large_image', title, description, images: images.slice(0, 1) },
  }
}

export default async function ProductPage({ params }) {
  const { slug, detail, related } = await load(params)
  if (!detail) await reportedNotFound()
  const { urlLang } = await requestLang()
  const p = detail.product
  const path = `/product/${slug}`
  const price = p.price || {}
  const inStock = Boolean(p.stock?.in_stock)
  // the dashboard's delivery fees, for the offer's shipping details — never the page's failure (2026-11-14)
  const fees = await getServerShippingFees().catch(() => null)
  // the colour names the Details block shows, in the page's language
  const colour = [...new Set((p.colors || []).map((c) => txt(c?.name, urlLang)).filter(Boolean))].join(' / ') || undefined
  const productLd = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name: txt(p.title, urlLang),
    image: (p.images || []).map((i) => i.src).filter(Boolean),
    description: txt(p.meta?.description, urlLang) || txt(p.short_description, urlLang) || undefined,
    sku: p.model_number ? String(p.model_number).replace(/\s+/g, '') : undefined,   // Google: no whitespace in a sku
    mpn: p.model_number || undefined,                                                 // the manufacturer's model number
    color: colour,
    material: productMaterial(p, urlLang) || undefined,                               // what the spec table shows
    // a matched product (hidden or a restricted Rolex, 2026-11-14) names no brand in its structured data
    brand: p.brand?.name && !p.search ? { '@type': 'Brand', name: txt(p.brand.name, 'en') } : undefined,
    offers: {
      '@type': 'Offer',
      url: `${SITE}${localePath(path, urlLang)}`,
      priceCurrency: price.currency || 'EGP',
      price: String(price.has_sale && price.sale_amount ? price.sale_amount : price.amount ?? ''),
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      itemCondition: 'https://schema.org/NewCondition',
      // delivery and returns (2026-11-14, Search Console's two missing fields); the shipping only when the fees were read
      ...(offerShippingDetails(fees) ? { shippingDetails: offerShippingDetails(fees) } : {}),
      hasMerchantReturnPolicy: offerReturnPolicy(),
      ...(offerWarranty(p.warranty_years) ? { warranty: offerWarranty(p.warranty_years, p.warranty_provider) } : {}),   // the warranty core says it carries (2026-11-14)
    },
    // the approved verified reviews only (SEO Phase 5, 2026-11-14): nothing when there are none
    ...reviewsLd(p.reviews),
  }
  // Only the levels that have a page, at their address in this page's language (2026-11-14: every sub-category crumb
  // named /category/{a}/{b} — a 404 — and the Arabic page named its English addresses).
  const crumbs = (Array.isArray(p.breadcrumb) ? p.breadcrumb : []).filter((c) => categoryPage(c.path))
  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: urlLang === 'ar' ? 'الرئيسية' : 'Home', item: urlLang === 'ar' ? `${SITE}/ar` : SITE },
      ...crumbs.map((c, i) => ({ '@type': 'ListItem', position: i + 2, name: txt(c.name, urlLang), item: `${SITE}${localePath(categoryPage(c.path), urlLang)}` })),
      { '@type': 'ListItem', position: crumbs.length + 2, name: txt(p.title, urlLang), item: `${SITE}${localePath(path, urlLang)}` },
    ],
  }
  return (
    <>
      {/* og:type as a PROPERTY (2026-11-14): Next's typed openGraph refuses 'product'; React hoists this into <head> */}
      <meta property="og:type" content="product" />
      {/* none for a product kept from search engines (2026-11-14, core's SearchExposure) */}
      {p.search === 'hidden' ? null : <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(productLd) }} />}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }} />
      <BfProductPage detail={detail} related={related} />
      {/* the approved verified reviews, in the server HTML (SEO Phase 5, 2026-11-14) */}
      <VerifiedReviews reviews={p.reviews} lang={urlLang} />
    </>
  )
}
