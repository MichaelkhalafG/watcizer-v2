import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { NextRequest } from 'next/server'

// The old WordPress shop's URLs in middleware.js (D-17, 2026-11-11): which paths ask core's redirect map, the one
// 301 they get, the 410 for WordPress internals, and the canonical host (D-3). core is a stub fetch.

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

// core's map for these tests
const MAP = {
  '/product-category/men/men-watches/': '/category/watches',
  '/sale/': '/listing?sale=1',
  '/product/old-slug-copy/': '/product/old-slug',
  '/wp-content/uploads/2024/02/x.jpg': 'https://api.brandfashionegy.com/Uploads_Images/Product/1.webp',
}

function stubCore() {
  vi.stubGlobal('fetch', async (url) => {
    const path = new URL(url).searchParams.get('path')
    asked.push(path)
    const to = MAP[path]
    return new Response(JSON.stringify({ data: to ? { to, status: 301 } : null }), { status: to ? 200 : 404 })
  })
}

const run = (url) => middleware(new NextRequest(url, { headers: { host: new URL(url).host } }))

describe('the old shop URLs', () => {
  it('sends an old URL to its new one in ONE 301', async () => {
    stubCore()
    const r = await run('https://www.brandfashionegy.com/product-category/men/men-watches/')
    expect(r.status).toBe(301)
    expect(r.headers.get('location')).toBe('https://www.brandfashionegy.com/category/watches')
  })

  it('keeps a target query, and the Arabic twin for ?lang=ar', async () => {
    stubCore()
    expect((await run('https://www.brandfashionegy.com/sale/')).headers.get('location')).toBe('https://www.brandfashionegy.com/listing?sale=1')
    expect((await run('https://www.brandfashionegy.com/sale/?lang=ar')).headers.get('location')).toBe('https://www.brandfashionegy.com/ar/listing?sale=1')
  })

  it('sends an old image URL to the master on the API host', async () => {
    stubCore()
    const r = await run('https://www.brandfashionegy.com/wp-content/uploads/2024/02/x.jpg')
    expect(r.headers.get('location')).toBe('https://api.brandfashionegy.com/Uploads_Images/Product/1.webp')
  })

  it('drops a trailing slash with one 301 when the map has no row', async () => {
    stubCore()
    const r = await run('https://www.brandfashionegy.com/product/kept-slug/')
    expect(r.status).toBe(301)
    expect(r.headers.get('location')).toBe('https://www.brandfashionegy.com/product/kept-slug')
  })

  it('never asks core for an ordinary page view', async () => {
    stubCore()
    const r = await run('https://www.brandfashionegy.com/product/kept-slug')
    expect(asked).toEqual([])
    expect(r.headers.get('location')).toBeNull()
  })

  it('answers 410 to WordPress internals without asking core', async () => {
    stubCore()
    for (const p of ['/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/feed/', '/xmlrpc.php', '/wp-content/plugins/x.js', '/?p=123']) {
      expect((await run(`https://www.brandfashionegy.com${p}`)).status).toBe(410)
    }
    expect(asked).toEqual([])
  })

  it('serves the page as it is when core is down', async () => {
    vi.stubGlobal('fetch', async () => {
      throw new Error('down')
    })
    const r = await run('https://www.brandfashionegy.com/product-category/men')   // no slash: nothing else to do
    expect(r.headers.get('location')).toBeNull()
    expect(r.status).toBe(200)
  })
})

describe('the canonical host (D-3)', () => {
  it('sends the bare domain to www, path and query kept', async () => {
    const r = await run('https://brandfashionegy.com/listing?sort=newest')
    expect(r.status).toBe(301)
    expect(r.headers.get('location')).toBe('https://www.brandfashionegy.com/listing?sort=newest')
  })

  it('leaves the canonical host alone', async () => {
    const r = await run('https://www.brandfashionegy.com/listing')
    expect(r.headers.get('location')).toBeNull()
  })
})
