'use client'
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import Link from '../../LocaleLink'
import { useUIStore } from '../../../Store/uiStore'
import { useLivePrices, useMenu } from '../../../Hooks/queries/usePromo'
import { livePrice, money, payOf, txt } from '../../../lib/campaign'
import { Chevron, GridIcon } from '../../Promo/icons'
import { megaBus } from './megaBus'
import { warmMenuImages } from '../../../lib/menuData'
import SalePane from './SalePane'
import './megaMenu.css'

// The mega menu (the twelve updates, Part 4, 2026-11-05). A TWIN. It replaces the "Shop" dropdown — one
// narrow column that scrolled inside itself.
//
// ONE MENU, NOT TWO (the developer's review, 2026-11-05): the nav row's own entries — each category, each gender,
// Brands, Sale — no longer have narrow dropdowns of their own. Pointing at one opens THIS panel on that entry
// (./megaBus.js), and "All categories" opens it on the first. THE HOVER CORRIDOR: the panel overlaps the bottom of
// the nav row by a few transparent pixels, so there is no gap to fall through on the way down; leaving starts a
// 280 ms close delay that arriving in the panel cancels; and while the panel is open, pointing at ANOTHER nav
// item switches to it only after 110 ms, so a pointer that crosses a neighbour on its way down does not change
// the panel under it. SALE has a pane of its own (./SalePane.jsx): the live campaigns, then the discounts by
// category and by brand, with core's counts.
//
//   leading side   a RAIL of the top level: the categories, the genders, Brands, On sale, New arrivals
//   middle         the pointed entry's sub-categories as PICTURE TILES in a grid, with their counts, and a
//                  row of that entry's brands under them
//   trailing side  a FEATURED CARD: a real product of that entry, its name and its real lowest price
//
// DATA: one request, `GET /api/v2/{storefront}/menu`, made the first time the menu is pointed at or
// focused (not on page load). A tile's picture is the category's own, or the newest in-stock product's
// (Part 5); a tile with neither shows its initial. Every count is core's.
//
// KEYBOARD: Enter, Space or ArrowDown on the trigger opens it and moves to the rail; ArrowUp/ArrowDown
// move along the rail; the arrow pointing INTO the panel (Right in English, Left in Arabic) enters the
// tiles; arrows move among the tiles, and the arrow pointing back returns to the rail; Esc closes and
// focus goes back to the trigger. Tab works as everywhere, and leaving the menu closes it.
//
// RTL: nothing is mirrored by hand. The grid is laid out with logical properties, so in Arabic the rail is
// on the right and the featured card on the left; only "which arrow key points inward" is asked of `dir`.
//
// `hrefs` — the links that differ between the two shops (a gender, a gender's sub-category, the sale page)
// are built by each shop's Nav, which owns its listing URL scheme. The rest are the clean routes.

export const STR = {
  en: {
    trigger: 'All categories',
    products: (n) => `${n} products`,
    viewAll: (name) => `View all ${name}`,
    brandsIn: 'Brands in this section',
    brands: 'Brands',
    allBrands: 'Every brand we stock',
    sale: 'Sale',
    upTo: (n) => `up to ${n}% off`,
    hot: 'Hot deals',
    flash: 'Flash sale',
    endsIn: 'Ends in',
    dealSub: (n, pct) => (pct > 0 ? `${n} products, up to ${pct}% off` : `${n} products`),
    saleSub: (n, pct) => (pct > 0 ? `${n} products on sale, up to ${pct}% off` : `${n} products on sale`),
    saleByCategory: 'On sale by category',
    saleByBrand: 'On sale by brand',
    onSale: (n) => `${n} on sale`,
    picks: (name) => `Top picks in ${name}`,
    newest: 'New arrivals',
    from: (x) => `From ${x}`,
    off: (n) => `${n}% off`,
    view: 'View product',
    loading: 'Loading the menu…',
    failed: 'The menu could not be loaded.',
    retry: 'Try again',
  },
  ar: {
    trigger: 'كل الفئات',
    products: (n) => `${n} منتج`,
    viewAll: (name) => `عرض كل ${name}`,
    brandsIn: 'علامات هذا القسم',
    brands: 'العلامات التجارية',
    allBrands: 'كل العلامات المتوفرة لدينا',
    sale: 'تخفيضات',
    upTo: (n) => `حتى ${n}%`,
    hot: 'عروض ساخنة',
    flash: 'تخفيضات خاطفة',
    endsIn: 'ينتهي خلال',
    dealSub: (n, pct) => (pct > 0 ? `${n} منتج، خصم حتى ${pct}%` : `${n} منتج`),
    saleSub: (n, pct) => (pct > 0 ? `${n} منتج مخفّض، خصم حتى ${pct}%` : `${n} منتج مخفّض`),
    saleByCategory: 'التخفيضات حسب القسم',
    saleByBrand: 'التخفيضات حسب الماركة',
    onSale: (n) => `${n} مخفّض`,
    picks: (name) => `مختارات من ${name}`,
    newest: 'وصل حديثًا',
    from: (x) => `يبدأ من ${x}`,
    off: (n) => `خصم ${n}%`,
    view: 'عرض المنتج',
    loading: 'جارٍ تحميل القائمة…',
    failed: 'تعذّر تحميل القائمة.',
    retry: 'إعادة المحاولة',
  },
}

export const DEFAULT_HREFS = {
  category: (c) => `/category/${c.slug}`,
  child: (c) => `/subtypes/${c.slug}`,
  brand: (b) => `/brand/${b.slug}`,
  product: (p) => `/product/${p.slug}`,
  gender: () => '/listing',
  genderChild: () => '/listing',
  sale: () => '/listing',
  saleCategory: () => '/listing',
  saleBrand: () => '/listing',
  newest: () => '/listing?sort=newest',
}

const CLOSE_DELAY = 280
const SWITCH_DELAY = 110
// …and when the pointer is AIMED at the panel (moving down from the nav row, or across the rail towards the pane), an
// entry it crosses on the way waits this long before it takes the panel — long enough to arrive, short enough that
// stopping on the entry still opens it.
const AIM_DELAY = 320
// The tile grid's columns for `n` sub-categories: one row up to eight (never fewer than five columns, so two tiles
// are not stretched across the pane), two rows up to sixteen, three beyond — and, on a wide pane (megaMenu.css asks
// the pane, not the screen), one row up to twelve. megaMenu.css caps a tile's picture.
const tileCols = (n) => (n <= 8 ? Math.max(n, 5) : n <= 16 ? Math.ceil(n / 2) : Math.ceil(n / 3))
const tileColsWide = (n) => (n <= 12 ? Math.max(n, 6) : n <= 24 ? Math.ceil(n / 2) : Math.ceil(n / 3))

/**
 * The top level, top to bottom — shared by the desktop rail and the phone drawer (MegaDrawer), so the two can
 * never list different things. An entry with `pane` opens its content; one without is a plain link.
 */
export function menuEntries(menu, lang, t, to) {
  if (!menu) return []
  const out = []
  for (const c of menu.categories || []) {
    out.push({ key: `c${c.id}`, kind: 'category', node: c, label: txt(c.name, lang), count: c.count, href: to.category(c), pane: true })
  }
  for (const g of menu.genders || []) {
    out.push({ key: `g${g.id}`, kind: 'gender', node: g, label: txt(g.name, lang), count: g.count, href: to.gender(g), pane: (g.children || []).length > 0 })
  }
  if ((menu.brands || []).length) out.push({ key: 'brands', kind: 'brands', label: t.brands, count: null, href: null, pane: true })
  if (menu.sale?.count > 0) out.push({ key: 'sale', kind: 'sale', label: t.sale, tag: menu.sale.max_discount > 0 ? t.upTo(menu.sale.max_discount) : null, href: to.sale(), pane: true })
  out.push({ key: 'new', kind: 'newest', label: t.newest, href: to.newest(), pane: false })
  return out
}

// `trigger` — draw the "All categories" button. FALSE on a computer since the review of 2026-11-05: every nav item
// opens this panel on itself, so the button was a second way into the same thing. The panel, the bus and the
// keyboard are all still here without it (Esc sends focus back to the nav item that opened the panel).
export default function MegaMenu({ hrefs = {}, onNavigate, trigger = true }) {
  // a pick's price as it holds now: a campaign price follows its campaign (2026-11-10)
  const livePriceMap = useLivePrices()
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  const t = STR[lang]
  const to = useMemo(() => ({ ...DEFAULT_HREFS, ...hrefs }), [hrefs])
  const panelId = useId()

  const [open, setOpen] = useState(false)
  // THE MENU IS READY BEFORE THE FIRST HOVER (item 36, 2026-11-05). The browser holds it for the session (useMenu:
  // the query cache and sessionStorage), the home's server render carries it, and on the first page of a visit it is
  // asked for once — when the page has loaded, never on the first hover, which is what showed "Loading the menu…".
  const [wanted, setWanted] = useState(false)
  // …asked for when the page has LOADED AND IS IDLE (the performance pass): during hydration the request, its 95 KB
  // answer and its slimming were main-thread work in the busiest second of the page. A hover or a key before that
  // asks at once (openNow).
  useEffect(() => {
    let idle = null
    const ask = () => setWanted(true)
    const arm = () => {
      idle = window.requestIdleCallback ? window.requestIdleCallback(ask, { timeout: 3000 }) : setTimeout(ask, 800)
    }
    if (document.readyState === 'complete') arm()
    else window.addEventListener('load', arm, { once: true })
    return () => {
      window.removeEventListener('load', arm)
      if (idle != null) {
        if (window.cancelIdleCallback && window.requestIdleCallback) window.cancelIdleCallback(idle)
        else clearTimeout(idle)
      }
    }
  }, [])
  // THE PANEL'S CONTENT IS BUILT WHEN IT IS FIRST WANTED (the performance pass, 2026-11-05): its data is in the page,
  // but ~40 links and a dozen pictures were rendered and hydrated on every page load, on phones too, for a panel
  // most visits never open. It is built the moment the pointer reaches the header (so it is there before any entry
  // is hovered), on the first open from the keyboard, and never later than the first open itself.
  const [built, setBuilt] = useState(false)
  const [active, setActive] = useState(0)
  const rootRef = useRef(null)
  const triggerRef = useRef(null)
  const openerRef = useRef(null) // what had focus when the panel was opened from the keyboard
  const railRef = useRef(null)
  const paneRef = useRef(null)
  const closeTimer = useRef(null)
  const pendingFocus = useRef(false)

  const { data: menu, isLoading, isError, refetch } = useMenu(wanted)

  // …and so are its pictures — WHEN THE POINTER IS HEADING FOR THE MENU, not on a clock (the follow-up of
  // 2026-11-06). The first section's pictures used to be asked for 2.5 s after every page load: 14 requests and
  // 61 KB on a computer for a panel most visits never open. They are now asked for the first time the pointer
  // moves in the upper part of the window (the header and the band under it — `nearMenu`), which is well before
  // it can reach an entry; every section's the moment it reaches the header, or focus does. A page that is only
  // read or scrolled asks for none. A few at a time (menuData.js), so a panel does not open on empty tiles.
  useEffect(() => {
    if (!menu) return undefined
    const header = rootRef.current?.closest('header')
    let last = 0
    const nearMenu = (e) => {
      if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return
      // one measurement at most every 120 ms: this runs on every pointer move until it has fired
      if (e.timeStamp - last < 120) return
      last = e.timeStamp
      const floor = header ? header.getBoundingClientRect().bottom : 0
      if (floor <= 0 || e.clientY > Math.max(floor + 240, window.innerHeight * 0.4)) return
      window.removeEventListener('pointermove', nearMenu)
      warmMenuImages(menu, 'first')
    }
    const all = () => {
      window.removeEventListener('pointermove', nearMenu)
      setBuilt(true)
      warmMenuImages(menu, 'first')
      warmMenuImages(menu, 'all')
    }
    window.addEventListener('pointermove', nearMenu, { passive: true })
    header?.addEventListener('pointerenter', all, { once: true })
    header?.addEventListener('focusin', all, { once: true })
    return () => {
      window.removeEventListener('pointermove', nearMenu)
      header?.removeEventListener('pointerenter', all)
      header?.removeEventListener('focusin', all)
    }
  }, [menu])

  // The rail, top to bottom. An entry with `pane` opens the middle; one without is a plain link.
  const entries = useMemo(() => menuEntries(menu, lang, t, to), [menu, lang, t, to])

  const current = entries[Math.min(active, Math.max(0, entries.length - 1))] || null

  const cancelClose = () => {
    if (closeTimer.current) clearTimeout(closeTimer.current)
    closeTimer.current = null
  }
  const openNow = useCallback(() => {
    cancelClose()
    setWanted(true)
    setBuilt(true)
    setOpen(true)
  }, [])
  const closeNow = useCallback((refocus = false) => {
    cancelClose()
    setOpen(false)
    if (refocus) (triggerRef.current || openerRef.current)?.focus?.()
  }, [])
  const closeSoon = () => {
    cancelClose()
    closeTimer.current = setTimeout(() => setOpen(false), CLOSE_DELAY)
  }
  useEffect(() => cancelClose, [])

  const railItems = () => Array.from(railRef.current?.querySelectorAll('[data-mm-rail]') || [])
  const tiles = () => Array.from(paneRef.current?.querySelectorAll('[data-mm-tile]') || [])
  const focusRail = (i) => {
    const items = railItems()
    if (!items.length) return
    const n = (i + items.length) % items.length
    setActive(n)
    items[n].focus()
  }

  // ── ONE MENU: the nav row's own entries open this panel on themselves (megaBus) ──
  const pendingTarget = useRef(null) // an entry asked for before the menu's data had arrived
  const switchTimer = useRef(null)
  const cancelSwitch = () => {
    if (switchTimer.current) clearTimeout(switchTimer.current)
    switchTimer.current = null
  }
  // WHERE THE POINTER IS HEADING (the hover corridor, 2026-11-05). Going from "Watches" to a tile at the far end of
  // its panel, the pointer crosses the neighbouring nav entries — and, inside the panel, the rail entries under the
  // one it left; each of them used to take the panel on the way. The last two pointer positions say which way it is
  // going; an entry crossed while AIMED at the panel waits AIM_DELAY, and arriving in the pane cancels the wait.
  const ptr = useRef({ x: 0, y: 0, px: 0, py: 0, t: 0 })
  useEffect(() => {
    if (!open) return undefined
    const onMove = (e) => {
      const p = ptr.current
      const now = e.timeStamp
      if (now - p.t > 40) {
        p.px = p.x
        p.py = p.y
        p.t = now
      }
      p.x = e.clientX
      p.y = e.clientY
    }
    document.addEventListener('mousemove', onMove, { passive: true })
    return () => document.removeEventListener('mousemove', onMove)
  }, [open])
  const headingDown = () => {
    const { x, y, px, py } = ptr.current
    return y - py > 2 && y - py > Math.abs(x - px) * 0.2
  }
  const headingToPane = () => {
    const { x, y, px, py } = ptr.current
    const dx = dir === 'rtl' ? px - x : x - px
    return dx > 2 && dx > Math.abs(y - py) * 0.5
  }
  // a rail entry pointed at: it takes the pane at once, unless the pointer is on its way across to the pane
  const pointRail = (i) => {
    cancelSwitch()
    if (i === active) return
    if (headingToPane()) switchTimer.current = setTimeout(() => setActive(i), AIM_DELAY)
    else setActive(i)
  }
  const indexOf = useCallback(
    (target) =>
      entries.findIndex((e) => {
        if (!target || e.kind !== target.kind) return false
        if (target.kind === 'category') return e.node?.legacy_id === target.legacyId || e.node?.slug === target.slug
        if (target.kind === 'gender') return txt(e.node?.name, 'en').toLowerCase() === String(target.name || '').toLowerCase()
        return true
      }),
    [entries],
  )
  useEffect(
    () =>
      megaBus.register({
        open: (target, { focus = false }) => {
          const wasOpen = open
          if (focus && typeof document !== 'undefined') openerRef.current = document.activeElement
          openNow()
          const i = indexOf(target)
          if (i < 0) {
            pendingTarget.current = { target, focus }
            return
          }
          cancelSwitch()
          const apply = () => {
            setActive(i)
            if (focus) requestAnimationFrame(() => focusRail(i))
          }
          // already open and only POINTED at: a short wait, so a pointer crossing a neighbour on its way down to
          // the panel does not change the panel under it
          if (wasOpen && !focus) switchTimer.current = setTimeout(apply, headingDown() ? AIM_DELAY : SWITCH_DELAY)
          else apply()
        },
        closeSoon: () => {
          cancelSwitch()
          closeSoon()
        },
      }),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- closeSoon and focusRail are this render's plain functions
    [open, openNow, indexOf],
  )
  // the data arrived after a nav item had asked for its entry
  useEffect(() => {
    if (!pendingTarget.current || !entries.length) return
    const { target, focus } = pendingTarget.current
    pendingTarget.current = null
    const i = indexOf(target)
    if (i >= 0) {
      setActive(i)
      if (focus) focusRail(i)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runs when the fetched rail first exists
  }, [entries.length])
  useEffect(() => cancelSwitch, [])

  // Esc anywhere inside; a click or a focus that lands outside closes it.
  useEffect(() => {
    if (!open) return undefined
    const onDown = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) closeNow()
    }
    document.addEventListener('pointerdown', onDown)
    return () => document.removeEventListener('pointerdown', onDown)
  }, [open, closeNow])

  const INWARD = dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight'
  const OUTWARD = dir === 'rtl' ? 'ArrowRight' : 'ArrowLeft'

  const onTriggerKey = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      openNow()
      // the rail may not be on the page yet (first open fetches it): try now, and again when it is
      requestAnimationFrame(() => focusRail(active))
      pendingFocus.current = true
    } else if (e.key === 'Escape' && open) {
      e.preventDefault()
      closeNow(true)
    }
  }
  useEffect(() => {
    if (open && pendingFocus.current && entries.length) {
      pendingFocus.current = false
      focusRail(active)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runs when the fetched rail first exists
  }, [open, entries.length])

  const onPanelKey = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      closeNow(true)
      return
    }
    const inRail = e.target.closest?.('[data-mm-rail]')
    const inTile = e.target.closest?.('[data-mm-tile]')
    if (inRail) {
      const i = railItems().indexOf(inRail)
      if (e.key === 'ArrowDown') { e.preventDefault(); focusRail(i + 1) }
      else if (e.key === 'ArrowUp') { e.preventDefault(); focusRail(i - 1) }
      else if (e.key === 'Home') { e.preventDefault(); focusRail(0) }
      else if (e.key === 'End') { e.preventDefault(); focusRail(-1) }
      else if (e.key === INWARD) {
        const list = tiles()
        if (list.length) { e.preventDefault(); list[0].focus() }
      }
      return
    }
    if (inTile) {
      const list = tiles()
      const i = list.indexOf(inTile)
      const row = (el) => Math.round(el.getBoundingClientRect().top)
      const move = (n) => { if (list[n]) { e.preventDefault(); list[n].focus() } }
      if (e.key === INWARD) move(i + 1)
      else if (e.key === OUTWARD) {
        if (i === 0 || row(list[i - 1]) !== row(inTile)) { e.preventDefault(); focusRail(active) }
        else move(i - 1)
      } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        // the tile in the next/previous ROW that sits nearest under/over this one
        const x = inTile.getBoundingClientRect().left
        const down = e.key === 'ArrowDown'
        const rows = list.filter((el) => (down ? row(el) > row(inTile) : row(el) < row(inTile)))
        if (!rows.length) return
        const target = down ? Math.min(...rows.map(row)) : Math.max(...rows.map(row))
        const best = rows.filter((el) => row(el) === target).sort((a, b) => Math.abs(a.getBoundingClientRect().left - x) - Math.abs(b.getBoundingClientRect().left - x))[0]
        if (best) { e.preventDefault(); best.focus() }
      }
    }
  }

  const go = () => {
    closeNow()
    onNavigate?.()
  }

  const node = current?.node
  const childHref = (child) => (current.kind === 'gender' ? to.genderChild(node, child) : to.child(child, node))
  const featured = node?.featured?.product ? node.featured : null
  const paneBrands = current?.kind === 'brands' ? menu?.brands || [] : node?.brands || []

  return (
    <div className={`mm${trigger ? '' : ' mm--bare'}`} ref={rootRef} dir={dir} lang={lang} onMouseEnter={openNow} onMouseLeave={closeSoon}>
      {trigger ? (
        <button
          type="button"
          ref={triggerRef}
          className="mm__trigger"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => (open ? closeNow() : openNow())}
          onKeyDown={onTriggerKey}
          onFocus={() => setWanted(true)}
          onMouseEnter={() => setActive(0)}
        >
          <GridIcon size={16} />
          <span>{t.trigger}</span>
        </button>
      ) : null}

      <div
        id={panelId}
        className="mm__panel"
        // NEVER A LOADING STATE (item 36): on the first page of a visit the menu's data arrives a moment after the page;
        // an entry hovered before that opens its panel the instant the data is in (the pending target below), not on
        // a "Loading…" box. Only a FAILED read shows the panel without data — with its retry button.
        hidden={!open || (!menu && !isError)}
        onMouseEnter={cancelSwitch}
        onKeyDown={onPanelKey}
        onBlur={(e) => {
          if (!rootRef.current?.contains(e.relatedTarget)) closeSoon()
        }}
        onFocus={cancelClose}
      >
        {/* nothing is drawn in the panel until it is first wanted — the same on the server and in the browser, whether
            or not this browser already holds the menu from an earlier page */}
        {!built ? null : !menu ? (
          <div className="mm__state" role="status">
            {isError ? (
              <>
                <span>{t.failed}</span>
                <button type="button" className="mm__retry" onClick={() => refetch()}>
                  {t.retry}
                </button>
              </>
            ) : (
              <span>{isLoading || wanted ? t.loading : ''}</span>
            )}
          </div>
        ) : (
          <>
            <ul className="mm__rail" ref={railRef} aria-label={t.trigger}>
              {entries.map((entry, i) => {
                const selected = entry === current
                const inner = (
                  <>
                    <span className="mm__rail-name">{entry.label}</span>
                    {entry.tag ? <span className="mm__rail-tag">{entry.tag}</span> : null}
                    {entry.count != null ? <span className="mm__rail-count">{entry.count}</span> : null}
                    {entry.pane ? (
                      <span className="mm__rail-caret" aria-hidden="true">
                        <Chevron flip={dir === 'rtl'} size={14} />
                      </span>
                    ) : null}
                  </>
                )
                return (
                  <li key={entry.key}>
                    {entry.href ? (
                      <Link
                        href={entry.href}
                        className={`mm__rail-item${selected ? ' is-active' : ''}`}
                        data-mm-rail=""
                        aria-current={selected && entry.pane ? 'true' : undefined}
                        onMouseEnter={() => pointRail(i)}
                        onFocus={() => setActive(i)}
                        onClick={go}
                        prefetch={false}
                      >
                        {inner}
                      </Link>
                    ) : (
                      <button
                        type="button"
                        className={`mm__rail-item${selected ? ' is-active' : ''}`}
                        data-mm-rail=""
                        aria-current={selected ? 'true' : undefined}
                        onMouseEnter={() => pointRail(i)}
                        onFocus={() => setActive(i)}
                        onClick={() => setActive(i)}
                      >
                        {inner}
                      </button>
                    )}
                  </li>
                )
              })}
            </ul>

            <div className={`mm__pane${featured ? '' : ' mm__pane--wide'}`} ref={paneRef} onMouseEnter={cancelSwitch}>
              {current?.pane ? (
                <>
                  <header className="mm__pane-head">
                    <div>
                      <p className="mm__pane-title">{current.label}</p>
                      <p className="mm__pane-sub">
                        {current.kind === 'brands' ? t.allBrands : current.kind === 'sale' ? t.saleSub(menu.sale.count, menu.sale.max_discount) : t.products(current.count)}
                      </p>
                    </div>
                    {current.href ? (
                      <Link className="mm__pane-all" href={current.href} onClick={go} prefetch={false}>
                        {t.viewAll(current.label)}
                      </Link>
                    ) : null}
                  </header>

                  {current.kind === 'sale' ? (
                    <SalePane sale={menu.sale} lang={lang} dir={dir} to={to} go={go} t={t} />
                  ) : current.kind === 'brands' ? (
                    <ul className="mm__brands">
                      {paneBrands.map((b) => (
                        <li key={b.id}>
                          <Link className="mm__brand" href={to.brand(b)} title={txt(b.name, lang)} data-mm-tile="" onClick={go} prefetch={false}>
                            <span className="mm__brand-logo" aria-hidden="true">
                              {b.logo?.src ? (
                                // eslint-disable-next-line @next/next/no-img-element -- brand logo, asset-host URL
                                <img src={b.logo.src} alt="" loading="lazy" />
                              ) : (
                                <span>{txt(b.name, 'en').slice(0, 2)}</span>
                              )}
                            </span>
                            <span className="mm__brand-name" lang="en">{txt(b.name, lang)}</span>
                            <span className="mm__tile-count">{b.count}</span>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <>
                      {/* the columns follow the number of tiles: one row up to eight, two up to sixteen, three beyond —
                          so a long section never makes the panel scroll and a short one is not a few lost tiles */}
                      <ul
                        className={`mm__tiles${(node.children || []).length <= 6 ? ' mm__tiles--few' : ''}${tileCols((node.children || []).length) === 6 ? ' mm__tiles--six' : ''}${tileCols((node.children || []).length) >= 7 ? ' mm__tiles--dense' : ''}`}
                        style={{ '--mm-cols': tileCols((node.children || []).length), '--mm-cols-wide': tileColsWide((node.children || []).length) }}
                      >
                        {(node.children || []).map((child) => (
                          <li key={child.id}>
                            <Link className="mm__tile" href={childHref(child)} data-mm-tile="" onClick={go} prefetch={false}>
                              <span className="mm__tile-pic" aria-hidden="true">
                                {child.image?.src ? (
                                  // eslint-disable-next-line @next/next/no-img-element -- category picture, asset-host URL
                                  <img src={child.image.src} alt="" loading="lazy" />
                                ) : (
                                  <span>{txt(child.name, lang).trim().charAt(0)}</span>
                                )}
                              </span>
                              <span className="mm__tile-name">{txt(child.name, lang)}</span>
                              <span className="mm__tile-count">{child.count}</span>
                            </Link>
                          </li>
                        ))}
                      </ul>
                      {/* A SECTION WITH FEW SUB-CATEGORIES is filled with real products of it (core: `picks`, the biggest
                          discounts in stock, else the newest) — never left as white space (the review of 2026-11-05) */}
                      {(node.children || []).length <= 6 && (node.picks || []).length ? (
                        <div className="mm__picks">
                          <p className="mm__chips-title">{t.picks(current.label)}</p>
                          <ul>
                            {node.picks.slice(0, 4).map((raw) => ({ ...raw, price: livePrice(raw.price, raw.id, livePriceMap) })).map((p) => (
                              <li key={p.id}>
                                <Link className="mm__pick" href={to.product(p)} data-mm-tile="" onClick={go} prefetch={false}>
                                  <span className="mm__pick-pic" aria-hidden="true">
                                    {/* eslint-disable-next-line @next/next/no-img-element -- product photo, asset-host URL */}
                                    <img src={p.image?.src} alt="" loading="lazy" />
                                    {p.price?.has_sale && p.price.discount_pct > 0 ? <span className="mm__tile-off">{t.off(p.price.discount_pct)}</span> : null}
                                  </span>
                                  <span className="mm__pick-brand" lang="en">{txt(p.brand?.name, 'en')}</span>
                                  <span className="mm__pick-title">{txt(p.title, lang)}</span>
                                  <span className="mm__pick-price">{money(payOf(p.price || {}), lang)}</span>
                                </Link>
                              </li>
                            ))}
                          </ul>
                        </div>
                      ) : null}
                      {paneBrands.length ? (
                        <div className="mm__chips">
                          <p className="mm__chips-title">{t.brandsIn}</p>
                          <ul>
                            {paneBrands.map((b) => (
                              <li key={b.id}>
                                <Link className="mm__chip" href={to.brand(b)} data-mm-tile="" onClick={go} prefetch={false} lang="en">
                                  {txt(b.name, lang)}
                                </Link>
                              </li>
                            ))}
                          </ul>
                        </div>
                      ) : null}
                    </>
                  )}
                </>
              ) : null}
            </div>

            {featured ? (
              <Link className="mm__feature" href={to.product(featured.product)} onClick={go} onMouseEnter={cancelSwitch} prefetch={false}>
                <span className="mm__feature-pic" aria-hidden="true">
                  {/* eslint-disable-next-line @next/next/no-img-element -- product photo, asset-host URL */}
                  <img src={featured.product.image?.src} alt="" loading="lazy" />
                </span>
                {livePrice(featured.product.price, featured.product.id, livePriceMap)?.has_sale && livePrice(featured.product.price, featured.product.id, livePriceMap).discount_pct > 0 ? (
                  <span className="mm__feature-off">{t.off(livePrice(featured.product.price, featured.product.id, livePriceMap).discount_pct)}</span>
                ) : null}
                <span className="mm__feature-brand" lang="en">{txt(featured.product.brand?.name, 'en')}</span>
                <span className="mm__feature-title">{txt(featured.product.title, lang)}</span>
                <span className="mm__feature-price">{t.from(money(featured.price_from, lang))}</span>
                <span className="mm__feature-cta">{t.view}</span>
              </Link>
            ) : null}
          </>
        )}
      </div>
    </div>
  )
}
