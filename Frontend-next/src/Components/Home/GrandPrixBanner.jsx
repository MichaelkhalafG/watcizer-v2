'use client'
import { Component, useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'
import dynamic from 'next/dynamic'
import Link from '../LocaleLink'
import { useUIStore } from '../../Store/uiStore'
import { COPY, ASSETS, PATH, BANNER_LAYOUT } from '../../content/grandPrix1513477'
import { STORE_LINE } from '../../content/homeHeadline'
import './GrandPrixBanner.css'

/*
 * THE GRAND PRIX BANNER — the Hugo Boss Grand Prix 1513477 on the Watchizer home (2026-10-29, Watchizer only).
 *
 * Replaces the 3D hero IN CODE for the campaign's run; there is no dashboard switch. Its box is the old hero's,
 * to the pixel (WatchHero.css: 50vh within 400–600 px on a wide screen, 55vh on a tablet, the viewport under the
 * 52 px header on a phone), so nothing below it moves when the campaign ends and `<WatchHero />` returns in
 * app/(main)/HomeClient.jsx (one import, one line; that hero's code is untouched in src/Components/Hero/).
 *
 * THE WATCH BEHAVES AS THE OLD HERO'S DID (GrandPrixCanvas.jsx lists every setting carried over) — OrbitControls,
 * the same damping, speed, limits and touch — but starts face-on, and is larger (~85 % of the banner's height).
 *
 * THE WORDS: the store's line is the page's H1 ("The Art of Timeless Precision" / «فنّ الدقة الخالدة», the old hero's
 * words); the brand's logo file as supplied; the campaign's name (always English) as an h2; one line; three facts,
 * the price and the stock FROM THE RECORD (`data`, read on the home's server render — nothing is typed here, and a
 * record that cannot be read shows none of them); the button to the experience and a link to the product page.
 *
 * FIRST PAINT IS THE POSTER — a still captured from this banner's own canvas (same pose, light, grade, transparent
 * over the same background), 1× and 2×, AVIF and WebP, eager and high priority: the LCP element, and the whole
 * banner under reduced motion or Save-Data. The canvas chunk mounts after the window has loaded, on idle, with the
 * banner in view (the phone model on phones); its first frame is the poster's frame, crossfaded in 0.35 s.
 * The bokeh — amber points at three depths — is CSS: still at first paint, drifting once idle, a little parallax with
 * the mouse; static under reduced motion.
 */

const GrandPrixCanvas = dynamic(() => import('./GrandPrixCanvas'), { ssr: false, loading: () => null })

// the H1 (today's line, or the SEO proposal behind one switch): src/content/homeHeadline.js

// The bokeh: fixed points (a seeded sequence, so the server and the browser draw the same ones), three depths — far
// (small, sharper, faint), mid, near (large, a haze, the most parallax). Phones draw the first 18.
const seeded = (n) => {
  const x = Math.sin(n * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}
const BOKEH = Array.from({ length: 34 }, (_, i) => {
  const depth = i % 3
  const size = depth === 0 ? 3 + seeded(i + 1) * 5 : depth === 1 ? 7 + seeded(i + 2) * 12 : 18 + seeded(i + 3) * 34
  return {
    i,
    depth,
    x: Math.round(seeded(i + 11) * 1000) / 10,
    y: Math.round(seeded(i + 23) * 1000) / 10,
    size: Math.round(size),
    alpha: depth === 0 ? 0.55 + seeded(i + 5) * 0.35 : depth === 1 ? 0.32 + seeded(i + 6) * 0.3 : 0.12 + seeded(i + 7) * 0.16,
    soft: depth === 0 ? 38 : depth === 1 ? 26 : 8,
    drift: Math.round((14 + seeded(i + 31) * 16) * 10) / 10,
    pulse: Math.round((4 + seeded(i + 37) * 5) * 10) / 10,
    delay: Math.round(seeded(i + 41) * -200) / 10,
  }
})

const subscribeMotion = (cb) => {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => {}
  const mq = window.matchMedia('(prefers-reduced-motion: reduce)')
  mq.addEventListener?.('change', cb)
  return () => mq.removeEventListener?.('change', cb)
}
const readMotion = () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
const serverMotion = () => false

// A failure anywhere in the 3D subtree (a chunk that will not load) ends at the poster.
class StageBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  componentDidCatch() {}
  render() {
    return this.state.failed ? null : this.props.children
  }
}

/** @param {{ data?: { price: { final: number, amount: number, hasSale: boolean }, stock: { total: number, inStock: boolean }, facts: Array<{ en: string, ar: string }>, productPath: string } | null }} props */
export default function GrandPrixBanner({ data = null }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const isRTL = lang === 'ar'
  const t = COPY[lang].home
  const L = COPY[lang].labels
  const calm = useSyncExternalStore(subscribeMotion, readMotion, serverMotion)
  const [armed, setArmed] = useState(false)
  const [live, setLive] = useState(false)
  const [moving, setMoving] = useState(false)
  const [phone, setPhone] = useState(false)
  const section = useRef(null)
  const art = useRef(null)
  const bokeh = useRef(null)

  // After the window has loaded, on idle: the bokeh starts moving; the canvas mounts once the banner is in view —
  // never on Save-Data (the bokeh still moves) and never under reduced motion (nothing moves).
  useEffect(() => {
    if (calm) return undefined
    const saveData = typeof navigator !== 'undefined' && navigator.connection && navigator.connection.saveData
    let cancelled = false
    let cancelIdle = () => {}
    let io = null
    const arm = () => {
      if (cancelled) return
      setMoving(true)
      if (saveData) return
      setPhone(window.matchMedia('(max-width: 768px)').matches)
      setArmed(true)
    }
    const onIdle = () => {
      if (typeof window.requestIdleCallback === 'function') {
        const id = window.requestIdleCallback(arm, { timeout: 4000 })
        cancelIdle = () => window.cancelIdleCallback(id)
      } else {
        const id = setTimeout(arm, 600)
        cancelIdle = () => clearTimeout(id)
      }
    }
    const whenInView = () => {
      if (cancelled) return
      const el = art.current
      if (!el || typeof IntersectionObserver !== 'function') return onIdle()
      io = new IntersectionObserver(
        (entries) => {
          if (!entries.some((e) => e.isIntersecting)) return
          io?.disconnect()
          io = null
          onIdle()
        },
        { rootMargin: '160px' },
      )
      io.observe(el)
    }
    if (document.readyState === 'complete') whenInView()
    else window.addEventListener('load', whenInView, { once: true })
    return () => {
      cancelled = true
      window.removeEventListener('load', whenInView)
      io?.disconnect()
      cancelIdle()
    }
  }, [calm])

  // The bokeh's parallax with the mouse (desktop only): two custom properties, at most once a frame.
  useEffect(() => {
    if (!moving) return undefined
    const sec = section.current
    const layer = bokeh.current
    if (!sec || !layer || !window.matchMedia('(pointer: fine)').matches) return undefined
    let raf = 0
    let px = 0
    let py = 0
    const move = (e) => {
      const r = sec.getBoundingClientRect()
      px = ((e.clientX - r.left) / Math.max(1, r.width)) * 2 - 1
      py = ((e.clientY - r.top) / Math.max(1, r.height)) * 2 - 1
      if (raf) return
      raf = requestAnimationFrame(() => {
        raf = 0
        layer.style.setProperty('--px', px.toFixed(3))
        layer.style.setProperty('--py', py.toFixed(3))
      })
    }
    const leave = () => {
      layer.style.setProperty('--px', '0')
      layer.style.setProperty('--py', '0')
    }
    sec.addEventListener('pointermove', move, { passive: true })
    sec.addEventListener('pointerleave', leave)
    return () => {
      cancelAnimationFrame(raf)
      sec.removeEventListener('pointermove', move)
      sec.removeEventListener('pointerleave', leave)
    }
  }, [moving])

  const onReady = useCallback(() => setLive(true), [])
  const onFail = useCallback(() => setLive(false), [])

  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')
  const currency = isRTL ? 'ج.م' : 'EGP'
  const stockLine = !data ? null : !data.stock.inStock ? L.soldOut : data.stock.total <= 3 ? L.lowStock(fmt(data.stock.total)) : null
  const wide = isRTL ? ASSETS.banner.rtl : ASSETS.banner.ltr
  const ph = ASSETS.banner.phone

  return (
    <section ref={section} className={`wz-gp wz-gp--${BANNER_LAYOUT}${live ? ' is-live' : ''}${calm ? ' is-calm' : ''}${moving ? ' is-moving' : ''}`} dir={isRTL ? 'rtl' : 'ltr'} aria-labelledby="wz-gp-store">
      <div ref={bokeh} className="wz-gp__bokeh" aria-hidden="true">
        {BOKEH.map((b) => (
          <span
            key={b.i}
            className={`wz-gp__dot d${b.depth}${b.i >= 18 ? ' is-wide' : ''}`}
            style={{ '--x': `${b.x}%`, '--y': `${b.y}%`, '--s': `${b.size}px`, '--a': b.alpha.toFixed(2), '--soft': `${b.soft}%`, '--drift': `${b.drift}s`, '--pulse': `${b.pulse}s`, '--delay': `${b.delay}s` }}
          />
        ))}
      </div>

      <div ref={art} className="wz-gp__art">
        <picture className="wz-gp__poster">
          <source media="(max-width: 768px)" type="image/avif" srcSet={`${ph.base}.avif 1x, ${ph.base}@2x.avif 2x`} />
          <source media="(max-width: 768px)" type="image/webp" srcSet={`${ph.base}.webp 1x, ${ph.base}@2x.webp 2x`} />
          <source type="image/avif" srcSet={`${wide.base}.avif 1x, ${wide.base}@2x.avif 2x`} />
          <source type="image/webp" srcSet={`${wide.base}.webp 1x, ${wide.base}@2x.webp 2x`} />
          <img src={`${wide.base}.webp`} width={wide.w} height={wide.h} alt={L.posterAlt} fetchPriority="high" loading="eager" decoding="async" draggable="false" />
        </picture>
        {armed && !calm ? (
          <div className="wz-gp__canvas" aria-hidden="true">
            <StageBoundary>
              <GrandPrixCanvas src={phone ? ASSETS.glbPhone : ASSETS.glb} env={ASSETS.env} isRTL={isRTL} onReady={onReady} onFail={onFail} onLost={onFail} onRestored={onReady} />
            </StageBoundary>
          </div>
        ) : null}
      </div>

      <div className="wz-gp__copy">
        <h1 id="wz-gp-store" className="wz-gp__store">
          {STORE_LINE[lang]}
        </h1>
        <img className="wz-gp__logo" src={ASSETS.logo} width={ASSETS.logoWidth} height={ASSETS.logoHeight} alt={t.logoAlt} fetchPriority="high" decoding="async" />
        <h2 className="wz-gp__name">
          <bdi dir="ltr">{t.name}</bdi>
        </h2>
        <p className="wz-gp__line">{t.line}</p>
        {data && data.facts.length ? (
          <ul className="wz-gp__facts">
            {data.facts.map((f) => (
              <li key={f.en}>{f[lang] || f.en}</li>
            ))}
          </ul>
        ) : null}
        {data ? (
          <p className="wz-gp__buy">
            <span className="wz-gp__now">
              {fmt(data.price.final)} <small>{currency}</small>
            </span>
            {data.price.hasSale ? (
              <s className="wz-gp__was">
                {fmt(data.price.amount)} {currency}
              </s>
            ) : null}
            {stockLine ? <span className="wz-gp__stock">{stockLine}</span> : null}
          </p>
        ) : null}
        <div className="wz-gp__actions">
          <Link href={PATH} className="wz-gp__cta">
            {t.cta}
          </Link>
          {data ? (
            <Link href={data.productPath} className="wz-gp__link">
              {t.productLink}
            </Link>
          ) : null}
        </div>
      </div>
    </section>
  )
}
