'use client'
import { useState, useEffect, useRef, useMemo } from 'react'
import Link from '@/src/Components/LocaleLink'
import Image from 'next/image'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { FiMenu, FiSearch, FiShoppingBag, FiX, FiChevronDown, FiUser } from 'react-icons/fi'
import './Header.css'
import SearchBox from './SearchBox/SearchBox'
import Nav from './Nav/Nav'
import { useSwitchLanguage } from '../../Hooks/useSwitchLanguage'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import useCart from '../../Hooks/useCart'
import { useTables } from '../../Hooks/queries/useTables'
import { useNav } from '../../Hooks/queries/useNav'
import { useHasOffers } from '../../Hooks/queries/useRunningOffers'
import { buildListingParams } from '../../utils/listingParams'
import { subTypesByName } from '../../utils/subTypeGroups'
import { getImageUrl } from '../../utils/imageUrl'

/*
 * The Brand Fashion header — spec v2.10 §10.1 item 1 ("Header 56 phone / 64 desktop: logo, search, cart.
 * Sticky on phone"), §3 ("black does the work: header"), §7.2 (the white focus ring on an ink surface),
 * §7.3 (tab order logo → search → nav → account → cart), §15 (20px line icons, 1.5 stroke). Reskinned per
 * §17.2: the SAME component and behaviour (drawer, language switch, cart count, the twin Nav and SearchBox
 * inside it), new styling in Header.css. Built 2026-10-17 (the identity pass).
 *
 * What the reskin removed from the copied Watchizer header, and why:
 *  • the scrolling announcement strip — Watchizer's copy ("Rolex · Omega …"), and §14 forbids anything that loops;
 *  • the "transparent over the hero" state — Brand Fashion's home has no full-viewport hero (§11.2), so the bar was
 *    transparent over the promo line; the header is one sticky ink bar on every page (the wrapper in globals.css);
 *  • the cart's running total in the bar — §10.1 names logo, search, cart; the count rides the bag as a badge.
 *
 * Tab order is DOM order: the nav row sits BEFORE the actions in the DOM and is placed on its own grid row by CSS,
 * so Tab goes logo → search → nav → account → cart (§7.3) while the eye sees the actions on the first row.
 *
 * Icons are Feather (react-icons/fi) drawn at 20px with stroke 1.5: Lucide is Feather's fork with the same
 * glyphs, and lucide-react is not a dependency of either app (pnpm-lock.yaml is a twin) — recorded.
 */

const STR = {
  en: {
    openMenu: 'Open menu', close: 'Close', search: 'Search', cancel: 'Cancel', searchPh: 'Search products…', account: 'Account', signIn: 'Sign in',
    cart: (n) => (n === 0 ? 'Cart, empty' : `Cart, ${n} ${n === 1 ? 'item' : 'items'}`), menu: 'Navigation menu', home: 'Home', shop: 'Shop', brands: 'All brands', sale: 'Sale', offers: 'Offers',
    myAccount: 'My account', myOrders: 'My orders', logOut: 'Log out', login: 'Sign in', register: 'Create an account',
    pages: [['/about-us', 'About us'], ['/contact-us', 'Contact us'], ['/privacy-policy', 'Privacy policy'], ['/terms-and-conditions', 'Terms and conditions']],
  },
  ar: {
    openMenu: 'فتح القائمة', close: 'إغلاق', search: 'بحث', cancel: 'إلغاء', searchPh: 'ابحث عن منتج…', account: 'حسابي', signIn: 'تسجيل الدخول',
    cart: (n) => (n === 0 ? 'السلة، فارغة' : `السلة، ${n} ${n === 1 ? 'منتج' : 'منتجات'}`), menu: 'قائمة التنقل', home: 'الرئيسية', shop: 'المتجر', brands: 'العلامات التجارية', sale: 'تخفيضات', offers: 'العروض',
    myAccount: 'حسابي', myOrders: 'طلباتي', logOut: 'تسجيل الخروج', login: 'تسجيل الدخول', register: 'إنشاء حساب',
    pages: [['/about-us', 'من نحن'], ['/contact-us', 'تواصل معنا'], ['/privacy-policy', 'سياسة الخصوصية'], ['/terms-and-conditions', 'الشروط والأحكام']],
  },
}

function Header() {
  // Cart count from the cart store (derived here, as before). Server data (the lookup tables and the menu's
  // facts) comes from TanStack Query — not the catalogue (C-1 stage 2).
  const { cart } = useCart()
  const productsCount = useMemo(() => {
    const cartItems = Array.isArray(cart?.cart_item) ? cart.cart_item : []
    return cartItems.reduce((total, item) => total + (parseInt(item.quantity, 10) || 0), 0)
  }, [cart])
  const { data: tables = {} } = useTables()
  const { data: nav } = useNav()
  const { language } = useUIStore()
  const hasOffers = useHasOffers()
  // /x ↔ /ar/x (S-AR stage 1): the switch moves to the same page in the other language.
  const setLanguage = useSwitchLanguage()
  const { userId: user_id, user } = useAuthStore()
  const router = useRouter()
  const isRTL = language === 'ar'
  const t = STR[isRTL ? 'ar' : 'en']

  // ── Mobile drawer + search state ──
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [openSection, setOpenSection] = useState(null) // 'shop' | 'brands' | `ct-${id}` | null
  const [mq, setMq] = useState('')
  const [searchOpen, setSearchOpen] = useState(false)
  const searchRef = useRef(null)

  // Lock body scroll while the mobile drawer is open.
  useEffect(() => {
    document.body.style.overflow = drawerOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [drawerOpen])

  // Auto-focus the mobile search field when it slides open.
  useEffect(() => {
    if (searchOpen) searchRef.current?.focus()
  }, [searchOpen])

  // Localized label: current language → English → flat field.
  const label = (item, key) =>
    item?.translations?.find((x) => x.locale === language)?.[key] ??
    item?.translations?.find((x) => x.locale === 'en')?.[key] ??
    item?.[key] ??
    ''

  // Sub-types / brands that actually have products (no dead links).
  const categoryTypes = tables?.categoryTypes || []
  const allSubTypes = tables?.subTypes || []
  const drawerBrands = (tables?.brands || []).filter((b) => (nav?.brand_ids || []).includes(b.id))
  const subTypesForCat = (ct) => {
    const ids = nav?.sub_types_by_category?.[ct.id] || []
    const byProducts = allSubTypes.filter((st) => ids.includes(st.id))
    return byProducts.length ? byProducts : subTypesByName(ct, allSubTypes)
  }
  const hasAnySubTypes = categoryTypes.some((ct) => subTypesForCat(ct).length > 0)
  const brandLogo = (b) => getImageUrl(b?.image, 'Brand')

  const closeDrawer = () => {
    setDrawerOpen(false)
    setOpenSection(null)
  }

  // Encode filters into the /listing URL (Listing reads them back) — same model the desktop Nav uses.
  const go = (filters) => {
    const params = buildListingParams(
      { categories: [], brands: [], subTypes: [], genders: [], offers: false, price: [0, 99999999], ...filters },
      {},
      tables,
    )
    const qs = params.toString()
    closeDrawer()
    router.push(qs ? `/listing?${qs}` : '/listing')
  }

  const goTo = (path) => {
    closeDrawer()
    router.push(path)
  }

  const submitSearch = (e) => {
    e.preventDefault()
    const q = mq.trim()
    closeDrawer()
    setSearchOpen(false)
    router.push(q ? `/listing?q=${encodeURIComponent(q)}` : '/listing')
    setMq('')
  }

  const toggleSection = (key) => setOpenSection((cur) => (cur === key ? null : key))

  const handleLogout = () => {
    closeDrawer()
    sessionStorage.clear()
    localStorage.clear()
    router.push('/')
    window.location.reload()
  }

  const langSwitch = (extra) => (
    <div className="wz-act-lang" role="group" aria-label="Language">
      <button type="button" className={`wz-act-lang__btn${language === 'en' ? ' is-active' : ''}`} lang="en" onClick={() => { setLanguage('en'); extra?.() }} aria-pressed={language === 'en'}>
        EN
      </button>
      <span className="wz-act-lang__pipe" aria-hidden="true">|</span>
      <button type="button" className={`wz-act-lang__btn${language === 'ar' ? ' is-active' : ''}`} lang="ar" onClick={() => { setLanguage('ar'); extra?.() }} aria-pressed={language === 'ar'}>
        ع
      </button>
    </div>
  )

  return (
    <header dir={isRTL ? 'rtl' : 'ltr'} className="wz-header">
      {/* row 1 (phone + desktop): burger · logo · search · actions — the actions come LATER in the DOM (§7.3) */}
      <button type="button" className="wz-burger" aria-label={t.openMenu} aria-expanded={drawerOpen} onClick={() => setDrawerOpen(true)}>
        <FiMenu aria-hidden="true" />
      </button>

      <div className="wz-logo">
        <Link href="/" aria-label="Brand Fashion">
          {/* LCP-critical: priority preloads it. The white wordmark on the ink bar (spec §22.4). 565×172 source. */}
          <Image src="/wordmark-white.svg" alt="Brand Fashion" width={118} height={36} priority />
        </Link>
      </div>

      <div className="wz-search-box">
        <SearchBox />
      </div>

      {/* row 2 (desktop): the menu — the twin Nav, restyled in Header.css */}
      <div className="wz-row2">
        <Nav />
      </div>

      <div className="wz-actions">
        {langSwitch()}

        <button type="button" className="wz-search-icon" onClick={() => setSearchOpen((o) => !o)} aria-label={t.search} aria-expanded={searchOpen}>
          <FiSearch aria-hidden="true" />
        </button>

        {user_id ? (
          <button type="button" className="wz-act-identity" onClick={() => router.push('/account')} aria-label={t.myAccount}>
            <span className="wz-act-ring" aria-hidden="true">
              {user?.image ? (
                // eslint-disable-next-line @next/next/no-img-element -- the account photo, asset-host URL
                <img
                  src={getImageUrl(user.image, 'User')}
                  alt=""
                  width="28"
                  height="28"
                  loading="lazy"
                  onError={(e) => {
                    e.currentTarget.style.display = 'none'
                    if (e.currentTarget.nextElementSibling) e.currentTarget.nextElementSibling.style.display = 'flex'
                  }}
                />
              ) : null}
              <span className="wz-act-monogram" style={{ display: user?.image ? 'none' : 'flex' }}>
                {(user?.first_name || user?.name || 'U').charAt(0).toUpperCase()}
              </span>
            </span>
            <span className="wz-act-name">{user?.first_name || user?.name?.split(' ')[0] || t.account}</span>
          </button>
        ) : (
          <button type="button" className="wz-act-auth__link" onClick={() => router.push('/login')} aria-label={t.signIn}>
            <FiUser aria-hidden="true" />
            <span className="wz-act-name">{t.signIn}</span>
          </button>
        )}

        <button type="button" className="wz-act-bag" onClick={() => router.push('/cart')} aria-label={t.cart(productsCount)}>
          <FiShoppingBag aria-hidden="true" />
          {productsCount > 0 ? <span className="wz-act-bag__num" aria-hidden="true">{productsCount > 99 ? '99+' : productsCount}</span> : null}
        </button>
      </div>

      {/* Mobile slide-down search */}
      <div className={`wz-msearch ${searchOpen ? 'is-open' : ''}`}>
        <form className="wz-msearch-form" onSubmit={submitSearch} role="search">
          <FiSearch className="wz-msearch-icon" aria-hidden="true" />
          <input ref={searchRef} type="search" className="wz-msearch-input" value={mq} onChange={(e) => setMq(e.target.value)} placeholder={t.searchPh} aria-label={t.search} />
          <button type="button" className="wz-msearch-cancel" onClick={() => setSearchOpen(false)} aria-label={t.cancel}>
            <FiX aria-hidden="true" />
          </button>
        </form>
      </div>

      {/* Mobile drawer (start side) */}
      <div className={`wz-drawer-overlay ${drawerOpen ? 'is-open' : ''}`} onClick={closeDrawer} aria-hidden={!drawerOpen} />
      <aside className={`wz-drawer ${drawerOpen ? 'is-open' : ''}`} dir={isRTL ? 'rtl' : 'ltr'} role="dialog" aria-modal="true" aria-label={t.menu} inert={!drawerOpen ? true : undefined}>
        <div className="wz-drawer-head">
          <Link href="/" onClick={closeDrawer} className="wz-drawer-logo" aria-label="Brand Fashion">
            <Image src="/wordmark.svg" alt="Brand Fashion" width={105} height={32} />
          </Link>
          <button type="button" className="wz-drawer-close" onClick={closeDrawer} aria-label={t.close}>
            <FiX aria-hidden="true" />
          </button>
        </div>

        <form className="wz-drawer-search" onSubmit={submitSearch} role="search">
          <input type="search" value={mq} onChange={(e) => setMq(e.target.value)} placeholder={t.searchPh} aria-label={t.search} />
          <button type="submit" aria-label={t.search}>
            <FiSearch aria-hidden="true" />
          </button>
        </form>

        <nav className="wz-drawer-nav">
          <button type="button" className="wz-drawer-link" onClick={() => goTo('/')}>
            {t.home}
          </button>

          {hasAnySubTypes && (
            <div className="wz-drawer-acc">
              <button type="button" className={`wz-drawer-link wz-drawer-acc-head ${openSection === 'shop' ? 'is-open' : ''}`} onClick={() => toggleSection('shop')} aria-expanded={openSection === 'shop'}>
                {t.shop}
                <FiChevronDown className="wz-drawer-caret" aria-hidden="true" />
              </button>
              {openSection === 'shop' && (
                <div className="wz-drawer-sub">
                  {categoryTypes.map((ct) => {
                    const subs = subTypesForCat(ct)
                    if (!subs.length) return null
                    return (
                      <div key={ct.id} className="wz-drawer-group">
                        <button type="button" className="wz-drawer-group-head" onClick={() => go({ categories: [ct.id] })}>
                          {label(ct, 'category_type_name')}
                        </button>
                        {subs.map((st) => (
                          <button key={st.id} type="button" className="wz-drawer-sublink" onClick={() => go({ categories: [ct.id], subTypes: [st.id] })}>
                            {label(st, 'sub_type_name')}
                          </button>
                        ))}
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          {categoryTypes.map((ct) => {
            const subs = subTypesForCat(ct)
            if (!subs.length) return null
            const key = `ct-${ct.id}`
            return (
              <div className="wz-drawer-acc" key={key}>
                <button type="button" className={`wz-drawer-link wz-drawer-acc-head ${openSection === key ? 'is-open' : ''}`} onClick={() => toggleSection(key)} aria-expanded={openSection === key}>
                  {label(ct, 'category_type_name')}
                  <FiChevronDown className="wz-drawer-caret" aria-hidden="true" />
                </button>
                {openSection === key && (
                  <div className="wz-drawer-sub">
                    {subs.map((st) => (
                      <button key={st.id} type="button" className="wz-drawer-sublink" onClick={() => go({ categories: [ct.id], subTypes: [st.id] })}>
                        {label(st, 'sub_type_name')}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            )
          })}

          {drawerBrands.length > 0 && (
            <div className="wz-drawer-acc">
              <button type="button" className={`wz-drawer-link wz-drawer-acc-head ${openSection === 'brands' ? 'is-open' : ''}`} onClick={() => toggleSection('brands')} aria-expanded={openSection === 'brands'}>
                {t.brands}
                <FiChevronDown className="wz-drawer-caret" aria-hidden="true" />
              </button>
              {openSection === 'brands' && (
                <div className="wz-drawer-sub">
                  {drawerBrands.map((b) => {
                    const lg = brandLogo(b)
                    return (
                      <button key={b.id} type="button" className="wz-drawer-sublink wz-drawer-brand" onClick={() => go({ brands: [b.id] })}>
                        <span className="wz-drawer-brand-logo" aria-hidden="true">
                          {lg ? (
                            // eslint-disable-next-line @next/next/no-img-element -- brand logo, asset-host URL
                            <img src={lg} alt="" width="24" height="24" loading="lazy" />
                          ) : (
                            <span className="wz-drawer-brand-ph">{(label(b, 'brand_name') || '?').charAt(0)}</span>
                          )}
                        </span>
                        {label(b, 'brand_name')}
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          )}

          {/* Sale (a product's own discount) always; Offers only while one runs (2026-10-19) */}
          <button type="button" className="wz-drawer-link" onClick={() => goTo('/listing?sale=1')}>
            {t.sale}
          </button>
          {hasOffers ? (
            <button type="button" className="wz-drawer-link" onClick={() => goTo('/offers')}>
              {t.offers}
            </button>
          ) : null}

          <div className="wz-drawer-sep" />

          {user_id ? (
            <>
              <button type="button" className="wz-drawer-link" onClick={() => goTo('/account?tab=profile')}>{t.myAccount}</button>
              <button type="button" className="wz-drawer-link" onClick={() => goTo('/account?tab=orders')}>{t.myOrders}</button>
              <button type="button" className="wz-drawer-link" onClick={handleLogout}>{t.logOut}</button>
            </>
          ) : (
            <>
              <button type="button" className="wz-drawer-link" onClick={() => goTo('/login')}>{t.login}</button>
              <button type="button" className="wz-drawer-link" onClick={() => goTo('/register')}>{t.register}</button>
            </>
          )}

          {/* The trust pages. Phones have no footer, so this is where a shopper on a phone finds "Contact us". */}
          <div className="wz-drawer-sep" />
          {t.pages.map(([href, text]) => (
            <Link key={href} href={href} className="wz-drawer-link" onClick={closeDrawer}>
              {text}
            </Link>
          ))}
        </nav>

        <div className="wz-drawer-foot">
          {langSwitch(closeDrawer)}
          {/* Developer credit — bilingual, always shown in both languages (deliberate; the developer's own number) */}
          <p className="wz-drawer-credit" dir="ltr">
            <span dir="rtl">تطوير و تصميم مايكل خلف</span>
            <span className="wz-drawer-credit-sep" aria-hidden="true">|</span>
            <a href="https://wa.me/201274550956" target="_blank" rel="noopener noreferrer" aria-label="Developed & Designed by Michael Khalaf — WhatsApp">
              <span>Developed &amp; Designed by Michael Khalaf</span>
              <svg className="wz-drawer-credit-wa" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
              </svg>
            </a>
          </p>
        </div>
      </aside>
    </header>
  )
}

export default Header
