import { useContext, useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import logo from '../../assets/images/logo.webp'
import LanguageDropdown from '../LanguageDropdown/LanguageDropdowen'
import { LazyLoadImage } from 'react-lazy-load-image-component'
import 'react-lazy-load-image-component/src/effects/blur.css'
import { IoBagOutline } from 'react-icons/io5'
import './Header.css'
import SearchBox from './SearchBox/SearchBox'
import Nav from './Nav/Nav'
import userimg from '../../assets/images/user.webp'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { FaFacebookF, FaInstagram } from 'react-icons/fa'

function Header() {
  const { productsCount, total_cart_price } = useContext(MyContext)
  const { language } = useUIStore()
  const { userId: user_id } = useAuthStore()
  const [scrolled, setScrolled] = useState(false)

  // Subtle depth on Row 1 once scrolled past 40px.
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 40)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  // Scrolling announcement strip — trust signals, brands, Watchizer info.
  const englishItems = [
    'Authentic Luxury Watches — Certified & Guaranteed',
    '500+ Premium Timepieces In Stock',
    'Rolex · Omega · Cartier · TAG Heuer · Breitling',
    'Free Shipping on Orders Over 5,000 EGP',
    "Watchizer — Egypt's Premier Watch Destination",
    'Patek Philippe · Audemars Piguet · IWC · Hublot',
    'Trusted by Watch Enthusiasts Since 2020',
    'Secure Payment · Easy Returns · Expert Support',
  ]
  const arabicItems = [
    'ساعات فاخرة أصلية — معتمدة ومضمونة',
    'أكثر من 500 ساعة فاخرة متوفرة',
    'رولكس · أوميغا · كارتييه · تاغ هوير · بريتلينج',
    'شحن مجاني للطلبات فوق 5,000 جنيه',
    'واتشايزر — وجهتك الأولى للساعات الفاخرة في مصر',
    'باتيك فيليب · أوديمار بيغيه · IWC · هوبلوت',
    'موثوق من عشاق الساعات منذ 2020',
    'دفع آمن · إرجاع سهل · دعم متخصص',
  ]
  const marqueeItems = language === 'ar' ? arabicItems : englishItems

  return (
    <header className={`wz-header lato-regular ${scrolled ? 'wz-scrolled' : ''}`}>
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
        <div className="wz-logo">
          <Link to={'/'}>
            <LazyLoadImage src={logo} alt="Watchizer-logo" effect="blur" width="150" height="46" />
          </Link>
        </div>

        <div className="wz-search-box">
          <SearchBox />
        </div>

        <div className="wz-actions">
          <div className="wz-lang">
            <LanguageDropdown />
          </div>

          {user_id && user_id !== null ? (
            <div className="wz-profile">
              <span className="profile-name">{[].find((u) => u.id === user_id)?.first_name}</span>
              <LazyLoadImage
                src={
                  sessionStorage.getItem('image') !== null
                    ? sessionStorage.getItem('image')
                    : userimg
                }
                alt="user"
                className="rounded-circle"
                effect="blur"
              />
            </div>
          ) : (
            <Link to={'/login'} className="wz-login" title="Sign In">
              {language === 'ar' ? 'تسجيل الدخول' : 'Sign In'}
            </Link>
          )}

          <div className="wz-cart">
            <span className="wz-cart-price">
              {productsCount === 0 ? '0.00' : total_cart_price}
              {language === 'ar' ? ' ج.م ' : ' EG '}
            </span>
            <Link className="wz-action-btn wz-cart-link" to={'/cart'} title="cart">
              <IoBagOutline style={{ fontSize: '22px' }} />
              {productsCount > 0 && (
                <span className="wz-badge" key={productsCount}>
                  {productsCount}
                </span>
              )}
            </Link>
          </div>
        </div>
      </div>

      {/* ── SECTION C — nav row (dark) ─────────────────────── */}
      <div className="wz-row2">
        <Nav />
      </div>
    </header>
  )
}

export default Header
