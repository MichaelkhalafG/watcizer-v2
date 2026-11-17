import { describe, expect, it } from 'vitest'
import { withDefaultOg, DEFAULT_OG_IMAGE } from '../src/lib/defaultOg'

// THE DEFAULT SHARE PICTURE (2026-11-17): an information page that sets its own openGraph keeps a picture.
describe('withDefaultOg', () => {
  it("gives a page without a picture the shop's 1200x630 card, for Open Graph and the X card", () => {
    const m = withDefaultOg({ title: 'About', openGraph: { title: 'About', type: 'website' } })
    expect(m.openGraph.images).toEqual([DEFAULT_OG_IMAGE])
    expect(m.openGraph.title).toBe('About')
    expect(DEFAULT_OG_IMAGE.url).toMatch(/^https:\/\/.+\/og-image\.jpg$/)
    expect(m.twitter).toEqual({ card: 'summary_large_image', images: [DEFAULT_OG_IMAGE.url] })
  })
  it("keeps a page's own picture", () => {
    const own = [{ url: 'https://x.test/a.jpg', width: 800, height: 800 }]
    const m = withDefaultOg({ openGraph: { images: own } })
    expect(m.openGraph.images).toBe(own)
    expect(m.twitter.images).toEqual(['https://x.test/a.jpg'])
  })
})
