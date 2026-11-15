// Brand Fashion's contact details (2026-11-14, the client's team). This file is Brand Fashion's own, not a twin:
// Watchizer's facts are its own. The number is 01014179820 everywhere; the old number is shown nowhere.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { FACEBOOK, INSTAGRAM, INSTAGRAM_WEB, PHONE, TIKTOK, WHATSAPP, WHATSAPP_DIGITS } from '../src/content/contact.js'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'
import { shopGraph } from '../src/lib/shopSchema.js'

const OLD = ['1283888490', '1283 888 490']
const DEVELOPER = '1274550956'
const root = fileURLToPath(new URL('..', import.meta.url))
const files = (dir) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f)
  return statSync(p).isDirectory() ? files(p) : /\.(m?jsx?|txt|json|css)$/.test(f) ? [p] : []
})

describe('Brand Fashion contact details', () => {
  it('are the number the client gave, on every channel', () => {
    expect(PHONE).toBe('01014179820')
    expect(WHATSAPP_DIGITS).toBe('201014179820')
    expect(WHATSAPP).toBe('https://wa.me/201014179820')
    expect(STORES_LD.phone).toBe('+201014179820')
  })

  it('never show the old number, in any source file of the shop', () => {
    const found = []
    // the code and the pages in full; public/ only its top-level text files (robots.txt and the like), not the images
    const publicText = readdirSync(join(root, 'public')).filter((f) => /\.(txt|json|xml|webmanifest)$/.test(f)).map((f) => join(root, 'public', f))
    for (const f of [...files(join(root, 'src')), ...files(join(root, 'app')), ...publicText]) {
      {
        const text = readFileSync(f, 'utf8')
        if (OLD.some((n) => text.includes(n))) found.push(f)
      }
    }
    expect(found).toEqual([])
  }, 30_000)

  it('link Instagram (both accounts), Facebook and TikTok on the contact page, without tracking parameters', () => {
    for (const locale of ['en', 'ar']) {
      const hrefs = TRUST_PAGES.contact[locale].cards.map((c) => c.href)
      for (const h of [INSTAGRAM, INSTAGRAM_WEB, FACEBOOK, TIKTOK]) expect(hrefs).toContain(h)
      for (const h of hrefs) expect(h).not.toMatch(/[?&](dlrf|exln|_r|_t|g_st|mibextid|igsh|utm_[a-z]+)=/)
    }
    expect(TIKTOK).toBe('https://www.tiktok.com/@brand_fashion_egy')
    expect(FACEBOOK).toBe('https://www.facebook.com/profile.php?id=61557499419226')
  })

  it('declare the stores with their maps and the shop number, and the developer\'s number nowhere', () => {
    const graph = shopGraph('en', null)['@graph']
    const stores = graph.filter((n) => n['@type'] === 'Store')
    expect(stores.map((s) => s.hasMap)).toEqual(['https://maps.app.goo.gl/jA9owX6N3L6bCpXP8', 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7'])
    for (const s of stores) expect(s.telephone).toBe('+201014179820')
    const org = graph[0]
    expect(org.sameAs).toEqual([FACEBOOK, INSTAGRAM, INSTAGRAM_WEB, TIKTOK])
    // both stores, every day 10:00 to midnight (the team, 2026-11-14; midnight in Google's form)
    for (const s of stores) expect(s.openingHoursSpecification).toEqual([{ '@type': 'OpeningHoursSpecification', dayOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], opens: '10:00', closes: '23:59' }])
    expect(JSON.stringify(graph)).not.toContain(DEVELOPER)
  })
})
