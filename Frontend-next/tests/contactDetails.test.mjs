// Watchizer's contact details (2026-11-14, the client's team). This file is Watchizer's own, not a twin: Brand
// Fashion's facts are its own. The number stays 01551096234; Brand Fashion's numbers are shown nowhere here.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { PHONE, WHATSAPP, WHATSAPP_DIGITS } from '../src/content/contact.js'
import { FACEBOOK, INSTAGRAM, TIKTOK } from '../src/content/social.js'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'
import { shopGraph } from '../src/lib/shopSchema.js'

// Brand Fashion's numbers, old and new: the twin account page carried the old one until 2026-11-14
const OLD = ['1283888490', '1014179820']
const DEVELOPER = '1274550956'
const root = fileURLToPath(new URL('..', import.meta.url))
const files = (dir) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f)
  return statSync(p).isDirectory() ? files(p) : /\.(m?jsx?|txt|json|css)$/.test(f) ? [p] : []
})

describe('Watchizer contact details', () => {
  it('are the number the client gave, on every channel', () => {
    expect(PHONE).toBe('01551096234')
    expect(WHATSAPP_DIGITS).toBe('201551096234')
    expect(WHATSAPP).toBe('https://wa.me/201551096234')
    expect(STORES_LD.phone).toBe('+201551096234')
  })

  it('never show a Brand Fashion number, in any source file of the shop', () => {
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

  it('link Instagram, Facebook and TikTok on the contact page, without tracking parameters', () => {
    for (const locale of ['en', 'ar']) {
      const hrefs = TRUST_PAGES.contact[locale].cards.map((c) => c.href)
      for (const h of [INSTAGRAM, FACEBOOK, TIKTOK]) expect(hrefs).toContain(h)
      for (const h of hrefs) expect(h).not.toMatch(/[?&](dlrf|exln|_r|_t|g_st|mibextid|igsh|utm_[a-z]+)=/)
    }
    expect(TIKTOK).toBe('https://www.tiktok.com/@watchizer_eg')
  })

  it('declare the stores with their maps and the shop number, and the developer\'s number nowhere', () => {
    const graph = shopGraph('en', null)['@graph']
    const stores = graph.filter((n) => n['@type'] === 'Store')
    expect(stores.map((s) => s.hasMap)).toEqual(['https://maps.app.goo.gl/jA9owX6N3L6bCpXP8', 'https://maps.app.goo.gl/c5wtDgsmLEP398ht7'])
    for (const s of stores) expect(s.telephone).toBe('+201551096234')
    const org = graph[0]
    expect(org.sameAs).toEqual([FACEBOOK, INSTAGRAM, TIKTOK])
    expect(FACEBOOK).toBe('https://www.facebook.com/profile.php?id=100087711800897')   // the same page (Michael, 2026-11-14)
    // both stores, every day 10:00 to midnight (the team, 2026-11-14; midnight in Google's form)
    for (const s of stores) expect(s.openingHoursSpecification).toEqual([{ '@type': 'OpeningHoursSpecification', dayOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], opens: '10:00', closes: '23:59' }])
    expect(JSON.stringify(graph)).not.toContain(DEVELOPER)
  })
})
