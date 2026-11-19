// Watchizer's social accounts (2026-11-05, the client's): typed ONCE in src/content/social.js, and every place that
// shows or declares them reads that. This file is Watchizer's own (not a twin): Brand Fashion's accounts are its own.
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { FACEBOOK, INSTAGRAM, TIKTOK } from '../src/content/social.js'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'
import { shopGraph } from '../src/lib/shopSchema.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

describe('Watchizer social accounts', () => {
  it('are the three the client gave', () => {
    expect(FACEBOOK).toBe('https://www.facebook.com/profile.php?id=100087711800897')
    expect(INSTAGRAM).toBe('https://www.instagram.com/watchizer_official')
    expect(TIKTOK).toBe('https://www.tiktok.com/@watchizer_eg')
  })

  // Rewritten 2026-11-14 (SEO Phase 1): the home page no longer types its own Store node — it emits the shop graph
  // (src/lib/shopSchema.js), whose Organization reads STORES_LD. Asked of the graph itself, not of the import line.
  it('are what the structured data declares as sameAs — the stores block and the home page alike', () => {
    expect(STORES_LD.sameAs).toEqual([FACEBOOK, INSTAGRAM, TIKTOK])
    const home = read('../app/(main)/page.jsx')
    expect(home).toContain('shopGraph(urlLang, fees)')
    const org = shopGraph('en', null)['@graph'].find((n) => Array.isArray(n['@type']) && n['@type'].includes('Organization'))
    expect(org.sameAs).toEqual([FACEBOOK, INSTAGRAM, TIKTOK])
  })

  it('are on the contact page in both languages, with the handle a visitor can read', () => {
    for (const locale of ['en', 'ar']) {
      const cards = TRUST_PAGES.contact[locale].cards
      const byIcon = Object.fromEntries(cards.map((card) => [card.icon, card]))
      expect(byIcon.facebook.href).toBe(FACEBOOK)
      expect(byIcon.instagram.href).toBe(INSTAGRAM)
      expect(byIcon.instagram.value).toBe('@watchizer_official')
      expect(byIcon.tiktok.href).toBe(TIKTOK)
      expect(byIcon.tiktok.value).toBe('@watchizer_eg')
    }
  })

  it('are imported, never typed, by the header and the footer — and no retired account is left anywhere', () => {
    const header = read('../src/Components/Header/Header.jsx')
    expect(header).toContain("import { FACEBOOK, INSTAGRAM, TIKTOK } from '@/src/content/social'")
    expect(header).toContain('href={FACEBOOK}')
    expect(header).toContain('href={INSTAGRAM}')
    expect(header).toContain('href={TIKTOK}')
    expect(header).not.toMatch(/facebook\.com|instagram\.com|tiktok\.com/)
    // the footer's accounts (2026-11-19, the unified footer): this shop's src/content/footer.js, imported the same way
    const footer = read('../src/content/footer.js')
    expect(footer).toContain("import { FACEBOOK, INSTAGRAM, TIKTOK } from './social'")
    expect(footer).toContain('href: FACEBOOK')
    expect(footer).toContain('href: INSTAGRAM')
    expect(footer).toContain('href: TIKTOK')
    expect(footer).not.toMatch(/facebook\.com|instagram\.com|tiktok\.com/)
    for (const file of ['../src/content/trustPages.js', '../src/content/social.js', '../app/(main)/page.jsx']) {
      const source = read(file)
      expect(source).not.toContain('100076267296916')
      expect(source).not.toContain('instagram.com/watchizer_eg')
    }
  })
})
