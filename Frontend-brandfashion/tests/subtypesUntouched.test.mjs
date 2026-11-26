import { createRequire } from 'node:module'
import { afterEach, beforeAll, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

// BRAND FASHION'S SUBTYPE PAGES ARE NOT REDIRECTED (2026-11-26, Michael). /ar/subtypes/diver is the shop's most-cited
// page in Bing's AI answers (Copilot). The /subtypes and /category redirects of 24 Nov belong to WATCHIZER's
// next.config.js only: they move Watchizer addresses that answered 404. Here: Brand Fashion's own config redirects no
// /subtypes or /category address (and none of Watchizer's six), and its middleware lets a subtype page through
// untouched, without even asking core's map of old addresses.

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

it("declares no redirect of a subtype or category address, and none of Watchizer's six", async () => {
  const config = require('../next.config.js')
  const rules = typeof config.redirects === 'function' ? await config.redirects() : []
  const sources = rules.map((r) => r.source)
  expect(sources.length).toBeGreaterThan(0) // the config was read: a missing redirects() must not pass
  expect(sources.filter((s) => /^\/(ar\/)?(subtypes|category)\b/.test(s))).toEqual([])
  for (const watchizerOnly of ['/subtypes/watches', '/subtypes/electronics', '/subtypes/fashion', '/subtypes/audio', '/subtypes/dress', '/category/chronograph']) {
    expect(sources).not.toContain(watchizerOnly)
  }
})

it('lets the subtype pages through untouched, in both languages', async () => {
  vi.stubGlobal('fetch', async (url) => {
    asked.push(String(url))
    return new Response('{}', { status: 404 })
  })
  for (const path of ['/ar/subtypes/diver', '/subtypes/diver', '/ar/subtypes/chronograph', '/category/watches']) {
    const url = 'https://www.brandfashionegy.com' + path
    const r = await middleware(new NextRequest(url, { headers: { host: 'www.brandfashionegy.com' } }))
    expect([path, r.status]).toEqual([path, 200])
    expect(r.headers.get('location')).toBeNull()
  }
  expect(asked).toEqual([])
})
