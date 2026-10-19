'use client'
import { useUIStore } from '@/src/Store/uiStore'
import { localePath } from '@/src/utils/localePath'
import { retryAfterError } from '@/src/utils/chunkError'
import { WHATSAPP } from '@/src/content/trustPages'

// Shown when a page could not be built — since 2026-09-30 that includes core being unreachable or
// rate-limited, which used to show the 404 page instead (and told Google the product was gone). The
// response is a 5xx, so crawlers retry; the words say "temporarily", in the page's language.
export default function MainError({ error, reset }) {
  const language = useUIStore((s) => s.language)
  const ar = language === 'ar'
  return (
    <div className="wz-error" dir={ar ? 'rtl' : 'ltr'}>
      <div className="wz-error-inner">
        {/* Brand Fashion (DIVERGED 2026-10-19, spec §10.8 "500"): the error dot, Try again, and WhatsApp in place of Browse */}
        <span className="bf-error-dot" aria-hidden="true" />
        <h1 className="wz-error-title">{ar ? 'هذه الصفحة غير متاحة مؤقتاً' : 'This page is temporarily unavailable'}</h1>
        <p className="wz-error-text">
          {ar ? 'تعذّر تحميلها الآن. حاول مرة أخرى بعد لحظات.' : "We couldn't load it just now. Please try again in a moment."}
        </p>
        <div className="wz-error-actions">
          <button onClick={() => retryAfterError(error, reset)} className="wz-error-retry">{ar ? 'حاول مرة أخرى' : 'Try Again'}</button>
          {/* Hard navigation (not next/link) is intentional in an error boundary: a
              full page load fully resets the crashed React tree. */}
          <a href={localePath('/', language)} className="wz-error-home">{ar ? 'العودة للرئيسية' : 'Back to Home'}</a>
          <a href={WHATSAPP} className="wz-error-browse" target="_blank" rel="noopener noreferrer">{ar ? 'تواصل معنا على واتساب' : 'Contact us on WhatsApp'}</a>
        </div>
      </div>
    </div>
  )
}
