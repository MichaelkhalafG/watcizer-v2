import { Suspense, useEffect, lazy, Component } from 'react'
import './App.css'
import { Route, Routes, useLocation, Navigate } from 'react-router-dom'
import Home from './Pages/Home/Home'
import Header from './Components/Header/Header'
import Footer from './Components/Footer/Footer'
import { useState } from 'react'
import Cart from './Pages/Cart/Cart'
import ScrollToTop from './Components/ScrollToTop/ScrollToTop'
import ProtectedRoute from './Components/Auth/ProtectedRoute'
import { Helmet } from 'react-helmet-async'
import { MyProvider } from './Context/MyProvider'
import { useToastStore } from './Store/toastStore'
import { useUIStore } from './Store/uiStore'
import Alert from '@mui/material/Alert'
import Snackbar from '@mui/material/Snackbar'
import useMediaQuery from '@mui/material/useMediaQuery'
import useCart from './Hooks/useCart'
import CartModal from './Pages/Cart/CartModal'
const NotFound = lazy(() => import('./Pages/Not Found/NotFound'))
const ProductDetail = lazy(() => import('./Components/Product/ProductDetail'))
const Listing = lazy(() => import('./Pages/Listing/Listing'))
// Unified account area — replaces the old EditProfile / WishList / OrderList pages.
const Account = lazy(() => import('./Pages/Account/Account'))
const Login = lazy(() => import('./Pages/Auth/Login/Login'))
const Register = lazy(() => import('./Pages/Auth/Register/Register'))
const AuthCallback = lazy(() => import('./Pages/Auth/AuthCallback'))
const ForgotPassword = lazy(() => import('./Pages/Auth/ForgotPassword'))
const ResetPassword = lazy(() => import('./Pages/Auth/ResetPassword'))
const Blog = lazy(() => import('./Pages/Blog/Blog'))
const Blogs = lazy(() => import('./Pages/Blog/Blogs'))
const Checkout = lazy(() => import('./Pages/Checkout/Checkout'))
const OrderConfirmation = lazy(() => import('./Pages/Checkout/OrderConfirmation'))

// Fallback shown while a lazy route chunk loads.
const PageLoader = () => (
  <div
    style={{
      width: '100%',
      height: '60vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
    }}
  >
    <div
      style={{
        width: 40,
        height: 40,
        borderRadius: '50%',
        border: '2px solid rgba(0,0,0,0.1)',
        borderTopColor: '#262626',
        animation: 'wzAppSpin 1s linear infinite',
      }}
    />
    <style>{`@keyframes wzAppSpin { to { transform: rotate(360deg); } }`}</style>
  </div>
)

// Catches render-time errors so a thrown component degrades to a graceful card
// instead of a blank white screen.
class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error }
  }

  componentDidCatch(error, info) {
    console.error('App Error:', error, info)
  }

  render() {
    if (this.state.hasError) {
      return (
        <div
          style={{
            width: '100%',
            minHeight: '60vh',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            justifyContent: 'center',
            gap: 16,
            padding: 32,
            textAlign: 'center',
          }}
        >
          <h2 style={{ fontFamily: 'Georgia, serif', fontWeight: 300, color: '#1a1a1a' }}>
            Something went wrong
          </h2>
          <p style={{ color: 'rgba(0,0,0,0.5)', fontSize: 14 }}>
            {this.state.error?.message || 'Unknown error'}
          </p>
          <button
            onClick={() => {
              this.setState({ hasError: false, error: null })
              window.location.href = '/'
            }}
            style={{
              padding: '10px 24px',
              background: '#262626',
              color: '#fff',
              border: 'none',
              cursor: 'pointer',
              fontSize: 12,
              letterSpacing: '0.1em',
              textTransform: 'uppercase',
            }}
          >
            Go Home
          </button>
        </div>
      )
    }
    return this.props.children
  }
}

function App() {
  // FB + TikTok pixels are bootstrapped once in index.html (init + PageView).
  // The old useFacebookPixel hook did a SECOND fbq init + PageView here — removed
  // to avoid the duplicate init / double PageView.
  return (
    <MyProvider>
      <MainApp />
    </MyProvider>
  )
}

// Routes that render full-bleed (no global header/footer/profile chrome).
const AUTH_ROUTES = ['/login', '/register', '/forgot-password', '/reset-password', '/auth/callback']

function MainApp() {
  const { language } = useUIStore()
  const location = useLocation()
  const isAuthPage = AUTH_ROUTES.includes(location.pathname)
  const {
    open: openAlert,
    type: alertType,
    message: alertMessage,
    hideToast,
  } = useToastStore()
  const { cart } = useCart()
  const isDesktop = useMediaQuery('(min-width:768px)')
  const [cartModalOpen, setCartModalOpen] = useState(false)
  const [prevCartCount, setPrevCartCount] = useState(cart?.cart_item?.length || 0)

  useEffect(() => {
    const currentCount = cart?.cart_item?.length || 0
    if (currentCount > prevCartCount) {
      setCartModalOpen(true)
    }
    setPrevCartCount(currentCount)
    // eslint-disable-next-line
  }, [cart?.cart_item?.length])

  // Keep the whole document direction/lang in sync with the active language —
  // canonical top-level place, so RTL applies even where the header toggle isn't.
  useEffect(() => {
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = language
  }, [language])

  return (
    <>
      {/* Skip link — the FIRST focusable element; lets keyboard/AT users jump
          straight to the main landmark, past the header/nav. Hidden until focused. */}
      <a href="#main-content" className="wz-skip-link">
        {language === 'ar' ? 'تخطَّ إلى المحتوى الرئيسي' : 'Skip to main content'}
      </a>
      {/* Global SEO defaults — each page renders its own <Helmet> to override
          title/description/canonical; anything not overridden falls back here. */}
      <Helmet>
          {/* Basic SEO */}
          <title>Watchizer - أفخم الساعات والإكسسوارات | تسوق الآن بأسعار مميزة</title>
          <meta
            name="description"
            content="اكتشف أفخم الساعات والإكسسوارات في Watchizer. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية."
          />
          <meta
            name="keywords"
            content="luxury watches, men's watches, women's watches, branded watches, best watch store Egypt, online watch shop, stylish watches, Rolex, Omega, TAG Heuer, Swiss watches, ساعات فاخرة, ساعات رجالي, ساعات نسائية, متجر ساعات في مصر, ساعات كوارتز, ساعات ذكية, شراء ساعات أونلاين"
          />
          <meta name="author" content="Watchizer - خبراء الساعات الفاخرة" />
          <meta
            name="robots"
            content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1"
          />
          <link rel="canonical" href="https://watchizereg.com" />

          {/* Language and Region */}
          <meta name="language" content="ar-eg, en-eg" />
          <meta name="geo.region" content="EG" />
          <meta name="geo.placename" content="Cairo, Egypt" />

          {/* Theme & Appearance */}
          <meta name="theme-color" content="#000000" />
          {/* Favicon/app icons live in index.html (static, cache-busted) — a
              Helmet-injected icon is applied after React mounts and browsers
              often ignore it for the initial favicon. */}

          {/* Web Manifest for PWA */}
          <link rel="manifest" href="/manifest.json" />

          {/* Structured Data (Schema.org) */}
          <script type="application/ld+json">
            {JSON.stringify({
              '@context': 'https://schema.org/',
              '@type': 'Store',
              name: 'Watchizer - Luxury Watches & Accessories',
              url: 'https://watchizereg.com',
              logo: 'https://watchizereg.com/logo.svg',
              image: 'https://watchizereg.com/logo.svg',
              description:
                'Discover a premium collection of luxury watches and fashion accessories at Watchizer. Shop exclusive timepieces with elegant designs and unbeatable prices in Egypt.',
              address: {
                '@type': 'PostalAddress',
                streetAddress: 'اركديا مول . كورنيش النيل . امتداد ماسبيرو',
                addressLocality: 'Cairo',
                addressRegion: 'Cairo Governorate',
                addressCountry: 'EG',
              },
              geo: {
                '@type': 'GeoCoordinates',
                latitude: 30.0444,
                longitude: 31.2357,
              },
              openingHoursSpecification: [
                {
                  '@type': 'OpeningHoursSpecification',
                  dayOfWeek: [
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday',
                    'Friday',
                    'Saturday',
                    'Sunday',
                  ],
                  opens: '10:00',
                  closes: '22:00',
                },
              ],
              sameAs: ['https://www.facebook.com/watchizer', 'https://www.instagram.com/watchizer'],
              contactPoint: {
                '@type': 'ContactPoint',
                telephone: '+201551096234',
                contactType: 'customer service',
                areaServed: 'EG',
                availableLanguage: ['English', 'Arabic'],
              },
            })}
          </script>
      </Helmet>
      {!isAuthPage && (
        <div className="wz-header-wrapper">
          <Header />
        </div>
      )}
      <CartModal open={cartModalOpen} onClose={() => setCartModalOpen(false)} cart={cart} />
      <Snackbar
        open={openAlert}
        autoHideDuration={3000}
        onClose={() => hideToast()}
        anchorOrigin={{
          vertical: isDesktop ? 'bottom' : 'top',
          horizontal: isDesktop ? 'right' : 'left',
        }}
      >
        <Alert severity={alertType} onClose={() => hideToast()}>
          {alertMessage}
        </Alert>
      </Snackbar>
      <ScrollToTop />
      <ErrorBoundary>
        <Suspense fallback={<PageLoader />}>
          <main id="main-content" tabIndex={-1}>
          <Routes>
            <Route path="/" exact element={<Home />} />
        <Route
          path="/listing"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/products/:id"
          element={
            <Suspense fallback={<PageLoader />}>
              <ProductDetail />
            </Suspense>
          }
        />
        <Route
          path="/product/:slug"
          element={
            <Suspense fallback={<PageLoader />}>
              <ProductDetail />
            </Suspense>
          }
        />
        <Route
          path="/offer/:slug"
          element={
            <Suspense fallback={<PageLoader />}>
              <ProductDetail />
            </Suspense>
          }
        />
        <Route path="/cart" element={<Cart />} />
        <Route path="/checkout" element={<Checkout />} />
        <Route path="/order-confirmation" element={<OrderConfirmation />} />
        <Route
          path="/category/:category"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/login"
          element={
            <Suspense fallback={<PageLoader />}>
              <Login />
            </Suspense>
          }
        />
        <Route
          path="/register"
          element={
            <Suspense fallback={<PageLoader />}>
              <Register />
            </Suspense>
          }
        />
        <Route
          path="/forgot-password"
          element={
            <Suspense fallback={<PageLoader />}>
              <ForgotPassword />
            </Suspense>
          }
        />
        <Route
          path="/reset-password"
          element={
            <Suspense fallback={<PageLoader />}>
              <ResetPassword />
            </Suspense>
          }
        />
        <Route
          path="/auth/callback"
          element={
            <Suspense fallback={<PageLoader />}>
              <AuthCallback />
            </Suspense>
          }
        />
        <Route
          path="/brand/:brand"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/subtypes/:subtype"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/:suptype/:brand"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/grade/:grade"
          element={
            <Suspense fallback={<PageLoader />}>
              <Listing />
            </Suspense>
          }
        />
        {/* Legacy pages retired — Listing handles offers/search via URL params. */}
        <Route path="/offers" element={<Navigate to="/listing?offers=true" replace />} />
        <Route path="/listingsearch" element={<Navigate to="/listing" replace />} />
        {/* Unified account area */}
        <Route
          path="/account"
          element={
            <ProtectedRoute>
              <Suspense fallback={<PageLoader />}>
                <Account />
              </Suspense>
            </ProtectedRoute>
          }
        />
        {/* Backward-compatible redirects for the old separate pages */}
        <Route path="/edit-profile" element={<Navigate to="/account?tab=profile" replace />} />
        <Route path="/order-list" element={<Navigate to="/account?tab=orders" replace />} />
        <Route path="/wish-list" element={<Navigate to="/account?tab=wishlist" replace />} />
        {/* Legacy mobile search page retired — the header search + /listing?q handle it. */}
        <Route path="/Search" element={<Navigate to="/listing" replace />} />
        <Route
          path="/blogs"
          element={
            <Suspense fallback={<PageLoader />}>
              <Blogs />
            </Suspense>
          }
        />
        <Route
          path="/blog/:name"
          element={
            <Suspense fallback={<PageLoader />}>
              <Blog />
            </Suspense>
          }
        />
        <Route
          path="*"
          element={
            <Suspense fallback={<PageLoader />}>
              <NotFound />
            </Suspense>
          }
        />
          </Routes>
          </main>
        </Suspense>
      </ErrorBoundary>
      {!isAuthPage && isDesktop ? (
        <Suspense fallback={<PageLoader />}>
          <Footer />
        </Suspense>
      ) : null}
    </>
  )
}

export default App
