import { describe, expect, it } from 'vitest'
import { emptyListingIs404, listingSummary } from '../src/lib/listingSeo.js'

// 2026-11-24 (Michael): a brand kept from search engines is hidden from search engines ONLY. A customer's search for it
// found its products and answered 404, because the empty rule counted what search engines may index. Now "found" counts
// every product core returned, and a search is never a 404.
const listing = (total, indexable) => ({ total, products: [], ratings: [], images: [], v2: { meta: { indexable_total: indexable } } })

describe('an empty listing', () => {
  it('counts what the customer is shown, apart from what search engines may index', () => {
    const s = listingSummary(listing(23, 0))
    expect(s.found).toBe(23)
    expect(s.total).toBe(0)
  })
  it('never 404s a search: not when every result is kept from search engines, not when nothing was found', () => {
    expect(emptyListingIs404({ query: 'q=hublot', q: 'hublot', summary: listingSummary(listing(23, 0)) })).toBe(false)
    expect(emptyListingIs404({ query: 'q=zzzz', q: 'zzzz', summary: listingSummary(listing(0, 0)) })).toBe(false)
  })
  it('404s a filter combination with no product at all, and never one with products or an outage', () => {
    expect(emptyListingIs404({ query: 'brands=9&color=pink', q: null, summary: listingSummary(listing(0, 0)) })).toBe(true)
    expect(emptyListingIs404({ query: 'brands=9', q: null, summary: listingSummary(listing(23, 0)) })).toBe(false)
    expect(emptyListingIs404({ query: 'brands=9', q: null, summary: listingSummary(null) })).toBe(false)
    expect(emptyListingIs404({ query: '', q: null, summary: listingSummary(listing(0, 0)) })).toBe(false)
  })
})
