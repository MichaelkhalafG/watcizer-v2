'use client'
import { memo } from 'react'
import Image from 'next/image'
import './Footer.css'
import Link from '@/src/Components/LocaleLink'
import { FaWhatsapp, FaInstagram, FaFacebook } from 'react-icons/fa'
import { useUIStore } from '../../Store/uiStore'
import { useTables } from '../../Hooks/queries/useTables'
import { useNav } from '../../Hooks/queries/useNav'
import { useHasOffers } from '../../Hooks/queries/useRunningOffers'
import { brandSlug, categorySlug } from '../../utils/slugs'
import { TRUST_PAGES } from '../../content/trustPages'

/*
 * The Brand Fashion footer — spec v2.10 §10.8 ("categories, brands, main pages, social. No newsletter field"),
 * §3 ("black does the work: footer"), §7.2 (white focus ring on ink). Reskinned per §17.2 on 2026-10-17 (the
 * identity pass): same place in the layout (desktop only — phones have the drawer and the bottom nav, see
 * app/(main)/desktop-footer.jsx), new columns and new styling.
 *
 * Every link is data or an existing route: the categories are the menu's category types (the same source and
 * the same slug function as the header's Nav, so the two never disagree), the brands are the ones with
 * products (`catalog/nav` brand_ids), the pages are the trust pages and the blog, the social links are the
 * contact page's own cards (src/content/trustPages.js — ONE place to change when the client's handles land;
 * today those cards still carry Watchizer's, a B8 item). No tagline: the copied one described a watch shop.
 */

const STR = {
  en: { categories: 'Categories', brands: 'Brands', pages: 'Pages', account: 'Account', all: 'All products', sale: 'Sale', offers: 'Offers', newest: 'New this week', blog: 'Blog', profile: 'My account', orders: 'My orders', cart: 'Cart', signIn: 'Sign in', follow: 'Follow us', rights: 'All rights reserved' },
  ar: { categories: 'الفئات', brands: 'العلامات التجارية', pages: 'الصفحات', account: 'الحساب', all: 'كل المنتجات', sale: 'تخفيضات', offers: 'العروض', newest: 'جديد هذا الأسبوع', blog: 'المدونة', profile: 'حسابي', orders: 'طلباتي', cart: 'السلة', signIn: 'تسجيل الدخول', follow: 'تابعنا', rights: 'جميع الحقوق محفوظة' },
}
const SOCIAL_ICON = { whatsapp: FaWhatsapp, instagram: FaInstagram, facebook: FaFacebook }

function Footer() {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const isRTL = lang === 'ar'
  const t = STR[lang]
  const year = new Date().getFullYear()
  const { data: tables = {} } = useTables()
  const { data: nav } = useNav()
  const hasOffers = useHasOffers()

  const label = (item, key) =>
    item?.translations?.find((x) => x.locale === language)?.[key] ??
    item?.translations?.find((x) => x.locale === 'en')?.[key] ??
    item?.[key] ??
    ''
  const categories = (tables?.categoryTypes || []).slice(0, 8)
  const brandIds = new Set(nav?.brand_ids || [])
  const brands = (tables?.brands || []).filter((b) => brandIds.has(b.id)).slice(0, 8)
  const social = (TRUST_PAGES.contact?.[lang]?.cards || []).filter((c) => SOCIAL_ICON[c.icon])

  const pages = [
    ['/about-us', TRUST_PAGES.about?.[lang]?.title],
    ['/contact-us', TRUST_PAGES.contact?.[lang]?.title],
    ['/blogs', t.blog],
    ['/privacy-policy', TRUST_PAGES.privacy?.[lang]?.title],
    ['/terms-and-conditions', TRUST_PAGES.terms?.[lang]?.title],
  ].filter(([, text]) => text)

  return (
    <footer className="bf-foot" dir={isRTL ? 'rtl' : 'ltr'} lang={lang}>
      <div className="bf-foot__inner">
        <div className="bf-foot__brand">
          <Link href="/" className="bf-foot__logo" aria-label="Brand Fashion">
            <Image src="/logo-lockup-white.svg" alt="Brand Fashion" width={96} height={70} />
          </Link>
          {social.length ? (
            <div className="bf-foot__social" aria-label={t.follow}>
              {social.map((c) => {
                const Icon = SOCIAL_ICON[c.icon]
                return (
                  <a key={c.icon} className="bf-foot__social-btn" href={c.href} target="_blank" rel="noopener noreferrer" aria-label={c.label}>
                    <Icon aria-hidden="true" />
                  </a>
                )
              })}
            </div>
          ) : null}
        </div>

        <nav className="bf-foot__col" aria-label={t.categories}>
          <h2 className="bf-foot__title">{t.categories}</h2>
          <ul className="bf-foot__links">
            {categories.map((ct) => (
              <li key={ct.id}>
                <Link className="bf-foot__link" href={`/category/${categorySlug(ct)}`}>{label(ct, 'category_type_name')}</Link>
              </li>
            ))}
            <li><Link className="bf-foot__link" href="/listing?sort=newest">{t.newest}</Link></li>
            <li><Link className="bf-foot__link" href="/listing?sale=1">{t.sale}</Link></li>
            {hasOffers ? <li><Link className="bf-foot__link" href="/offers">{t.offers}</Link></li> : null}
            <li><Link className="bf-foot__link" href="/listing">{t.all}</Link></li>
          </ul>
        </nav>

        {brands.length ? (
          <nav className="bf-foot__col" aria-label={t.brands}>
            <h2 className="bf-foot__title">{t.brands}</h2>
            <ul className="bf-foot__links">
              {brands.map((b) => (
                <li key={b.id}>
                  <Link className="bf-foot__link" href={`/brand/${brandSlug(b)}`} lang="en">{label(b, 'brand_name')}</Link>
                </li>
              ))}
            </ul>
          </nav>
        ) : null}

        <nav className="bf-foot__col" aria-label={t.pages}>
          <h2 className="bf-foot__title">{t.pages}</h2>
          <ul className="bf-foot__links">
            {pages.map(([href, text]) => (
              <li key={href}><Link className="bf-foot__link" href={href}>{text}</Link></li>
            ))}
          </ul>
        </nav>

        <nav className="bf-foot__col" aria-label={t.account}>
          <h2 className="bf-foot__title">{t.account}</h2>
          <ul className="bf-foot__links">
            <li><Link className="bf-foot__link" href="/account?tab=profile">{t.profile}</Link></li>
            <li><Link className="bf-foot__link" href="/account?tab=orders">{t.orders}</Link></li>
            <li><Link className="bf-foot__link" href="/cart">{t.cart}</Link></li>
            <li><Link className="bf-foot__link" href="/login">{t.signIn}</Link></li>
          </ul>
        </nav>
      </div>

      <div className="bf-foot__bottom">
        <p className="bf-foot__copy">© {year} Brand Fashion · {t.rights}</p>
        {/* Developer credit — bilingual, always shown in both languages (deliberate; the developer's own number) */}
        <p className="bf-foot__credit" dir="ltr">
          <span dir="rtl">تطوير و تصميم مايكل خلف</span>
          <span className="bf-foot__credit-sep" aria-hidden="true">|</span>
          <a className="bf-foot__credit-name" href="https://wa.me/201274550956" target="_blank" rel="noopener noreferrer" aria-label="Developed & Designed by Michael Khalaf — WhatsApp">
            <span>Developed &amp; Designed by Michael Khalaf</span>
            <FaWhatsapp aria-hidden="true" />
          </a>
        </p>
      </div>
    </footer>
  )
}

export default memo(Footer)
