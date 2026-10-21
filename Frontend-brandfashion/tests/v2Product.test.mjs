import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { fromV2Product, specRowsIn } from '../src/lib/v2Product'
import { transformProductData } from '../src/utils/transformProduct'

/*
 * THE PRODUCT PAGE'S ADAPTER, HELD TO COMPAT'S ANSWER FOR THE SAME PRODUCT (2026-10-21).
 *
 * The page moved to v2 on 2026-10-20 and the live shop drew ONE picture, no colour swatches and the short
 * description in place of the long one — for every product, for a day — because the adapter put the gallery
 * where `transformProductData` does not look, and left the colour pivots and the long text out. No test
 * could have caught it: the apps had no test runner at all, and core's tests prove what v2 SERVES, not what
 * the page keeps of it.
 *
 * So this runs the real adapter and the real transform over core's REAL answers for one live product
 * (Tommy Hilfiger 1791631, eight gallery rows, two finishes, a 654-character description) — v2's and
 * compat's `catalog/product`, recorded on 2026-10-21 — and asserts that what the page reads is the same
 * on both. A field that v2 has and the adapter drops fails here by name.
 */

const here = dirname(fileURLToPath(import.meta.url))
const fixture = (name) => JSON.parse(readFileSync(join(here, 'fixtures', name), 'utf8'))
const tables = {}

const viaV2 = () => {
  const page = fixture('v2-product-440.json')
  const adapted = fromV2Product(page)
  const [row] = transformProductData(adapted.payload.products, tables, adapted.payload.ratings, adapted.payload.images, 'en')
  return { page, adapted, row }
}
const viaCompat = () => {
  const cards = fixture('compat-catalog-product-440.json')
  const [row] = transformProductData(cards.products, tables, cards.ratings, cards.images, 'en')
  return { cards, row }
}

describe('what the product page reads is the same from v2 as it was from compat', () => {
  it('keeps the whole gallery, as urls, the cover included', () => {
    const { row, page } = viaV2()
    const compat = viaCompat().row
    const gallery = (r) => [...new Set([...(r.images || []), r.image].filter(Boolean))]
    expect(page.product.images).toHaveLength(8)
    expect(gallery(row)).toHaveLength(8)
    // the same eight files compat drew: its seven gallery rows plus its cover
    expect(new Set(gallery(row).map((u) => u.split('/').pop()))).toEqual(new Set(gallery(compat).map((u) => u.split('/').pop())))
  })

  it('keeps the colour finishes with their hex and both names', () => {
    const { row } = viaV2()
    const compat = viaCompat().row
    const flat = (list) => list.map((c) => [c.color_value, c.color_name_en, c.color_name_ar])
    expect(flat(row.dial_colors)).toEqual(flat(compat.dial_colors))
    expect(flat(row.band_colors)).toEqual(flat(compat.band_colors))
    expect(row.dial_colors[0].color_value).toMatch(/^#/)
  })

  it('keeps the long description, the model name and the short text in both languages', () => {
    const { row } = viaV2()
    const compat = viaCompat().row
    for (const key of ['long_description', 'short_description', 'model_name', 'product_title', 'name']) {
      expect(row[key], key).toBe(compat[key])
    }
    expect(row.long_description.length).toBeGreaterThan(row.short_description.length)
  })

  it('keeps the prices, the stock, the brand, the features and the gender', () => {
    const { row } = viaV2()
    const compat = viaCompat().row
    for (const key of ['selling_price', 'sale_price_after_discount', 'stock', 'market_stock', 'brand_id', 'warranty_years']) {
      expect(Number(row[key]), key).toBe(Number(compat[key]))
    }
    expect(row.features).toEqual(compat.features)
    expect(row.gender).toEqual(compat.gender)
  })

  it('names the family as the category type, so a fashion product is not drawn as a watch', () => {
    const { row, page } = viaV2()
    expect(row.category_type_name).toBe(page.product.family)
  })

  it('opens the spec table with the eight rows compat opened with, then core specs, then the five', () => {
    const { adapted, page } = viaV2()
    const labels = specRowsIn(adapted.specs, 'en', page.product).map((r) => r.label)
    expect(labels.slice(0, 8)).toEqual(['Brand', 'Category', 'Type', 'Gender', 'Grade', 'Model Name', 'Model Number', 'Features'])
    expect(labels).toContain('Case size')
    expect(labels.slice(-3)).toEqual(['Dial Colour', 'Band Colour', 'Warranty'])
    const ar = specRowsIn(adapted.specs, 'ar', page.product).map((r) => r.label)
    expect(ar[0]).toBe('الماركة')
    expect(ar).toHaveLength(labels.length)
  })
})
