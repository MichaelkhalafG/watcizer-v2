import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { slimCompatHome, slimV2Home, trimSrcset } from '../src/lib/homeData'
import { transformProductData } from '../src/utils/transformProduct'
import { productUrl } from '../src/utils/productUrl'

/*
 * THE HOME CARRIES LESS, AND DRAWS THE SAME (the performance pass, 2026-11-05). A TWIN.
 *
 * The home's server render hands its data to the browser inside the HTML; `homeData.js` cuts that data to what a
 * home card reads. The claim that matters is that NO CARD CHANGES: these tests put core's real row for one live
 * product (the compat answer recorded on 2026-10-21) through the real transform, whole and cut, and compare every
 * field ProductCard, the sliders, the brand banner and productUrl read.
 */

const here = dirname(fileURLToPath(import.meta.url))
const fixture = (name) => JSON.parse(readFileSync(join(here, 'fixtures', name), 'utf8'))

const host = 'https://api.example.test/Uploads_Images/Product'
const ladder = [160, 320, 480, 640, 960].flatMap((w) => [
  { src: `${host}/m-${w}.avif`, width: w, format: 'avif' },
  { src: `${host}/m-${w}.webp`, width: w, format: 'webp' },
])

// what a home card reads from a transformed row (ProductCard.jsx, HomeClient.jsx's brand banner, productUrl)
const READ = [
  'id', 'name', 'product_title', 'brand', 'brand_id', 'image', 'selling_price', 'sale_price_after_discount', 'stock',
  'market_stock', 'rating', 'ratings_count', 'reviews_count', 'average_rating', 'category_type_id', 'grade_id', 'sub_type_id',
]

describe('slimCompatHome (Watchizer, catalog/home)', () => {
  const answer = fixture('compat-catalog-product-440.json')
  const row = { ...answer.products[0], image_srcset: ladder }
  const home = { hero: 'campaign', rails: [{ id: 1, kind: 'custom', products: [row.id] }], products: [row], ratings: answer.ratings, images: answer.images }
  const slim = slimCompatHome(home)

  it.each(['en', 'ar'])('draws the same card in %s', (lang) => {
    const [whole] = transformProductData(home.products, {}, home.ratings, home.images, lang)
    const [cut] = transformProductData(slim.products, {}, slim.ratings, slim.images, lang)
    for (const k of READ) expect(cut[k], k).toEqual(whole[k])
    expect(cut.created_at.getTime()).toBe(whole.created_at.getTime())
    expect(productUrl(cut)).toBe(productUrl(whole))
    // the card finds its name through the row's translations, in both languages
    expect(cut.translations.map((t) => [t.locale, t.product_title])).toEqual(whole.translations.map((t) => [t.locale, t.product_title]))
  })

  it('leaves out what no home card reads', () => {
    const [cut] = slim.products
    for (const k of ['long_description', 'short_description', 'search_keywords', 'dial_color', 'band_color', 'purchase_price']) expect(cut, k).not.toHaveProperty(k)
    expect(Object.keys(cut.translations[0]).sort()).toEqual(['locale', 'product_title'])
    expect(slim.images).toEqual([])
    // the rest of the answer is untouched
    expect(slim.rails).toBe(home.rails)
    expect(slim.hero).toBe('campaign')
    expect(JSON.stringify(slim).length).toBeLessThan(JSON.stringify(home).length / 2)
  })

  it("caps a card's renditions at 640 px and keeps the two formats", () => {
    const widths = slim.products[0].image_srcset.map((s) => s.width)
    expect(Math.max(...widths)).toBe(640)
    expect(new Set(slim.products[0].image_srcset.map((s) => s.format))).toEqual(new Set(['avif', 'webp']))
  })

  it('does not touch the answer it was given, and slims one answer once', () => {
    expect(home.products[0]).toHaveProperty('long_description')
    expect(home.products[0].image_srcset).toHaveLength(10)
    expect(slimCompatHome(home)).toBe(slim)
  })

  it('passes through what it cannot read', () => {
    expect(slimCompatHome(null)).toBeNull()
    const odd = { rails: [] }
    expect(slimCompatHome(odd)).toBe(odd)
  })
})

describe('slimV2Home (Brand Fashion, v2 home)', () => {
  const card = (id) => ({
    id,
    slug: `p-${id}`,
    family: 'watches',
    title: { en: 'A watch', ar: 'ساعة' },
    short_description: { en: 'x'.repeat(400), ar: 'ص'.repeat(400) },
    brand: { id: 1, slug: 'b', name: { en: 'B', ar: 'ب' } },
    image: { src: `${host}/m.webp`, srcset: ladder, width: 1200, height: 1200, alt: null },
    hover_image: { src: `${host}/h.webp`, srcset: ladder, width: 1200, height: 1200, alt: null },
    colors: [{ id: 3, name: { en: 'Black' }, hex: '#000' }],
    variants: [{ id: 9, color: { id: 3 }, size: null, in_stock: true }],
    price: { amount: 1000, sale_amount: 800, currency: 'EGP', discount_pct: 20, has_sale: true },
    stock: { express: 2, market: 0, in_stock: true },
    rating: { avg: null, count: 0 },
    created_at: '2026-10-15T15:13:45.000000Z',
  })
  const home = { hero: 'banner', rails: [{ id: 1, kind: 'custom', cards: [card(1), card(2)], masks: { 1: 'data:image/png;base64,AA==' } }, { id: 2, kind: 'brands' }], deals: [card(3)], brands: [{ id: 1 }] }
  const slim = slimV2Home(home)

  it('keeps every field of a card except its short description', () => {
    const [cut] = slim.rails[0].cards
    const whole = home.rails[0].cards[0]
    expect(cut).not.toHaveProperty('short_description')
    for (const k of Object.keys(whole)) {
      if (k === 'short_description' || k === 'image' || k === 'hover_image') continue
      expect(cut[k], k).toEqual(whole[k])
    }
    expect(cut.image.src).toBe(whole.image.src)
    expect(cut.hover_image.src).toBe(whole.hover_image.src)
  })

  it('caps both photos at 640 px, in the sliders and in the deals band', () => {
    for (const c of [...slim.rails[0].cards, ...slim.deals]) {
      expect(Math.max(...c.image.srcset.map((s) => s.width))).toBe(640)
      expect(Math.max(...c.hover_image.srcset.map((s) => s.width))).toBe(640)
    }
  })

  it('leaves the masks, the section rows and the rest of the answer as they are', () => {
    expect(slim.rails[0].masks).toBe(home.rails[0].masks)
    expect(slim.rails[1]).toBe(home.rails[1])
    expect(slim.brands).toBe(home.brands)
    expect(home.rails[0].cards[0]).toHaveProperty('short_description')
  })
})

describe('trimSrcset', () => {
  it('keeps a list whole when nothing in it is at or under the cap', () => {
    const wide = [{ src: 'a', width: 960, format: 'webp' }]
    expect(trimSrcset(wide)).toBe(wide)
    expect(trimSrcset(null)).toBeNull()
    expect(trimSrcset([])).toEqual([])
  })
})
