// Watchizer's social accounts (2026-11-05, the client's): typed ONCE in src/content/social.js, and every place that
// shows or declares them reads that. This file is Watchizer's own (not a twin): Brand Fashion's accounts are its own.
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { FACEBOOK, INSTAGRAM, TIKTOK } from '../src/content/social.js'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

describe('Watchizer social accounts', () => {
  it('are the three the client gave', () => {
    expect(FACEBOOK).toBe('https://www.facebook.com/profile.php?id=100087711800897')
    expect(INSTAGRAM).toBe('https://www.instagram.com/watchizer_official')
    expect(TIKTOK).toBe('https://www.tiktok.com/@watchizer_eg')
  })

  it('are what the structured data declares as sameAs — the stores block and the home page alike', () => {
    expect(STORES_LD.sameAs).toEqual([FACEBOOK, INSTAGRAM, TIKTOK])
    const home = read('../app/(main)/page.jsx')
    expect(home).toContain("import { FACEBOOK, INSTAGRAM, TIKTOK } from '@/src/content/trustPages'")
    expect(home).toContain('sameAs: [FACEBOOK, INSTAGRAM, TIKTOK]')
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
    for (const file of ['../src/Components/Header/Header.jsx', '../src/Components/Footer/Footer.jsx']) {
      const source = read(file)
      expect(source).toContain("import { FACEBOOK, INSTAGRAM, TIKTOK } from '@/src/content/social'")
      expect(source).toContain('href={FACEBOOK}')
      expect(source).toContain('href={INSTAGRAM}')
      expect(source).toContain('href={TIKTOK}')
      expect(source).not.toMatch(/facebook\.com|instagram\.com|tiktok\.com/)
    }
    for (const file of ['../src/content/trustPages.js', '../src/content/social.js', '../app/(main)/page.jsx']) {
      const source = read(file)
      expect(source).not.toContain('100076267296916')
      expect(source).not.toContain('instagram.com/watchizer_eg')
    }
  })
})
