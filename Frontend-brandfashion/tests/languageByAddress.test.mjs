import { beforeAll, describe, expect, it } from 'vitest'
import { NextRequest } from 'next/server'

/*
 * THE ADDRESS DECIDES THE LANGUAGE (Michael, 2026-11-14). A bare URL is English whatever cookie the visitor holds; /ar
 * is Arabic; the wz-lang cookie only sends a visitor who chose Arabic from the bare HOME page to /ar, with a 302 that is
 * never cached. Before, a bare URL showed the Arabic body under the English title to anyone holding the cookie.
 * Brand Fashion's middleware is async (it may ask core's redirect map); none of these paths looks old, so core is not asked.
 */

let middleware
beforeAll(async () => {
  process.env.SITE_CANONICAL_HOST = 'www.brandfashionegy.com'
  ;({ middleware } = await import('../middleware.js'))
})

const run = async (url, cookie) =>
  middleware(new NextRequest(url, { headers: { host: new URL(url).host, ...(cookie ? { cookie } : {}) } }))
const lang = (r) => r.headers.get('x-middleware-request-x-wz-lang')

describe('the language of a page', () => {
  it('is English on a bare URL, even with the Arabic cookie', async () => {
    for (const cookie of [undefined, 'wz-lang=ar', 'wz-lang=en']) {
      const r = await run('https://www.brandfashionegy.com/product/x', cookie)
      expect(r.status).toBe(200)
      expect(lang(r)).toBe('en')
      expect(r.headers.get('x-middleware-request-x-wz-url-lang')).toBe('en')
    }
  })

  it('is Arabic under /ar', async () => {
    const r = await run('https://www.brandfashionegy.com/ar/product/x')
    expect(lang(r)).toBe('ar')
    expect(r.headers.get('x-middleware-request-x-wz-url-lang')).toBe('ar')
  })

  it('sends a visitor who chose Arabic from the bare home page to /ar, uncached — and nobody else', async () => {
    const r = await run('https://www.brandfashionegy.com/', 'wz-lang=ar')
    expect(r.status).toBe(302)
    expect(r.headers.get('location')).toBe('https://www.brandfashionegy.com/ar')
    expect(r.headers.get('cache-control')).toBe('private, no-store')
    expect((await run('https://www.brandfashionegy.com/')).status).toBe(200)                  // a crawler: no cookie, no redirect
    expect((await run('https://www.brandfashionegy.com/', 'wz-lang=en')).status).toBe(200)
    expect((await run('https://www.brandfashionegy.com/listing', 'wz-lang=ar')).status).toBe(200)  // only the home page
  })
})
