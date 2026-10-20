'use client'
import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * A horizontal track with "is there more?" knowledge and page-wise scrolling, direction-aware.
 * Returns the track ref apart from the plain state, so a caller never reads a ref during render.
 *
 * A TWIN since 2026-10-20: it lives in both storefront apps because the shared banner hero
 * (`src/Components/Home/BannerHero.jsx`) uses it, and a shared component cannot reach into one
 * app's rail module. Brand Fashion's rails, brands strip and category strip import it from here
 * through `BfRail.jsx`, which re-exports it under the name its own callers already use.
 *
 * ── It DRAGS with the mouse since 2026-10-20 (D3) ───────────────────────────────────────────
 *
 * The track is a native overflow-x container, so a phone could always swipe it — a browser does not
 * drag-scroll one with the MOUSE, which is why the arrows were the only way in on a desktop while
 * Watchizer's product rails (Embla, through the shared `Carousel`) dragged. Rather than put the
 * other strips on Embla — their arrows and look are the ones the developer wants kept — the drag is
 * added here, to the ONE hook every strip already used.
 *
 * What it has to get right, and does:
 *   • it never fights the browser. Pointer events only, and only for a MOUSE — touch and pen fall
 *     through to native scrolling, so a phone keeps its own momentum and a vertical swipe still
 *     scrolls the page (the drag never calls preventDefault on a touch).
 *   • a drag must not open what it dragged over. Past a small threshold it captures the pointer and
 *     swallows the click that follows, in the capture phase, once.
 *   • RTL is the browser's: `scrollLeft` is simply moved by the pointer's delta, whichever way the
 *     numbers run, so nothing here has to know about direction.
 *   • `scroll-snap-type: x mandatory` would fight a free drag, so snapping is suspended for the
 *     duration of one and restored on release — that is also what gives the release its glide.
 *
 * A consumer's own stylesheet carries the two cosmetic halves: `cursor: grab` / `grabbing` on a fine
 * pointer, and `user-drag: none` on the images and anchors inside the track.
 */
export function useRailScroll(dir = 'ltr') {
  const ref = useRef(null)
  const [edges, setEdges] = useState({ start: true, end: true })
  const measure = useCallback(() => {
    const el = ref.current
    if (!el) return
    const max = el.scrollWidth - el.clientWidth
    const pos = Math.abs(el.scrollLeft)
    setEdges({ start: pos <= 1, end: pos >= max - 1 })
  }, [])
  useEffect(() => {
    const el = ref.current
    if (!el) return undefined
    measure()
    let raf = 0
    const onScroll = () => {
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(measure)
    }
    el.addEventListener('scroll', onScroll, { passive: true })
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null
    ro?.observe(el)
    return () => {
      el.removeEventListener('scroll', onScroll)
      ro?.disconnect()
      cancelAnimationFrame(raf)
    }
  }, [measure])
  // ── mouse drag (D3) ───────────────────────────────────────────────────────────────────────
  useEffect(() => {
    const el = ref.current
    if (!el) return undefined

    let startX = 0
    let startScroll = 0
    let pointer = null
    let dragging = false
    let snap = ''

    const THRESHOLD = 4 // px before a press becomes a drag: a click must survive a shaky hand

    // Chrome drags a link or an image by itself, and that drag eats every pointer event after the
    // first move. The CSS says `user-drag: none`; this is the behaviour half, for the elements the
    // rule cannot reach and for engines that ignore it.
    const noDrag = (e) => e.preventDefault()

    const down = (e) => {
      // Mouse only. Touch and pen already scroll natively, and taking them over here is how a
      // carousel stops the page from scrolling vertically under a thumb.
      if (e.pointerType !== 'mouse' || e.button !== 0) return
      if (e.target.closest('input, select, textarea, [contenteditable]')) return
      pointer = e.pointerId
      startX = e.clientX
      startScroll = el.scrollLeft
      dragging = false
    }

    const move = (e) => {
      if (pointer !== e.pointerId) return
      const dx = e.clientX - startX
      if (!dragging) {
        if (Math.abs(dx) < THRESHOLD) return
        dragging = true
        snap = el.style.scrollSnapType
        el.style.scrollSnapType = 'none'   // a mandatory snap cannot be dragged across
        el.classList.add('is-dragging')
        el.setPointerCapture?.(e.pointerId)
      }
      el.scrollLeft = startScroll - dx
      e.preventDefault()
    }

    const up = (e) => {
      if (pointer !== e.pointerId) return
      pointer = null
      if (!dragging) return
      dragging = false
      el.style.scrollSnapType = snap     // restored: the track glides to the nearest card
      el.classList.remove('is-dragging')
      el.releasePointerCapture?.(e.pointerId)
      // Swallow the click this release is about to fire, so a drag never opens the card under it.
      const swallow = (ev) => { ev.stopPropagation(); ev.preventDefault() }
      el.addEventListener('click', swallow, { capture: true, once: true })
      setTimeout(() => el.removeEventListener('click', swallow, { capture: true }), 0)
    }

    el.addEventListener('dragstart', noDrag)
    el.addEventListener('pointerdown', down)
    el.addEventListener('pointermove', move)
    el.addEventListener('pointerup', up)
    el.addEventListener('pointercancel', up)
    return () => {
      el.removeEventListener('dragstart', noDrag)
      el.removeEventListener('pointerdown', down)
      el.removeEventListener('pointermove', move)
      el.removeEventListener('pointerup', up)
      el.removeEventListener('pointercancel', up)
    }
  }, [])

  const page = (sign) => {
    const el = ref.current
    if (!el) return
    const step = el.clientWidth * sign * (dir === 'rtl' ? -1 : 1)
    el.scrollBy({ left: step, behavior: 'smooth' })
  }
  return [ref, { atStart: edges.start, atEnd: edges.end, page }]
}

export default useRailScroll
