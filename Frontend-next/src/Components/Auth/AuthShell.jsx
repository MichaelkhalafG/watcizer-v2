'use client'
import Link from 'next/link'
import { useUIStore } from '../../Store/uiStore'
import '../../PageViews/Auth/auth.css'

// Logo served from /public (Vite imported from src/assets; Next uses a URL string).
const logo = '/logo.webp'

// Split-screen luxury auth layout: dark brand panel (left) + form (right).
export default function AuthShell({ title, subtitle, children }) {
  const { language } = useUIStore()
  const isRTL = language === 'ar'

  return (
    <div className="wz-auth" dir={isRTL ? 'rtl' : 'ltr'}>
      {/* Brand panel */}
      <aside className="wz-auth-brand">
        <div className="wz-auth-brand-grid" />
        <div className="wz-auth-brand-inner">
          <Link href="/" aria-label="Watchizer">
            <img src={logo} alt="Watchizer" className="wz-auth-brand-logo" width="190" height="58" loading="lazy" />
          </Link>
          <span className="wz-auth-brand-rule" />
          <p className="wz-auth-brand-tagline">
            {isRTL ? 'بوابتك إلى عالم الساعات الفاخرة' : 'Your gateway to luxury timepieces'}
          </p>
        </div>
      </aside>

      {/* Form panel */}
      <main className="wz-auth-main">
        <div className="wz-auth-card">
          <header className="wz-auth-head">
            <h1 className="wz-auth-title">{title}</h1>
            <p className="wz-auth-subtitle">{subtitle}</p>
          </header>
          {children}
        </div>
      </main>
    </div>
  )
}
