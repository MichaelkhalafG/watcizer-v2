import { alternatesFor, SITE } from './requestLang'

export { listingTitle } from './bfListing'

// B4's metadata (spec v2.9 §10.2 "Indexing — two rules", as built on Watchizer 2026-10-08 and decided):
//  • a CLEAN route (/category/{path}, /brand/{slug}, bare /listing) is indexable while it has products;
//    while its count is zero it carries `noindex, follow` — and only when core actually answered (a failed
//    load never de-indexes); a slug that resolves to nothing at all is a 404 (the page decides that).
//  • EVERY query-string listing (filters, q, sort, page > 1) is a shopper's tool: `noindex, follow` with a
//    SELF canonical — never a canonical to another page (the developer's decision over the spec's wording).

const TRACKING = /^(utm_|fbclid$|gclid$|msclkid$|ttclid$)/i

/** The query string with tracking parameters removed — what decides "query listing" and the self canonical. */
export function listingQueryOf(search) {
  const sp = new URLSearchParams(search || '')
  for (const k of [...sp.keys()]) if (TRACKING.test(k)) sp.delete(k)
  const s = sp.toString()
  return s ? `?${s}` : ''
}

export function bfListingMetadata({ title, total = 0, answered = false, image = null, pathname = '/listing', query = '', lang = 'en' }) {
  const ar = lang === 'ar'
  const isQuery = query !== ''
  const noindex = isQuery || (answered && total === 0)
  const path = pathname + query
  const fullTitle = `${title} | ${ar ? 'براند فاشون' : 'Brand Fashion'}`
  const description = ar
    ? `تسوّق ${title} في براند فاشون — ${total} منتج، توصيل داخل مصر.`
    : `Shop ${title} at Brand Fashion — ${total} ${total === 1 ? 'product' : 'products'}, delivery across Egypt.`
  return {
    title: fullTitle,
    description,
    robots: noindex ? { index: false, follow: true } : { index: true, follow: true },
    alternates: alternatesFor(path, lang),
    openGraph: {
      title: fullTitle,
      description,
      url: `${SITE}${path}`,
      type: 'website',
      ...(image ? { images: [{ url: image }] } : {}),
    },
  }
}
