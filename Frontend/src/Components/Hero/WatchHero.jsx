import { lazy, Suspense, useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import './WatchHero.css'

// The 3D canvas pulls in three.js + drei (~950 kB). Lazy-load it so the hero
// text/CTAs paint immediately and the heavy chunk streams in behind a spinner.
const WatchCanvas = lazy(() => import('./WatchCanvas'))

const WatchHero = () => {
  const navigate = useNavigate()
  const { language } = useUIStore()
  const isRTL = language === 'ar'

  // Defer the heavy 3D (three.js + Draco GLB) until AFTER first paint so it never
  // competes with the hero's LCP. The preloaded poster fills the slot immediately;
  // the canvas mounts on idle and crossfades in over it once the model is ready.
  const [mount3D, setMount3D] = useState(false)
  const [modelReady, setModelReady] = useState(false)
  useEffect(() => {
    const ric = window.requestIdleCallback || ((cb) => setTimeout(cb, 200))
    const cic = window.cancelIdleCallback || clearTimeout
    const id = ric(() => setMount3D(true))
    return () => cic(id)
  }, [])

  // Stable identity — a re-render of WatchHero (e.g. modelReady flip) must not
  // hand WatchCanvas a new onReady and churn it. mount3D only ever goes
  // false → true once, so the canvas mounts a single time and unmounts cleanly.
  const handleReady = useCallback(() => setModelReady(true), [])

  return (
    <div className="wz-hero" dir={isRTL ? 'rtl' : 'ltr'}>
      {/* Left — Text content */}
      <div className="wz-hero-text">
        {/* Top label */}
        <div className="wz-hero-label">
          <span className="wz-hero-label-line" />
          <span className="wz-hero-label-text">
            {isRTL ? 'إتقان سويسري · تأسست 2020' : 'Swiss Mastery · Est. 2020'}
          </span>
        </div>

        {/* Main headline */}
        <h1 className="wz-hero-headline">
          {isRTL ? (
            <>
              فنّ <span className="wz-hero-headline-accent">الدقة</span>
              <br />
              الخالدة
            </>
          ) : (
            <>
              The Art of
              <span className="wz-hero-headline-accent"> Timeless</span>
              <br />
              Precision
            </>
          )}
        </h1>

        {/* Body copy */}
        <p className="wz-hero-body">
          {isRTL
            ? 'اكتشف مجموعتنا المختارة من الساعات الفاخرة الأصلية — معتمدة ومضمونة وتُوصَّل إلى باب منزلك.'
            : 'Discover our curated collection of authentic luxury timepieces — certified, guaranteed, and delivered to your door.'}
        </p>

        {/* CTA buttons */}
        <div className="wz-hero-cta">
          <button className="wz-hero-cta-primary" onClick={() => navigate('/listing')}>
            {isRTL ? 'استكشف المجموعة' : 'Explore Collection'}
            <span className="wz-hero-cta-arrow">{isRTL ? '←' : '→'}</span>
          </button>
          <button className="wz-hero-cta-secondary" onClick={() => navigate('/listing')}>
            {isRTL ? 'تصفح العلامات' : 'View Brands'}
          </button>
        </div>
      </div>

      {/* Right — 3D Watch (lazy-loaded Three.js canvas). No spinner: the dark
          hero shows through until the model fades in, so the hero looks complete
          immediately and the model load never affects LCP/CLS. */}
      <div className="wz-hero-watch">
        {/* Lightweight PRELOADED poster = the hero's first paint in this slot, so
            the LCP is a cheap static image, not the three.js canvas. It crossfades
            out once the 3D model is ready (mounted on idle, below). */}
        <img
          className={`wz-hero-poster${modelReady ? ' is-hidden' : ''}`}
          src="/logo.svg"
          alt="Watchizer"
          width="180"
          height="55"
          fetchpriority="high"
          decoding="async"
        />
        {mount3D && (
          <Suspense fallback={null}>
            <WatchCanvas onReady={handleReady} />
          </Suspense>
        )}
      </div>

      {/* Corner brackets */}
      <div className="wz-hero-corner wz-hero-corner-tl" />
      <div className="wz-hero-corner wz-hero-corner-tr" />
      <div className="wz-hero-corner wz-hero-corner-bl" />
      <div className="wz-hero-corner wz-hero-corner-br" />
    </div>
  )
}

export default WatchHero
