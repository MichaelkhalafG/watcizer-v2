import { existsSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { canonicalToken, splitBody, inlineText, showsSomething, tableOfContents, headingId, faqOf, readingMinutes, listedBlocks } from '../src/lib/dataBlocks.js'
import { blogPath, decodedSlug } from '../src/lib/blogPath.js'
import { feedXml } from '../src/lib/blogFeed.js'
import { TRUST_PAGES } from '../src/content/trustPages.js'
import { archiveOf, archiveName, pageOf, TOPICS, MIN_INDEXABLE, PER_PAGE } from '../src/lib/blogTopics.js'

/*
 * THE BLOG ENGINE'S STOREFRONT HALF (SEO Phase 3, 2026-11-14) — a twin. Core resolves the live data blocks; this side
 * places them (inline words, card grids, nothing for a token core did not resolve), builds the reading aids (table of
 * contents, reading time, the questions for FAQPage), the article's two addresses (Arabic slug), the RSS feed, and the
 * team and editorial-policy pages, which must never claim human review or editing.
 */
const card = (id, inStock = true) => ({ id, slug: `p-${id}`, title: { en: `P ${id}`, ar: `م ${id}` }, in_stock: inStock, price: { amount: 1000 }, image: { src: 'x.webp' } })

describe('data blocks', () => {
  it('keys a token the way core does: the kind, then each k=v in the order written', () => {
    expect(canonicalToken('products', '  brand=guess   family=bag limit=6')).toBe('products brand=guess family=bag limit=6')
    expect(canonicalToken('count', '')).toBe('count')
  })

  it('fills inline blocks in the sentence, places list blocks, and drops what core did not resolve', () => {
    const body = 'We carry {{count brand=guess}} models from {{price-range brand=guess}}.\n\n{{products brand=guess limit=2}}\n\nAfter. {{count brand=nope}} {{products brand=gone}}'
    const blocks = {
      'count brand=guess': { kind: 'count', total: 1234, available: 1000 },
      'price-range brand=guess': { kind: 'price-range', total: 12, min: 2500, max: 7000 },
      'products brand=guess limit=2': { kind: 'products', total: 12, available: 11, items: [card(1), card(2, false)] },
    }
    const seg = splitBody(body, blocks, 'en')
    expect(seg.map((s) => s.type)).toEqual(['md', 'block', 'md'])
    expect(seg[0].text).toContain('We carry 1,234 models from EGP 2,500–7,000.')
    expect(seg[1].block.items).toHaveLength(2)
    expect(seg[2].text).not.toContain('{{')
    expect(splitBody(body, blocks, 'ar')[0].text).toContain('2,500–7,000 ج.م')
    expect(listedBlocks(body, blocks)).toHaveLength(1)
  })

  it('the main number is the full LIVE count, and a range with no LIVE product says nothing', () => {
    expect(inlineText({ kind: 'count', total: 337, available: 325 }, 'en')).toBe('337')
    expect(inlineText({ kind: 'price-range', total: 0, min: 0, max: 0 }, 'en')).toBe('')
  })

  it('"available now instead" shows only while its model is out of stock', () => {
    expect(showsSomething({ kind: 'in-stock-alternatives', model_in_stock: true, items: [card(1)] })).toBe(false)
    expect(showsSomething({ kind: 'in-stock-alternatives', model_in_stock: false, items: [card(1)] })).toBe(true)
    expect(showsSomething({ kind: 'products', items: [] })).toBe(false)
  })
})

describe('the reading aids', () => {
  const body = '## Why Guess\nText.\n\n## Prices\nMore.\n\n## Prices\nAgain.\n\n## الأسئلة الشائعة\n\n### Is it original?\nYes, **every** one.\n\n### هل يوجد ضمان؟\nنعم.\n\n### Not a question\nx'
  it('anchors every H2 the same way the body renders it, a repeat with -2', () => {
    const toc = tableOfContents(body)
    expect(toc.map((h) => h.id)).toEqual(['why-guess', 'prices', 'prices-2', 'الأسئلة-الشائعة'])
    expect(headingId('ساعات تُومي')).toBe('ساعات-تومي')
  })
  it('takes the questions (a ### ending in ? or ؟) with their answers as plain text', () => {
    expect(faqOf(body)).toEqual([
      { question: 'Is it original?', answer: 'Yes, every one.' },
      { question: 'هل يوجد ضمان؟', answer: 'نعم.' },
    ])
  })
  it('reads 200 words a minute, at least one', () => {
    expect(readingMinutes('word '.repeat(1000))).toBe(5)
    expect(readingMinutes('')).toBe(1)
  })
})

describe("the article's addresses and feed", () => {
  const a = { slug: 'tommy-hilfiger-mens-watches', slug_ar: 'ساعات-تومي-هيلفيغر-رجالي', title: { en: 'Tommy & co', ar: 'تومي' }, excerpt: { en: '<b>x</b>', ar: 'ع' }, published_at: '2026-12-01T08:00:00+00:00' }
  it('English keeps the ASCII slug; Arabic uses its Arabic slug, percent-encoded where a URL is written', () => {
    expect(blogPath(a, 'en')).toBe('/blog/tommy-hilfiger-mens-watches')
    expect(blogPath(a, 'ar')).toBe('/blog/ساعات-تومي-هيلفيغر-رجالي')
    expect(blogPath(a, 'ar', { encoded: true })).toBe(`/blog/${encodeURIComponent('ساعات-تومي-هيلفيغر-رجالي')}`)
    expect(blogPath({ slug: 'old' }, 'ar')).toBe('/blog/old')
    expect(decodedSlug('%D8%B3')).toBe('س')
    expect(decodedSlug('%E0%A4%A')).toBe('%E0%A4%A')
  })
  it('the feed is escaped XML with each item at its own address in the language', () => {
    const en = feedXml([a], 'en')
    expect(en).toContain('<title>Tommy &amp; co</title>')
    expect(en).toContain('&lt;b&gt;x&lt;/b&gt;')
    expect(en).toContain('/blog/tommy-hilfiger-mens-watches</link>')
    expect(en).toContain('<pubDate>Tue, 01 Dec 2026 08:00:00 GMT</pubDate>')
    const ar = feedXml([a], 'ar')
    expect(ar).toContain(`/ar/blog/${encodeURIComponent('ساعات-تومي-هيلفيغر-رجالي')}</link>`)
    expect(ar).toContain('<language>ar</language>')
  })
})

describe('the team page and the editorial policy', () => {
  const forbidden = /(review(ed)? by|edited by|our editors|human review|fact-check(ed)? by|authori[sz]ed dealer|official agent of|راجعه|يراجعها|محرر|مراجعة بشرية|وكيل معتمد|موزع معتمد)/i
  it('exist in both languages with a route, a description Google shows whole, and the same sections', () => {
    for (const key of ['team', 'editorial']) {
      const page = TRUST_PAGES[key]
      expect(existsSync(new URL(`../app/(main)${page.path}/page.jsx`, import.meta.url)), key).toBe(true)
      for (const lang of ['en', 'ar']) {
        expect(page[lang].description.length, `${key} ${lang}`).toBeLessThanOrEqual(155)
        expect(page[lang].sections.length, `${key} ${lang}`).toBeGreaterThanOrEqual(4)
      }
      expect(page.en.sections.length).toBe(page.ar.sections.length)
    }
  })
  it('say truthfully how articles are written — with AI tools, checked automatically — and never claim human review', () => {
    for (const key of ['team', 'editorial']) {
      for (const lang of ['en', 'ar']) {
        const all = JSON.stringify(TRUST_PAGES[key][lang])
        expect(all, `${key} ${lang}`).not.toMatch(forbidden)
        expect(all, `${key} ${lang}`).toMatch(lang === 'en' ? /AI tools/ : /الذكاء الاصطناعي/)
        expect(all, `${key} ${lang}`).toMatch(lang === 'en' ? /automatically/ : /آلياً/)
      }
    }
  })
  it('the feed route and the article page exist', () => {
    expect(existsSync(new URL('../app/(main)/blog/feed/route.js', import.meta.url))).toBe(true)
    expect(existsSync(new URL('../app/(main)/blog/[name]/page.jsx', import.meta.url))).toBe(true)
  })
})

describe('the archives', () => {
  const blogs = [
    { slug: 'a', topic: 'care', brand: { slug: 'guess', name: { en: 'Guess', ar: 'جيس' } } },
    { slug: 'b', topic: 'buying-guide', brand: { slug: 'guess', name: { en: 'Guess', ar: 'جيس' } } },
    { slug: 'c', topic: 'care', brand: null },
  ]
  it("gathers a brand's or a topic's articles and names the archive", () => {
    expect(archiveOf(blogs, 'brand', 'guess').map((b) => b.slug)).toEqual(['a', 'b'])
    expect(archiveOf(blogs, 'topic', 'care').map((b) => b.slug)).toEqual(['a', 'c'])
    expect(archiveName('brand', 'guess', blogs, 'ar')).toBe('جيس')
    expect(archiveName('topic', 'care', blogs, 'en')).toBe(TOPICS.care.en)
  })
  it('pages 24 at a time; a page past the end is empty (the route answers 404); indexable from 3', () => {
    const many = Array.from({ length: 30 }, (_, i) => ({ slug: `s${i}` }))
    expect(pageOf(many, 2)).toMatchObject({ page: 2, pages: 2 })
    expect(pageOf(many, 2).items).toHaveLength(30 - PER_PAGE)
    expect(pageOf(many, 3).items).toHaveLength(0)
    expect(pageOf(many, 'x').page).toBe(1)
    expect(MIN_INDEXABLE).toBe(3)
  })
})
