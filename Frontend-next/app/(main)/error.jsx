'use client'
import { useUIStore } from '@/src/Store/uiStore'
import { localePath } from '@/src/utils/localePath'

// Shown when a page could not be built — since 2026-09-30 that includes core being unreachable or
// rate-limited, which used to show the 404 page instead (and told Google the product was gone). The
// response is a 5xx, so crawlers retry; the words say "temporarily", in the page's language.
export default function MainError({ reset }) {
  const language = useUIStore((s) => s.language)
  const ar = language === 'ar'
  return (
    <div className="wz-error" dir={ar ? 'rtl' : 'ltr'}>
      <div className="wz-error-inner">
        <h1 className="wz-error-title">{ar ? 'هذه الصفحة غير متاحة مؤقتاً' : 'This page is temporarily unavailable'}</h1>
        <p className="wz-error-text">
          {ar ? 'تعذّر تحميلها الآن. حاول مرة أخرى بعد لحظات.' : "We couldn't load it just now. Please try again in a moment."}
        </p>
        <div className="wz-error-actions">
          <button onClick={() => reset()} className="wz-error-retry">{ar ? 'حاول مرة أخرى' : 'Try Again'}</button>
          {/* Hard navigation (not next/link) is intentional in an error boundary: a
              full page load fully resets the crashed React tree. */}
          <a href={localePath('/', language)} className="wz-error-home">{ar ? 'العودة للرئيسية' : 'Back to Home'}</a>
          <a href={localePath('/listing', language)} className="wz-error-browse">{ar ? 'تصفّح المجموعة' : 'Browse Collection'}</a>
        </div>
      </div>
    </div>
  )
}
