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
import { passesFilters } from '../utils/filterPredicate'

export const SEO_DOMAIN = 'https://watchizereg.com'
const PRICE_MAX = 99999999

// english table name (→ flat column → any) for a lookup item
const nameEn = (item, key) =>
  item?.translations?.find((t) => t.locale === 'en')?.[key] ?? item?.[key] ?? ''

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

// The active single-facet crumb (english), matching old Listing's `crumb`:
// category → sub-type → brand → "All Products".
export function listingCrumbEn(tables = {}, filters = {}) {
  const one = (arr) => (arr || []).length === 1
  if (one(filters.categories)) {
    const c = tables.categoryTypes?.find((i) => i.id === filters.categories[0])
    if (c) return nameEn(c, 'category_type_name')
  }
  if (one(filters.subTypes)) {
    const s = tables.subTypes?.find((i) => i.id === filters.subTypes[0])
    if (s) return nameEn(s, 'sub_type_name')
  }
  if (one(filters.brands)) {
    const b = tables.brands?.find((i) => i.id === filters.brands[0])
    if (b) return nameEn(b, 'brand_name')
  }
  return 'All Products'
}

// Result count for the given filters + search — replicates ListingClient's
// pipeline (category base-split + passesFilters + text search, sans sort/paging)
// so the metadata description count matches what renders.
export function countListing(products = [], tables = {}, filters = {}, q = '') {
  let base = products
  if ((filters.categories || []).length > 0) {
    const names = filters.categories
      .map((id) => {
        const c = tables.categoryTypes?.find((i) => i.id === id)
        return c?.translations?.find((t) => t.locale === 'en')?.category_type_name
      })
      .filter(Boolean)
    if (names.length === 1) {
      if (names[0] === 'Watches') base = products.filter((p) => p.category_type === 'Watches')
      else if (names[0] === 'Fashion') base = products.filter((p) => p.category_type === 'Fashion')
    }
  }
  let filtered = base.filter((p) => passesFilters(p, filters))
  const query = (q || '').trim().toLowerCase()
  if (query) {
    filtered = filtered.filter(
      (p) =>
        p.product_title?.toLowerCase().includes(query) ||
        p.short_description?.toLowerCase().includes(query) ||
        p.brand?.toLowerCase().includes(query) ||
        p.search_keywords?.toLowerCase().includes(query),
    )
  }
  return filtered.length
}

// Next `metadata` object mirroring old Listing's <Helmet> (title / description /
// canonical + OG / Twitter). `pathname` is the clean self-canonical path.
export function listingMetadata({ tables = {}, products = [], filters = {}, q = '', pathname = '/listing' }) {
  const crumb = listingCrumbEn(tables, filters)
  const resultCount = countListing(products, tables, filters, q)

  const brandId = (filters.brands || []).length === 1 ? filters.brands[0] : null
  const catId = (filters.categories || []).length === 1 ? filters.categories[0] : null
  const subId = (filters.subTypes || []).length === 1 ? filters.subTypes[0] : null
  const activeCount =
    (filters.brands?.length || 0) + (filters.categories?.length || 0) + (filters.subTypes?.length || 0)

  const brandName = brandId && tables.brands?.find((i) => i.id === brandId)
  const catName = catId && tables.categoryTypes?.find((i) => i.id === catId)
  const subName = subId && tables.subTypes?.find((i) => i.id === subId)

  let title
  if (activeCount === 1 && brandName) {
    title = `${nameEn(brandName, 'brand_name')} Watches in Egypt | Watchizer`
  } else if (activeCount === 1 && catName) {
    title = `${nameEn(catName, 'category_type_name')} | Watchizer`
  } else if (activeCount === 1 && subName) {
    title = `${nameEn(subName, 'sub_type_name')} Watches | Watchizer`
  } else {
    title = 'Shop All Luxury Watches & Accessories | Watchizer'
  }

  const description = `Browse ${resultCount} ${crumb} at Watchizer — luxury watches and accessories with premium designs and unbeatable prices in Egypt.`
  const canonical = `${SEO_DOMAIN}${pathname}`

  // Social preview image: prefer the first catalog product's image (already a
  // fully-resolved absolute URL from the transform's getImageUrl); fall back to
  // the site logo so every facet page always emits an og:image / twitter:image.
  const ogImage =
    products?.[0]?.image && /^https?:\/\//.test(products[0].image)
      ? products[0].image
      : `${SEO_DOMAIN}/logo.svg`

  return {
    title,
    description,
    alternates: { canonical },
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
export function listingBreadcrumbLd({ tables = {}, filters = {}, pathname = '/listing' }) {
  const crumb = listingCrumbEn(tables, filters)
  return {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: `${SEO_DOMAIN}/` },
      { '@type': 'ListItem', position: 2, name: crumb, item: `${SEO_DOMAIN}${pathname}` },
    ],
  }
}
