import { productUrl, offerUrl } from '../utils/productUrl'
import { buildListingParams } from '../utils/listingParams'
import { localePath } from '../utils/localePath'
import { brandPageOf } from './menuLinks'
import { offerShippingDetails, offerReturnPolicy, offerWarranty } from './shopSchema'
import { productTitle, productDescription, productMaterial } from './productWords'

// Server-side SEO for the product/offer detail pages. Produces BOTH the Next
// `metadata` object (→ generateMetadata, replacing the old <Helmet>) and the
// Product + BreadcrumbList JSON-LD (→ emitted as <script> in the page's server
// HTML). The URL slug is always the English one (Arabic slugs are S-AR stage 4); the TEXT —
// title, description, JSON-LD names, breadcrumbs — is in the URL's language (S-AR stage 1):
// English on /product/…, Arabic on /ar/product/…, each self-canonical with hreflang to the other.

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

const toMetadata = ({ title, seoDesc, canonicalUrl, ogTitle, ogImage, languages }) => ({
  title,
  description: seoDesc,
  alternates: { canonical: canonicalUrl, ...(languages ? { languages } : {}) },
  openGraph: {
    // Next's typed OpenGraph union rejects 'product' (throws → drops ALL
    // metadata), so og:type=product is emitted via `other` below instead.
    title: ogTitle,
    description: seoDesc,
    url: canonicalUrl,
    siteName: 'Watchizer',
    images: [{ url: ogImage }],
  },
  twitter: {
    card: 'summary_large_image',
    title: ogTitle,
    description: seoDesc,
    images: [ogImage],
  },
})

// ── Product ────────────────────────────────────────────────────────────────
// `product` is the ENGLISH card (it names the URL); `localized` is the same product transformed in
// `lang` (the text). Without `localized`, English text. `meta` is core's cleaned meta title and
// description per language (S-AR stage 2, `catalog/product`): the team's words win where they wrote
// some — a meta title only when it says more than a model code (20+ characters) — and the templates
// below are the fallback, unchanged.
const META_TITLE_MIN = 20

export function buildProductSeo(product, { ratings = [], tables = null, lang = 'en', localized = null, meta = null, v2 = null, fees = null } = {}) {
  const ar = lang === 'ar'
  // WHAT SEARCH ENGINES AND AI ARE SHOWN of it (2026-11-14, core's SearchExposure — the dashboard's switch, else the
  // rule): `hidden` = noindex,follow and no Product structured data; `restricted` (a matched Rolex) = indexable, but
  // its structured data names no brand. Either way no authenticity claim. v2's record, else compat's on the ladder.
  const exposure = v2?.search ?? product?.search_class ?? null
  const matched = exposure === 'hidden' || exposure === 'restricted'
  const text = (ar && localized) || product
  const name = (ar ? text.product_title : null) || product.name || product.product_title || product.name_en || ''
  const brand =
    typeof text.brand === 'string'
      ? text.brand
      : text.brand?.brand_name || text.brand?.name_en || text.brand_name || ''

  const images = productImages(product)
  const price = Number(product.selling_price || 0)
  const sale = Number(product.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < price
  const priceNow = hasSale ? sale : price
  const inStock = Number(product.stock || 0) > 0 || Number(product.market_stock || 0) > 0

  // Price-led meta description (reused for OG/Twitter via toMetadata). Null-guarded:
  // when no valid price is present we fall back to the short-description blurb.
  //
  // 2026-10-19 (the developer's instruction): it said "Free delivery across Egypt" / «توصيل مجاني لجميع أنحاء مصر»
  // in EVERY product's and offer's search snippet, and delivery is not free — the terms put the fee on the
  // governorate and the cart charges it. Replaced by what the terms and the About page actually say: 27
  // governorates. The authenticity half of the sentence STAYS — the shop does guarantee it (the terms now say so).
  const egp = (n) => Math.round(n).toLocaleString('en-US')
  const priceDesc =
    price > 0
      ? ar
        ? `اشترِ ${name}${brand ? ` من ${brand}` : ''}. ${
            hasSale ? `الآن ${egp(priceNow)} ج.م بدلاً من ${egp(price)} ج.م` : `${egp(price)} ج.م`
          }.${matched ? '' : ' فخامة أصلية، معتمدة ومضمونة.'} نوصّل إلى ٢٧ محافظة.`
        : `Buy ${name}${brand ? ` by ${brand}` : ''}. ${
            hasSale
              ? `Now EGP ${Math.round(priceNow).toLocaleString()} (was EGP ${Math.round(
                  price,
                ).toLocaleString()})`
              : `EGP ${Math.round(price).toLocaleString()}`
          }.${matched ? '' : ' Authentic luxury, certified & guaranteed.'} We deliver to 27 governorates.`
      : null
  const metaDesc = meta?.description?.[lang] ? stripDesc(meta.description[lang]) : ''
  // THE TITLE AND DESCRIPTION FROM THE CATALOGUE (2026-11-14, SEO Phase 1 item 3; src/lib/productWords.js, a twin):
  // brand + model name + model number + gender + type, and a description per family that fits 155 characters. Built
  // from v2's record; a page without it (the compat ladder) and a Copy/Style product (null) keep the lines below.
  // …except a meta title a PERSON wrote in the dashboard (Michael 2026-11-14, D7: core's `meta.title_by_hand`)
  const handTitle = v2?.meta?.title_by_hand?.[lang] ? String(v2.meta.title?.[lang] || '').trim() : ''
  const builtTitle = handTitle
    ? (/watchizer|واتشايزر/i.test(handTitle) ? handTitle : `${handTitle} | ${ar ? 'واتشايزر' : 'Watchizer'}`)
    : productTitle(v2, lang, 'watchizer')
  const builtDesc = productDescription(v2, lang, {
    price: { now: priceNow, was: hasSale ? price : null },
    warrantyYears: v2?.warranty_years ?? product.warranty_years,
  })
  const seoDesc =
    builtDesc ||
    metaDesc ||
    priceDesc ||
    (ar
      ? stripDesc(text.short_description, `${name}${brand ? ` – ${brand}` : ''} — تسوّق هذه الساعة من Watchizer.`)
      : stripDesc(
          product.short_description_en || product.short_description || product.short_description_ar,
          `${name}${brand ? ` – ${brand}` : ''} — shop this timepiece at Watchizer.`,
        ))

  const canonicalPath = productUrl(product)
  const canonicalUrl = `${SEO_DOMAIN}${localePath(canonicalPath, lang)}`
  const languages = {
    en: `${SEO_DOMAIN}${canonicalPath}`,
    ar: `${SEO_DOMAIN}${localePath(canonicalPath, 'ar')}`,
    'x-default': `${SEO_DOMAIN}${canonicalPath}`,
  }
  const metaTitle = meta?.title?.[lang] || ''
  const ogTitle =
    builtTitle ||
    (metaTitle.length >= META_TITLE_MIN
      ? /watchizer/i.test(metaTitle)
        ? metaTitle
        : `${metaTitle} | Watchizer`
      : `${name}${brand ? ` – ${brand}` : ''} | Watchizer`)
  const ogImage = images[0] || `${SEO_DOMAIN}/og-image.jpg`

  // Live review count/avg for aggregateRating (only emitted when reviews exist). SEO Phase 5 (2026-11-14): on the v2
  // record, the shop's APPROVED VERIFIED reviews (core's product_reviews, from delivered orders, approved on the
  // dashboard) are the source — real reviews only — with up to five of them as Review markup.
  const verified = v2?.reviews && Number(v2.reviews.count) > 0 ? v2.reviews : null
  const productRatings = (ratings || []).filter((r) => r.product_id === product.id)
  const reviewCount = verified ? Number(verified.count) : productRatings.length
  const avgRating = verified
    ? Number(verified.average || 0)
    : reviewCount
      ? productRatings.reduce((a, r) => a + Number(r.rating || 0), 0) / reviewCount
      : Number(product.rating || product.average_rating || 0)
  const reviewLd = verified
    ? (verified.items || []).slice(0, 5).map((r) => ({
        '@type': 'Review',
        reviewRating: { '@type': 'Rating', ratingValue: r.rating, bestRating: 5 },
        author: { '@type': 'Person', name: r.name },
        ...(r.date ? { datePublished: r.date } : {}),
        reviewBody: r.body,
      }))
    : []

  // the brand's own page, never the filtered listing (2026-11-14: every product's BreadcrumbList named /listing?brand=…)
  const brandHref =
    (brand && product.brand_id && tables && (brandPageOf(product.brand_id, tables) || `/listing?${buildListingParams({ brands: [product.brand_id] }, {}, tables).toString()}`)) ||
    '/listing'
  const crumbMid = brand
    ? { name: brand, item: `${SEO_DOMAIN}${localePath(brandHref, lang)}` }
    : { name: ar ? 'كل المنتجات' : 'All Products', item: `${SEO_DOMAIN}${localePath('/listing', lang)}` }

  // from the v2 record (the product page passes it): the model number as the catalogue holds it, and the colour names
  // in the page's language — only what the specification block shows
  const modelNumber = String(v2?.model_number || product.model_number || '').trim() || null
  const colour = [...new Set((v2?.colors || []).map((c) => (ar ? c?.name?.ar : c?.name?.en) || c?.name?.en).filter(Boolean))].join(' / ') || null

  const productLd = {
    '@context': 'https://schema.org/',
    '@type': 'Product',
    name,
    ...(brand ? { brand: { '@type': 'Brand', name: brand } } : {}),
    ...(images.length ? { image: images } : {}),
    sku: String(product.id ?? ''),
    // the manufacturer's model number (2026-11-14): the sku stays the product id — the id the Merchant Center feed
    // and the pixel name this product by (ContentIds)
    ...(modelNumber ? { mpn: modelNumber } : {}),
    ...(colour ? { color: colour } : {}),
    ...(productMaterial(v2, lang) ? { material: productMaterial(v2, lang) } : {}),   // what the spec table shows
    description: seoDesc,
    offers: {
      '@type': 'Offer',
      priceCurrency: 'EGP',
      price: priceNow,
      availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      url: canonicalUrl,
      // the offer's condition, delivery and return policy (2026-11-14, SEO Phase 1 — Search Console: "missing
      // shippingDetails / hasMerchantReturnPolicy"); the shipping only when the dashboard's fees were read
      itemCondition: 'https://schema.org/NewCondition',
      ...(offerShippingDetails(fees) ? { shippingDetails: offerShippingDetails(fees) } : {}),
      hasMerchantReturnPolicy: offerReturnPolicy(),
      ...(offerWarranty(product.warranty_years ?? v2?.warranty_years) ? { warranty: offerWarranty(product.warranty_years ?? v2?.warranty_years, v2?.warranty_provider ?? null) } : {}),   // the warranty core says it carries (2026-11-14)
    },
    ...(reviewCount > 0
      ? {
          aggregateRating: {
            '@type': 'AggregateRating',
            ratingValue: Number(avgRating).toFixed(1),
            reviewCount,
          },
          ...(reviewLd.length ? { review: reviewLd } : {}),
        }
      : {}),
  }

  const breadcrumbLd = {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: ar ? 'الرئيسية' : 'Home', item: `${SEO_DOMAIN}${localePath('/', lang)}` },
      { '@type': 'ListItem', position: 2, name: crumbMid.name, item: crumbMid.item },
      { '@type': 'ListItem', position: 3, name, item: canonicalUrl },
    ],
  }

  // a matched product's structured data names no brand (the Rolex rule) — and a hidden one has none at all
  if (matched) delete productLd.brand
  const metadata = toMetadata({ title: ogTitle, seoDesc, canonicalUrl, ogTitle, ogImage, languages })
  return {
    canonicalPath,
    metadata: exposure === 'hidden' ? { ...metadata, robots: { index: false, follow: true } } : metadata,
    productLd: exposure === 'hidden' ? null : productLd,
    exposure,
    breadcrumbLd,
  }
}

// ── Offer ──────────────────────────────────────────────────────────────────
export function buildOfferSeo(offer, offerProduct = null, fees = null) {
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
  const ogImage = images[0] || `${SEO_DOMAIN}/og-image.jpg`

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
      // the offer's condition, delivery and return policy (2026-11-14, SEO Phase 1 — Search Console: "missing
      // shippingDetails / hasMerchantReturnPolicy"); the shipping only when the dashboard's fees were read
      itemCondition: 'https://schema.org/NewCondition',
      ...(offerShippingDetails(fees) ? { shippingDetails: offerShippingDetails(fees) } : {}),
      hasMerchantReturnPolicy: offerReturnPolicy(),
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
