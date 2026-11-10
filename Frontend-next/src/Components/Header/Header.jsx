'use client'
import { useState, useEffect, useRef, useMemo, Suspense } from 'react'
import Link from '@/src/Components/LocaleLink'
import Image from 'next/image'
import { usePathname } from 'next/navigation'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { FiMenu, FiSearch, FiShoppingBag, FiX, FiChevronDown } from 'react-icons/fi'

// Logo lives in /public (Vite imported it from src/assets; Next serves it from
// public as a plain URL string, which LazyLoadImage/<img> both accept).
const logo = '/logo.webp'
import './Header.css'
import SearchBox from './SearchBox/SearchBox'
import Nav from './Nav/Nav'
import CampaignNav from '../Promo/CampaignNav'
import WheelFloat from '../Wheel/WheelFloat'
import InviteFromLink from '../Wheel/InviteFromLink'
import NavProgress from '../NavProgress/NavProgress'
import { useHotDealsMode } from '../../Hooks/queries/usePromo'
import MegaDrawer from './MegaMenu/MegaDrawer'
import { useSwitchLanguage } from '../../Hooks/useSwitchLanguage'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useShippingStore } from '../../Store/shippingStore'
import useCart from '../../Hooks/useCart'
import usePromotion from '../../Hooks/usePromotion'
import useFreeShipping from '../../Hooks/useFreeShipping'
import { payable } from '../../lib/coupon'
import { useTables } from '../../Hooks/queries/useTables'
import { useNav } from '../../Hooks/queries/useNav'
import { buildListingParams } from '../../utils/listingParams'
import { subTypeSlug } from '../../utils/slugs'
import { subTypesByName } from '../../utils/subTypeGroups'
import { getImageUrl } from '../../utils/imageUrl'
import { FaFacebookF, FaInstagram, FaTiktok } from 'react-icons/fa'
import { FACEBOOK, INSTAGRAM, TIKTOK } from '@/src/content/social'

// The drawer lists the shop's Offers page under its own name, so the mega menu's "On sale" row is left out there.
const DRAWER_OMIT = ['sale']

function Header() {
  // Cart-derived counters (were MyProvider state) are derived here from the cart
  // + selected shipping. Server data (the lookup tables and the menu's facts)
  // comes from TanStack Query — not the catalogue (C-1 stage 2).
  const { cart } = useCart()
  const shipping = useShippingStore((s) => s.shipping)
  // the same total the cart screen shows (M1u, 2026-10-30): free delivery over the threshold, the promotion taken off
  const promotion = usePromotion()
  const cartSubtotal = (Array.isArray(cart?.cart_item) ? cart.cart_item : []).reduce((sum, item) => sum + (parseFloat(item.piece_price) || 0) * (parseInt(item.quantity, 10) || 0), 0)
  const freeShipping = useFreeShipping(cartSubtotal)
  const { productsCount, total_cart_price } = useMemo(() => {
    const cartItems = Array.isArray(cart?.cart_item) ? cart.cart_item : []
    const count = cartItems.reduce(
      (total, item) => total + (parseInt(item.quantity, 10) || 0),
      0,
    )
    const subtotal = cartItems.reduce((total, item) => {
      const piecePrice = parseFloat(item.piece_price || 0)
      const quantity = parseInt(item.quantity || 1, 10)
      return total + piecePrice * quantity
    }, 0)
    // Empty cart → total is 0 (do NOT add the default shipping fee to nothing).
    const total = (count > 0 ? payable(subtotal, promotion.discount, freeShipping.free ? 0 : parseFloat(shipping || 0)) : 0).toFixed(2)
    return { productsCount: count, total_cart_price: total }
  }, [cart, shipping, promotion.discount, freeShipping.free])
  const { data: tables = {} } = useTables()
  const { data: nav } = useNav()
  const { language } = useUIStore()
  // /x ↔ /ar/x (S-AR stage 1): the switch moves to the same page in the other language.
  const setLanguage = useSwitchLanguage()
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
  // HOT DEALS MODE recolours the phone header too (the review of 2026-11-05): it takes the header's DARK state —
  // white logo, white icons, already designed for the hero — and Header.css paints that state with the fire.
  const hotMode = useHotDealsMode()

  // ── Mobile drawer + search state ──
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [openSection, setOpenSection] = useState(null) // 'shop' | 'brands' | `ct-${id}` | null
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
  const categoryTypes = tables?.categoryTypes || []
  const allSubTypes = tables?.subTypes || []
  const drawerBrands = (tables?.brands || []).filter((b) => (nav?.brand_ids || []).includes(b.id))
  // Sub-types grouped under the category type their products live in. When a
  // category type has no products yet (fresh catalog), fall back to classifying
  // sub-types by their English name so the Watches / Fashion menus still populate.
  const subTypesForCat = (ct) => {
    const ids = nav?.sub_types_by_category?.[ct.id] || []
    const byProducts = allSubTypes.filter((st) => ids.includes(st.id))
    return byProducts.length ? byProducts : subTypesByName(ct, allSubTypes)
  }
  // Whether any category type has sub-types to show (drives the Shop accordion).
  const hasAnySubTypes = categoryTypes.some((ct) => subTypesForCat(ct).length > 0)
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

  // The mega menu's links that are this shop's own (a gender, a gender's sub-category): the listing URL scheme
  // lives in this app, as it does for the desktop Nav. Stable, so the drawer's menu does not rebuild every render.
  const megaHrefs = useMemo(() => {
    const listing = (filters) => {
      const qs = buildListingParams({ categories: [], brands: [], subTypes: [], genders: [], offers: false, price: [0, 99999999], ...filters }, {}, tables).toString()
      return qs ? `/listing?${qs}` : '/listing'
    }
    return {
      gender: (g) => listing({ genders: [g.name?.en] }),
      genderChild: (g, child) => {
        const st = (tables?.subTypes || []).find((x) => subTypeSlug(x) === child.slug)
        return listing(st ? { genders: [g.name?.en], subTypes: [st.id] } : { genders: [g.name?.en] })
      },
    }
  }, [tables])

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
        transparent || hotMode.on ? 'wz-bar-transparent' : ''
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
            {/* The shop's accounts come from content/trustPages.js (2026-11-05) — the one place they are typed. */}
            <a href={FACEBOOK} target="_blank" rel="noopener noreferrer" aria-label="Facebook">
              <FaFacebookF style={{ height: '13px', width: '13px' }} />
            </a>
            <a href={INSTAGRAM} target="_blank" rel="noopener noreferrer" aria-label="Instagram">
              <FaInstagram style={{ height: '14px', width: '14px' }} />
            </a>
            <a href={TIKTOK} target="_blank" rel="noopener noreferrer" aria-label="TikTok">
              <FaTiktok style={{ height: '13px', width: '13px' }} />
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
            <Image src={logo} alt="Watchizer-logo" width={68} height={46} priority quality={90} />
          </Link>
        </div>

        <div className="wz-search-box">
          <SearchBox />
        </div>

        {/* the Hot Deals switch (Part 2.4, 2026-11-05) — only while a Hot Deals campaign is live */}
        <CampaignNav variant="top" />

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
          >
            {/* the button's name is "Cart" FOLLOWED BY what it shows (Lighthouse: label-content-name-mismatch — an
                aria-label of "Cart" alone hid the count and the total from a screen reader and from voice control) */}
            <span className="wz-sr-only">{isRTL ? 'السلة' : 'Cart'}</span>
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

      {/* The live campaigns on a phone, where the nav row is hidden: the Hot Deals switch and the Flash Sale
          countdown, in one slim row. Nothing is drawn without a live campaign (Part 2, 2026-11-05). */}
      {/* phones: the Hot Deals switch is a pill in the page's floating corner (the review of 2026-11-05; the corner, 2026-11-07) */}
      <CampaignNav variant="float" />
      {/* "Spin & win" (2026-11-07, Watchizer only): a link to /spin in the same corner, above the pill, while the wheel is on */}
      <WheelFloat />
      {/* a friend's invite link (?invite=FRIEND-…) readies the code for the checkout (2026-11-09 evening) */}
      <InviteFromLink />
      {/* the line across the top from a click to the new page (item 19): an answer within one frame */}
      <Suspense fallback={null}>
        <NavProgress />
      </Suspense>

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
            {/* Same props as the header logo above → the same optimised URL, so the drawer reuses the
                bytes the header already fetched (it requested the 141,944 B original, 2026-10-11). */}
            <Image src={logo} alt="Watchizer" width={68} height={46} quality={90} />
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

          {/*
            THE MEGA MENU ON A PHONE (the design pass, 2026-11-05): the top level as picture rows, an entry opened
            in place as picture tiles, with its brands and its featured product — the same content, links and
            counts as the desktop mega menu (the twin MegaDrawer), in place of the text accordions that were here.
          */}
          <MegaDrawer active={drawerOpen} omit={DRAWER_OMIT} hrefs={megaHrefs} onNavigate={closeDrawer} />

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
            </>
          )}

          {/* The trust pages (2026-10-02). Phones have no footer, so this is where a shopper on a
              phone finds "Contact us". Real links, so they are in the page's HTML as well. */}
          <div className="wz-drawer-sep" />
          {[
            ['/about-us', 'من نحن', 'About us'],
            ['/contact-us', 'تواصل معنا', 'Contact us'],
            ['/privacy-policy', 'سياسة الخصوصية', 'Privacy policy'],
            ['/terms-and-conditions', 'الشروط والأحكام', 'Terms and conditions'],
          ].map(([href, ar, en]) => (
            <Link key={href} href={href} className="wz-drawer-link" onClick={closeDrawer}>
              {isRTL ? ar : en}
            </Link>
          ))}
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
          {/* Developer credit — bilingual, always shown in both languages */}
          <p className="wz-drawer-credit" dir="ltr">
            <span dir="rtl">تطوير و تصميم مايكل خلف</span>
            <span className="wz-drawer-credit-sep" aria-hidden="true">|</span>
            <a
              href="https://wa.me/201274550956"
              target="_blank"
              rel="noopener noreferrer"
              aria-label="Developed & Designed by Michael Khalaf — WhatsApp"
            >
              <span>Developed &amp; Designed by Michael Khalaf</span>
              <svg
                className="wz-drawer-credit-wa"
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
      </aside>
    </header>

    {/* Mobile spacer: the header is position:fixed on mobile, so reserve its
        height on every page EXCEPT home (where the hero sits under it). */}
    {!heroPage && <div className="wz-bar-spacer" aria-hidden="true" />}
    </>
  )
}

export default Header
