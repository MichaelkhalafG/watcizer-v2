import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { firstReport, notFoundReport } from '../src/lib/notFoundReport.js'

/*
 * THE 404 MONITOR (SEO Phase 1, 2026-11-14). A twin. The 404 page reports the address the middleware saw, the referring
 * page and the user agent; nothing when the middleware gave no path (an asset-looking address it does not match).
 */
const h = (o) => new Headers(o)

describe('the 404 report', () => {
  it('carries the address as asked, with its /ar, the referer and the user agent', () => {
    expect(notFoundReport(h({ 'x-wz-path': '/ar/brand/nope', referer: 'https://www.google.com/', 'user-agent': 'Googlebot/2.1' }))).toEqual({
      path: '/ar/brand/nope',
      referer: 'https://www.google.com/',
      user_agent: 'Googlebot/2.1',
    })
  })

  it('reports nothing without a path from the middleware, and no referer it was not given', () => {
    expect(notFoundReport(h({}))).toBeNull()
    expect(notFoundReport(h({ 'x-wz-path': 'nope' }))).toBeNull()
    expect(notFoundReport(h({ 'x-wz-path': '/x' })).referer).toBeNull()
  })

  it('reports one ask once though the page renders twice, and the same address again after a few seconds', () => {
    const body = { path: '/twice', referer: null, user_agent: 'UA' }
    expect(firstReport(body, 1000)).toBe(true)
    expect(firstReport(body, 1200)).toBe(false)
    expect(firstReport({ ...body, user_agent: 'other' }, 1200)).toBe(true)
    expect(firstReport(body, 7000)).toBe(true)
  })

  it('is wired: the middleware always sets the path, every page that decides a 404 reports it, the 404 page never does', () => {
    const read = (p) => readFileSync(new URL('../' + p, import.meta.url), 'utf8')
    expect(read('middleware.js')).toMatch(/headers\.set\('x-wz-path', pathname\)/)
    for (const p of [
      'app/(main)/product/[slug]/page.jsx',
      'app/(main)/products/[id]/page.jsx',
      'app/(main)/blog/[name]/page.jsx',
      'app/(main)/offer/[slug]/page.jsx',
      'src/lib/facetListing.jsx',
      'app/(main)/[suptype]/page.jsx',
      'app/(main)/[suptype]/[brand]/[...rest]/page.jsx',
    ]) {
      const src = read(p)
      expect(src, p).toMatch(/await reportedNotFound\(\)/)
      expect(src.replace(/\/\/.*$/gm, ''), p).not.toMatch(/(^|[^.\w])notFound\(\)/m)   // no unreported 404 left
    }
    // Next runs the root 404 component on EVERY page (measured on a production build), so it must not report
    expect(read('app/not-found.jsx')).not.toMatch(/reportNotFound|reportedNotFound/)
  })
})
