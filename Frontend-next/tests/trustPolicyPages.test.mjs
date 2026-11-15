import { existsSync, readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'
import { FAQ } from '../src/content/faq.js'
import { followersText } from '../src/lib/followers.js'

/*
 * THE WARRANTY, SHIPPING AND RETURNS PAGES, AND WHO WE ARE (SEO Phase 1, 2026-11-14; Michael's trust-pages input). A
 * twin. The words are each shop's own; what is held here is the same on both: every page in both languages, inside
 * Google's description length, nothing the rules forbid, and the returns page in the terms' own words.
 */
const NEW = ['warranty', 'shipping', 'returns']
const all = (page) => JSON.stringify(page)

describe('the policy pages', () => {
  it('exist in both languages with a route, a title, a description Google shows whole, and sections', () => {
    for (const key of NEW) {
      const page = TRUST_PAGES[key]
      expect(page?.path, key).toBe(`/${key}`)
      expect(existsSync(new URL(`../app/(main)/${key}/page.jsx`, import.meta.url)), key).toBe(true)
      for (const lang of ['en', 'ar']) {
        const t = page[lang]
        expect(t.title && t.metaTitle && t.intro, `${key} ${lang}`).toBeTruthy()
        expect(t.description.length, `${key} ${lang} description`).toBeLessThanOrEqual(155)
        expect(t.sections.length, `${key} ${lang}`).toBeGreaterThanOrEqual(4)
      }
      expect(page.en.sections.length, key).toBe(page.ar.sections.length)
    }
  })

  it('state no free-shipping threshold, no dealer claim, and link nowhere but the stores, WhatsApp and the shop', () => {
    for (const key of [...NEW, 'about']) {
      const text = all(TRUST_PAGES[key])
      expect(text, key).not.toMatch(/10,000|١٠٬٠٠٠|ships? free|شحنها مجاني|مجاناً للطلبات/)
      expect(text, key).not.toMatch(/authori[sz]ed|official (dealer|retailer|distributor)|وكيل معتمد|موزع معتمد|الوكيل المعتمد/i)
      for (const [, href] of text.matchAll(/\]\((https?:[^)\s]+)\)/g)) {
        expect(href, key).toMatch(/^https:\/\/(maps\.app\.goo\.gl\/|wa\.me\/|api\.whatsapp\.com\/|www\.facebook\.com\/|www\.instagram\.com\/|instagram\.com\/|www\.tiktok\.com\/)/)
      }
    }
    // the FAQ is FAQPage structured data: no threshold there either (Michael's rule, 2026-11-14)
    expect(all(FAQ)).not.toMatch(/10,000|١٠٬٠٠٠|ship free|شحنها مجاني/)
  })

  it('give the returns policy in the terms\' own words, delivery within 24 hours (the brief\'s business fact), and each policy\'s markup on its page', () => {
    const terms = all(TRUST_PAGES.terms)
    for (const lang of ['en', 'ar']) {
      const returns = TRUST_PAGES.returns[lang].sections
      const window = returns[0].blocks[0].ul
      for (const line of window) expect(terms, line).toContain(line)
    }
    expect(all(TRUST_PAGES.shipping.en)).toContain('within 24 hours')
    expect(all(TRUST_PAGES.shipping.ar)).toContain('خلال ٢٤ ساعة')
    expect(all(TRUST_PAGES.returns.en)).toContain('We pay the return shipping')
    expect(all(TRUST_PAGES.returns.ar)).toContain('نتحمّل نحن شحن الإرجاع')
    // Google: the shipping policy markup on the shipping page, the return policy markup on the returns page
    expect(readFileSync(new URL('../app/(main)/shipping/page.jsx', import.meta.url), 'utf8')).toMatch(/shippingService\(fees\)/)
    expect(readFileSync(new URL('../app/(main)/returns/page.jsx', import.meta.url), 'utf8')).toMatch(/returnPolicy\(\)/)
  })
})

describe('who we are', () => {
  it('says since 2013, names both stores with their maps, the phone, and carries the followers line and the policies', () => {
    expect(STORES_LD.founded).toBe('2013')
    expect(STORES_LD.stores.map((s) => s.map)).toEqual(['https://maps.app.goo.gl/jA9owX6N3L6bCpXP8', 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7'])
    for (const lang of ['en', 'ar']) {
      const t = TRUST_PAGES.about[lang]
      expect(t.lead, lang).toMatch(/2013|٢٠١٣/)
      for (const s of STORES_LD.stores) expect(t.store, lang).toContain(s.map)
      expect(t.store, lang).toMatch(/\]\(tel:/)
      expect(t.followers, lang).toContain('{followers}')
      for (const key of NEW) expect(t.policies, `${lang} ${key}`).toContain(`](/${key})`)
    }
  })

  it('rounds the follower total DOWN, with a plus, in both languages — and states none below a thousand', () => {
    expect(followersText(900000, 'en')).toBe('900K+')
    expect(followersText(900000, 'ar')).toBe('أكثر من ٩٠٠ ألف')
    expect(followersText(812345, 'en')).toBe('812K+')
    expect(followersText(1250000, 'en')).toBe('1.2M+')
    expect(followersText(1250000, 'ar')).toBe('أكثر من ١٫٢ مليون')
    expect(followersText(9999, 'en')).toBe('9.9K+')
    expect(followersText(999, 'en')).toBeNull()
    expect(followersText(null, 'en')).toBeNull()
  })
})
