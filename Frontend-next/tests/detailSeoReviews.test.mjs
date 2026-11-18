import { describe, expect, it } from 'vitest'
import { buildProductSeo } from '../src/lib/detailSeo.js'

/*
 * WATCHIZER ONLY (detailSeo.js is split): the product markup's rating comes only from core's approved reviews, the ones
 * the Reviews tab shows (2026-11-19). The product's own `rating` field and the older logged-in ratings no longer count.
 */
const product = { id: 7, name: 'A watch', name_en: 'A watch', price: 1000, rating: 4.8, average_rating: 4.8, stock: 3, images: [] }
const ld = (v2) => buildProductSeo(product, { lang: 'en', v2, ratings: [{ product_id: 7, rating: 5 }] }).productLd

describe('the product markup', () => {
  it('has no rating at all when the product has no reviews, whatever its old rating field says', () => {
    const out = ld({ reviews: { count: 0, average: null, items: [] } })
    expect(out.aggregateRating).toBeUndefined()
    expect(out.review).toBeUndefined()
    expect(ld(null).aggregateRating).toBeUndefined()
  })
  it('rates from the approved reviews when there are some', () => {
    const out = ld({ reviews: { count: 2, average: 4.5, items: [{ id: 1, name: 'Mona', rating: 5, body: 'Lovely.' }, { id: 2, name: 'Ali', rating: 4, body: 'Good.' }] } })
    expect(out.aggregateRating).toMatchObject({ ratingValue: '4.5', reviewCount: 2 })
    expect(out.review).toHaveLength(2)
  })
})
