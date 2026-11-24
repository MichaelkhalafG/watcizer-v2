import { describe, expect, it } from 'vitest'
import { brandLd } from '../src/lib/productBrand.js'

// Merchant listings, 2026-11-24: the product page's markup names the brand - except Generic, and a product the search
// rules match (hidden, or a restricted Rolex).
describe('the brand in product markup', () => {
  it('names a maker in English, from either shape of the name', () => {
    expect(brandLd({ brand: { name: { en: 'Coach', ar: 'كوتش' } } })).toEqual({ '@type': 'Brand', name: 'Coach' })
    expect(brandLd({ brand: { name: 'Guess' } })).toEqual({ '@type': 'Brand', name: 'Guess' })
  })
  it('names none for Generic, a matched product, or no brand', () => {
    expect(brandLd({ brand: { name: { en: 'Generic', ar: 'غير محدد' } } })).toBeUndefined()
    expect(brandLd({ brand: { name: { en: 'Rolex' } }, search: 'restricted' })).toBeUndefined()
    expect(brandLd({ brand: { name: { en: 'Hublot' } }, search: 'hidden' })).toBeUndefined()
    expect(brandLd({})).toBeUndefined()
  })
})
