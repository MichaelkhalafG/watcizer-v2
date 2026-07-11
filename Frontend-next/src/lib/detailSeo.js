import { productUrl, offerUrl } from '../utils/productUrl'
import { buildListingParams } from '../utils/listingParams'

// Server-side SEO for the product/offer detail pages. Produces BOTH the Next
// `metadata` object (→ generateMetadata, replacing the old <Helmet>) and the
// Product + BreadcrumbList JSON-LD (→ emitted as <script> in the page's server
// HTML). English-canonical: titles/slugs/JSON-LD are built from the english
// fields so a single canonical URL + payload is served regardless of UI language.

export const SEO_DOMAIN = 'https://watchizereg.com'

const stripDesc = (raw, fallback) =>
  (raw || fallback || '')
    .toString()
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 160)

const productImages = (item, extra) => {
  const list = []
  if (item?.images?.length) item.images.forEach((u) => list.push(u))
  if (item?.image) list.push(item.image)
  if (extra?.image) list.push(extra.image)
  return [...new Set(list.filter(Boolean))]
}

const toMetadata = ({ title, seoDesc, canonicalUrl, ogTitle, ogImage }) => ({
  title,
  description: seoDesc,
  alternates: { canonical: canonicalUrl },
  openGraph: {
    // Next's typed OpenGraph union rejects 'product' (throws → drops ALL
    // metadata), so og:type=product is emitted via `other` below instead.
    title: ogTitle,
    description: seoDesc,
    url: canonicalUrl,
    siteName: 'Watchizer',
    images: [{ url: ogImage }],
  },
  other: { 'og:type': 'product' },
  twitter: {
    card: 'summary_large_image',
    title: ogTitle,
    description: seoDesc,
    images: [ogImage],
  },
})

// ── Product ────────────────────────────────────────────────────────────────
export function buildProductSeo(product, { ratings = [], tables = null } = {}) {
  const name = product.name || product.product_title || product.name_en || ''
  const brand =
    typeof product.brand === 'string'
      ? product.brand
      : product.brand?.brand_name || product.brand?.name_en || product.brand_name || ''

  const images = productImages(product)
  const price = Number(product.selling_price || 0)
  const sale = Number(product.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < price
  const priceNow = hasSale ? sale : price
  const inStock = Number(product.stock || 0) > 0 || Number(product.market_stock || 0) > 0

  // Price-led meta description (reused for OG/Twitter via toMetadata). Null-guarded:
  // when no valid price is present we fall back to the short-description blurb.
  const priceDesc =
    price > 0
      ? `Buy ${name}${brand ? ` by ${brand}` : ''}. ${
          hasSale
            ? `Now EGP ${Math.round(priceNow).toLocaleString()} (was EGP ${Math.round(
                price,
              ).toLocaleString()})`
            : `EGP ${Math.round(price).toLocaleString()}`
        }. Authentic luxury, certified & guaranteed. Free delivery across Egypt.`
      : null
  const seoDesc =
    priceDesc ||
    stripDesc(
      product.short_description_en || product.short_description || product.short_description_ar,
      `${name}${brand ? ` – ${brand}` : ''} — shop this timepiece at Watchizer.`,
    )

  const canonicalPath = productUrl(product)
  const canonicalUrl = `${SEO_DOMAIN}${canonicalPath}`
  const ogTitle = `${name}${brand ? ` – ${brand}` : ''} | Watchizer`
  const ogImage = images[0] || `${SEO_DOMAIN}/logo.svg`

  // Live review count/avg for aggregateRating (only emitted when reviews exist).
  const productRatings = (ratings || []).filter((r) => r.product_id === product.id)
  const reviewCount = productRatings.length
  const avgRating = reviewCount
    ? productRatings.reduce((a, r) => a + Number(r.rating || 0), 0) / reviewCount
    : Number(product.rating || product.average_rating || 0)

  const brandHref =
    brand && product.brand_id && tables
      ? `/listing?${buildListingParams({ brands: [product.brand_id] }, {}, tables).toString()}`
      : '/listing'
  const crumbMid = brand
    ? { name: brand, item: `${SEO_DOMAIN}${brandHref}` }
    : { name: 'All Products', item: `${SEO_DOMAIN}/listing` }

  const productLd = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name,
    ...(brand ? { brand: { '@type': 'Brand', name: brand } } : {}),
    ...(images.length ? { image: images } : {}),
    sku: String(product.id ?? ''),
    description: seoDesc,
    offers: {
      '@type': 'Offer',
      priceCurrency: 'EGP',
      price: priceNow,
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      url: canonicalUrl,
    },
    ...(reviewCount > 0
      ? {
          aggregateRating: {
            '@type': 'AggregateRating',
            ratingValue: Number(avgRating).toFixed(1),
            reviewCount,
          },
        }
      : {}),
  }

  const breadcrumbLd = {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: `${SEO_DOMAIN}/` },
      { '@type': 'ListItem', position: 2, name: crumbMid.name, item: crumbMid.item },
      { '@type': 'ListItem', position: 3, name, item: canonicalUrl },
    ],
  }

  return {
    canonicalPath,
    metadata: toMetadata({ title: ogTitle, seoDesc, canonicalUrl, ogTitle, ogImage }),
    productLd,
    breadcrumbLd,
  }
}

// ── Offer ──────────────────────────────────────────────────────────────────
export function buildOfferSeo(offer, offerProduct = null) {
  const name = offer.offer_name_en || offer.name_en || offer.name || ''
  const images = productImages(offer, offerProduct)
  const price = Number(offer.selling_price || 0)
  const sale = Number(offer.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < price
  const priceNow = hasSale ? sale : price
  const inStock = Number(offer.stock || 0) > 0

  const seoDesc = stripDesc(
    offer.short_description_en || offer.short_description || offer.short_description_ar,
    `${name} — shop this exclusive offer at Watchizer.`,
  )

  const canonicalPath = offerUrl(offer)
  const canonicalUrl = `${SEO_DOMAIN}${canonicalPath}`
  const ogTitle = `${name} | Watchizer`
  const ogImage = images[0] || `${SEO_DOMAIN}/logo.svg`

  const reviewCount = offer.offer_rating?.length || 0
  const avgRating = reviewCount
    ? offer.offer_rating.reduce((a, r) => a + Number(r.rating || 0), 0) / reviewCount
    : Number(offer.average_rate || 0)

  const productLd = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name,
    ...(images.length ? { image: images } : {}),
    sku: String(offer.id ?? ''),
    description: seoDesc,
    offers: {
      '@type': 'Offer',
      priceCurrency: 'EGP',
      price: priceNow,
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      url: canonicalUrl,
    },
    ...(reviewCount > 0
      ? {
          aggregateRating: {
            '@type': 'AggregateRating',
            ratingValue: Number(avgRating).toFixed(1),
            reviewCount,
          },
        }
      : {}),
  }

  const breadcrumbLd = {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: `${SEO_DOMAIN}/` },
      { '@type': 'ListItem', position: 2, name: 'Offers', item: `${SEO_DOMAIN}/offers` },
      { '@type': 'ListItem', position: 3, name, item: canonicalUrl },
    ],
  }

  return {
    canonicalPath,
    metadata: toMetadata({ title: ogTitle, seoDesc, canonicalUrl, ogTitle, ogImage }),
    productLd,
    breadcrumbLd,
  }
}
