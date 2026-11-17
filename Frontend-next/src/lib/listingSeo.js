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
import { familiesOf, listingTitle, listingDescription, THIN_LISTING_MIN } from './listingWords'
import { landingName, landingSegments, watchLandingFilters } from './landing'

export const SEO_DOMAIN = 'https://watchizereg.com'
const PRICE_MAX = 99999999

// ── Indexing rules (2026-10-08, developer's decision) ────────────────────────────────────────────
// Indexable, and only while they have products: /listing (bare), /category/{c}, /brand/{b},
// /subtypes/{s} and /grade/{g} — every grade, one rule, no exception list. Everything else — any
// filtered /listing?…, the two-segment /[suptype]/[brand] — is `noindex, follow` with a SELF
// canonical, never a canonical to another page (Google reads noindex + cross-page canonical as
// conflicting, and the noindex can carry over to the target).

// The query keys that change what /listing shows (parseListingParams). Anything else — fbclid,
// utm_*, gclid — is tracking, and a /listing URL carrying only those is still the bare listing.
const LISTING_KEYS = new Set([
  'brand', 'subType', 'category', 'gender', 'dialColor', 'bandColor', 'material', 'movement',
  'shape', 'displayType', 'grade', 'offers', 'minPrice', 'maxPrice', 'q', 'sort', 'page',
])

// The listing-relevant part of a query string, in its original order ('' when there is none).
export function listingQuery(search) {
  const kept = new URLSearchParams()
  for (const [k, v] of new URLSearchParams(search || '')) if (LISTING_KEYS.has(k)) kept.append(k, v)
  return kept.toString()
}

const NOINDEX_FOLLOW = { index: false, follow: true }

// "Rolex Watches | Watchizer" → "Rolex Watches — page 2 | Watchizer" (2026-11-14, item 6)
const withPage = (title, page, ar) => {
  const word = ar ? `صفحة ${page.toLocaleString('ar-EG')}` : `page ${page}`
  const at = title.lastIndexOf(' | ')
  return at < 0 ? `${title} — ${word}` : `${title.slice(0, at)} — ${word}${title.slice(at)}`
}

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

  const { brand, subType, category, grade, suptype, family, gender } = facet
  // a LANDING page's narrowing (2026-11-14): one family and/or one gender, on a brand (src/lib/landing.js)
  if (family !== undefined) filters.family = family
  if (gender !== undefined) filters.genders = [gender]
  // a dial-colour or strap page of a brand's watches (2026-11-17): the colour / material must be one of the tables'
  if (!watchLandingFilters(filters, tables, facet)) ok = false

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
    if (b) return landingName(nameIn(b, 'brand_name', lang), filters, lang)
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
  // `answered`: core actually returned this listing. A failed call also yields total 0, and an empty
  // listing is `noindex` — so without this flag an outage would de-index every listing in the shop.
  const answered = listing != null && Number.isFinite(Number(listing.total))
  // the page's own text from the dashboard, both languages, when it has one (M1y, core's `meta.context`)
  const context = listing?.v2?.meta?.context || null
  // the families the page holds (core's facets for the same request) — what its title may say (listingWords.js)
  // the thin rule counts what search engines may index (2026-11-14, core's `indexable_total`: a brand whose products are
  // all kept from search goes noindex like an empty one); a core that predates it answers the total, as before
  const indexable = Number(listing?.v2?.meta?.indexable_total)
  const total = Number.isFinite(indexable) ? indexable : Number(listing?.total) || 0
  // a landing page's key and whether it may be indexed; a brand page's landing pages (2026-11-14, SEO Phase 2)
  const landing = listing?.v2?.meta?.landing || null
  const landings = listing?.v2?.meta?.landings || []
  return { total, image: first?.image || null, answered, context, families: familiesOf(listing), landing, landings }
}

// The page's own clean path, from the RESOLVED entity's slug — so /brand/Rolex and /brand/rol (both
// resolve to Rolex) share one canonical, /brand/rolex. `kind` is the route's first segment.
export function cleanPath(kind, tables, filters, pathname) {
  const one = (arr) => ((arr || []).length === 1 ? arr[0] : null)
  const find = (list, id) => (id == null ? null : (list || []).find((i) => i.id === id) || null)
  if (kind === 'brand') {
    const b = find(tables.brands, one(filters.brands))
    // a landing page's own address (2026-11-14): the brand's, then its family and gender segments
    if (b) return ['/brand', brandSlug(b), ...landingSegments(filters)].join('/')
  } else if (kind === 'category') {
    const c = find(tables.categoryTypes, one(filters.categories))
    if (c) return `/category/${categorySlug(c)}`
  } else if (kind === 'subtypes') {
    const s = find(tables.subTypes, one(filters.subTypes))
    if (s) return `/subtypes/${subTypeSlug(s)}`
  } else if (kind === 'grade') {
    const g = find(tables.grades, one(filters.grades))
    if (g) return `/grade/${toSlug(nameEn(g, 'grade_name'))}`
  }
  return pathname
}

// A facet URL whose segment is not the entity's clean slug — a brand by its NAME (/brand/TAG Heuer), a case
// variant (/brand/Citizen), a prefix the tolerant match accepted (/brand/rol), a grade by its name — is one page
// under two addresses. Search Console listed 40 of them as "alternate page with proper canonical" and the
// owner's decision (2026-10-22) is ONE address: a permanent redirect to the clean path. Returns that path when
// the request differs from it (after decoding), null when the URL is already clean or the route has no clean form.
export function facetRedirectPath(pathname, clean) {
  if (!clean || !pathname || clean === pathname) return null
  const dec = (s) => {
    try {
      return decodeURIComponent(s)
    } catch {
      return s
    }
  }
  return dec(pathname) === dec(clean) ? null : clean
}

// Robots + canonical path for a listing page (the rules above). `query` is listingQuery() of /listing's
// search string ('' on the facet routes, which ignore their query string).
function indexing({ pathname, query, tables, filters, total, answered, landing = null }) {
  const kind = pathname.split('/')[1] || ''
  if (kind === 'listing') {
    return query ? { robots: NOINDEX_FOLLOW, path: `/listing?${query}` } : { robots: undefined, path: '/listing' }
  }
  const path = cleanPath(kind, tables, filters, pathname)
  if (!['brand', 'category', 'subtypes', 'grade'].includes(kind)) return { robots: NOINDEX_FOLLOW, path } // /[suptype]/[brand]
  // THIN → noindex, follow (2026-11-14, item 5: fewer than THIN_LISTING_MIN LIVE products — it was "empty"), ONLY when
  // core answered: if core failed, nothing is added (the layout's index). Core's sitemap leaves the same pages out.
  if (answered && total < THIN_LISTING_MIN) return { robots: NOINDEX_FOLLOW, path }
  // a LANDING page is indexable only once core says so (its copy passed the quality gate in both languages and it
  // holds 6+ LIVE products — `meta.landing`); until then, and if core did not answer, noindex, follow
  if (kind === 'brand' && landingSegments(filters).length > 0 && !landing?.indexable) return { robots: NOINDEX_FOLLOW, path }
  return { robots: undefined, path }
}

// Next `metadata` object mirroring old Listing's <Helmet> (title / description /
// canonical + OG / Twitter). `pathname` is the clean self-canonical path.
export function listingMetadata({ tables = {}, total = 0, image = null, answered = false, context = null, families = { known: false, top: [], second: [] }, filters = {}, pathname = '/listing', query = '', page = 1, landing = null, lang = 'en' }) {
  const ar = lang === 'ar'
  const crumb = listingCrumb(tables, filters, lang)

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

  // The words come from the page's own products (src/lib/listingWords.js, 2026-11-14): "Watches" only where every
  // product is a watch; the families the page holds otherwise; no count and no "luxury watches" in the fallback.
  const SHOP = 'Watchizer'
  let title
  const landingOf = landingSegments(filters).length > 0 && brandName
  if (landingOf) {
    // a landing page (2026-11-14): "Guess Women's Bags in Egypt" / «حقائب Guess للنساء في مصر»
    const name = landingName(ar ? nameIn(brandName, 'brand_name', lang) : nameEn(brandName, 'brand_name'), filters, lang)
    title = ar ? `${name} في مصر | ${SHOP}` : `${name} in Egypt | ${SHOP}`
  } else if (activeCount === 1 && brandName) {
    title = listingTitle({ kind: 'brand', name: ar ? nameIn(brandName, 'brand_name', lang) : nameEn(brandName, 'brand_name'), families, lang, shop: SHOP })
  } else if (activeCount === 1 && catName) {
    title = listingTitle({ kind: 'category', name: nameIn(catName, 'category_type_name', lang), families, lang, shop: SHOP })
  } else if (activeCount === 1 && subName) {
    title = listingTitle({ kind: 'subtype', name: ar ? nameIn(subName, 'sub_type_name', lang) : nameEn(subName, 'sub_type_name'), families, lang, shop: SHOP })
  } else if (activeCount === 1 && gradeName) {
    title = listingTitle({ kind: 'grade', name: ar ? nameIn(gradeName, 'grade_name', lang) : nameEn(gradeName, 'grade_name'), families, lang, shop: SHOP })
  } else {
    title = ar ? 'تسوّق كل الساعات والإكسسوارات الفاخرة | Watchizer' : 'Shop All Luxury Watches & Accessories | Watchizer'
  }

  // The meta description written for this brand or category page in the dashboard wins (M1y); the fallback is a
  // sentence true of every product (listingDescription).
  const written = context?.[lang]?.meta_description
  const description = written || listingDescription({ crumb, lang, shop: SHOP })
  // Self-canonical in the URL's language, with hreflang to the other (S-AR stage 1) — built from the
  // page's clean path (resolved slug), and noindex where the indexing rules say so.
  // page 2+ says so in its title, before the shop's name, so no two pages of one listing share a title
  if (page > 1) title = withPage(title, page, ar)
  const { robots, path: own } = indexing({ pathname, query, tables, filters, total, answered, landing })
  // page 2+ of a facet page (?page=N, 2026-11-14 item 6): its OWN canonical and hreflang, never page 1's
  const path = page > 1 && !own.includes('?') ? `${own}?page=${page}` : own
  const [pathOnly, search] = path.split('?')
  const withQuery = (p) => (search ? `${p}?${search}` : p)
  const canonical = `${SEO_DOMAIN}${withQuery(localePath(pathOnly, lang))}`
  const languages = {
    en: `${SEO_DOMAIN}${withQuery(pathOnly)}`,
    ar: `${SEO_DOMAIN}${withQuery(localePath(pathOnly, 'ar'))}`,
    'x-default': `${SEO_DOMAIN}${withQuery(pathOnly)}`,
  }

  // Social preview image: this listing's first card (an absolute URL from the transform's
  // getImageUrl); fall back to the site preview image (a JPG — social platforms refuse SVG) so
  // every listing page always emits an og:image / twitter:image.
  const ogImage = image && /^https?:\/\//.test(image) ? image : `${SEO_DOMAIN}/og-image.jpg`

  return {
    title,
    description,
    ...(robots ? { robots } : {}),
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
  // a landing page (2026-11-14): Home › the brand › the landing page
  const segs = landingSegments(filters)
  const b = (filters.brands || []).length === 1 ? tables.brands?.find((i) => i.id === filters.brands[0]) : null
  if (segs.length > 0 && b) {
    const brandPath = `/brand/${brandSlug(b)}`
    return {
      '@context': 'https://schema.org/',
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: lang === 'ar' ? 'الرئيسية' : 'Home', item: `${SEO_DOMAIN}${localePath('/', lang)}` },
        { '@type': 'ListItem', position: 2, name: nameIn(b, 'brand_name', lang), item: `${SEO_DOMAIN}${localePath(brandPath, lang)}` },
        { '@type': 'ListItem', position: 3, name: crumb, item: `${SEO_DOMAIN}${localePath(pathname, lang)}` },
      ],
    }
  }
  return {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: lang === 'ar' ? 'الرئيسية' : 'Home', item: `${SEO_DOMAIN}${localePath('/', lang)}` },
      { '@type': 'ListItem', position: 2, name: crumb, item: `${SEO_DOMAIN}${localePath(pathname, lang)}` },
    ],
  }
}
