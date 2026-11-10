import { describe, expect, it } from 'vitest'
import { FLOAT_HIDDEN_PREFIXES, isFloatHidden, isFloatHiddenPath } from '../src/lib/floating'

// FLOATING ELEMENTS (2026-11-07, the developer's instruction). A TWIN. No floating element on the cart, the
// checkout and every product page, in either language; the wheel's button not on the wheel's page; everywhere
// else they are drawn (and stacked by FloatingStack, which this cannot see without a browser).

describe('where no floating element is drawn', () => {
  it('hides on the cart, the checkout and every product page, in both languages', () => {
    for (const path of ['/cart', '/ar/cart', '/cart/recover', '/checkout', '/ar/checkout', '/product/rolex-submariner', '/ar/product/x', '/products/25754', '/offer/weekend', '/order-confirmation']) {
      expect(isFloatHiddenPath(path), path).toBe(true)
      expect(isFloatHidden('hot-deals', path), path).toBe(true)
      expect(isFloatHidden('back-to-top', path), path).toBe(true)
      expect(isFloatHidden('wheel', path), path).toBe(true)
    }
  })
  it('draws them everywhere else', () => {
    for (const path of ['/', '/ar', '/listing', '/category/watches', '/brand/rolex', '/hot-deals', '/flash-sale', '/faq', '/account', '/products', '/cartridge']) {
      expect(isFloatHiddenPath(path), path).toBe(false)
      expect(isFloatHidden('hot-deals', path), path).toBe(false)
    }
  })
  it('hides the wheel’s own button on /spin and nothing else there', () => {
    expect(isFloatHidden('wheel', '/spin')).toBe(true)
    expect(isFloatHidden('wheel', '/ar/spin')).toBe(true)
    expect(isFloatHidden('hot-deals', '/spin')).toBe(false)
    expect(isFloatHidden('back-to-top', '/spin')).toBe(false)
  })
  it('names the hidden prefixes once, so a new page type is a deliberate edit', () => {
    expect(FLOAT_HIDDEN_PREFIXES).toEqual(['/cart', '/checkout', '/product/', '/products/', '/offer/', '/order-confirmation'])
  })
})
