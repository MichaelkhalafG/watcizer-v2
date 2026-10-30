'use client'
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import dynamic from 'next/dynamic'
import Link from '../LocaleLink'
import { useRouter } from '../../Hooks/useLocaleRouter'
import { useUIStore } from '../../Store/uiStore'
import { useToastStore } from '../../Store/toastStore'
import useCart, { getItemKey } from '../../Hooks/useCart'
import { refusedAddMessage } from '../../Store/cartStore'
import { trackViewContent } from '../../scripts/pixels'
import { cartLineFor, boxLines } from '../../lib/experienceSpecs'
import { COPY, ASSETS, WORDS, CALLOUTS } from '../../content/grandPrix1513477'
import { SECTIONS, TOTAL_VH, sectionAt, idleAt } from './timeline'
import './experience.css'

/*
 * THE EXPERIENCE PAGE — one watch, a scroll-driven film (2026-10-28, Watchizer only; Michael's design pass).
 *
 * LAYERS, BACK TO FRONT: the static petrol gradient (what shows before WebGL and under reduced motion) → the one
 * fixed canvas, which draws the animated environment, the ghost words BEHIND the watch and the watch itself
 * (WatchStage + Atmosphere) → the callout lines, projected every frame from the parts' positions → the twelve
 * pinned sections' type, each with its own composition and its own motion → the Home control and the buy bar.
 *
 * THE SCROLL IS THE ONLY INPUT: progress 0 → 1 across the page drives the stage (timeline.js), and each section's
 * own progress (--t) drives its type: a fill that wipes into an outlined headline, words revealed through masks,
 * letters that scatter with the disassembly and gather with the reassembly, a line along a path, a focus pull,
 * numerals that count to the record's figures. Lenis smooths the wheel; touch stays native.
 *
 * FIRST PAINT IS A STILL (the poster, the page's LCP) over the gradient; the stage mounts on idle. Reduced motion:
 * no canvas, no motion, the sections in flow. THE BUY BAR adds the product page's own cart line through the same
 * store, so the existing AddToCart / add_to_cart fire from there; no new event name anywhere.
 */

const WatchStage = dynamic(() => import('./WatchStage'), { ssr: false, loading: () => null })

const subscribeMotion = (cb) => {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => {}
  const mq = window.matchMedia('(prefers-reduced-motion: reduce)')
  mq.addEventListener?.('change', cb)
  return () => mq.removeEventListener?.('change', cb)
}
const readMotion = () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
const serverMotion = () => false

// which side of a wide screen a section's text takes: the opposite of where the timeline puts the watch
const textSideOf = (section) => {
  const keys = section.keys.filter((k) => k.cam)
  const side = keys.length ? keys[keys.length - 1].cam.side : 0
  return side > 0 ? 'start' : side < 0 ? 'end' : 'center'
}

// the type motion each section gets (experience.css .k-*)
const MOTION = { hero: 'rise', case: 'fill', dial: 'mask', subdials: 'track', crown: 'slide', bracelet: 'path', open: 'scatter', movement: 'focus', parts: 'scatter', together: 'gather', specs: 'count', final: 'mask' }

const seeded = (i) => {
  const x = Math.sin(i * 12.9898 + 78.233) * 43758.5453
  return x - Math.floor(x)
}

/** A headline split for motion: letters in Latin, whole words in Arabic (shaping must not break). */
function Split({ text, lang, as: Tag = 'span', className = '', dir }) {
  // words stay whole (a line may break only between them); Latin words split into letters, Arabic words stay one unit
  const words = useMemo(() => text.split(' ').map((w) => (lang === 'ar' ? [w] : Array.from(w))), [text, lang])
  const n = words.reduce((a, w) => a + w.length, 0)
  let k = 0
  return (
    <Tag className={className} aria-label={text} dir={dir}>
      {words.map((units, wi) => (
        <span key={wi} className="w" aria-hidden="true">
          {units.map((u, i) => {
            const idx = k++
            return (
              <span
                key={i}
                className="ch"
                style={{ '--i': (idx / Math.max(1, n - 1)).toFixed(3), '--sx': Math.round((seeded(idx + 1) - 0.5) * 320), '--sy': Math.round((seeded(idx + 7) - 0.5) * 220), '--r': Math.round((seeded(idx + 13) - 0.5) * 40) }}
              >
                {u}
              </span>
            )
          })}
        </span>
      )).reduce((acc, el, wi) => (wi === 0 ? [el] : [...acc, ' ', el]), [])}
    </Tag>
  )
}

/** The number in a recorded spec value ("44 mm" → 44, "mm"), or null. */
const numberOf = (fact, lang) => {
  const m = /(\d+(?:[.,]\d+)?)\s*(.*)$/.exec(fact?.value?.en || '')
  if (!m) return null
  const local = fact?.value?.[lang] || fact?.value?.en || ''
  const unit = (local.replace(/^[\d٠-٩.,]+\s*/, '') || m[2]).trim()
  return { n: Number(m[1].replace(',', '.')), unit, label: fact.label[lang] || fact.label.en }
}

function Poster({ alt, eager }) {
  return (
    <picture className="exp-poster">
      <source media="(max-aspect-ratio: 1/1)" type="image/avif" srcSet={`${ASSETS.expPoster.phone.base}.avif 1x, ${ASSETS.expPoster.phone.base}@2x.avif 2x`} />
      <source media="(max-aspect-ratio: 1/1)" type="image/webp" srcSet={`${ASSETS.expPoster.phone.base}.webp 1x, ${ASSETS.expPoster.phone.base}@2x.webp 2x`} />
      <source type="image/avif" srcSet={`${ASSETS.expPoster.wide.base}.avif`} />
      <source type="image/webp" srcSet={`${ASSETS.expPoster.wide.base}.webp`} />
      <img src={`${ASSETS.expPoster.wide.base}.webp`} width={ASSETS.expPoster.wide.w} height={ASSETS.expPoster.wide.h} alt={alt} fetchPriority={eager ? 'high' : 'auto'} loading={eager ? 'eager' : 'lazy'} decoding="async" />
    </picture>
  )
}

export default function GrandPrixExperience({ model, fontClass = '' }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const isRTL = lang === 'ar'
  const t = COPY[lang]
  const L = t.labels
  const calm = useSyncExternalStore(subscribeMotion, readMotion, serverMotion)
  const router = useRouter()

  const { cart, addItem, updateQuantity } = useCart()
  const showToast = useToastStore((s) => s.showToast)
  const [busy, setBusy] = useState(null)
  const [armed, setArmed] = useState(false)
  const [live, setLive] = useState(false)
  const [phone, setPhone] = useState(false)
  const [wide, setWide] = useState(false)
  // the 3D's progress, as a small line (never a screen): 'model' → 'sharp' (the full textures) → 'done'
  const [loader, setLoader] = useState('model')
  const [progress, setProgress] = useState(0)
  const [hint, setHint] = useState(true) // from the first paint, until the first scroll
  const lenisRef = useRef(null)

  const root = useRef(null)
  const flow = useRef(null)
  const stage = useRef(null)
  const backdropEl = useRef(null)
  const sectionEls = useRef([])
  const calloutEls = useRef({}) // `${section}:${i}` → { g, line, ring, label }
  const countEls = useRef({})
  const current = useRef({ index: -1 })
  const idleRef = useRef(1) // the idle weight at the page's position (1 at either end), for the touch drag
  const flowBox = useRef({ top: 0, height: 0 }) // the flow's box, measured by a ResizeObserver — never in the loop

  const name = model.title.en // the product's name is always English, on the Arabic page too (Michael, 2026-10-29)
  const fmt = useCallback((v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US'), [isRTL])
  const currency = isRTL ? 'ج.م' : 'EGP'
  const stockLine = !model.stock.inStock ? L.soldOut : model.stock.total <= 3 ? L.lowStock(fmt(model.stock.total)) : L.inStock

  // the numerals the page counts to: the record's figures only
  const numerals = useMemo(() => {
    const find = (sec, key) => model.captions[sec]?.facts.find((f) => f.key === key)
    return {
      case: [numberOf(find('case', 'case_size'), lang), numberOf(find('case', 'case_thickness'), lang)].filter(Boolean),
      bracelet: [numberOf(find('bracelet', 'band_width'), lang), numberOf(find('bracelet', 'band_length'), lang)].filter(Boolean),
    }
  }, [model, lang])
  const allNumerals = useMemo(() => [...numerals.case, ...numerals.bracelet], [numerals])

  // the ghost words inside the canvas, in the page's own display face
  const words = useMemo(() => Object.entries(WORDS[lang]).map(([id, text]) => ({ id, text, outline: id !== 'specs' })), [lang])
  const wordFont = isRTL ? '400 360px "El Messiri", serif' : '300 380px "Cormorant Garamond", Georgia, serif'

  // ViewContent once, as the product page fires it for a product view (an existing event, the same payload).
  useEffect(() => {
    trackViewContent({ id: model.id, name: model.title.en, value: model.price.final })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [model.id])

  // The site's header leaves this page (the Home control replaces it); the body carries the flag for the CSS.
  useEffect(() => {
    document.body.classList.add('exp-film')
    return () => document.body.classList.remove('exp-film')
  }, [])

  // The stage mounts on idle, after first paint; the phone variant and the wide layout are decided then.
  useEffect(() => {
    if (calm) return undefined
    // right after hydration, not on idle (a throttled phone found idle seconds later): the poster is painted by then
    const ric = (cb) => setTimeout(cb, 0)
    const cic = clearTimeout
    const decide = () => {
      setPhone(window.matchMedia('(max-width: 767px)').matches)
      setWide(window.matchMedia('(min-width: 1024px)').matches)
    }
    const id = ric(() => {
      decide()
      setArmed(true)
    })
    window.addEventListener('resize', decide)
    return () => {
      cic(id)
      window.removeEventListener('resize', decide)
    }
  }, [calm])

  // Every frame from the stage: the callout lines follow their parts; the scatter amount follows the explode.
  // The 3D's progress (2026-10-29, Michael: "16 s on 4G is too long"): the page is open from the first paint — the
  // poster (the opening's own frame) and the words — and scrolls at once; nothing waits on the 3D. A small line shows
  // the first model's loading (0–60 %), then the full textures (60–100 %), and leaves when they are in.
  const onProgress = useCallback((pct) => {
    const v = Math.round(Math.min(100, pct) * 0.6)
    setProgress((prev) => (v > prev ? v : prev))
  }, [])
  const onUpgrade = useCallback((fraction, done) => {
    setProgress((prev) => Math.max(prev, Math.round(60 + fraction * 40)))
    if (done) setLoader('done')
  }, [])
  useEffect(() => {
    if (!hint) return undefined
    const off = () => {
      if (window.scrollY > 8) setHint(false)
    }
    window.addEventListener('scroll', off, { passive: true })
    return () => window.removeEventListener('scroll', off)
  }, [hint])

  const firstFrame = useRef(false)
  const onFrame = useCallback((f, points) => {
    const secId = f.section.id
    const local = f.section.local
    const bg = backdropEl.current
    if (bg) {
      const sc = f.scene
      bg.style.setProperty('--bg-top', sc.top)
      bg.style.setProperty('--bg-bottom', sc.bottom)
      bg.style.setProperty('--bg-light', sc.light)
      bg.style.setProperty('--bg-x', `${(sc.lightPos[0] * 100).toFixed(1)}%`)
      bg.style.setProperty('--bg-y', `${((1 - sc.lightPos[1]) * 100).toFixed(1)}%`)
      bg.style.setProperty('--bg-glow', (0.18 + sc.glow * 0.35 + sc.flare * 0.5).toFixed(3))
    }
    const on = local > 0.12 && local < 0.92
    for (const [key, el] of Object.entries(calloutEls.current)) {
      const [sec, idx] = key.split(':')
      const c = CALLOUTS[sec]?.[Number(idx)]
      const pt = c && points[c.anchor]
      const show = on && sec === secId && pt && pt[2]
      if (!show) {
        if (el.g.style.opacity !== '0') el.g.style.opacity = '0'
        if (el.label && el.label.style.opacity !== '0') el.label.style.opacity = '0'
        continue
      }
      const x = pt[0]
      const y = pt[1]
      const k = Number(idx)
      const W = window.innerWidth
      const narrow = W < 768
      let dx = (isRTL ? -1 : 1) * (k % 2 === 0 ? (narrow ? 70 : 120) : narrow ? -64 : -110)
      // a label never leaves the screen: flip it to the other side of its part near an edge
      if (dx < 0 ? x + dx < 170 : x + dx > W - 170) dx = -dx
      const dy = k % 2 === 0 ? -90 : 80
      const lx = Math.min(W - 12, Math.max(12, x + dx))
      const ly = Math.min(window.innerHeight - 40, Math.max(60, y + dy))
      el.line.setAttribute('x1', x.toFixed(1))
      el.line.setAttribute('y1', y.toFixed(1))
      el.line.setAttribute('x2', lx.toFixed(1))
      el.line.setAttribute('y2', ly.toFixed(1))
      el.ring.setAttribute('cx', x.toFixed(1))
      el.ring.setAttribute('cy', y.toFixed(1))
      // the label's own width, measured once (it never changes): the label is clamped inside the screen by it — the
      // long ones ("Chronograph seconds hand") ran off a 360 px phone (safe-area check, 2026-10-29)
      if (el.label && !el.lw) el.lw = el.label.offsetWidth || 1
      const lw = el.lw || 0
      const left = Math.min(W - 12 - lw, Math.max(12, dx > 0 ? lx + 10 : lx - 10 - lw))
      el.label.style.transform = `translate(${left.toFixed(1)}px, ${(ly - 12).toFixed(1)}px)`
      const reveal = Math.min(1, Math.max(0, (local - 0.12) * 5))
      el.g.style.opacity = reveal.toFixed(3)
      if (el.label) {
        el.label.style.opacity = reveal.toFixed(3)
        if (!el.label.classList.contains('is-placed')) el.label.classList.add('is-placed') // placed: may show
      }
      el.line.style.strokeDashoffset = (400 * (1 - reveal)).toFixed(1)
    }
    // the first frame the stage has drawn, with the scene's colours and the callouts placed: the full textures next
    if (!firstFrame.current) {
      firstFrame.current = true
      setLoader((l) => (l === 'model' ? 'sharp' : l))
    }
  }, [isRTL])

  // The pointer, for the idle poses at either end (no touch: a phone drifts on its own, in the stage).
  useEffect(() => {
    if (calm) return undefined
    const move = (e) => {
      if (e.pointerType === 'touch') return
      stage.current?.setPointer((e.clientX / window.innerWidth) * 2 - 1, (e.clientY / window.innerHeight) * 2 - 1)
    }
    const leave = () => stage.current?.setPointer(0, 0)
    window.addEventListener('pointermove', move, { passive: true })
    document.documentElement.addEventListener('pointerleave', leave)
    return () => {
      window.removeEventListener('pointermove', move)
      document.documentElement.removeEventListener('pointerleave', leave)
    }
  }, [calm])

  // A touch drag at either idle end turns the watch the way the mouse leans it: horizontal travel only — a vertical
  // pan is the page's scroll (touch-action: pan-y on the root; the browser then cancels the pointer and the drag ends)
  useEffect(() => {
    if (calm) return undefined
    const el = root.current
    if (!el) return undefined
    let active = false
    let lastX = 0
    let pointerId = null
    const down = (e) => {
      if (e.pointerType !== 'touch' || idleRef.current < 0.5) return
      active = true
      lastX = e.clientX
      pointerId = e.pointerId
      stage.current?.dragStart()
    }
    const move = (e) => {
      if (!active || e.pointerId !== pointerId) return
      const dx = (e.clientX - lastX) / Math.max(1, window.innerWidth)
      lastX = e.clientX
      stage.current?.dragMove(dx) // physical: the near side follows the finger, in either language
    }
    const up = (e) => {
      if (!active || (pointerId !== null && e.pointerId !== pointerId)) return
      active = false
      pointerId = null
      stage.current?.dragEnd()
    }
    el.addEventListener('pointerdown', down, { passive: true })
    el.addEventListener('pointermove', move, { passive: true })
    el.addEventListener('pointerup', up, { passive: true })
    el.addEventListener('pointercancel', up, { passive: true })
    return () => {
      el.removeEventListener('pointerdown', down)
      el.removeEventListener('pointermove', move)
      el.removeEventListener('pointerup', up)
      el.removeEventListener('pointercancel', up)
    }
  }, [calm])

  // One rAF loop: Lenis, the scroll → the stage's progress and clock, the active section's --t, the numerals.
  useEffect(() => {
    if (calm) return undefined
    const el = flow.current
    if (!el) return undefined
    let lenis = null
    let raf = 0
    let alive = true
    import('lenis').then(({ default: Lenis }) => {
      if (!alive) return
      lenis = new Lenis({ lerp: 0.085, smoothWheel: true, syncTouch: false })
      lenisRef.current = lenis
    })
    const sections = sectionEls.current
    let lastIndex = -1
    let lastY = -1
    let frame = 0
    // the flow's box, measured when it changes — reading offsetHeight in the loop after the previous frame's style
    // writes forced a layout every frame (Michael's console: "Forced reflow" 32–59 ms)
    const measure = () => {
      flowBox.current = { top: el.offsetTop, height: el.offsetHeight }
    }
    measure()
    const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(measure) : null
    ro?.observe(el)
    window.addEventListener('resize', measure)
    const tick = (time) => {
      raf = requestAnimationFrame(tick)
      const y = window.scrollY // read before anything writes
      lenis?.raf(time)
      frame++
      const vh = window.innerHeight || 1
      const span = Math.max(1, flowBox.current.height - vh)
      const p = Math.min(1, Math.max(0, (y - flowBox.current.top) / span))
      idleRef.current = idleAt(p)
      const moved = y !== lastY
      lastY = y
      stage.current?.setProgress(p)
      // the environment moves on its own: every frame while scrolling, every second frame at rest (phones every third)
      if (moved || frame % (phone ? 3 : 2) === 0) stage.current?.tick(time / 1000)
      const s = sectionAt(p)
      if (s.index !== lastIndex) {
        sections.forEach((node, i) => {
          if (!node) return
          node.dataset.state = i < s.index ? 'after' : i > s.index ? 'before' : 'in'
          if (i !== s.index) node.style.setProperty('--t', i < s.index ? '1' : '0')
        })
        if (root.current) root.current.dataset.section = s.id
        lastIndex = s.index
      }
      sections[s.index]?.style.setProperty('--t', s.local.toFixed(4))
      // numerals count up over the first 60 % of their section (kept as built, by Michael's note)
      for (const c of Object.values(countEls.current)) {
        if (!c || c.dataset.sec !== s.id) continue
        const e = Math.min(1, s.local / 0.6)
        const eased = 1 - Math.pow(1 - e, 3)
        const v = Math.round(Number(c.dataset.n) * eased)
        const txt = v.toLocaleString(isRTL ? 'ar-EG' : 'en-US')
        if (c.textContent !== txt) c.textContent = txt
      }
    }
    raf = requestAnimationFrame(tick)
    return () => {
      alive = false
      cancelAnimationFrame(raf)
      ro?.disconnect()
      window.removeEventListener('resize', measure)
      lenis?.destroy()
      lenisRef.current = null
    }
  }, [calm, phone, isRTL])

  const addLine = useCallback(async () => {
    const line = cartLineFor(model, 1)
    const key = getItemKey({ product_id: model.id, variant_id: null })
    const existing = cart?.cart_item?.find((i) => getItemKey(i) === key)
    return existing ? updateQuantity(key, existing.quantity + 1) : addItem(line)
  }, [model, cart, addItem, updateQuantity])

  const handleAdd = useCallback(async () => {
    if (busy || !model.stock.inStock) return
    setBusy('add')
    try {
      const result = await addLine()
      if (result && result.ok === false) showToast(refusedAddMessage(result, isRTL), 'error')
      else showToast(isRTL ? 'تمت الإضافة إلى السلة!' : 'Added to cart!', 'success')
    } finally {
      setBusy(null)
    }
  }, [busy, model.stock.inStock, addLine, showToast, isRTL])

  const handleBuy = useCallback(async () => {
    if (busy || !model.stock.inStock) return
    setBusy('buy')
    try {
      const result = await addLine()
      if (result && result.ok === false) {
        showToast(refusedAddMessage(result, isRTL), 'error')
        return
      }
      router.push('/checkout')
    } finally {
      setBusy(null)
    }
  }, [busy, model.stock.inStock, addLine, showToast, isRTL, router])

  const price = (
    <span className="exp-price">
      <span className="exp-price__now">
        {fmt(model.price.final)} <small>{currency}</small>
      </span>
      {model.price.hasSale ? (
        <s className="exp-price__was">
          {fmt(model.price.amount)} {currency}
        </s>
      ) : null}
    </span>
  )

  const buttons = (compact = false) => (
    <div className={`exp-actions${compact ? ' exp-actions--bar' : ''}`}>
      <button type="button" className="exp-btn exp-btn--ghost" onClick={handleAdd} disabled={!!busy || !model.stock.inStock} aria-busy={busy === 'add'}>
        {!model.stock.inStock ? L.soldOut : busy === 'add' ? L.adding : L.addToCart}
      </button>
      <button type="button" className="exp-btn exp-btn--solid" onClick={handleBuy} disabled={!!busy || !model.stock.inStock} aria-busy={busy === 'buy'}>
        {busy === 'buy' ? L.adding : L.buyNow}
      </button>
    </div>
  )

  const numeral = (num, sec) => {
    if (!num) return null
    const key = `${sec}:${num.label}`
    return (
      <div className="exp-numeral" key={key}>
        <span className="exp-numeral__n">
          <span
            ref={(el) => {
              countEls.current[key] = el
            }}
            data-sec={sec}
            data-n={num.n}
            className="exp-numeral__count"
            style={{ minWidth: `${fmt(num.n).length}ch` }}
          >
            {fmt(num.n)}
          </span>
          <small>{num.unit}</small>
        </span>
        <span className="exp-numeral__label">{num.label}</span>
      </div>
    )
  }

  const facts = (id, except = []) => {
    const rows = (model.captions[id]?.facts || []).filter((f) => !except.includes(f.key))
    if (!rows.length) return null
    return (
      <dl className="exp-facts">
        {rows.map((r) => (
          <div key={r.key} className="exp-fact">
            <dt>{r.label[lang] || r.label.en}</dt>
            <dd>{r.value[lang] || r.value.en}</dd>
          </div>
        ))}
      </dl>
    )
  }
  const chips = (id) => {
    const items = model.captions[id]?.features || []
    if (!items.length) return null
    return (
      <ul className="exp-chips" aria-label={L.features}>
        {items.map((f) => (
          <li key={f.id}>{f.name[lang] || f.name.en}</li>
        ))}
      </ul>
    )
  }
  const chapter = (i) => (
    <span className="exp-chapter" aria-hidden="true">
      {String(i).padStart(2, '0')}
    </span>
  )

  const body = (s, i) => {
    const c = t.sections[s.id]
    switch (s.id) {
      case 'hero':
        // the opening: the logo centred above the watch, the short name below it, the price under that; the long
        // record name stays in the buy bar (and in the h1 for assistive tech and search)
        return (
          <div className="exp-hero">
            <div className="exp-hero__top">
              <img className="exp-logo" src={ASSETS.logo} width={ASSETS.logoWidth} height={ASSETS.logoHeight} alt={t.home.logoAlt} fetchPriority="high" decoding="async" />
              <p className="exp-label exp-hero__eyebrow">{c.eyebrow}</p>
            </div>
            <div className="exp-hero__bottom">
              <h1 className="exp-hero__title">
                <Split text="Grand Prix" lang="en" as="bdi" dir="ltr" className="exp-display exp-display--xl k-rise" />
                <Split text={model.modelNumber || '1513477'} lang="en" as="bdi" dir="ltr" className="exp-display exp-display--num k-rise" />
                <span className="sr-only">{name}</span>
              </h1>
              <div className="exp-hero__buy">
                {price}
                <span className={`exp-stock${model.stock.inStock ? '' : ' is-out'}`}>{stockLine}</span>
              </div>
            </div>
          </div>
        )
      case 'case':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l k-fill" data-text={c.title}>
              {c.title}
            </h2>
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
            <div className="exp-numerals">
              {numerals.case.map((n) => numeral(n, 'case'))}
            </div>
            {facts('case', ['case_size', 'case_thickness'])}
          </>
        )
      case 'dial':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l k-mask">
              {c.title.split(' ').map((w, k, arr) => (
                <span key={k} className="k-mask__w" style={{ '--i': (k / Math.max(1, arr.length - 1)).toFixed(3) }}>
                  <span>{w}</span>
                </span>
              ))}
            </h2>
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
            {facts('dial')}
            {chips('dial')}
          </>
        )
      case 'subdials':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <Split text={c.title} lang={lang} as="h2" className="exp-display exp-display--l k-track" />
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
            {chips('subdials')}
          </>
        )
      case 'crown':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l k-slide">
              {c.title.split(/,\s*/).map((w, k, arr) => (
                <span key={k} className="k-slide__w" style={{ '--i': (k / Math.max(1, arr.length - 1)).toFixed(3) }}>
                  {w}
                  {k < arr.length - 1 ? ',' : ''}
                </span>
              ))}
            </h2>
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
          </>
        )
      case 'bracelet':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l">{c.title}</h2>
            <svg className="exp-path" viewBox="0 0 600 120" aria-hidden="true">
              <path id={`exp-path-${lang}`} d="M 10 95 C 150 20, 300 120, 590 35" fill="none" />
              <text className="exp-path__text">
                <textPath href={`#exp-path-${lang}`} startOffset="0%">
                  {`${t.sections.bracelet.eyebrow} · ${c.title} · ${t.sections.bracelet.eyebrow} · ${c.title}`}
                </textPath>
              </text>
            </svg>
            <div className="exp-numerals">
              {numerals.bracelet.map((n) => numeral(n, 'bracelet'))}
            </div>
            {facts('bracelet', ['band_width', 'band_length'])}
          </>
        )
      case 'open':
      case 'parts':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <Split text={c.title} lang={lang} as="h2" className="exp-display exp-display--l k-scatter" />
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
            {facts(s.id)}
            {chips(s.id)}
          </>
        )
      case 'movement':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l k-focus">{c.title}</h2>
            <p className="exp-line exp-line--full k-focus">{c.line}</p>
            <p className="exp-line exp-line--short k-focus">{c.short}</p>
            {facts('movement')}
            {chips('movement')}
          </>
        )
      case 'together':
        return (
          <>
            {chapter(i)}
            <p className="exp-label">{c.eyebrow}</p>
            <Split text={c.title} lang={lang} as="h2" className="exp-display exp-display--l k-gather" />
            <p className="exp-line exp-line--full">{c.line}</p>
            <p className="exp-line exp-line--short">{c.short}</p>
          </>
        )
      case 'specs':
        return (
          <div className="exp-specs">
            <p className="exp-label">{c.eyebrow}</p>
            <h2 className="exp-display exp-display--l">{c.title}</h2>
            <div className="exp-numerals exp-numerals--row">
              {allNumerals.map((n) => numeral(n, 'specs'))}
            </div>
            {/* the rows in two halves: side by side on a wide screen, two pinned steps of the page's own scroll on a phone */}
            <div className="exp-specs__groups">
              {[0, 1].map((g) => {
                const rows = model.specRows[lang]
                const half = Math.ceil(rows.length / 2)
                return (
                  <dl key={g} className={`exp-specs__list exp-specs__list--${g + 1}`}>
                    {rows.slice(g * half, (g + 1) * half).map((r, k) => (
                      <div key={`${r.label}-${k}`}>
                        <dt>{r.label}</dt>
                        <dd>{r.value}</dd>
                      </div>
                    ))}
                  </dl>
                )
              })}
            </div>
            {/* with your watch: only when the product records its own warranty length (empty → nothing, by design) */}
            {boxLines(model, lang).length ? (
              <div className="exp-box">
                <p className="exp-label">{t.box.title}</p>
                <ul>
                  {boxLines(model, lang).map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </div>
            ) : null}
          </div>
        )
      case 'final':
        return (
          <div className="exp-final">
            <div className="exp-final__top">
              <img className="exp-logo" src={ASSETS.logo} width={ASSETS.logoWidth} height={ASSETS.logoHeight} alt={t.home.logoAlt} loading="lazy" decoding="async" />
              <p className="exp-label">{c.eyebrow}</p>
            </div>
            <h2 className="exp-display exp-display--l k-mask" dir="ltr">
              {'Grand Prix 1513477'.split(' ').map((w, k, arr) => (
                <span key={k} className="k-mask__w" style={{ '--i': (k / Math.max(1, arr.length - 1)).toFixed(3) }}>
                  <span>{w}</span>
                </span>
              ))}
            </h2>
            <p className="exp-line">{c.line}</p>
            <div className="exp-final__buy">
              {price}
              <span className={`exp-stock${model.stock.inStock ? '' : ' is-out'}`}>{stockLine}</span>
            </div>
            {buttons()}
            <Link href={model.productPath} className="exp-link">
              {L.productPage}
            </Link>
          </div>
        )
      default:
        return null
    }
  }

  const calloutList = Object.entries(CALLOUTS).flatMap(([sec, list]) => list.map((c, i) => ({ sec, i, c })))

  return (
    <div ref={root} className={`exp ${fontClass}${calm ? ' is-calm' : ''}${live ? ' is-live' : ''}`} dir={isRTL ? 'rtl' : 'ltr'} lang={lang} data-section="hero">
      <div ref={backdropEl} className="exp-backdrop" aria-hidden="true" />

      {/* ── The stage: the environment, the ghost words and the watch, one canvas ─── */}
      <div className="exp-stage" aria-hidden={live ? 'true' : undefined}>
        <div className="exp-stage__art">
          <Poster alt={L.posterAlt} eager />
        </div>
        {armed && !calm ? (
          <div className="exp-stage__canvas" aria-hidden="true">
            <WatchStage ref={stage} src={ASSETS.glbLite} upgrade={phone ? ASSETS.texPhone : ASSETS.texWide} onUpgrade={onUpgrade} env={ASSETS.env} phone={phone} mode="story" wide={wide ? (isRTL ? -1 : 1) : 0} words={phone ? [] : words} font={wordFont} wordColor="rgba(242, 237, 227, 0.16)" onReady={() => setLive(true)} onFail={() => { setLive(false); setLoader('done') }} onLost={() => setLive(false)} onRestored={() => setLive(true)} onFrame={onFrame} onProgress={onProgress} />
          </div>
        ) : null}
      </div>

      {/* ── The callouts: a ring on the part, a line, a label — placed every frame ─── */}
      {!calm ? (
        <div className="exp-callouts" aria-hidden="true">
          <svg className="exp-callouts__svg">
            {calloutList.map(({ sec, i }) => (
              <g
                key={`${sec}:${i}`}
                style={{ opacity: 0 }}
                ref={(g) => {
                  if (!g) return
                  calloutEls.current[`${sec}:${i}`] = { ...(calloutEls.current[`${sec}:${i}`] || {}), g, line: g.querySelector('line'), ring: g.querySelector('circle') }
                }}
              >
                <circle r="5" />
                <line pathLength="400" />
              </g>
            ))}
          </svg>
          {calloutList.map(({ sec, i, c }) => (
            <span
              key={`${sec}:${i}`}
              className="exp-callout__label"
              ref={(el) => {
                if (!el) return
                calloutEls.current[`${sec}:${i}`] = { ...(calloutEls.current[`${sec}:${i}`] || {}), label: el }
              }}
            >
              {c[lang] || c.en}
            </span>
          ))}
        </div>
      ) : null}

      {/* ── The 3D's progress: a small line, never a screen; gone when the full textures are in ─── */}
      {!calm ? (
        <div className={`exp-load${loader === 'done' ? ' is-done' : ''}`} role="status" aria-live="polite">
          <span className="exp-load__text">{isRTL ? `تحميل الساعة ثلاثية الأبعاد ${fmt(progress)}٪` : `Loading the 3D watch ${fmt(progress)}%`}</span>
          <span className="exp-load__bar" aria-hidden="true">
            <span style={{ transform: `scaleX(${Math.max(0.04, progress / 100).toFixed(3)})` }} />
          </span>
        </div>
      ) : null}

      {/* ── The scroll hint: from the first paint, until the first scroll ───────────── */}
      <p className={`exp-scroll${hint ? ' is-on' : ''}`} aria-hidden="true">
        <span>{isRTL ? 'مرّر لأسفل' : 'Scroll'}</span>
        <span className="exp-scroll__arrow">↓</span>
      </p>

      {/* ── Home ────────────────────────────────────────────────────────────── */}
      <Link href="/" className="exp-home">
        <span className="exp-home__arrow" aria-hidden="true">
          {isRTL ? '→' : '←'}
        </span>
        {isRTL ? 'الرئيسية' : 'Home'}
      </Link>

      {/* ── The flow: twelve sections, each pinning its composition for its length ─── */}
      <main ref={flow} className="exp-flow" style={{ '--exp-total-vh': TOTAL_VH }}>
        {SECTIONS.map((s, i) => (
          <section
            key={s.id}
            ref={(el) => {
              sectionEls.current[i] = el
            }}
            className={`exp-sec exp-sec--${s.id} is-${textSideOf(s)} m-${MOTION[s.id]}`}
            data-sec={s.id}
            data-state={i === 0 ? 'in' : 'before'}
            style={{ '--vh': s.vh, '--t': 0 }}
            aria-label={t.sections[s.id].title || t.sections[s.id].eyebrow}
          >
            <div className="exp-sec__pin">
              <div className="exp-sec__text">{body(s, i)}</div>
            </div>
          </section>
        ))}
      </main>

      {/* ── The buy bar, fixed at the bottom ──────────────────────────────────── */}
      <div className="exp-bar" role="region" aria-label={L.bar}>
        <div className="exp-bar__inner">
          <div className="exp-bar__text">
            <bdi dir="ltr" className="exp-bar__name exp-bar__name--full">{name}</bdi>
            <bdi dir="ltr" className="exp-bar__name exp-bar__name--short">{t.home.name}</bdi>
            {price}
          </div>
          {buttons(true)}
        </div>
      </div>
    </div>
  )
}

