'use client'
import { memo } from 'react'
import './Footer.css'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { FaFacebook, FaInstagram, FaWhatsapp } from 'react-icons/fa'
import { useUIStore } from '../../Store/uiStore'
import LanguageDropdown from '../LanguageDropdown/LanguageDropdowen'

const logo = '/logo.webp'

const WHATSAPP_URL = 'https://wa.me/201274550956'

function Footer() {
  const { language } = useUIStore()
  const router = useRouter()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const year = new Date().getFullYear()

  // Shop column → real listing routes only.
  const shopLinks = [
    { label: t('Watches', 'ساعات'), onClick: () => router.push('/category/Watches') },
    { label: t('Fashion', 'أزياء'), onClick: () => router.push('/category/Fashion') },
    { label: t('Offers', 'العروض'), onClick: () => router.push('/offers') },
    { label: t('New Arrivals', 'وصل حديثاً'), onClick: () => router.push('/listing?sort=newest') },
    { label: t('All Products', 'كل المنتجات'), onClick: () => router.push('/listing') },
  ]

  // Account column → real user/auth routes only.
  const accountLinks = [
    { label: t('Profile', 'الملف الشخصي'), to: '/account?tab=profile' },
    { label: t('My Orders', 'طلباتي'), to: '/account?tab=orders' },
    { label: t('Wishlist', 'قائمة الأمنيات'), to: '/account?tab=wishlist' },
    { label: t('Cart', 'سلة المشتريات'), to: '/cart' },
    { label: t('Sign In', 'تسجيل الدخول'), to: '/login' },
  ]

  return (
    <footer className="wz-foot" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-foot__accent" />

      <div className="wz-foot__main">
        {/* Column 1 — Brand */}
        <div className="wz-foot__col wz-foot__brand">
          <Link href="/" className="wz-foot__logo-link" aria-label="Watchizer">
            <img src={logo} alt="Watchizer" className="wz-foot__logo" width="130" height="38" />
          </Link>
          <p className="wz-foot__tagline">
            {t(
              "Egypt's premier destination for authentic luxury timepieces",
              'وجهتك الأولى للساعات الفاخرة الأصلية في مصر',
            )}
          </p>
          <div className="wz-foot__social">
            <a
              href="https://www.facebook.com/profile.php?id=100076267296916"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Facebook"
              className="wz-foot__social-btn"
            >
              <FaFacebook />
            </a>
            <a
              href="https://www.instagram.com/watchizer_eg/"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Instagram"
              className="wz-foot__social-btn"
            >
              <FaInstagram />
            </a>
          </div>
        </div>

        {/* Column 2 — Shop */}
        <div className="wz-foot__col">
          <h3 className="wz-foot__title">{t('Shop', 'تسوق')}</h3>
          <ul className="wz-foot__links">
            {shopLinks.map((link) => (
              <li key={link.label}>
                <button type="button" className="wz-foot__link" onClick={link.onClick}>
                  {link.label}
                </button>
              </li>
            ))}
          </ul>
        </div>

        {/* Column 3 — Account */}
        <div className="wz-foot__col">
          <h3 className="wz-foot__title">{t('Account', 'الحساب')}</h3>
          <ul className="wz-foot__links">
            {accountLinks.map((link) => (
              <li key={link.to}>
                <Link className="wz-foot__link" href={link.to}>
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        {/* Column 4 — Support */}
        <div className="wz-foot__col">
          <h3 className="wz-foot__title">{t('Support', 'الدعم')}</h3>
          <ul className="wz-foot__links">
            <li>
              <Link className="wz-foot__link" href="/blogs">
                {t('Blog', 'المدونة')}
              </Link>
            </li>
            <li>
              <Link className="wz-foot__link" href="/offers">
                {t('Latest Offers', 'أحدث العروض')}
              </Link>
            </li>
          </ul>
        </div>
      </div>

      {/* Bottom bar */}
      <div className="wz-foot__bottombar">
        <div className="wz-foot__bottom-row">
          <p className="wz-foot__copy">
            {t(
              `© ${year} Watchizer · All rights reserved`,
              `© ${year} واتشايزر · جميع الحقوق محفوظة`,
            )}
          </p>
          <div className="wz-foot__bottom-right">
            <LanguageDropdown />
          </div>
        </div>

        <p className="wz-foot__credit">
          {t('Designed & developed by ', 'تصميم وتطوير بواسطة ')}
          <a
            className="wz-foot__credit-name"
            href={WHATSAPP_URL}
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('Michael Khalaf', 'مايكل خلف')}
          </a>
        </p>
      </div>
    </footer>
  )
}

export default memo(Footer)
