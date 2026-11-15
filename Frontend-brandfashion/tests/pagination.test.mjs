import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { listingMetadata } from '../src/lib/listingSeo'

/*
 * CRAWLABLE PAGINATION (2026-11-14, the brief's Phase 1 item 6). A twin. Every page number of a listing is a real link to
 * that page's own address; a brand, category, sub-type or grade page renders ?page=N on the server; page 2 onward is
 * self-canonical with its own hreflang and says its number in the title; a page past the end is a 404.
 */
const read = (p) => readFileSync(new URL('../' + p, import.meta.url), 'utf8')
const tables = { brands: [{ id: 7, brand_name: 'Rolex', slug: 'rolex', translations: [{ locale: 'en', brand_name: 'Rolex' }] }] }
const meta = (page, lang = 'en') =>
  listingMetadata({ tables, total: 120, answered: true, filters: { brands: [7] }, pathname: '/brand/rolex', page, lang })

describe('pagination', () => {
  it('gives page 2 its own canonical and hreflang, and keeps page 1 bare', () => {
    expect(meta(1).alternates.canonical).toMatch(/\/brand\/rolex$/)
    const two = meta(2)
    expect(two.alternates.canonical).toMatch(/\/brand\/rolex\?page=2$/)
    expect(two.alternates.languages.ar).toMatch(/\/ar\/brand\/rolex\?page=2$/)
    expect(two.alternates.languages['x-default']).toMatch(/\/brand\/rolex\?page=2$/)
    expect(meta(2, 'ar').alternates.canonical).toMatch(/\/ar\/brand\/rolex\?page=2$/)
    expect(two.robots).toBeUndefined()
  })

  it('says the page number in the title, before the shop name, in both languages', () => {
    expect(meta(1).title).not.toMatch(/page/)
    expect(meta(3).title).toMatch(/ — page 3 \| /)
    expect(meta(3, 'ar').title).toMatch(/ — صفحة ٣ \| /)
  })

  it('draws the pager as links, and every facet route passes the page it was asked for', () => {
    const client = read('app/(main)/listing/ListingClient.jsx')
    expect(client).toMatch(/href=\{pageHref\(p\)\}/)
    expect(client).toMatch(/rel="next"/)
    expect(client).toMatch(/setCurrentPage\(seed\.page \|\| 1\)/)
    expect(read('src/lib/facetListing.jsx')).toMatch(/pastTheEnd\(listing, page\)\) await reportedNotFound\(\)/)
    for (const r of ['brand/[brand]', 'category/[category]', 'subtypes/[subtype]', 'grade/[grade]']) {
      expect(read(`app/(main)/${r}/page.jsx`), r).toMatch(/page=\{pageOf\(await searchParams\)\}/)
    }
  })
})
