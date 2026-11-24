import { describe, expect, it } from 'vitest'
import { buildProductSeo } from '../src/lib/detailSeo.js'

// Merchant listings, 2026-11-24: every product's markup names its brand - except a product whose brand is "Generic"
// (Google asks not to send it), a restricted one (the Rolex rule) and a hidden one (no Product markup at all).
const base = { id: 9, name: 'A watch', name_en: 'A watch', price: 1000, stock: 3, images: [] }
const named = { ...base, brand: { id: 4, name_en: 'Tommy Hilfiger', name_ar: 'تومي هيلفيغر' } }
const ld = (product, opts = {}) => buildProductSeo(product, { lang: 'en', ...opts }).productLd

describe('the brand in product markup', () => {
  it('names the brand in both languages, from the main payload when the Arabic one carries none', () => {
    expect(ld(named).brand).toEqual({ '@type': 'Brand', name: 'Tommy Hilfiger' })
    expect(ld(named, { lang: 'ar', localized: { product_title: 'ساعة' } }).brand).toEqual({ '@type': 'Brand', name: 'Tommy Hilfiger' })
    expect(ld({ ...base, brand_name: 'Guess' }).brand).toEqual({ '@type': 'Brand', name: 'Guess' })
  })
  it('names no brand for Generic, a restricted product, and has no markup for a hidden one', () => {
    expect(ld({ ...base, brand: { id: 1, name_en: 'Generic', name_ar: 'غير محدد' } }).brand).toBeUndefined()
    expect(ld({ ...named, search_class: 'restricted' }).brand).toBeUndefined()
    expect(ld({ ...named, search_class: 'hidden' })).toBeNull()
  })
})
