'use client'
import { useEffect, useRef } from 'react'
import Link from '../LocaleLink'
import { optimized, optimizedSet } from '../../utils/picture'
import './homeZone.css'

// The brand banner (the twelve updates, Part 3.3, 2026-11-05; recomposed in the design pass). A TWIN.
//
// A band on the home page for ONE brand: its logo, a title and a description the team wrote, and three of
// its products, on three colours the team picked.
//
// WHAT OPENS WHAT (the developer's review, 2026-11-05): the banner is NOT one big link any more. Each of the
// three product cards is its own link, to THAT product's page, labelled with the product's name; only the
// banner's button ("Shop Tommy Hilfiger") opens the brand's page. No link sits inside another.
//
// HOW THE THREE COLOURS ARE USED: the first is the ground behind the text; the second is the blade that cuts
// the banner on a slant (and the button, the discount tags and the halo behind the products); the third is the
// stage the products stand on. The brand's own name is set very large and very faint behind the text.
//
// DEPTH: the three products are a fan of cards — the middle one forward, the outer two leaning away behind
// it. MOTION, two moments only, both off under prefers-reduced-motion: when the banner first scrolls into view
// the cards are dealt into the fan (once); and under a pointer the card pointed at comes forward. A banner
// that is already on screen when the page loads is drawn as it is — nothing waits on JavaScript to be visible.
//
// `bleed` — the banner runs the full width of the page (no rounded corners), its content kept on the page's
// own column by each shop's `--pm-container` / `--pm-gutter`.
//
// It draws a MODEL, not a payload: the two shops read the home from different endpoints (Brand Fashion v2,
// Watchizer compat) with different card shapes, so each home builds this plain object and the banner stays
// one file:
//
//   { href, brand, logo, title, description, colors: [text ground, blade, products' stage],
//     products: [{ id, href, image, title, price, was, off }] }
//       href is the product's own page; price / was are already-formatted strings; off is the product's REAL
//       discount in percent, or null
//
// The text colour is CHOSEN FROM the first colour (dark ground → white type, light ground → ink), so any
// colour the team picks stays readable.

const hex = (v, fallback) => (typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v.trim()) ? v.trim() : fallback)

/** WCAG relative luminance of `#rrggbb`. */
const luminance = (color) => {
  const n = parseInt(color.slice(1), 16)
  const lin = (c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255)
}
/** Ink or white, whichever contrasts more with the ground. */
export const onColor = (ground) => (luminance(ground) > 0.179 ? '#111111' : '#ffffff')

const STR = {
  en: { cta: (brand) => `Shop ${brand}`, off: (n) => `${n}% off`, products: (brand) => `${brand} products` },
  ar: { cta: (brand) => `تسوّق ${brand}`, off: (n) => `خصم ${n}%`, products: (brand) => `منتجات ${brand}` },
}

const Arrow = ({ flip }) => (
  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={flip ? { transform: 'scaleX(-1)' } : undefined}>
    <path d="M5 12h14M13 6l6 6-6 6" />
  </svg>
)

export default function BrandBanner({ model, lang = 'en', dir = 'ltr', bleed = false }) {
  const ref = useRef(null)

  // THE DEAL: only for a banner that is still below the fold, and only where motion is welcome. The state is a
  // data attribute the stylesheet reads, set straight on the element so a re-render never resets it.
  useEffect(() => {
    const el = ref.current
    if (!el || typeof IntersectionObserver === 'undefined') return undefined
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return undefined
    if (el.getBoundingClientRect().top < window.innerHeight * 0.92) return undefined
    el.dataset.bb = 'armed'
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          el.dataset.bb = 'in'
          io.disconnect()
        }
      },
      { threshold: 0.3 },
    )
    io.observe(el)
    return () => io.disconnect()
  }, [])

  if (!model?.href) return null
  const c1 = hex(model.colors?.[0], '#14213d')
  const c2 = hex(model.colors?.[1], '#c1121f')
  const c3 = hex(model.colors?.[2], '#f4f4f4')
  const products = (model.products || []).slice(0, 3)
  const l = lang === 'ar' ? 'ar' : 'en'
  const t = STR[l]
  const cta = (extra) => (
    <Link className={`bb__cta ${extra}`} href={model.href} prefetch={false}>
      {t.cta(model.brand || '')}
      <Arrow flip={dir === 'rtl'} />
    </Link>
  )

  return (
    <section
      ref={ref}
      className={`bb bb--${products.length}${bleed ? ' bb--bleed' : ''}`}
      dir={dir}
      lang={l}
      aria-label={model.title || model.brand || undefined}
      style={{ '--bb-1': c1, '--bb-2': c2, '--bb-3': c3, '--bb-on-1': onColor(c1), '--bb-on-2': onColor(c2), '--bb-on-3': onColor(c3) }}
    >
      <div className="bb__inner">
        <div className="bb__text">
          {model.brand ? (
            <span className="bb__ghost" aria-hidden="true" lang="en">
              {model.brand}
            </span>
          ) : null}
          {model.logo ? (
            <span className="bb__logo">
              {/* eslint-disable-next-line @next/next/no-img-element -- brand logo, asset-host URL */}
              <img src={model.logo} alt={model.brand || ''} loading="lazy" />
            </span>
          ) : model.brand ? (
            <span className="bb__logo bb__logo--name" lang="en">
              {model.brand}
            </span>
          ) : null}
          {model.title ? <h2 className="bb__title">{model.title}</h2> : null}
          {model.description ? <p className="bb__desc">{model.description}</p> : null}
          {cta('bb__cta--text')}
        </div>
        {products.length ? (
          <div className="bb__stage">
            <span className="bb__halo" aria-hidden="true" />
            <ul className="bb__products" aria-label={t.products(model.brand || '')}>
              {products.map((p, i) => (
                <li key={p.id} style={{ '--i': i }}>
                  <Link className="bb__card" href={p.href || model.href} prefetch={false} aria-label={p.title}>
                    <span className="bb__pic">
                      {/* eslint-disable-next-line @next/next/no-img-element -- product photo, asset-host URL */}
                      {/* the optimiser's copy at the tile's size (the performance pass, 2026-11-05): these were 1200 px
                          files for a tile about 200 px wide; the box is square (homeZone.css), so are the attributes */}
                      <img src={optimized(p.image)} srcSet={optimizedSet(p.image)} alt="" loading="lazy" decoding="async" width="320" height="320" />
                    </span>
                    {p.off > 0 ? <span className="bb__off">{t.off(p.off)}</span> : null}
                    <span className="bb__name">{p.title}</span>
                    <span className="bb__price">
                      {p.price}
                      {p.was ? <s>{p.was}</s> : null}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
            {cta('bb__cta--bar')}
          </div>
        ) : null}
      </div>
    </section>
  )
}
