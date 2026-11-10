'use client'
import { useEffect, useRef, useState } from 'react'
import { FiArrowUp } from 'react-icons/fi'
import { useUIStore } from '../../Store/uiStore'
import { FloatingSlot } from '../Floating/FloatingStack'

// Scroll-triggered "back to top" button for long pages (listing / PDP). Separate
// from ScrollToTop.jsx, which is the route-change auto-scroll (not a button).
// Appears once the user has scrolled past SHOW_AFTER, smooth-scrolls to the top,
// and is keyboard/screen-reader labelled.
//
// A FLOATING ELEMENT (2026-11-07): it lives in the page's one floating corner (FloatingStack), the lowest item of
// the column, so the Hot Deals switch and the wheel's button sit ABOVE it and nothing lands on anything. It is
// therefore not drawn at all on a product page, the cart or the checkout (src/lib/floating.js) — the product page's
// sticky buy bar used to be exactly where this button sat. While the page is at the top it keeps its place in the
// column, invisible and untouchable, so the items above it do not jump when it appears.
const SHOW_AFTER = 500 // px scrolled before the button appears

function BackToTop() {
  const [visible, setVisible] = useState(false)
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const ticking = useRef(false)

  useEffect(() => {
    const onScroll = () => {
      if (ticking.current) return
      ticking.current = true
      requestAnimationFrame(() => {
        setVisible(window.scrollY > SHOW_AFTER)
        ticking.current = false
      })
    }
    onScroll() // sync initial state (e.g. restored scroll position)
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  const toTop = () => {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' })
  }

  return (
    <FloatingSlot name="back-to-top" order={30}>
      <button
        type="button"
        onClick={toTop}
        aria-label={isRTL ? 'العودة إلى الأعلى' : 'Back to top'}
        title={isRTL ? 'العودة إلى الأعلى' : 'Back to top'}
        aria-hidden={!visible}
        tabIndex={visible ? 0 : -1}
        style={{
          width: '44px',
          height: '44px',
          display: 'grid',
          placeItems: 'center',
          borderRadius: '50%',
          border: 'none',
          background: '#262626',
          color: '#fff',
          fontSize: '18px',
          cursor: 'pointer',
          boxShadow: '0 6px 20px rgba(0,0,0,0.22)',
          opacity: visible ? 1 : 0,
          transform: visible ? 'translateY(0)' : 'translateY(8px)',
          pointerEvents: visible ? 'auto' : 'none',
          transition: 'opacity 180ms ease, transform 180ms ease',
        }}
      >
        <FiArrowUp aria-hidden="true" />
      </button>
    </FloatingSlot>
  )
}

export default BackToTop
