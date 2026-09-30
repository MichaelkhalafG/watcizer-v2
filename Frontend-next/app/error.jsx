'use client'
import { useUIStore } from '@/src/Store/uiStore'
import { localePath } from '@/src/utils/localePath'

// In the page's language, like app/(main)/error.jsx (2026-09-30); the home link keeps it too (D7).
export default function GlobalError({ reset }) {
  const language = useUIStore((s) => s.language)
  const ar = language === 'ar'
  return (
    <div className="wz-error" dir={ar ? 'rtl' : 'ltr'}>
      <div className="wz-error-inner">
        <h1 className="wz-error-title">{ar ? 'حدث خطأ' : 'Something went wrong'}</h1>
        <p className="wz-error-text">
          {ar ? 'نعتذر، حدث خطأ غير متوقع. حاول مرة أخرى.' : "We're sorry — an unexpected error occurred. Please try again."}
        </p>
        <div className="wz-error-actions">
          <button onClick={() => reset()} className="wz-error-retry">{ar ? 'حاول مرة أخرى' : 'Try Again'}</button>
          {/* Hard navigation (not next/link) is intentional in an error boundary: a
              full page load fully resets the crashed React tree. */}
          <a href={localePath('/', language)} className="wz-error-home">{ar ? 'العودة للرئيسية' : 'Back to Home'}</a>
        </div>
      </div>
    </div>
  )
}
