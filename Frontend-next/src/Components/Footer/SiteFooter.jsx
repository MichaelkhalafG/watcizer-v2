import Link from 'next/link'
import Image from 'next/image'
import { FaFacebook, FaInstagram, FaTiktok, FaWhatsapp } from 'react-icons/fa'
import { getServerV2Menu } from '@/src/lib/serverV2'
import { getServerTables } from '@/src/lib/serverCatalog'
import { requestLang } from '@/src/lib/requestLang'
import { localizeHref } from '@/src/utils/localePath'
import { txt } from '@/src/lib/campaign'
import { calmCase, footerDirectoryOf } from '@/src/lib/menuLinks'
import { displayName } from '@/src/lib/listingWords'
import { FOOTER } from '@/src/content/footer'
import LanguageDropdown from '../LanguageDropdown/LanguageDropdowen'
import PaymentBadges from '../Promo/PaymentBadges'
import FootContact from './FootContact'
import FootSection from './FootSection'
import './SiteFooter.css'

// THE SHOP'S FOOTER, ONE FOR EVERY WIDTH (Michael, 2026-11-19, part 2 of the three fixes). A TWIN, a SERVER component:
// every link is in the server HTML. What differs between the shops (the logo, the tagline, the Shop links, the social
// accounts, the colours) is each shop's own src/content/footer.js and Footer.css; the layout is this file.
//
//   1. the top row: the logo, the tagline, the customer-service line (the shop's number, call and WhatsApp), the
//      social icons; then Shop, Account and Support; then a compact Categories column — each main category with its
//      sub-categories behind a closed expander (every link stays in the HTML);
//   2. the brands row (core's `directory_brands`: indexable brand pages only, as the directory of 2026-11-14);
//   3. the ways to pay (the checkout's own list);
//   4. the bottom row: the copyright, the language switch and the developer's credit.
//
// On a phone the four columns are closed sections that open on a tap (FootSection); nothing is wider than the screen,
// and the last row keeps room under it for the floating corner (the wheel's button), so it never covers a link.
const SOCIAL_ICON = { facebook: FaFacebook, instagram: FaInstagram, tiktok: FaTiktok, whatsapp: FaWhatsapp }

const WORDS = {
  en: {
    shop: 'Shop', account: 'Account', support: 'Support', categories: 'Categories', brands: 'Brands', follow: 'Follow us',
    all: (name) => `All ${name}`, more: 'Sub-categories of', footer: 'Footer',
    profile: 'My account', orders: 'My orders', cart: 'Cart', signIn: 'Sign in',
    about: 'About us', contact: 'Contact us', faq: 'FAQ', warranty: 'Warranty', shipping: 'Shipping and delivery',
    returns: 'Returns and exchanges', blog: 'Blog', privacy: 'Privacy policy', terms: 'Terms and conditions',
    rights: 'All rights reserved',
  },
  ar: {
    shop: 'تسوّق', account: 'الحساب', support: 'الدعم', categories: 'الفئات', brands: 'الماركات', follow: 'تابعنا',
    all: (name) => `كل ${name}`, more: 'الأقسام الفرعية لـ', footer: 'تذييل الصفحة',
    profile: 'حسابي', orders: 'طلباتي', cart: 'السلة', signIn: 'تسجيل الدخول',
    about: 'من نحن', contact: 'تواصل معنا', faq: 'الأسئلة الشائعة', warranty: 'الضمان', shipping: 'الشحن والتوصيل',
    returns: 'الإرجاع والاستبدال', blog: 'المدونة', privacy: 'سياسة الخصوصية', terms: 'الشروط والأحكام',
    rights: 'جميع الحقوق محفوظة',
  },
}

export default async function SiteFooter() {
  const { urlLang } = await requestLang()
  const lang = urlLang === 'ar' ? 'ar' : 'en'
  const w = WORDS[lang]
  const [menu, tables] = await Promise.all([
    getServerV2Menu().then((body) => body?.data || null, () => null),
    getServerTables().catch(() => null),
  ])
  const { categories, brands } = footerDirectoryOf(menu, tables)
  const href = (h) => localizeHref(h, lang)
  const name = (n) => calmCase(txt(n, lang))
  const year = new Date().getFullYear()

  const account = [
    ['/account?tab=profile', w.profile],
    ['/account?tab=orders', w.orders],
    ['/cart', w.cart],
    ['/login', w.signIn],
  ]
  const support = [
    ['/about-us', w.about],
    ['/contact-us', w.contact],
    ['/faq', w.faq],
    ['/warranty', w.warranty],
    ['/shipping', w.shipping],
    ['/returns', w.returns],
    ['/blogs', w.blog],
    ['/privacy-policy', w.privacy],
    ['/terms-and-conditions', w.terms],
  ]
  const ShopExtra = FOOTER.ShopExtra || null
  const logo = FOOTER.logo

  return (
    <footer className="wz-sf" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang} aria-label={w.footer}>
      <div className="wz-sf__top">
        <div className="wz-sf__brand">
          <Link href={href('/')} className="wz-sf__logo" aria-label={FOOTER.name}>
            {logo.next ? (
              <Image src={logo.src} alt={FOOTER.name} width={logo.width} height={logo.height} quality={90} />
            ) : (
              // an SVG goes as the file, never through next/image (2026-10-30, GHSA-2464-fhr4-w9xw)
              <img src={logo.src} alt={FOOTER.name} width={logo.width} height={logo.height} loading="lazy" decoding="async" />
            )}
          </Link>
          {FOOTER.tagline ? <p className="wz-sf__tagline">{FOOTER.tagline[lang]}</p> : null}
          {/* the shop's own number as text, with its call and WhatsApp links (2026-11-17) — never the developer's */}
          <FootContact lang={lang} className="wz-sf__contact" />
          <ul className="wz-sf__social" aria-label={w.follow}>
            {FOOTER.social(lang).map((s) => {
              const Icon = SOCIAL_ICON[s.icon]
              return Icon ? (
                <li key={s.href}>
                  <a className="wz-sf__social-btn" href={s.href} target="_blank" rel="noopener noreferrer" aria-label={s.label}>
                    <Icon aria-hidden="true" />
                  </a>
                </li>
              ) : null
            })}
          </ul>
        </div>

        <FootSection id="wz-sf-shop" title={w.shop}>
          <ul className="wz-sf__links">
            {FOOTER.shop.map((l) => (
              <li key={l.href}>
                <Link className="wz-sf__link" href={href(l.href)}>{l[lang]}</Link>
              </li>
            ))}
            {ShopExtra ? <ShopExtra lang={lang} /> : null}
          </ul>
        </FootSection>

        <FootSection id="wz-sf-account" title={w.account}>
          <ul className="wz-sf__links">
            {account.map(([h, text]) => (
              <li key={h}>
                <Link className="wz-sf__link" href={href(h)}>{text}</Link>
              </li>
            ))}
          </ul>
        </FootSection>

        <FootSection id="wz-sf-support" title={w.support}>
          <ul className="wz-sf__links">
            {support.map(([h, text]) => (
              <li key={h}>
                <Link className="wz-sf__link" href={href(h)}>{text}</Link>
              </li>
            ))}
          </ul>
        </FootSection>

        {categories.length ? (
          <FootSection id="wz-sf-categories" title={w.categories}>
            <ul className="wz-sf__links wz-sf__cats">
              {categories.map((c) => (
                <li key={c.href}>
                  {c.children.length ? (
                    // closed on every width; the sub-categories are in the HTML either way
                    <details className="wz-sf__cat">
                      <summary className="wz-sf__cat-sum">
                        <span>{name(c.name)}</span>
                        <span className="wz-sf__chev" aria-hidden="true" />
                      </summary>
                      <ul className="wz-sf__sub" aria-label={`${w.more} ${name(c.name)}`}>
                        <li>
                          <Link className="wz-sf__link" href={href(c.href)}>{w.all(name(c.name))}</Link>
                        </li>
                        {c.children.map((s) => (
                          <li key={s.href}>
                            <Link className="wz-sf__link" href={href(s.href)}>{name(s.name)}</Link>
                          </li>
                        ))}
                      </ul>
                    </details>
                  ) : (
                    <Link className="wz-sf__link" href={href(c.href)}>{name(c.name)}</Link>
                  )}
                </li>
              ))}
            </ul>
          </FootSection>
        ) : null}
      </div>

      {brands.length ? (
        <nav className="wz-sf__brands" aria-label={w.brands}>
          <h2 className="wz-sf__brands-title">{w.brands}</h2>
          <ul className="wz-sf__brand-list">
            {brands.map((b) => (
              <li key={b.href}>
                <Link className="wz-sf__link" href={href(b.href)} lang="en">{displayName(txt(b.name, 'en'))}</Link>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}

      {/* The ways to pay this shop really offers (Part 7, 2026-11-05): the checkout's own list, the dashboard's
          pictures; nothing is drawn while no method is enabled. */}
      <PaymentBadges tone="dark" className="wz-sf__pay" />

      <div className="wz-sf__bottom">
        <p className="wz-sf__copy">© {year} {FOOTER.name} · {w.rights}</p>
        <div className="wz-sf__lang">
          <LanguageDropdown />
        </div>
        {/* Developer credit — bilingual, always shown in both languages (deliberate; the developer's own number) */}
        <p className="wz-sf__credit" dir="ltr">
          <span dir="rtl">تطوير و تصميم مايكل خلف</span>
          <span className="wz-sf__credit-sep" aria-hidden="true">|</span>
          <a className="wz-sf__credit-name" href="https://wa.me/201274550956" target="_blank" rel="noopener noreferrer" aria-label="Developed & Designed by Michael Khalaf — WhatsApp">
            <span>Developed &amp; Designed by Michael Khalaf</span>
            <FaWhatsapp aria-hidden="true" />
          </a>
        </p>
      </div>
    </footer>
  )
}
