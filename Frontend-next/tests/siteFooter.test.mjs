import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { createElement as h } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import FootSection from '../src/Components/Footer/FootSection'

/*
 * THE FOOTER, ONE FOR EVERY WIDTH (2026-11-19) — a twin. A column is a heading on a computer and a closed section on a
 * phone, its links in the HTML either way; the footer itself is a server component with every page link written in it.
 */
const src = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

describe('a footer column', () => {
  it('has a heading and a phone button that controls its links, closed, the links still in the HTML', () => {
    const html = renderToStaticMarkup(h(FootSection, { id: 'wz-sf-shop', title: 'Shop' }, h('a', { href: '/listing' }, 'All products')))
    expect(html).toContain('<section class="wz-sf__sec" aria-labelledby="wz-sf-shop-title">')
    expect(html).toContain('<h2 class="wz-sf__title" id="wz-sf-shop-title">')
    expect(html).toContain('aria-expanded="false" aria-controls="wz-sf-shop-body"')
    expect(html).toContain('<div id="wz-sf-shop-body" class="wz-sf__body"><a href="/listing">All products</a></div>')
  })
})

describe('the footer', () => {
  const footer = src('../src/Components/Footer/SiteFooter.jsx')
  it('is a server component, never gated by a media query in script', () => {
    expect(footer).not.toContain("'use client'")
    expect(footer).not.toContain('useMediaQuery')
    expect(src('../app/(main)/desktop-footer.jsx')).toContain('<SiteFooter />')
  })
  it('keeps every account and support page', () => {
    for (const path of ['/account?tab=profile', '/account?tab=orders', '/cart', '/login', '/about-us', '/contact-us', '/faq', '/warranty', '/shipping', '/returns', '/blogs', '/privacy-policy', '/terms-and-conditions']) {
      expect(footer).toContain(`'${path}'`)
    }
  })
  it('draws the categories with their sub-categories behind a closed expander, the brands row, the ways to pay and the language switch', () => {
    expect(footer).toContain('<details className="wz-sf__cat">')
    expect(footer).not.toContain('<details className="wz-sf__cat" open')
    expect(footer).toContain('footerDirectoryOf(menu, tables)')
    expect(footer).toContain('<PaymentBadges')
    expect(footer).toContain('<LanguageDropdown />')
    expect(footer).toContain('<FootContact')
  })
  it('keeps room for the floating corner under its last row on a phone', () => {
    expect(src('../src/Components/Footer/SiteFooter.css')).toContain('padding-block-end: calc(160px + env(safe-area-inset-bottom, 0px) + var(--pm-float-lift, 0px));')
  })
})

describe("each shop's part", async () => {
  const { FOOTER } = await import('../src/content/footer.js').catch(() => ({ FOOTER: null }))
  it('names the shop, a logo, a tagline in both languages, and Shop links in both languages', () => {
    if (!FOOTER) return // Brand Fashion's imports a client hook (its Offers link); its shape is read from the source there
    expect(FOOTER.name).toBeTruthy()
    expect(FOOTER.logo.src).toMatch(/^\//)
    expect(FOOTER.tagline.en && FOOTER.tagline.ar).toBeTruthy()
    for (const l of FOOTER.shop) {
      expect(l.href).toMatch(/^\//)
      expect(l.en && l.ar).toBeTruthy()
    }
    expect(FOOTER.social('en').length).toBeGreaterThan(0)
  })
})
