import { createRequire } from 'node:module'
import { afterEach, beforeAll, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

// BRAND FASHION'S SUBTYPE PAGES ARE NOT REDIRECTED (2026-11-26, Michael). /ar/subtypes/diver is the shop's most-cited
// page in Bing's AI answers (Copilot). The /subtypes and /category redirects of 24 Nov belong to WATCHIZER's
// next.config.js. Brand Fashion's config redirects EXACTLY two subtype addresses, which answered 404 and which our
// own Electronics text linked (Bing's Site Scan, 2026-11-27): /subtypes/audio and /subtypes/watches and their /ar
// twins, each to its right live page; no other /subtypes or /category address. Its middleware lets a live subtype
// page through untouched, without even asking core's map of old addresses.

const require = createRequire(import.meta.url)
let middleware
const asked = []

beforeAll(async () => {
  process.env.LARAVEL_ORIGIN = 'http://core.test'
  process.env.SITE_CANONICAL_HOST = 'www.brandfashionegy.com'
  ;({ middleware } = await import('../middleware.js'))
})

afterEach(() => {
  asked.length = 0
  vi.unstubAllGlobals()
})

it('redirects exactly the two dead subtype addresses and their /ar twins, and no other subtype or category', async () => {
  const config = require('../next.config.js')
  const rules = typeof config.redirects === 'function' ? await config.redirects() : []
  const sources = rules.map((r) => r.source)
  expect(sources.length).toBeGreaterThan(0) // the config was read: a missing redirects() must not pass
  const facet = rules.filter((r) => /^\/(ar\/)?(subtypes|category)\b/.test(r.source)).map((r) => [r.source, r.destination, r.statusCode])
  expect(facet).toEqual([
    ['/subtypes/audio', '/subtypes/earphones-headphones', 301],
    ['/ar/subtypes/audio', '/ar/subtypes/earphones-headphones', 301],
    ['/subtypes/watches', '/category/watches', 301],
    ['/ar/subtypes/watches', '/ar/category/watches', 301],
  ])
  // Watchizer's other four stay Watchizer's: on this shop /subtypes/dress is a live page
  for (const watchizerOnly of ['/subtypes/electronics', '/subtypes/fashion', '/subtypes/dress', '/category/chronograph']) {
    expect(sources).not.toContain(watchizerOnly)
  }
})

it('lets the subtype pages through untouched, in both languages', async () => {
  vi.stubGlobal('fetch', async (url) => {
    asked.push(String(url))
    return new Response('{}', { status: 404 })
  })
  for (const path of ['/ar/subtypes/diver', '/subtypes/diver', '/ar/subtypes/chronograph', '/subtypes/dress', '/subtypes/earphones-headphones', '/category/watches']) {
    const url = 'https://www.brandfashionegy.com' + path
    const r = await middleware(new NextRequest(url, { headers: { host: 'www.brandfashionegy.com' } }))
    expect([path, r.status]).toEqual([path, 200])
    expect(r.headers.get('location')).toBeNull()
  }
  expect(asked).toEqual([])
})
