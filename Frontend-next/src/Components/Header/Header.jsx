'use client'
import { useContext, useState, useEffect, useRef } from 'react'
import Link from 'next/link'
import Image from 'next/image'
import { useRouter, usePathname } from 'next/navigation'
import { FiMenu, FiSearch, FiShoppingBag, FiX, FiChevronDown } from 'react-icons/fi'

// Logo lives in /public (Vite imported it from src/assets; Next serves it from
// public as a plain URL string, which LazyLoadImage/<img> both accept).
const logo = '/logo.webp'
import './Header.css'
import SearchBox from './SearchBox/SearchBox'
import Nav from './Nav/Nav'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { buildListingParams } from '../../utils/listingParams'
import { getImageUrl } from '../../utils/imageUrl'
import { FaFacebookF, FaInstagram } from 'react-icons/fa'

function Header() {
  // Cart-derived counters stay in context; server data (products/tables) comes
  // from TanStack Query via useCatalog (shared, cached — no extra fetch).
  const { productsCount, total_cart_price } = useContext(MyContext)
  const { products, tables } = useCatalog()
  const { language, setLanguage } = useUIStore()
  const { userId: user_id, user } = useAuthStore()
  const router = useRouter()
  const pathname = usePathname()
  const [scrolled, setScrolled] = useState(false)
  const [pastHero, setPastHero] = useState(false)
  const isRTL = language === 'ar'

  // The dark "blend with hero" state only applies on the home page (the route
  // with the dark WatchHero filling the first viewport) and only until the user
  // has scrolled most of the way past it — then the bar turns frosted white.
  const heroPage = pathname === '/'
  const transparent = heroPage && !pastHero

  // ── Mobile drawer + search state ──
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [openSection, setOpenSection] = useState(null) // 'shop' | 'brands' | null
  const [mq, setMq] = useState('')
  const [searchOpen, setSearchOpen] = useState(false)
  const searchRef = useRef(null)

  // Materialize the bar once scrolled past the hero (60px). rAF-throttled.
  useEffect(() => {
    let ticking = false
    const onScroll = () => {
      if (ticking) return
      ticking = true
      requestAnimationFrame(() => {
        const y = window.scrollY
        setScrolled(y > 60) // desktop row-1 shadow
        setPastHero(y > window.innerHeight * 0.7) // mobile dark→frosted switch
        ticking = false
      })
    }
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

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
    item?.translations?.find((t) => t.locale === language)?.[key] ??
    item?.translations?.find((t) => t.locale === 'en')?.[key] ??
    item?.[key] ??
    ''

  // Sub-types / brands that actually have products (no dead links).
  const list = products || []
  const categoryTypes = tables?.categoryTypes || []
  const drawerSubTypes = (tables?.subTypes || []).filter((st) =>
    list.some((p) => p.sub_type_id === st.id),
  )
  const drawerBrands = (tables?.brands || []).filter((b) => list.some((p) => p.brand_id === b.id))
  // Sub-types grouped under the category type their products live in.
  const subTypesForCat = (ct) =>
    drawerSubTypes.filter((st) =>
      list.some((p) => p.category_type_id === ct.id && p.sub_type_id === st.id),
    )
  const brandLogo = (b) => getImageUrl(b?.image, 'Brand')

  const closeDrawer = () => {
    setDrawerOpen(false)
    setOpenSection(null)
  }


  // Encode filters into the /listing URL (Listing reads them back) — same model
  // the desktop Nav uses, then close the drawer.
  const go = (filters) => {
    const params = buildListingParams(
      {
        categories: [],
        brands: [],
        subTypes: [],
        genders: [],
        offers: false,
        price: [0, 99999999],
        ...filters,
      },
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

  // Scrolling announcement strip — trust signals, brands, Watchizer info.
  const englishItems = [
    'Authentic Luxury Watches — Certified & Guaranteed',
    '500+ Premium Timepieces In Stock',
    'Rolex · Omega · Cartier · TAG Heuer · Breitling',
    'Fast & Secure Delivery Across Egypt',
    "Watchizer — Egypt's Premier Watch Destination",
    'Patek Philippe · Audemars Piguet · IWC · Hublot',
    'Trusted by Watch Enthusiasts Since 2020',
    'Secure Payment · Easy Returns · Expert Support',
  ]
  const arabicItems = [
    'ساعات فاخرة أصلية — معتمدة ومضمونة',
    'أكثر من 500 ساعة فاخرة متوفرة',
    'رولكس · أوميغا · كارتييه · تاغ هوير · بريتلينج',
    'توصيل سريع وآمن لجميع أنحاء مصر',
    'واتشايزر — وجهتك الأولى للساعات الفاخرة في مصر',
    'باتيك فيليب · أوديمار بيغيه · IWC · هوبلوت',
    'موثوق من عشاق الساعات منذ 2020',
    'دفع آمن · إرجاع سهل · دعم متخصص',
  ]
  const marqueeItems = language === 'ar' ? arabicItems : englishItems

  return (
    <>
    <header
      dir={isRTL ? 'rtl' : 'ltr'}
      className={`wz-header ${scrolled ? 'wz-scrolled' : ''} ${
        transparent ? 'wz-bar-transparent' : ''
      }`}
    >
      {/* ── SECTION A — announcement strip ─────────────────── */}
      <div className="wz-strip">
        <div className="wz-strip-inner">
          <div className="wz-marquee-wrapper">
            <div className="wz-marquee-track">
              {marqueeItems.map((item, i) => (
                <span key={i} className="wz-marquee-item">
                  {item}
                  <span className="wz-marquee-sep">◆</span>
                </span>
              ))}
              {/* Duplicate for a seamless loop */}
              {marqueeItems.map((item, i) => (
                <span key={`dup-${i}`} className="wz-marquee-item">
                  {item}
                  <span className="wz-marquee-sep">◆</span>
                </span>
              ))}
            </div>
          </div>
          <div className="wz-strip-social">
            <a
              href="https://www.facebook.com/profile.php?id=100076267296916"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Facebook"
            >
              <FaFacebookF style={{ height: '13px', width: '13px' }} />
            </a>
            <a
              href="https://www.instagram.com/watchizer_eg/"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Instagram"
            >
              <FaInstagram style={{ height: '14px', width: '14px' }} />
            </a>
          </div>
        </div>
      </div>

      {/* ── SECTION B — main row: Logo | Search | Actions ──── */}
      <div className="wz-row1">
        <button
          type="button"
          className="wz-burger"
          aria-label={isRTL ? 'فتح القائمة' : 'Open menu'}
          aria-expanded={drawerOpen}
          onClick={() => setDrawerOpen(true)}
        >
          <FiMenu />
        </button>

        <div className="wz-logo">
          <Link href={'/'}>
            {/* LCP-critical: priority preloads it, no lazy-loading. Kept at the
                logo's true 150×46 aspect; CSS renders it height:46 / width:auto. */}
            <Image src={logo} alt="Watchizer-logo" width={150} height={46} priority quality={90} />
          </Link>
        </div>

        <div className="wz-search-box">
          <SearchBox />
        </div>

        <div className="wz-actions">
          {/* ── Language: EN | ع (each letter its own button) ── */}
          <div className="wz-act-lang">
            <button
              type="button"
              className={`wz-act-lang__btn${language === 'en' ? ' is-active' : ''}`}
              onClick={() => setLanguage('en')}
            >
              EN
            </button>
            <span className="wz-act-lang__pipe">|</span>
            <button
              type="button"
              className={`wz-act-lang__btn${language === 'ar' ? ' is-active' : ''}`}
              onClick={() => setLanguage('ar')}
            >
              ع
            </button>
          </div>

          <span className="wz-act-sep" aria-hidden="true" />

          {/* ── Mobile search icon ────────────── */}
          <button
            type="button"
            className="wz-search-icon"
            onClick={() => setSearchOpen((o) => !o)}
            aria-label={isRTL ? 'بحث' : 'Search'}
            aria-expanded={searchOpen}
          >
            <FiSearch />
          </button>

          {/* ── Auth / Identity ───────────────── */}
          {user_id && user_id !== null ? (
            <button
              type="button"
              className="wz-act-identity"
              onClick={() => router.push('/account')}
              aria-label={isRTL ? 'حسابي' : 'My Account'}
            >
              <span className="wz-act-ring">
                {user?.image ? (
                  <img
                    src={getImageUrl(user.image, 'User')}
                    alt=""
                    width="32"
                    height="32"
                    loading="lazy"
                    onError={(e) => {
                      e.currentTarget.style.display = 'none'
                      if (e.currentTarget.nextElementSibling)
                        e.currentTarget.nextElementSibling.style.display = 'flex'
                    }}
                  />
                ) : null}
                <span
                  className="wz-act-monogram"
                  style={{ display: user?.image ? 'none' : 'flex' }}
                >
                  {(user?.first_name || user?.name || 'U').charAt(0).toUpperCase()}
                </span>
              </span>
              <span className="wz-act-name">
                {user?.first_name || user?.name?.split(' ')[0] || ''}
              </span>
            </button>
          ) : (
            <div className="wz-act-auth">
              <button
                type="button"
                className="wz-act-auth__link"
                onClick={() => router.push('/login')}
              >
                {isRTL ? 'تسجيل' : 'SIGN IN'}
              </button>
              <button
                type="button"
                className="wz-act-auth__link"
                onClick={() => router.push('/register')}
              >
                {isRTL ? 'حساب جديد' : 'REGISTER'}
              </button>
            </div>
          )}

          <span className="wz-act-sep" aria-hidden="true" />

          {/* ── Cart: icon + count + price ────── */}
          <button
            type="button"
            className="wz-act-bag"
            onClick={() => router.push('/cart')}
            aria-label={isRTL ? 'السلة' : 'Cart'}
          >
            <span className="wz-act-bag__icon">
              <FiShoppingBag />
            </span>
            <span className="wz-act-bag__info">
              <span className="wz-act-bag__count">
                <span className="wz-act-bag__num">{productsCount}</span>{' '}
                {isRTL
                  ? productsCount === 1
                    ? 'منتج'
                    : 'منتجات'
                  : productsCount === 1
                    ? 'ITEM'
                    : 'ITEMS'}
              </span>
              <span className="wz-act-bag__price">
                {isRTL
                  ? `${Math.round(total_cart_price || 0).toLocaleString('ar-EG')} ج.م`
                  : `EGP ${Math.round(total_cart_price || 0).toLocaleString()}`}
              </span>
            </span>
          </button>
        </div>
      </div>

      {/* ── Mobile slide-down search ───────────────────────── */}
      <div className={`wz-msearch ${searchOpen ? 'is-open' : ''}`}>
        <form className="wz-msearch-form" onSubmit={submitSearch}>
          <FiSearch className="wz-msearch-icon" />
          <input
            ref={searchRef}
            type="search"
            className="wz-msearch-input"
            value={mq}
            onChange={(e) => setMq(e.target.value)}
            placeholder={isRTL ? 'ابحث عن منتج…' : 'Search products…'}
            aria-label={isRTL ? 'بحث' : 'Search'}
          />
          <button
            type="button"
            className="wz-msearch-cancel"
            onClick={() => setSearchOpen(false)}
            aria-label={isRTL ? 'إلغاء' : 'Cancel'}
          >
            <FiX />
          </button>
        </form>
      </div>

      {/* ── SECTION C — nav row (dark) ─────────────────────── */}
      <div className="wz-row2">
        <Nav />
      </div>

      {/* ── Mobile drawer (left, or right in RTL) ──────────── */}
      <div
        className={`wz-drawer-overlay ${drawerOpen ? 'is-open' : ''}`}
        onClick={closeDrawer}
        aria-hidden={!drawerOpen}
      />
      <aside
        className={`wz-drawer ${drawerOpen ? 'is-open' : ''}`}
        dir={isRTL ? 'rtl' : 'ltr'}
        role="dialog"
        aria-modal="true"
        aria-label={isRTL ? 'قائمة التنقل' : 'Navigation menu'}
        inert={!drawerOpen ? true : undefined}
      >
        <div className="wz-drawer-head">
          <Link href="/" onClick={closeDrawer} className="wz-drawer-logo" aria-label="Watchizer">
            <img src={logo} alt="Watchizer" width="150" height="46" loading="lazy" />
          </Link>
          <button
            type="button"
            className="wz-drawer-close"
            onClick={closeDrawer}
            aria-label={isRTL ? 'إغلاق' : 'Close'}
          >
            <FiX />
          </button>
        </div>

        <form className="wz-drawer-search" onSubmit={submitSearch}>
          <input
            type="search"
            value={mq}
            onChange={(e) => setMq(e.target.value)}
            placeholder={isRTL ? 'ابحث عن منتج…' : 'Search products…'}
            aria-label={isRTL ? 'بحث' : 'Search'}
          />
          <button type="submit" aria-label={isRTL ? 'بحث' : 'Search'}>
            <FiSearch />
          </button>
        </form>

        <nav className="wz-drawer-nav">
          <button type="button" className="wz-drawer-link" onClick={() => goTo('/')}>
            {isRTL ? 'الرئيسية' : 'Home'}
          </button>

          {/* Shop accordion → sub-categories */}
          {drawerSubTypes.length > 0 && (
            <div className="wz-drawer-acc">
              <button
                type="button"
                className={`wz-drawer-link wz-drawer-acc-head ${openSection === 'shop' ? 'is-open' : ''}`}
                onClick={() => toggleSection('shop')}
                aria-expanded={openSection === 'shop'}
              >
                {isRTL ? 'المتجر' : 'Shop'}
                <FiChevronDown className="wz-drawer-caret" />
              </button>
              {openSection === 'shop' && (
                <div className="wz-drawer-sub">
                  {categoryTypes.map((ct) => {
                    const subs = subTypesForCat(ct)
                    if (!subs.length) return null
                    return (
                      <div key={ct.id} className="wz-drawer-group">
                        <button
                          type="button"
                          className="wz-drawer-group-head"
                          onClick={() => go({ categories: [ct.id] })}
                        >
                          {label(ct, 'category_type_name')}
                        </button>
                        {subs.map((st) => (
                          <button
                            key={st.id}
                            type="button"
                            className="wz-drawer-sublink"
                            onClick={() => go({ categories: [ct.id], subTypes: [st.id] })}
                          >
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

          {/* Brands accordion */}
          {drawerBrands.length > 0 && (
            <div className="wz-drawer-acc">
              <button
                type="button"
                className={`wz-drawer-link wz-drawer-acc-head ${openSection === 'brands' ? 'is-open' : ''}`}
                onClick={() => toggleSection('brands')}
                aria-expanded={openSection === 'brands'}
              >
                {isRTL ? 'العلامات التجارية' : 'All Brands'}
                <FiChevronDown className="wz-drawer-caret" />
              </button>
              {openSection === 'brands' && (
                <div className="wz-drawer-sub">
                  {drawerBrands.map((b) => {
                    const lg = brandLogo(b)
                    return (
                      <button
                        key={b.id}
                        type="button"
                        className="wz-drawer-sublink wz-drawer-brand"
                        onClick={() => go({ brands: [b.id] })}
                      >
                        <span className="wz-drawer-brand-logo">
                          {lg ? (
                            <img src={lg} alt="" width="24" height="24" loading="lazy" />
                          ) : (
                            <span className="wz-drawer-brand-ph">
                              {(label(b, 'brand_name') || '?').charAt(0)}
                            </span>
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

          <button type="button" className="wz-drawer-link" onClick={() => go({ offers: true })}>
            {isRTL ? 'العروض' : 'Offers'}
          </button>

          <div className="wz-drawer-sep" />

          {user_id && user_id !== null ? (
            <>
              <button
                type="button"
                className="wz-drawer-link"
                onClick={() => goTo('/account?tab=profile')}
              >
                {isRTL ? 'حسابي' : 'My Account'}
              </button>
              <button
                type="button"
                className="wz-drawer-link"
                onClick={() => goTo('/account?tab=wishlist')}
              >
                {isRTL ? 'قائمة الأمنيات' : 'Wishlist'}
              </button>
              <button
                type="button"
                className="wz-drawer-link"
                onClick={() => goTo('/account?tab=orders')}
              >
                {isRTL ? 'طلباتي' : 'My Orders'}
              </button>
              <button type="button" className="wz-drawer-link" onClick={handleLogout}>
                {isRTL ? 'تسجيل الخروج' : 'Log Out'}
              </button>
            </>
          ) : (
            <>
              <button type="button" className="wz-drawer-link" onClick={() => goTo('/login')}>
                {isRTL ? 'تسجيل الدخول' : 'Login'}
              </button>
              <button
                type="button"
                className="wz-drawer-link"
                onClick={() => goTo('/account?tab=wishlist')}
              >
                {isRTL ? 'قائمة الأمنيات' : 'Wishlist'}
              </button>
            </>
          )}
        </nav>

        <div className="wz-drawer-foot">
          <div className="wz-act-lang">
            <button
              type="button"
              className={`wz-act-lang__btn${language === 'en' ? ' is-active' : ''}`}
              onClick={() => {
                setLanguage('en')
                closeDrawer()
              }}
            >
              EN
            </button>
            <span className="wz-act-lang__pipe">|</span>
            <button
              type="button"
              className={`wz-act-lang__btn${language === 'ar' ? ' is-active' : ''}`}
              onClick={() => {
                setLanguage('ar')
                closeDrawer()
              }}
            >
              ع
            </button>
          </div>
          <p className="wz-drawer-credit">
            {isRTL ? 'تصميم وتطوير ' : 'Designed by '}
            <a
              href="https://wa.me/201274550956"
              target="_blank"
              rel="noopener noreferrer"
            >
              {isRTL ? 'مايكل خلف' : 'Michael Khalaf'}
            </a>
          </p>
        </div>
      </aside>
    </header>

    {/* Mobile spacer: the header is position:fixed on mobile, so reserve its
        height on every page EXCEPT home (where the hero sits under it). */}
    {!heroPage && <div className="wz-bar-spacer" aria-hidden="true" />}
    </>
  )
}

export default Header
