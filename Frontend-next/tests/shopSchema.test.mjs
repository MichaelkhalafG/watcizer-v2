import { describe, expect, it } from 'vitest'
import { returnPolicy, offerReturnPolicy, shippingService, offerShippingDetails, shopGraph, feesOf, offerWarranty } from '../src/lib/shopSchema.js'
import { warrantyText } from '../src/lib/v2Product.js'

/*
 * THE SHOP AS STRUCTURED DATA (2026-11-14, SEO Phase 1). The values are the business facts the pages state: founded
 * 2013; returns within 14 days, by post, free, full refund (fact 6); delivery within 24 hours at the dashboard's fee
 * (fact 5, Michael 2026-11-14), never a free-shipping threshold; no SearchAction.
 */

describe('the return policy', () => {
  it('is 14 days, free, by post, a full refund, in Egypt', () => {
    expect(returnPolicy()).toMatchObject({
      '@type': 'MerchantReturnPolicy',
      applicableCountry: 'EG',
      returnPolicyCountry: 'EG',
      returnPolicyCategory: 'https://schema.org/MerchantReturnFiniteReturnWindow',
      merchantReturnDays: 14,
      returnMethod: 'https://schema.org/ReturnByMail',
      returnFees: 'https://schema.org/FreeReturn',
      refundType: 'https://schema.org/FullRefund',
    })
  })
  it('carries only the offer-level subset on a product', () => {
    expect(Object.keys(offerReturnPolicy()).sort()).toEqual(['@type', 'applicableCountry', 'merchantReturnDays', 'returnFees', 'returnMethod', 'returnPolicyCategory'])
  })
})

describe('the shipping policy', () => {
  it('is the dashboard\'s highest fee as the upper bound, within one day, for Egypt', () => {
    const fees = feesOf([{ shipping_cost: '95.00' }, { shipping_cost: '250.00' }, { shipping_cost: '150' }])
    expect(fees).toEqual({ min: 95, max: 250 })
    const s = shippingService(fees)
    expect(s.shippingConditions).toHaveLength(1)
    expect(s.shippingConditions[0].shippingDestination).toEqual({ '@type': 'DefinedRegion', addressCountry: 'EG' })
    expect(s.shippingConditions[0].shippingRate).toEqual({ '@type': 'MonetaryAmount', maxValue: 250, currency: 'EGP' })
    expect(s.shippingConditions[0].transitTime.duration).toMatchObject({ minValue: 0, maxValue: 1, unitCode: 'DAY' })
    expect(offerShippingDetails(fees).deliveryTime.transitTime).toMatchObject({ minValue: 0, maxValue: 1, unitCode: 'DAY' })
    expect(JSON.stringify(s)).not.toMatch(/orderValue|freeShipping/)
  })
  it('is left out — never invented — when the fees could not be read', () => {
    expect(feesOf(null)).toBeNull()
    expect(feesOf([{ shipping_cost: '0.00' }, { shipping_cost: 'x' }])).toBeNull()
    expect(shippingService(null)).toBeNull()
    expect(offerShippingDetails(null)).toBeNull()
    const org = shopGraph('en', null)['@graph'][0]
    expect(org).not.toHaveProperty('hasShippingService')
  })
})

describe('the shop graph', () => {
  it('names the shop, founded 2013, its stores, and the site — with no SearchAction', () => {
    const graph = shopGraph('ar', { min: 95, max: 250 })['@graph']
    const org = graph[0]
    expect(org['@type']).toEqual(['Organization', 'OnlineStore'])
    expect(org.foundingDate).toBe('2013')
    expect(org.hasMerchantReturnPolicy.merchantReturnDays).toBe(14)
    expect(org.hasShippingService.shippingConditions[0].shippingRate.maxValue).toBe(250)
    expect(graph.filter((n) => n['@type'] === 'Store')).toHaveLength(2)
    for (const store of graph.filter((n) => n['@type'] === 'Store')) {
      expect(store.parentOrganization).toEqual({ '@id': org['@id'] })
      expect(store.url).toMatch(/\/ar\/contact-us$/)
    }
    const site = graph.find((n) => n['@type'] === 'WebSite')
    expect(site).not.toHaveProperty('potentialAction')
    expect(JSON.stringify(graph)).not.toContain('01274550956')
  })
})

describe('the warranty (2026-11-14)', () => {
  it('is a WarrantyPromise in years when core says the product carries one, and nothing for none', () => {
    expect(offerWarranty(2)).toEqual({ '@type': 'WarrantyPromise', durationOfWarranty: { '@type': 'QuantitativeValue', value: 2, unitCode: 'ANN' } })
    expect(offerWarranty('1').durationOfWarranty.value).toBe(1)
    for (const none of [0, '0', null, undefined, '', 'x']) expect(offerWarranty(none)).toBeNull()
  })
  it('reads as a reader says it, in both languages', () => {
    expect([1, 2, 3].map((n) => warrantyText(n, false))).toEqual(['1 year', '2 years', '3 years'])
    expect([1, 2, 3, 11].map((n) => warrantyText(n, true))).toEqual(['سنة واحدة', 'سنتان', '3 سنوات', '11 سنة'])
    for (const none of [0, null, '', 'x']) expect(warrantyText(none, false)).toBe('')
  })
})
