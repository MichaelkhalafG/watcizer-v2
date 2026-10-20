'use client'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useRailScroll } from '@/src/Hooks/useRailScroll'
import { localizeHref } from '@/src/utils/localePath'
import './BannerHero.css'

/**
 * The home hero that draws the dashboard's banners — ONE component, BOTH storefronts (D1 + F1 and the
 * developer's addendum, 2026-10-20).
 *
 * ── What was wrong ──────────────────────────────────────────────────────────────────────────
 *
 * Three banners on the dashboard, one on the page, no dots and no arrows. The cause was not here:
 * core's `home` payload ended in `->first()` and sent ONE banner object, so the page could not
 * rotate what it was never given. The payload now carries `banners`, every active one in the
 * dashboard's order, and this draws them.
 *
 * ── Why it is a twin and not two copies ─────────────────────────────────────────────────────
 *
 * Until today the hero a shop drew was decided by which APP was deployed: Watchizer's home rendered
 * a 3D watch, Brand Fashion's rendered a banner, and a shop that wanted the other one needed a
 * developer. It is one value per storefront now — `settings.home.hero`, flipped on the dashboard's
 * Banners screen — and both apps render THIS file for it. So the rotation, the device filtering, the
 * drag and the crop are the same behaviour in both shops, and a fix lands in both at once.
 *
 * It depends on nothing either app lacks: the drag hook is a twin (`src/Hooks/useRailScroll`), the
 * locale helper is a twin (`src/utils/localePath`), and the per-banner link and alt text arrive
 * RESOLVED from core (`href`, `alt`) rather than being rebuilt from a category here — which is also
 * what fixed them. The first cut built `/category/{path}`, and a category's `path` is a slug PATH
 * (`women/bags`) while the route is one segment, so every banner below depth 1 would have 404ed.
 *
 * ── Which banners this device shows, and why the SERVER does not decide ─────────────────────
 *
 * Each banner carries `device`: `both`, `desktop` or `mobile`. The CDN in front of the API keeps
 * ONE COPY PER URL and ignores `Vary`, so an endpoint that filtered by user-agent would hand a
 * desktop visitor a cached phone answer. Every banner is therefore sent to everyone, and the
 * choosing happens here — in CSS, not in JavaScript:
 *
 *   • the slide for the other device is `display: none` at the breakpoint, so it is not visible
 *     AND it does not occupy a slide: it leaves the flow, so scroll-snap, the arrows and the dot
 *     count all see only this device's banners (the dots carry the same classes);
 *   • its IMAGE is not downloaded either, because a device-specific slide is a `<picture>` whose
 *     `<source media>` matches only that device and whose `<img>` fallback is a 1x1 transparent
 *     GIF — the browser resolves that before any network request. No flash, nothing wasted, and
 *     nothing to trade off;
 *   • the server renders the same markup for everyone, so the hydrated output cannot disagree
 *     with it, and resizing across the breakpoint is just CSS re-evaluating: the track keeps its
 *     height (one fixed aspect-ratio per device) and the controls re-count on the resize.
 *
 * The breakpoint is 1024 px, the one the hero CSS has always used.
 *
 * ── The rest of the behaviour ───────────────────────────────────────────────────────────────
 *
 * Autoplay every 6 s, and it stops the moment the shopper is doing anything: pointer over the
 * hero, keyboard focus inside it, the tab hidden, or `prefers-reduced-motion`. Dots and arrows are
 * real buttons with labels in the shopper's language. The track is a native scroll-snap container,
 * so a phone swipes it with its own momentum, and `useRailScroll` — the same hook the rails and
 * the strips use — adds the mouse drag. With ONE banner it renders an anchor and an image, no
 * track, no controls, no animation, in the same box.
 */

// Resolved before any request: the browser never fetches this, and it is what a device-specific
// slide shows on the device it is not for (where the slide is display:none anyway).
const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

const DESKTOP_MQ = '(min-width: 1024px)'
const AUTOPLAY_MS = 6000

const TEXT = {
  en: { region: 'Offers', prev: 'Previous banner', next: 'Next banner', go: (n) => `Go to banner ${n}` },
  ar: { region: 'العروض', prev: 'البانر السابق', next: 'البانر التالي', go: (n) => `انتقل إلى البانر ${n}` },
}

/**
 * A banner's alt text in the shopper's language. Core sends `{en, ar}` when the banner points at a
 * category and null when it does not: a banner has no alt-text field, and an empty alt on a
 * decorative image is better for a screen reader than an invented description.
 */
const altOf = (banner, lang) => {
  const alt = banner.alt
  if (!alt) return ''
  if (typeof alt === 'string') return alt
  return alt[lang] ?? alt.en ?? Object.values(alt)[0] ?? ''
}

function Picture({ banner, lang, priority }) {
  const alt = altOf(banner, lang)
  const src = banner.image?.src
  if (!src) return null
  // `both` is a plain image — every device downloads it, which is correct.
  if (banner.device !== 'desktop' && banner.device !== 'mobile') {
    // eslint-disable-next-line @next/next/no-img-element -- the dashboard's banner, an absolute asset-host URL
    return <img src={src} alt={alt} fetchPriority={priority ? 'high' : 'auto'} loading={priority ? 'eager' : 'lazy'} />
  }
  const media = banner.device === 'desktop' ? DESKTOP_MQ : '(max-width: 1023px)'
  return (
    <picture>
      <source media={media} srcSet={src} />
      {/* the device this banner is not for resolves to the blank and asks the network for nothing */}
      <img src={BLANK} alt={alt} fetchPriority={priority ? 'high' : 'auto'} loading={priority ? 'eager' : 'lazy'} />
    </picture>
  )
}

export default function BannerHero({ banners, lang, dir }) {
  const t = TEXT[lang === 'ar' ? 'ar' : 'en']
  const isRTL = dir === 'rtl'
  const list = Array.isArray(banners) ? banners : []
  const [trackRef, { atStart, atEnd, page }] = useRailScroll(dir)
  const [index, setIndex] = useState(0)
  const [visibleIds, setVisibleIds] = useState(() => list.map((b) => b.id))
  const [paused, setPaused] = useState(false)
  const [calm, setCalm] = useState(false)
  const rootRef = useRef(null)

  // How many slides this viewport actually shows, and which one is in view. Both are read from the
  // DOM rather than from `banners`, because the hiding is CSS: a slide the breakpoint removed has
  // no offsetParent, and the browser has already taken it out of the scroll flow.
  const recount = useCallback(() => {
    const el = trackRef.current
    if (!el) return
    // the ids of the slides this breakpoint is showing, in order — a dot belongs to a banner, and the
    // index below counts VISIBLE slides, so the two have to be matched by id rather than by position
    const shownIds = Array.from(el.children)
      .filter((c) => c.offsetParent !== null)
      .map((c) => Number(c.dataset.id))
    setVisibleIds((prev) => (prev.length === shownIds.length && prev.every((v, i) => v === shownIds[i]) ? prev : shownIds))
    const width = el.clientWidth || 1
    setIndex(Math.min(Math.max(Math.round(Math.abs(el.scrollLeft) / width), 0), Math.max(shownIds.length - 1, 0)))
  }, [trackRef])

  useEffect(() => {
    const el = trackRef.current
    if (!el) return undefined
    recount()
    const onScroll = () => recount()
    el.addEventListener('scroll', onScroll, { passive: true })
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(recount) : null
    ro?.observe(el)
    return () => {
      el.removeEventListener('scroll', onScroll)
      ro?.disconnect()
    }
  }, [recount, trackRef])

  // Reduced motion is a preference, not a one-off reading: a shopper can change it mid-session.
  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)')
    const on = () => setCalm(mq.matches)
    on()
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [])

  // A hidden tab must not advance: the shopper comes back to a hero that moved without them.
  useEffect(() => {
    const on = () => setPaused(document.visibilityState === 'hidden')
    document.addEventListener('visibilitychange', on)
    return () => document.removeEventListener('visibilitychange', on)
  }, [])

  useEffect(() => {
    if (calm || paused || visibleIds.length < 2) return undefined
    const id = setInterval(() => {
      const el = trackRef.current
      if (!el) return
      if (atEnd) el.scrollTo({ left: 0, behavior: 'smooth' })
      else page(1)
    }, AUTOPLAY_MS)
    return () => clearInterval(id)
  }, [calm, paused, visibleIds.length, atEnd, page, trackRef])

  const goTo = (i) => {
    const el = trackRef.current
    if (!el) return
    const left = el.clientWidth * i * (isRTL ? -1 : 1)
    el.scrollTo({ left, behavior: calm ? 'auto' : 'smooth' })
  }

  if (list.length === 0) return null

  // One banner: an anchor and an image, nothing to operate — but the SAME box, so the hero is the same
  // height whether the dashboard has one banner or four, and a cropped image fills it exactly.
  if (list.length === 1) {
    const b = list[0]
    return (
      <a className="banner-hero banner-hero--one" href={localizeHref(b.href || '/listing', lang)}>
        <Picture banner={b} lang={lang} priority />
      </a>
    )
  }

  const controls = visibleIds.length > 1

  return (
    <div
      className="banner-hero banner-hero--carousel"
      ref={rootRef}
      role="region"
      aria-roledescription="carousel"
      aria-label={t.region}
      onPointerEnter={() => setPaused(true)}
      onPointerLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={(e) => {
        if (!rootRef.current?.contains(e.relatedTarget)) setPaused(false)
      }}
    >
      <div className="banner-hero__track" ref={trackRef}>
        {list.map((b, i) => (
          <a
            key={b.id}
            data-id={b.id}
            className={`banner-hero__slide${b.device === 'desktop' || b.device === 'mobile' ? ` banner-hero__slide--${b.device}` : ''}`}
            href={localizeHref(b.href || '/listing', lang)}
            aria-label={altOf(b, lang) || undefined}
          >
            <Picture banner={b} lang={lang} priority={i === 0} />
          </a>
        ))}
      </div>

      {controls ? (
        <>
          <button
            type="button"
            className="banner-hero__arrow banner-hero__arrow--prev"
            onClick={() => page(-1)}
            disabled={atStart}
            aria-label={t.prev}
          >
            <Chevron flip={!isRTL} />
          </button>
          <button
            type="button"
            className="banner-hero__arrow banner-hero__arrow--next"
            onClick={() => page(1)}
            disabled={atEnd}
            aria-label={t.next}
          >
            <Chevron flip={isRTL} />
          </button>
          <div className="banner-hero__dots">
            {list.map((b) => {
              // where this banner sits among the ones this viewport shows — a mobile-only banner is
              // the 2nd slide on a phone and no slide at all on a desktop, and its dot follows it
              const at = visibleIds.indexOf(b.id)
              const on = at >= 0 && at === index
              return (
                <button
                  key={b.id}
                  type="button"
                  /* the same device class as its slide, so a hidden banner has no dot either */
                  className={`banner-hero__dot${b.device === 'desktop' || b.device === 'mobile' ? ` banner-hero__slide--${b.device}` : ''}${on ? ' is-on' : ''}`}
                  onClick={() => at >= 0 && goTo(at)}
                  aria-label={t.go(Math.max(at, 0) + 1)}
                  aria-current={on ? 'true' : undefined}
                />
              )
            })}
          </div>
        </>
      ) : null}
    </div>
  )
}

/** The same chevron the rails and the strips draw, so `flip` means the same thing everywhere. */
function Chevron({ flip = false }) {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={flip ? { transform: 'scaleX(-1)' } : undefined}>
      <path d="m9 6 6 6-6 6" />
    </svg>
  )
}
