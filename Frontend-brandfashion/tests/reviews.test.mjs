import { existsSync, readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { reviewsLd } from '../src/lib/reviewsLd.js'

/*
 * VERIFIED REVIEWS ON THE STOREFRONT (SEO Phase 5, 2026-11-14) — a twin. The product's review markup comes only from the
 * reviews core sends, which are the APPROVED verified ones; none means no aggregateRating at all. The review page is
 * never indexed.
 */
const approved = {
  count: 2,
  average: 4.5,
  items: [
    { id: 1, name: 'Mona', rating: 5, body: 'Lovely.', date: '2026-12-01', photos: [] },
    { id: 2, name: 'Ahmed', rating: 4, body: 'Good.', date: '2026-12-02', photos: [] },
  ],
}

describe('the review markup', () => {
  it('is an aggregateRating with up to five reviews, from the approved reviews only', () => {
    const ld = reviewsLd(approved)
    expect(ld.aggregateRating).toEqual({ '@type': 'AggregateRating', ratingValue: '4.5', reviewCount: 2, bestRating: 5 })
    expect(ld.review).toHaveLength(2)
    expect(ld.review[0]).toMatchObject({ '@type': 'Review', author: { '@type': 'Person', name: 'Mona' }, reviewRating: { ratingValue: 5 } })
    const many = { count: 9, average: 4, items: Array.from({ length: 9 }, (_, i) => ({ id: i, name: `N${i}`, rating: 4, body: 'x' })) }
    expect(reviewsLd(many).review).toHaveLength(5)
  })
  it('is nothing at all when there are no approved reviews', () => {
    expect(reviewsLd(null)).toEqual({})
    expect(reviewsLd({ count: 0, average: null, items: [] })).toEqual({})
  })
})

describe('the review page', () => {
  it('exists, is never indexed, and posts to core under this shop', () => {
    const page = new URL('../app/(main)/review/[token]/page.jsx', import.meta.url)
    expect(existsSync(page)).toBe(true)
    const src = readFileSync(page, 'utf8')
    expect(src).toContain('robots: { index: false, follow: false }')
    const form = readFileSync(new URL('../app/(main)/review/[token]/ReviewForm.jsx', import.meta.url), 'utf8')
    expect(form).toContain('/v2/${STOREFRONT_CODE}/reviews')
    expect(form).not.toContain('STOREFRONT_SERVER_KEY')
  })
})
