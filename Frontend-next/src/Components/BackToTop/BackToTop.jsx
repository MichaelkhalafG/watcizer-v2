'use client'
import { useEffect, useRef, useState } from 'react'
import { FiArrowUp } from 'react-icons/fi'
import { useUIStore } from '../../Store/uiStore'

// Scroll-triggered "back to top" button for long pages (listing / PDP). Separate
// from ScrollToTop.jsx, which is the route-change auto-scroll (not a button).
// Appears once the user has scrolled past SHOW_AFTER, smooth-scrolls to the top,
// and is keyboard/screen-reader labelled. Position is logical (inset-inline-end)
// so it sits bottom-start-corner-mirrored under RTL.
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
    <button
      type="button"
      onClick={toTop}
      aria-label={isRTL ? 'العودة إلى الأعلى' : 'Back to top'}
      title={isRTL ? 'العودة إلى الأعلى' : 'Back to top'}
      style={{
        position: 'fixed',
        bottom: '24px',
        insetInlineEnd: '24px',
        zIndex: 1200,
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
        transform: visible ? 'translateY(0)' : 'translateY(12px)',
        pointerEvents: visible ? 'auto' : 'none',
        transition: 'opacity 0.25s ease, transform 0.25s ease',
      }}
      // Keep it out of the tab order / AT tree while hidden.
      aria-hidden={!visible}
      tabIndex={visible ? 0 : -1}
    >
      <FiArrowUp aria-hidden="true" />
    </button>
  )
}

export default BackToTop