'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '../../Store/uiStore'
import '../../PageViews/Auth/auth.css'

// Logo served from /public (Vite imported from src/assets; Next uses a URL string).
const logo = '/wordmark.svg'

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
          <Link href="/" aria-label="Brand Fashion">
            {/* An SVG wordmark, drawn as the file. No SVG goes through next/image (2026-10-30, GHSA-2464-fhr4-w9xw): next/image already served it unoptimised, as the file. */}
            <img src={logo} alt="Brand Fashion" className="wz-auth-brand-logo" width={190} height={58} decoding="async" />
          </Link>
          <span className="wz-auth-brand-rule" />
          <p className="wz-auth-brand-tagline">
            {/* Brand Fashion's own line (2026-10-19): it sold watches only ("luxury timepieces") on a shop of watches, fashion and accessories */}
            {isRTL ? 'ساعات وأزياء وإكسسوارات، توصيل لكل محافظات مصر' : 'Watches, fashion and accessories, delivered across Egypt'}
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
