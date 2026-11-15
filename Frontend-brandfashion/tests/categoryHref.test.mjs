import { describe, expect, it } from 'vitest'
import { categoryPage, nearestCategoryPage } from '../src/lib/categoryHref.js'

// The category-page rule of the product page's breadcrumb and "See all" (2026-11-14): only the first two levels of
// the tree have a page on Brand Fashion; a deeper node is never linked.

describe('a category node\'s page', () => {
  it('is /category/{slug} at the top level, /subtypes/{slug} at the second', () => {
    expect(categoryPage('fashion')).toBe('/category/fashion')
    expect(categoryPage('fashion/bags')).toBe('/subtypes/bags')
    expect(categoryPage('/fashion/bags/')).toBe('/subtypes/bags')
  })

  it('is none below — the crawl found /subtypes/shoulder-bag and /category/fashion/bags, both 404', () => {
    expect(categoryPage('fashion/bags/shoulder-bag')).toBeNull()
    expect(categoryPage('')).toBeNull()
    expect(categoryPage(undefined)).toBeNull()
    expect(categoryPage('fashion/bags')).not.toBe('/category/fashion/bags')
  })

  it('"See all" goes to the nearest level that has a page', () => {
    expect(nearestCategoryPage('fashion/bags/shoulder-bag')).toBe('/subtypes/bags')
    expect(nearestCategoryPage('fashion/bags')).toBe('/subtypes/bags')
    expect(nearestCategoryPage('watches')).toBe('/category/watches')
    expect(nearestCategoryPage(null)).toBe('/listing')
  })
})
