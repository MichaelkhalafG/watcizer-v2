import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { createElement as h } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import ProductTabs from '../src/Components/Product/ProductTabs'
import ReviewStarLine from '../src/Components/Reviews/ReviewStarLine'
import ReviewsPanel from '../src/Components/Reviews/ReviewsPanel'
import { reviewsCount, starsWord, REVIEW_WORDS } from '../src/lib/reviewWords.js'
import { reviewsLd } from '../src/lib/reviewsLd.js'

/*
 * THE PRODUCT PAGE'S TABS AND REVIEWS (2026-11-19) — a twin. Description / Specifications / Reviews as a real tab set,
 * every panel in the server HTML; the star line under the name; the Reviews tab with its bars, list, "Show more" and
 * form; the review markup only from the reviews the page shows.
 */
const tabs = [
  { id: 'desc', label: 'Description', panel: h('p', null, 'The long description.') },
  { id: 'specs', label: 'Specifications', panel: h('p', null, 'The spec table.') },
  { id: 'reviews', label: 'Reviews (2)', panel: h('p', null, 'The reviews.') },
]
const render = (props) => renderToStaticMarkup(h(ProductTabs, { tabs, active: 'desc', onChange: () => {}, id: 'reviews', label: 'Product details', ...props }))

const approved = {
  count: 12,
  average: 4.5,
  breakdown: { 5: 8, 4: 3, 3: 1, 2: 0, 1: 0 },
  items: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, name: `N${i}`, rating: 5, body: 'Lovely watch.', date: '2026-11-19', photos: [], verified: i === 0 })),
}

describe('the tabs', () => {
  it('are a tablist of buttons, each controlling its panel; only the chosen tab is in the tab order', () => {
    const html = render()
    expect(html).toContain('<section class="wz-tabs" id="reviews" dir="ltr">')
    expect(html).toContain('role="tablist" aria-label="Product details"')
    expect(html).toContain('<button type="button" role="tab" id="wz-tab-desc" aria-controls="wz-panel-desc" aria-selected="true" tabindex="0" class="wz-tabs__tab is-active">Description</button>')
    expect(html).toContain('<button type="button" role="tab" id="wz-tab-reviews" aria-controls="wz-panel-reviews" aria-selected="false" tabindex="-1" class="wz-tabs__tab">Reviews (2)</button>')
  })
  it('serve every panel in the HTML, the hidden ones with `hidden`', () => {
    const html = render({ active: 'reviews' })
    expect(html).toContain('The long description.')
    expect(html).toContain('The spec table.')
    expect(html).toContain('<div role="tabpanel" id="wz-panel-desc" aria-labelledby="wz-tab-desc" tabindex="0" hidden="" class="wz-tabs__panel">')
    expect(html).toContain('<div role="tabpanel" id="wz-panel-reviews" aria-labelledby="wz-tab-reviews" tabindex="0" class="wz-tabs__panel">')
  })
  it('read right to left in Arabic, the arrows following the reading direction', () => {
    expect(render({ isRTL: true })).toContain('dir="rtl"')
    const src = readFileSync(new URL('../src/Components/Product/ProductTabs.jsx', import.meta.url), 'utf8')
    expect(src).toContain("const next = isRTL ? 'ArrowLeft' : 'ArrowRight'")
    expect(src).toContain("e.key === 'Home'")
    expect(src).toContain("e.key === 'End'")
  })
})

describe('the star line under the name', () => {
  it('links to #reviews with the average and the count', () => {
    const html = renderToStaticMarkup(h(ReviewStarLine, { reviews: approved, lang: 'en' }))
    expect(html).toContain('href="#reviews"')
    expect(html).toContain('4.5 out of 5 · 12 reviews')
    expect(html).toContain('--wz-star-pct:90%')
  })
  it('says "Be the first to review" when there are none, in both languages, right to left in Arabic', () => {
    expect(renderToStaticMarkup(h(ReviewStarLine, { reviews: { count: 0, items: [] }, lang: 'en' }))).toContain('Be the first to review')
    const ar = renderToStaticMarkup(h(ReviewStarLine, { reviews: null, lang: 'ar' }))
    expect(ar).toContain('dir="rtl"')
    expect(ar).toContain(REVIEW_WORDS.ar.first)
    // the letters in reading order (never reversed): kaf, waw, nun first
    expect([...REVIEW_WORDS.ar.first].slice(0, 3).map((c) => c.codePointAt(0).toString(16))).toEqual(['643', '646', '20'])
  })
})

describe('the Reviews tab', () => {
  it('draws the bars 5 to 1, the list with the Verified buyer mark, and "Show more" when there are more', () => {
    const html = renderToStaticMarkup(h(ReviewsPanel, { reviews: approved, productId: 7, lang: 'en' }))
    expect(html).toContain('Based on 12 reviews')
    expect(html).toContain('aria-label="5 stars: 8 reviews"')
    expect(html).toContain('aria-label="1 star: 0 reviews"')
    expect(html).toContain('inline-size:67%')
    expect(html.match(/class="wz-reviews__item"/g)).toHaveLength(10)
    expect(html).toContain('Verified buyer')
    expect(html).toContain('Show more reviews')
    expect(html).toContain('Write a review')
  })
  it('says there are none yet, with the form, and no bars or "Show more"', () => {
    const html = renderToStaticMarkup(h(ReviewsPanel, { reviews: { count: 0, average: null, items: [] }, productId: 7, lang: 'ar' }))
    expect(html).toContain(REVIEW_WORDS.ar.none)
    expect(html).not.toContain('wz-revtab__bars')
    expect(html).not.toContain(REVIEW_WORDS.ar.more)
    expect(html).toContain('اكتب تقييمك')
  })
  it('fetches later pages from core’s read-only route under this shop', () => {
    const src = readFileSync(new URL('../src/Components/Reviews/ReviewsPanel.jsx', import.meta.url), 'utf8')
    expect(src).toContain('/v2/${STOREFRONT_CODE}/products/${productId}/reviews?page=${page + 1}')
  })
})

describe('the Arabic counts', () => {
  it('follow the number', () => {
    expect(reviewsCount(1, 'ar')).toBe('تقييم واحد')
    expect(reviewsCount(2, 'ar')).toBe('تقييمان')
    expect(reviewsCount(5, 'ar')).toBe('5 تقييمات')
    expect(reviewsCount(12, 'ar')).toBe('12 تقييمًا')
    expect(starsWord(1, 'ar')).toBe('نجمة واحدة')
    expect(starsWord(2, 'ar')).toBe('نجمتان')
    expect(starsWord(4, 'ar')).toBe('4 نجوم')
    expect(reviewsCount(1, 'en')).toBe('1 review')
  })
})

describe('the review markup', () => {
  it('comes only from the reviews the page shows: nothing when there are none', () => {
    expect(reviewsLd({ count: 0, average: null, items: [] })).toEqual({})
    expect(reviewsLd(approved).aggregateRating.reviewCount).toBe(12)
  })
})
