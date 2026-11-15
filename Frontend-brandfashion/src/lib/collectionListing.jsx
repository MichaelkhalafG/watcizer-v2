import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import { listingRequest, packListing } from './listingRequest'
import { buildListingParams, parseListingParams } from '../utils/listingParams'
import { getServerTables, getServerListing } from './serverCatalog'
import { requestLang, SITE } from './requestLang'
import { localePath } from '../utils/localePath'
import { listingSummary } from './listingSeo'
import { THIN_LISTING_MIN } from './listingWords'
import ListingClient from '@/app/(main)/listing/ListingClient'
import FacetIntro from '@/src/Components/FacetIntro/FacetIntro'
import { safeJsonLd } from './safeJsonLd'
import { ORIGINAL_WATCHES, ORIGINAL_WATCHES_PATH } from '../content/originalWatches'
import { getServerV2Meta } from './serverV2'
import { dealerBrands, dealerSentence } from './dealers'
import { SHOP_NAME } from './siteAddress'

// THE ORIGINAL WATCHES PAGE (Michael, 2026-11-14). A TWIN. The existing listing — the same grid, filters, sorting and
// pages — over core's `collection=original-watches`: the watches the hide-from-search rule leaves alone (never a
// matched product, never the restricted Rolex, never a Generic watch). Its own title, H1, meta description and intro
// (content/originalWatches.js), self-canonical with hreflang, a BreadcrumbList and an ItemList of the first page's
// watches; noindex,follow while it is thin, like any listing.
async function collectionContext() {
  const tables = (await getServerTables()) || {}
  const seedParams = buildListingParams({ collection: 'original-watches' }, {}, tables).toString()
  const seed = parseListingParams(new URLSearchParams(seedParams), tables)
  const qs = listingRequest({ filters: seed.filters, q: seed.q, sort: seed.sort, page: seed.page, lang: 'en' }, tables)
  let listing = null
  try {
    listing = await getServerListing(qs)
  } catch {
    // core unreachable → the client fetches and shows the inline error/retry, as on every listing
  }
  return { tables, seedParams, qs, listing }
}

const urlOf = (path, lang) => `${SITE}${localePath(path, lang)}`

export async function originalWatchesMetadata() {
  const { tables, listing } = await collectionContext()
  const { urlLang } = await requestLang()
  const lang = urlLang === 'ar' ? 'ar' : 'en'
  const { total, answered } = listingSummary(listing, tables)
  const c = ORIGINAL_WATCHES
  return {
    title: c.title[lang],
    description: c.meta[lang],
    alternates: {
      canonical: urlOf(ORIGINAL_WATCHES_PATH, lang),
      languages: { en: urlOf(ORIGINAL_WATCHES_PATH, 'en'), ar: urlOf(ORIGINAL_WATCHES_PATH, 'ar'), 'x-default': urlOf(ORIGINAL_WATCHES_PATH, 'en') },
    },
    openGraph: { title: c.title[lang], description: c.meta[lang], url: urlOf(ORIGINAL_WATCHES_PATH, lang) },
    ...(answered && total < THIN_LISTING_MIN ? { robots: { index: false, follow: true } } : {}),
  }
}

export async function OriginalWatchesPage() {
  const { seedParams, qs, listing } = await collectionContext()
  const { urlLang } = await requestLang()
  const lang = urlLang === 'ar' ? 'ar' : 'en'
  const c = ORIGINAL_WATCHES
  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: c.crumbs.home[lang], item: urlOf('/', lang) },
      { '@type': 'ListItem', position: 2, name: c.crumbs.watches[lang], item: urlOf('/category/watches', lang) },
      { '@type': 'ListItem', position: 3, name: c.heading[lang], item: urlOf(ORIGINAL_WATCHES_PATH, lang) },
    ],
  }
  // the first page's watches — core's collection already leaves out every matched product; each card's own `search`
  // is checked again, so a stale answer can never name one
  const cards = (listing?.v2?.data || []).filter((card) => card?.slug && !card.search)
  const itemListLd = cards.length
    ? {
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        name: c.heading[lang],
        itemListElement: cards.map((card, i) => ({ '@type': 'ListItem', position: i + 1, url: urlOf(`/product/${card.slug}`, lang) })),
      }
    : null
  const qc = new QueryClient()
  if (listing) qc.setQueryData(['listing', qs], packListing(listing))

  // the brands this shop is an authorized dealer for (Michael, 2026-11-14): one line, none = no claim
  const dealerMeta = await getServerV2Meta().catch(() => null)
  const dealer = dealerSentence(dealerBrands(dealerMeta?.data ?? dealerMeta), lang, SHOP_NAME)
  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }} />
      {itemListLd ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(itemListLd) }} /> : null}
      {dealer ? (
        <p className="wz-collection-dealers" dir={lang === 'ar' ? 'rtl' : 'ltr'} style={{ maxWidth: 1200, margin: '0 auto', padding: '8px 16px 0', fontWeight: 600 }}>
          {dealer}
        </p>
      ) : null}
      <ListingClient seedParams={seedParams} heading={c.heading} />
      <FacetIntro context={{ en: { description: c.intro.en }, ar: { description: c.intro.ar } }} lang={lang} />
    </HydrationBoundary>
  )
}
