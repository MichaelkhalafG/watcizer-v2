import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/*
 * AN ARTICLE'S META IN ITS PAGE'S OWN LANGUAGE (2026-11-14, the local re-crawl). A twin. The body and the title may fall
 * back to the other language when a translation is missing; the meta title and description never do — an empty one
 * falls to the page's own title (three Brand Fashion articles carried an Arabic <title> on their English pages).
 * serverBlogs.js uses React's server-only cache(), so the source is read rather than imported.
 */
const read = (p) => readFileSync(new URL('../' + p, import.meta.url), 'utf8')

describe('article meta', () => {
  it('has a reader for the page language only, beside the one that may fall back', () => {
    const src = read('src/lib/serverBlogs.js')
    expect(src).toMatch(/export const ownLang = \(pair, lang\) => \(pair\?\.\[lang\] \|\| ''\)\.trim\(\)/)
    expect(src).toMatch(/export const inLang = /)
  })

  it('is what the article page uses for its title and descriptions', () => {
    const src = read('app/(main)/blog/[name]/page.jsx')
    expect(src).toMatch(/ownLang\(blog\.meta_title, urlLang\)/)
    expect(src).not.toMatch(/inLang\(blog\.meta_(title|description)/)
  })
})
