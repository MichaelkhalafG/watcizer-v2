import { notFound } from 'next/navigation'
import { getServerV2Product, getServerV2Related } from '@/src/lib/serverV2'
import { requestLang, localePath, SITE } from '@/src/lib/requestLang'
import { txt } from '@/src/lib/cardData'
import BfProductPage from '@/src/Components/Product/BfProductPage'
import { safeJsonLd } from '@/src/lib/safeJsonLd'

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
  const title = txt(p.meta?.title, urlLang) || txt(p.title, urlLang)
  const description = txt(p.meta?.description, urlLang) || txt(p.short_description, urlLang) || ''
  const path = `/product/${slug}`
  const images = (p.images || []).map((i) => i.src).filter(Boolean)
  return {
    title: `${title} | ${urlLang === 'ar' ? 'براند فاشون' : 'Brand Fashion'}`,
    description,
    alternates: {
      canonical: `${SITE}${localePath(path, urlLang)}`,
      languages: { en: `${SITE}${path}`, ar: `${SITE}${localePath(path, 'ar')}`, 'x-default': `${SITE}${path}` },
    },
    openGraph: { title, description, type: 'website', url: `${SITE}${localePath(path, urlLang)}`, images: images.slice(0, 1).map((url) => ({ url })) },
  }
}

export default async function ProductPage({ params }) {
  const { slug, detail, related } = await load(params)
  if (!detail) notFound()
  const { urlLang } = await requestLang()
  const p = detail.product
  const path = `/product/${slug}`
  const price = p.price || {}
  const inStock = Boolean(p.stock?.in_stock)
  const productLd = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name: txt(p.title, urlLang),
    image: (p.images || []).map((i) => i.src).filter(Boolean),
    description: txt(p.meta?.description, urlLang) || txt(p.short_description, urlLang) || undefined,
    sku: p.model_number || undefined,
    brand: p.brand?.name ? { '@type': 'Brand', name: txt(p.brand.name, 'en') } : undefined,
    offers: {
      '@type': 'Offer',
      url: `${SITE}${localePath(path, urlLang)}`,
      priceCurrency: price.currency || 'EGP',
      price: String(price.has_sale && price.sale_amount ? price.sale_amount : price.amount ?? ''),
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      itemCondition: 'https://schema.org/NewCondition',
    },
  }
  const crumbs = Array.isArray(p.breadcrumb) ? p.breadcrumb : []
  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: urlLang === 'ar' ? 'الرئيسية' : 'Home', item: SITE },
      ...crumbs.map((c, i) => ({ '@type': 'ListItem', position: i + 2, name: txt(c.name, urlLang), item: `${SITE}/category/${c.path}` })),
      { '@type': 'ListItem', position: crumbs.length + 2, name: txt(p.title, urlLang), item: `${SITE}${path}` },
    ],
  }
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(productLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }} />
      <BfProductPage detail={detail} related={related} />
    </>
  )
}
