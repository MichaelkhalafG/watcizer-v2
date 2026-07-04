import { lazy, Suspense } from 'react'
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
        <Suspense fallback={null}>
          <WatchCanvas />
        </Suspense>
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
