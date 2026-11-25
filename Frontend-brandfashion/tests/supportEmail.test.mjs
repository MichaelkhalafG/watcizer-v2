import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { EMAIL, MAILTO, PHONE } from '../src/content/contact.js'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages.js'
import { FAQ } from '../src/content/faq.js'

// The shop's support e-mail (Michael, 2026-11-25): on the contact, privacy, terms, warranty and returns pages, the FAQ,
// the footer, the Organization markup and /llms.txt, in both languages. The phone is unchanged. No old address is left.
const root = fileURLToPath(new URL('..', import.meta.url))
const files = (dir) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f)
  return statSync(p).isDirectory() ? files(p) : /\.(m?jsx?|txt)$/.test(f) ? [p] : []
})
const text = (v) => JSON.stringify(v)

describe('the support e-mail', () => {
  it('is brandfashion@eleganceeg.com, with its mailto, and the phone is unchanged', () => {
    expect(EMAIL).toBe('brandfashion@eleganceeg.com')
    expect(MAILTO).toBe('mailto:brandfashion@eleganceeg.com')
    expect(PHONE).toBe('01014179820')
  })
  it('is named on every page that gives a way to reach us, in both languages, and in the markup', () => {
    for (const page of ['contact', 'privacy', 'terms', 'warranty', 'returns']) {
      const p = TRUST_PAGES[page]
      expect(text(p.en ?? p), page).toContain(EMAIL)
      expect(text(p.ar ?? p), page).toContain(EMAIL)
    }
    expect(STORES_LD.email).toBe(EMAIL)
    expect(text(FAQ)).toContain(EMAIL)
    expect(readFileSync(join(root, 'src/Components/Footer/FootContact.jsx'), 'utf8')).toContain('{EMAIL}')
    expect(readFileSync(join(root, 'app/llms.txt/route.js'), 'utf8')).toContain('STORES_LD.email')
  })
  it('leaves no old address anywhere in the shop', () => {
    for (const f of [...files(join(root, 'src')), ...files(join(root, 'app'))]) {
      const body = readFileSync(f, 'utf8')
      for (const old of ['Info@brandfashionegy.com', 'info@brandfashionegy.com']) expect(body.includes(old), `${f} still names ${old}`).toBe(false)
    }
  })
})
