// SERVER-side SEO + seeding for the /listing route and its facet alias routes
// (/brand/[brand], /category/[category], /subtypes/[subtype], /grade/[grade],
// /[suptype]/[brand]). Mirrors the old Listing.jsx <Helmet> logic (title /
// description / canonical + BreadcrumbList JSON-LD) and produces the query-string
// seed that ListingClient hydrates from.
//
// English-canonical: titles/JSON-LD are built from the english table names so a
// single canonical payload is served regardless of UI language (same approach as
// detailSeo.js for the PDP).

import { fromSlug, brandSlug, subTypeSlug, categorySlug, toSlug } from '../utils/slugs'
import { buildListingParams } from '../utils/listingParams'
import { transformProductData } from '../utils/transformProduct'
import { localePath } from '../utils/localePath'

export const SEO_DOMAIN = 'https://watchizereg.com'
const PRICE_MAX = 99999999

// english table name (→ flat column → any) for a lookup item
const nameEn = (item, key) =>
  item?.translations?.find((t) => t.locale === 'en')?.[key] ?? item?.[key] ?? ''

// A lookup item's name in `lang` (S-AR stage 1): Arabic falls back to the English name.
const nameIn = (item, key, lang) =>
  (lang === 'ar' ? item?.translations?.find((t) => t.locale === 'ar')?.[key] : null) || nameEn(item, key)

// Next server pages hand searchParams as a plain object ({ k: v | v[] }); turn it
// into the URLSearchParams that parseListingParams / ListingClient expect.
export function objectToSearchParams(spObj) {
  const sp = new URLSearchParams()
  for (const [k, v] of Object.entries(spObj || {})) {
    if (Array.isArray(v)) v.forEach((x) => x != null && sp.append(k, x))
    else if (v != null) sp.append(k, String(v))
  }
  return sp
}

// Resolve a facet alias segment → a listing filters object. `ok` is false when a
// required slug doesn't resolve to a real table row (→ the route should 404).
//   facet: { brand?, subType?, category?, grade?, suptype? }  (all slug strings)
export function resolveFacetFilters(tables = {}, facet = {}) {
  const filters = { price: [0, PRICE_MAX] }
  let matched = false
  let ok = true

  const { brand, subType, category, grade, suptype } = facet

  if (brand !== undefined) {
    const b = fromSlug(brand, tables.brands, brandSlug)
    if (b) {
      filters.brands = [b.id]
      matched = true
    } else ok = false
  }
  if (subType !== undefined) {
    const s = fromSlug(subType, tables.subTypes, subTypeSlug)
    if (s) {
      filters.subTypes = [s.id]
      matched = true
    } else ok = false
  }
  if (category !== undefined) {
    const c = fromSlug(category, tables.categoryTypes, categorySlug)
    if (c) {
      filters.categories = [c.id]
      matched = true
    } else ok = false
  }
  if (grade !== undefined) {
    // The grade param is the english grade_name (legacy /grade/:grade), matched
    // against the plain column or the EN translation — mirroring old Listing.
    // Grade names carry spaces ("Iconic Luxury"), so the URL segment is encoded;
    // match tolerantly against the raw value, its decoded form, and the slug form.
    const dec = (() => {
      try {
        return decodeURIComponent(grade)
      } catch {
        return grade
      }
    })()
    const wants = [grade, dec, toSlug(dec)]
    const g = (tables.grades || []).find((x) => {
      const names = [
        x.grade_name,
        x.translations?.find((t) => t.locale === 'en')?.grade_name,
      ].filter(Boolean)
      return names.some((nm) => wants.includes(nm) || wants.includes(toSlug(nm)))
    })
    if (g) {
      filters.grades = [g.id]
      matched = true
    } else ok = false
  }
  // Two-segment /[suptype]/[brand]: suptype is a sub-type slug + brand slug.
  if (suptype !== undefined) {
    const s = fromSlug(suptype, tables.subTypes, subTypeSlug)
    if (s) {
      filters.subTypes = [s.id]
      matched = true
    } else ok = false
  }

  return { filters, ok: ok && matched }
}

// The query-string ListingClient seeds from (reuses buildListingParams so the
// client's parseListingParams round-trips it into the identical filter state).
export function buildListingSeed(tables, filters) {
  return buildListingParams(filters, {}, tables).toString()
}

// The active single-facet crumb, matching old Listing's `crumb`:
// category → sub-type → brand → grade → "All Products". In `lang` (S-AR stage 1).
export function listingCrumb(tables = {}, filters = {}, lang = 'en') {
  const one = (arr) => (arr || []).length === 1
  if (one(filters.categories)) {
    const c = tables.categoryTypes?.find((i) => i.id === filters.categories[0])
    if (c) return nameIn(c, 'category_type_name', lang)
  }
  if (one(filters.subTypes)) {
    const s = tables.subTypes?.find((i) => i.id === filters.subTypes[0])
    if (s) return nameIn(s, 'sub_type_name', lang)
  }
  if (one(filters.brands)) {
    const b = tables.brands?.find((i) => i.id === filters.brands[0])
    if (b) return nameIn(b, 'brand_name', lang)
  }
  if (one(filters.grades)) {
    const g = tables.grades?.find((i) => i.id === filters.grades[0])
    if (g) return nameIn(g, 'grade_name', lang)
  }
  return lang === 'ar' ? 'كل المنتجات' : 'All Products'
}

// What a listing's metadata needs from core's `catalog/listing` answer for the SAME request the page
// renders (C-1 stage 4 slice D): the result count, and the first card's picture for the social
// preview. It used to filter the whole catalogue on the server to count, and took the catalogue's
// first product's picture for EVERY listing page; now the preview is this listing's own first card.
export function listingSummary(listing, tables = {}) {
  const row = listing?.products?.[0]
  const [first] = row ? transformProductData([row], tables, listing.ratings || [], listing.images || [], 'en') : []
  return { total: Number(listing?.total) || 0, image: first?.image || null }
}

// Next `metadata` object mirroring old Listing's <Helmet> (title / description /
// canonical + OG / Twitter). `pathname` is the clean self-canonical path.
export function listingMetadata({ tables = {}, total = 0, image = null, filters = {}, pathname = '/listing', lang = 'en' }) {
  const ar = lang === 'ar'
  const crumb = listingCrumb(tables, filters, lang)
  const resultCount = total

  const brandId = (filters.brands || []).length === 1 ? filters.brands[0] : null
  const catId = (filters.categories || []).length === 1 ? filters.categories[0] : null
  const subId = (filters.subTypes || []).length === 1 ? filters.subTypes[0] : null
  const gradeId = (filters.grades || []).length === 1 ? filters.grades[0] : null
  const activeCount =
    (filters.brands?.length || 0) +
    (filters.categories?.length || 0) +
    (filters.subTypes?.length || 0) +
    (filters.grades?.length || 0)

  const brandName = brandId && tables.brands?.find((i) => i.id === brandId)
  const catName = catId && tables.categoryTypes?.find((i) => i.id === catId)
  const subName = subId && tables.subTypes?.find((i) => i.id === subId)
  const gradeName = gradeId && tables.grades?.find((i) => i.id === gradeId)

  let title
  if (activeCount === 1 && brandName) {
    title = ar
      ? `ساعات ${nameIn(brandName, 'brand_name', lang)} في مصر | Watchizer`
      : `${nameEn(brandName, 'brand_name')} Watches in Egypt | Watchizer`
  } else if (activeCount === 1 && catName) {
    title = `${nameIn(catName, 'category_type_name', lang)} | Watchizer`
  } else if (activeCount === 1 && subName) {
    title = ar
      ? `ساعات ${nameIn(subName, 'sub_type_name', lang)} | Watchizer`
      : `${nameEn(subName, 'sub_type_name')} Watches | Watchizer`
  } else if (activeCount === 1 && gradeName) {
    title = ar
      ? `ساعات ${nameIn(gradeName, 'grade_name', lang)} | Watchizer`
      : `${nameEn(gradeName, 'grade_name')} Watches | Watchizer`
  } else {
    title = ar ? 'تسوّق كل الساعات والإكسسوارات الفاخرة | Watchizer' : 'Shop All Luxury Watches & Accessories | Watchizer'
  }

  const description = ar
    ? `تصفّح ${resultCount} من ${crumb} في Watchizer — ساعات وإكسسوارات فاخرة بتصاميم راقية وأسعار لا تُقاوم في مصر.`
    : `Browse ${resultCount} ${crumb} at Watchizer — luxury watches and accessories with premium designs and unbeatable prices in Egypt.`
  // Self-canonical in the URL's language, with hreflang to the other (S-AR stage 1).
  const canonical = `${SEO_DOMAIN}${localePath(pathname, lang)}`
  const languages = {
    en: `${SEO_DOMAIN}${pathname}`,
    ar: `${SEO_DOMAIN}${localePath(pathname, 'ar')}`,
    'x-default': `${SEO_DOMAIN}${pathname}`,
  }

  // Social preview image: this listing's first card (an absolute URL from the transform's
  // getImageUrl); fall back to the site preview image (a JPG — social platforms refuse SVG) so
  // every listing page always emits an og:image / twitter:image.
  const ogImage = image && /^https?:\/\//.test(image) ? image : `${SEO_DOMAIN}/og-image.jpg`

  return {
    title,
    description,
    alternates: { canonical, languages },
    openGraph: {
      type: 'website',
      title,
      description,
      url: canonical,
      siteName: 'Watchizer',
      images: [{ url: ogImage, width: 600, height: 600, alt: title }],
    },
    twitter: {
      card: 'summary_large_image',
      title,
      description,
      images: [ogImage],
    },
  }
}

// BreadcrumbList JSON-LD: Home → active facet crumb (english-canonical).
export function listingBreadcrumbLd({ tables = {}, filters = {}, pathname = '/listing', lang = 'en' }) {
  const crumb = listingCrumb(tables, filters, lang)
  return {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: lang === 'ar' ? 'الرئيسية' : 'Home', item: `${SEO_DOMAIN}${localePath('/', lang)}` },
      { '@type': 'ListItem', position: 2, name: crumb, item: `${SEO_DOMAIN}${localePath(pathname, lang)}` },
    ],
  }
}
