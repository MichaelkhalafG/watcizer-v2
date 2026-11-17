import { describe, expect, it } from 'vitest'
import { nodeSlug, subTypePage, categoryTypePage, menuChildHref, directoryOf, brandPageOf, categoryPageOf, calmCase, footerDirectoryOf } from '../src/lib/menuLinks'

/*
 * THE MENU AS LINKS THAT ANSWER (2026-11-14). A node's page is the slug of its English name, the facet routes' rule.
 * The four nodes below are the live menu's own (measured 2026-11-14): their `slug` answered 404 (`audio`), redirected
 * (`chargers`, `accessories-2`) or has no page at all (Brand Fashion's "Shop by material").
 */

const sub = (id, en) => ({ id, sub_type_name: en, translations: [{ locale: 'en', sub_type_name: en }] })
const tables = {
  categoryTypes: [
    { id: 1, category_type_name: 'Watches', translations: [{ locale: 'en', category_type_name: 'Watches' }] },
    { id: 2, category_type_name: 'Fashion', translations: [{ locale: 'en', category_type_name: 'Fashion' }] },
    { id: 3, category_type_name: 'Electronics', translations: [{ locale: 'en', category_type_name: 'Electronics' }] },
  ],
  subTypes: [sub(1, 'GMT'), sub(2, 'Chargers & Cables'), sub(3, 'Earphones & Headphones'), sub(4, 'Accessories & Spare Parts'), sub(5, 'Bags')],
}
const node = (slug, en, count = 5) => ({ slug, name: { en, ar: en }, count })

describe('a menu node\'s page', () => {
  it('is the slug of its English name, not its own slug', () => {
    expect(nodeSlug(node('audio', 'Earphones & Headphones'))).toBe('earphones-headphones')
    expect(subTypePage(node('audio', 'Earphones & Headphones'), tables)).toBe('/subtypes/earphones-headphones')
    expect(subTypePage(node('chargers', 'Chargers & Cables'), tables)).toBe('/subtypes/chargers-cables')
    expect(subTypePage(node('accessories-2', 'Accessories & Spare Parts'), tables)).toBe('/subtypes/accessories-spare-parts')
    expect(subTypePage(node('gmt', 'GMT'), tables)).toBe('/subtypes/gmt')
  })

  it('is null for a node the tables do not know — never a link to a 404', () => {
    expect(subTypePage(node('materials', 'Shop by material'), tables)).toBeNull()
    expect(categoryTypePage(node('toys', 'Toys'), tables)).toBeNull()
  })

  it('trusts the English name while the tables are not loaded', () => {
    expect(subTypePage(node('materials', 'Shop by material'), null)).toBe('/subtypes/shop-by-material')
  })

  it('a menu tile with no page of its own links its category, else the listing', () => {
    const fashion = node('fashion', 'Fashion')
    expect(menuChildHref(node('materials', 'Shop by material'), fashion, tables)).toBe('/category/fashion')
    expect(menuChildHref(node('materials', 'Shop by material'), node('x', 'Nothing'), tables)).toBe('/listing')
    expect(menuChildHref(node('bags', 'Bags'), fashion, tables)).toBe('/subtypes/bags')
  })
})

describe('the directory', () => {
  it('lists each category with the sub-categories that have a page and products, and every brand with products', () => {
    const menu = {
      categories: [
        { ...node('electronics', 'Electronics'), children: [node('audio', 'Earphones & Headphones'), node('chargers', 'Chargers & Cables', 0)] },
        { ...node('fashion', 'Fashion'), children: [node('materials', 'Shop by material'), node('bags', 'Bags')] },
        { ...node('toys', 'Toys'), children: [] },
      ],
      brands: [{ id: 1, slug: 'rolex', name: { en: 'Rolex' }, count: 3 }, { id: 4, slug: 'cartier', name: { en: 'Cartier' }, count: 0 }, { id: 1, slug: 'rolex', name: { en: 'Rolex' }, count: 3 }],
    }
    expect(directoryOf(menu, tables)).toEqual({
      categories: [
        { href: '/category/electronics', name: { en: 'Electronics', ar: 'Electronics' }, children: [{ href: '/subtypes/earphones-headphones', name: { en: 'Earphones & Headphones', ar: 'Earphones & Headphones' } }] },
        { href: '/category/fashion', name: { en: 'Fashion', ar: 'Fashion' }, children: [{ href: '/subtypes/bags', name: { en: 'Bags', ar: 'Bags' } }] },
      ],
      brands: [{ href: '/brand/rolex', name: { en: 'Rolex' }, id: 1 }],   // the id since 2026-11-14 (/llms.txt)
    })
    expect(directoryOf(null, tables)).toEqual({ categories: [], brands: [] })
  })
})

describe('a brand or category page from its table id', () => {
  const t = {
    ...tables,
    brands: [{ id: 8, brand_name: 'TAG Heuer', translations: [{ locale: 'en', brand_name: 'TAG Heuer' }] }, { id: 60, brand_name: 'Q&Q', translations: [{ locale: 'en', brand_name: 'Q&Q' }] }],
  }
  it('names the page, never the filtered listing', () => {
    expect(brandPageOf(8, t)).toBe('/brand/tag-heuer')
    expect(brandPageOf(60, t)).toBe('/brand/qq')
    expect(categoryPageOf(2, t)).toBe('/category/fashion')
  })
  it('is null for an unknown id or no tables', () => {
    expect(brandPageOf(999, t)).toBeNull()
    expect(categoryPageOf(1, null)).toBeNull()
  })
})

// THE FOOTER DIRECTORY (2026-11-17): one casing from the data's names, and core's directory brands only
describe('calmCase', () => {
  it('gives a word typed in capitals a capital and small letters, keeps short ones and Arabic', () => {
    expect(calmCase('SOCKS')).toBe('Socks')
    expect(calmCase('TOURBILLON WATCHES')).toBe('Tourbillon Watches')
    expect(calmCase('GMT')).toBe('GMT')
    expect(calmCase('Accessories & Spare Parts')).toBe('Accessories & Spare Parts')
    expect(calmCase('perfumes')).toBe('Perfumes')
    expect(calmCase('جوارب')).toBe('جوارب')
  })
})

describe('footerDirectoryOf', () => {
  const menu = {
    categories: [{ slug: 'watches', name: { en: 'Watches', ar: 'ساعات' }, count: 9, children: [node('gmt', 'GMT')] }],
    brands: [{ id: 1, slug: 'rolex', name: { en: 'Rolex' }, count: 200 }, { id: 2, slug: 'guess', name: { en: 'Guess' }, count: 40 }],
    directory_brands: [{ id: 2, slug: 'guess', name: { en: 'Guess' } }, { id: 3, slug: 'tissot', name: { en: 'Tissot' } }],
  }
  it("lists core's directory brands, never the menu's own brand list", () => {
    const { categories, brands } = footerDirectoryOf(menu, tables)
    expect(brands.map((b) => b.href)).toEqual(['/brand/guess', '/brand/tissot'])
    expect(categories.map((c) => c.href)).toEqual(['/category/watches'])
  })
  it('shows no brand at all when core sends no directory list', () => {
    expect(footerDirectoryOf({ ...menu, directory_brands: undefined }, tables).brands).toEqual([])
  })
})
