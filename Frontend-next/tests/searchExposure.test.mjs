import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fromV2Product } from '../src/lib/v2Product'
import { transformProductData } from '../src/utils/transformProduct'
import { buildProductSeo } from '../src/lib/detailSeo'
import { listingSummary } from '../src/lib/listingSeo'

/*
 * WHAT SEARCH ENGINES AND AI ARE SHOWN OF A PRODUCT (2026-11-14, core's SearchExposure; Watchizer's own file). The
 * product stays on sale exactly as it is; a HIDDEN one is noindex,follow with no Product structured data; a RESTRICTED
 * one (a matched Rolex) stays indexable but names no brand; neither claims authenticity.
 */
const recorded = () => JSON.parse(readFileSync(new URL('./fixtures/v2-product-26101.json', import.meta.url), 'utf8'))

function seoFor(search) {
  const page = recorded()
  page.product.search = search
  const adapted = fromV2Product(page)
  const [product] = transformProductData(adapted.payload.products, {}, adapted.payload.ratings, adapted.payload.images, 'en')
  // the fixture is read without the lookup tables, so its card carries no brand name: give it one to drop
  product.brand = 'Hugo Boss'
  return buildProductSeo(product, { ratings: [], tables: {}, lang: 'en', localized: null, meta: adapted.payload.meta, v2: page.product })
}

describe('a product page and the hide-from-search rule', () => {
  it('is indexed with its brand when the rule leaves it alone', () => {
    const seo = seoFor(null)
    expect(seo.metadata.robots).toBeUndefined()
    expect(seo.productLd.brand).toBeTruthy()
  })
  it('stays indexable but names no brand when it is a matched Rolex (restricted)', () => {
    const seo = seoFor('restricted')
    expect(seo.metadata.robots).toBeUndefined()
    expect(seo.productLd).toBeTruthy()
    expect(seo.productLd).not.toHaveProperty('brand')
    expect(JSON.stringify(seo.productLd)).not.toMatch(/authentic|certified|genuine|original/i)
  })
  it('is noindex,follow with no Product structured data when hidden', () => {
    const seo = seoFor('hidden')
    expect(seo.metadata.robots).toEqual({ index: false, follow: true })
    expect(seo.productLd).toBeNull()
    expect(seo.breadcrumbLd).toBeTruthy()
  })
})

describe('a listing page', () => {
  it('counts what search engines may index for the thin rule, and the total where core does not say', () => {
    expect(listingSummary({ total: 52, products: [], v2: { meta: { indexable_total: 0 } } }).total).toBe(0)
    expect(listingSummary({ total: 52, products: [], v2: { meta: {} } }).total).toBe(52)
  })
})
