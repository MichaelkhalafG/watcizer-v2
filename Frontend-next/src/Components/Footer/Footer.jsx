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

        {/* Developer credit — bilingual, always shown in both languages */}
        <p className="wz-foot__credit" dir="ltr">
          <span dir="rtl">تطوير و تصميم مايكل خلف</span>
          <span className="wz-foot__credit-sep" aria-hidden="true">|</span>
          <a
            className="wz-foot__credit-name"
            href={WHATSAPP_URL}
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Developed & Designed by Michael Khalaf — WhatsApp"
          >
            <span>Developed &amp; Designed by Michael Khalaf</span>
            <svg
              className="wz-foot__credit-wa"
              xmlns="http://www.w3.org/2000/svg"
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="currentColor"
              aria-hidden="true"
            >
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
            </svg>
          </a>
        </p>
      </div>
    </footer>
  )
}

export default memo(Footer)
