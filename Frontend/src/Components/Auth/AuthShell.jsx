import { Link } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import logo from '../../assets/images/logo.webp'
import '../../Pages/Auth/auth.css'

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
          <Link to="/" aria-label="Watchizer">
            <img src={logo} alt="Watchizer" className="wz-auth-brand-logo" />
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
