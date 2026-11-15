import { describe, expect, it } from 'vitest'
import { familiesOf, watchesOnly, displayName, listingTitle, listingDescription, THIN_LISTING_MIN } from '../src/lib/listingWords'

/*
 * A LISTING'S WORDS ARE TRUE OF ITS PRODUCTS (2026-11-14, item 4). The 2026-11-13 crawl found "Perfumes Watches",
 * "Power Banks Watches", «ساعات أساور», "Pinko Watches in Egypt" (a shop of bags) and "Browse 1 perfume at … — luxury
 * watches and accessories". The page's own families (core's facets.category for the same request) decide now.
 */

const facets = (rows) => ({ v2: { facets: { category: rows } } })
const node = (slug, depth, count, en, ar = en) => ({ slug, depth, count, name: { en, ar } })
const watches = familiesOf(facets([node('watches', 1, 40, 'Watches', 'ساعات'), node('diver', 2, 40, 'Diver', 'غوص')]))
const coach = familiesOf(facets([node('fashion', 1, 66, 'Fashion'), node('bags', 2, 55, 'Bags', 'حقائب'), node('wallets', 2, 11, 'Wallets', 'محافظ')]))
const perfumes = familiesOf(facets([node('fashion', 1, 2, 'Fashion'), node('perfumes', 2, 2, 'Perfumes', 'عطور')]))
const mixed = familiesOf(facets([node('watches', 1, 9, 'Watches'), node('fashion', 1, 3, 'Fashion'), node('bags', 2, 3, 'Bags'), node('gmt', 2, 9, 'GMT')]))

describe('the families a page holds', () => {
  it('reads core\'s facets: watches only, or not', () => {
    expect(watchesOnly(watches)).toBe(true)
    expect(watchesOnly(coach)).toBe(false)
    expect(watchesOnly(mixed)).toBe(false)
    expect(watchesOnly(familiesOf(null))).toBe(false)          // core said nothing: never claim "Watches"
  })
})

describe('a facet page title', () => {
  it('says Watches only for a page of watches', () => {
    expect(listingTitle({ kind: 'brand', name: 'Rolex', families: watches, lang: 'en', shop: 'Watchizer' })).toBe('Rolex Watches in Egypt | Watchizer')
    expect(listingTitle({ kind: 'brand', name: 'رولكس', families: watches, lang: 'ar', shop: 'Watchizer' })).toBe('ساعات رولكس في مصر | Watchizer')
    expect(listingTitle({ kind: 'subtype', name: 'Diver', families: watches, lang: 'en', shop: 'Watchizer' })).toBe('Diver Watches | Watchizer')
  })

  it('names what a brand page holds, otherwise', () => {
    expect(listingTitle({ kind: 'brand', name: 'Coach', families: coach, lang: 'en', shop: 'Brand Fashion' })).toBe('Coach Bags & Wallets in Egypt | Brand Fashion')
    expect(listingTitle({ kind: 'brand', name: 'كوتش', families: coach, lang: 'ar', shop: 'Brand Fashion' })).toBe('حقائب ومحافظ كوتش في مصر | Brand Fashion')
    expect(listingTitle({ kind: 'brand', name: 'Pinko', families: familiesOf(null), lang: 'en', shop: 'Watchizer' })).toBe('Pinko in Egypt | Watchizer')
  })

  it('never adds Watches to a sub-type or grade that is not watches', () => {
    expect(listingTitle({ kind: 'subtype', name: 'Perfumes', families: perfumes, lang: 'en', shop: 'Watchizer' })).toBe('Perfumes | Watchizer')
    expect(listingTitle({ kind: 'subtype', name: 'عطور', families: perfumes, lang: 'ar', shop: 'Watchizer' })).toBe('عطور | Watchizer')
    expect(listingTitle({ kind: 'grade', name: 'perfume', families: perfumes, lang: 'en', shop: 'Watchizer' })).toBe('Perfume | Watchizer')
    expect(listingTitle({ kind: 'subtype', name: 'Digital Watch', families: watches, lang: 'en', shop: 'Watchizer' })).toBe('Digital Watch | Watchizer')
  })

  it('keeps the team\'s casing, and capitalises only an all-lower-case English name', () => {
    expect(displayName('perfume')).toBe('Perfume')
    expect(displayName('naviforce')).toBe('Naviforce')
    expect(displayName('TAG Heuer')).toBe('TAG Heuer')
    expect(displayName('iPhone Cases')).toBe('iPhone Cases')
    expect(displayName('عطور')).toBe('عطور')
  })
})

describe('the fallback description', () => {
  it('has no count and no claim about the products — the shop\'s delivery and payment facts', () => {
    const en = listingDescription({ crumb: 'perfume', lang: 'en', shop: 'Watchizer' })
    expect(en).toBe('Shop Perfume at Watchizer, with delivery across Egypt within 24 hours and cash on delivery.')
    expect(en).not.toMatch(/Browse|\d+ perfume|luxury/i)
    expect(listingDescription({ crumb: 'عطور', lang: 'ar', shop: 'Watchizer' })).toBe('تسوّق عطور من Watchizer مع توصيل خلال 24 ساعة إلى كل محافظات مصر والدفع عند الاستلام.')
    expect(en.length).toBeLessThanOrEqual(155)
  })
})

it('calls a listing thin below three LIVE products — the number core\'s sitemap uses too', () => {
  expect(THIN_LISTING_MIN).toBe(3)
})
