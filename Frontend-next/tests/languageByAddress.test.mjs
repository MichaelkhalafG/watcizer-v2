import { describe, expect, it } from 'vitest'
import { NextRequest } from 'next/server'
import { middleware } from '../middleware.js'

/*
 * THE ADDRESS DECIDES THE LANGUAGE (Michael, 2026-11-14). A bare URL is English whatever cookie the visitor holds; /ar
 * is Arabic; the wz-lang cookie only sends a visitor who chose Arabic from the bare HOME page to /ar, with a 302 that is
 * never cached. Before, a bare URL showed the Arabic body under the English title to anyone holding the cookie.
 */

const run = (url, cookie) =>
  middleware(new NextRequest(url, { headers: { host: new URL(url).host, ...(cookie ? { cookie } : {}) } }))
const lang = (r) => r.headers.get('x-middleware-request-x-wz-lang')

describe('the language of a page', () => {
  it('is English on a bare URL, even with the Arabic cookie', () => {
    for (const cookie of [undefined, 'wz-lang=ar', 'wz-lang=en']) {
      const r = run('https://watchizereg.com/product/x', cookie)
      expect(r.status).toBe(200)
      expect(lang(r)).toBe('en')
      expect(r.headers.get('x-middleware-request-x-wz-url-lang')).toBe('en')
    }
  })

  it('is Arabic under /ar', () => {
    const r = run('https://watchizereg.com/ar/product/x')
    expect(lang(r)).toBe('ar')
    expect(r.headers.get('x-middleware-request-x-wz-url-lang')).toBe('ar')
  })

  it('sends a visitor who chose Arabic from the bare home page to /ar, uncached — and nobody else', () => {
    const r = run('https://watchizereg.com/', 'wz-lang=ar')
    expect(r.status).toBe(302)
    expect(r.headers.get('location')).toBe('https://watchizereg.com/ar')
    expect(r.headers.get('cache-control')).toBe('private, no-store')
    expect(run('https://watchizereg.com/').status).toBe(200)                  // a crawler: no cookie, no redirect
    expect(run('https://watchizereg.com/', 'wz-lang=en').status).toBe(200)
    expect(run('https://watchizereg.com/listing', 'wz-lang=ar').status).toBe(200)  // only the home page
  })
})
