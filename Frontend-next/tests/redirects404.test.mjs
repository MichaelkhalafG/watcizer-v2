import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const config = require('../next.config.js')

// This week's 404s with search-engine demand (2026-11-24): each old path 301s to its closest live page, in both languages.
describe('the 404s with demand', () => {
  it('sends each old path to its closest live page, and its /ar twin to the Arabic page', async () => {
    const rules = await config.redirects()
    const to = (source) => rules.find((r) => r.source === source)
    const expected = {
      '/subtypes/watches': '/category/watches',
      '/subtypes/electronics': '/category/electronics',
      '/subtypes/fashion': '/category/fashion',
      '/subtypes/audio': '/subtypes/earphones-headphones',
      '/subtypes/dress': '/subtypes/classic-watch',
      '/category/chronograph': '/subtypes/chronograph',
    }
    for (const [source, destination] of Object.entries(expected)) {
      expect(to(source)).toMatchObject({ destination, permanent: true })
      expect(to(`/ar${source}`)).toMatchObject({ destination: `/ar${destination}`, permanent: true })
    }
  })

  it('sends any old MK4508 product URL to the Michael Kors brand page, and nothing else', async () => {
    const rules = await config.redirects()
    const rule = rules.find((r) => r.source.startsWith('/product/:slug(michael-kors-?mk4508'))
    expect(rule).toMatchObject({ destination: '/brand/michael-kors', permanent: true })
    expect(rules.find((r) => r.source === `/ar${rule.source}`)).toMatchObject({ destination: '/ar/brand/michael-kors' })
    // matched by Next's own path matcher, as the server will
    const { pathToRegexp } = require('next/dist/compiled/path-to-regexp')
    const matcher = pathToRegexp(rule.source)
    const pattern = { test: (slug) => matcher.test(`/product/${slug}`) }
    for (const slug of ['michael-kors-mk4508-slim-runway-brown-unisex-watch', 'michael-korsmk4508', 'michael-kors-mk4508']) expect(pattern.test(slug)).toBe(true)
    for (const slug of ['michael-kors-mk5566-pearl-light-blue-dial-silver-stainless-steel-bracelet-watch', 'michael-kors-mk45080', 'michael-kors-mk4508x']) expect(pattern.test(slug)).toBe(false)
  })
})
