import { Suspense, useEffect, lazy, useContext, Component } from 'react'
import './App.css'
import { Route, Routes } from 'react-router-dom'
import Home from './Pages/Home/Home'
import Header from './Components/Header/Header'
import Footer from './Components/Footer/Footer'
import { useState } from 'react'
import Cart from './Pages/Cart/Cart'
import Checkout from './Pages/Checkout/Checkout'
import ScrollToTop from './Components/ScrollToTop/ScrollToTop'
import PhoneNavBar from './Components/Header/PhoneNavBar/PhoneNavBar'
import { HelmetProvider, Helmet } from 'react-helmet-async'
import { MyContext } from './Context/Context'
import { MyProvider } from './Context/MyProvider'
import { useAuthStore } from './Store/authStore'
import { useToastStore } from './Store/toastStore'
import { Alert, Snackbar, useMediaQuery } from '@mui/material'
import useCart from './Hooks/useCart'
import useFacebookPixel from './scripts/useFacebookPixel'
import CartModal from './Pages/Cart/CartModal'
const NotFound = lazy(() => import('./Pages/Not Found/NotFound'))
const ProductDisplay = lazy(() => import('./Components/Product/ProductDisplay'))
const Listing = lazy(() => import('./Pages/Listing/Listing'))
const ListingSearch = lazy(() => import('./Pages/Listing/ListingSearch'))
const ListingGrades = lazy(() => import('./Pages/Listing/ListingGrades'))
const Listingoffers = lazy(() => import('./Pages/Listing/Listingoffers'))
const ProfileSpeed = lazy(() => import('./Components/Header/Nav/ProfileSpeed'))
const EditProfile = lazy(() => import('./Pages/EditProfile/EditProfile'))
const WishList = lazy(() => import('./Pages/WishList/WishList'))
const OrderList = lazy(() => import('./Pages/OrderList/OrderList'))
const OfferDisplay = lazy(() => import('./Components/Product/OfferDisplay'))
const Login = lazy(() => import('./Pages/Auth/Login/Login'))
const Register = lazy(() => import('./Pages/Auth/Register/Register'))
const ProfileSpeedPhoneNotLogin = lazy(
  () => import('./Components/Header/Nav/ProfileSpeedPhoneNotLogin'),
)
const SearchPageForPhone = lazy(() => import('./Pages/SearchPageForPhone/SearchPageForPhone'))
const Blog = lazy(() => import('./Pages/Blog/Blog'))
const Blogs = lazy(() => import('./Pages/Blog/Blogs'))

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
  useFacebookPixel('1611910119460872')
  return (
    <MyProvider>
      <MainApp />
    </MyProvider>
  )
}

function MainApp() {
  const { isFetching, Loader } = useContext(MyContext)
  const { userId: user_id } = useAuthStore()
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

  const renderProfileComponent = () => {
    if (user_id !== null) {
      return <ProfileSpeed />
    } else {
      return isDesktop ? null : <ProfileSpeedPhoneNotLogin />
    }
  }

  // Gate the loader on the fetch lifecycle, not on products.length — an empty
  // catalog (or empty DB) must still clear the loader once the fetch completes.
  const showLoader = isFetching

  return (
    <>
      <HelmetProvider>
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
          <link rel="icon" href="/favicon.ico" />
          <link rel="apple-touch-icon" href="/logo.svg" />

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
      </HelmetProvider>
      {showLoader && (
        <div
          style={{
            position: 'fixed',
            zIndex: 2000,
            top: 0,
            left: 0,
            width: '100vw',
            height: '100vh',
            background: 'rgba(255,255,255,0.85)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Loader />
        </div>
      )}
      <div className="header rounded-bottom-4 sticky-top ">
        <Header />
      </div>
      <div className="phone-nav">
        <PhoneNavBar />
      </div>
      <CartModal open={cartModalOpen} onClose={() => setCartModalOpen(false)} cart={cart} />
      {renderProfileComponent()}
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
          <Routes>
            <Route path="/" exact element={<Home />} />
        <Route
          path="/listing"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/products/:id"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/product/:slug"
          element={
            <Suspense fallback={<Loader />}>
              <ProductDisplay />
            </Suspense>
          }
        />
        <Route
          path="/offer/:id"
          element={
            <Suspense fallback={<Loader />}>
              <OfferDisplay />
            </Suspense>
          }
        />
        <Route path="/cart" element={<Cart />} />
        <Route path="/checkout" element={<Checkout />} />
        <Route
          path="/category/:category"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/login"
          element={
            <Suspense fallback={<Loader />}>
              <Login />
            </Suspense>
          }
        />
        <Route
          path="/register"
          element={
            <Suspense fallback={<Loader />}>
              <Register />
            </Suspense>
          }
        />
        <Route
          path="/brand/:brand"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/subtypes/:subtype"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/:suptype/:brand"
          element={
            <Suspense fallback={<Loader />}>
              <Listing />
            </Suspense>
          }
        />
        <Route
          path="/grade/:grade"
          element={
            <Suspense fallback={<Loader />}>
              <ListingGrades />
            </Suspense>
          }
        />
        <Route
          path="/offers"
          element={
            <Suspense fallback={<Loader />}>
              <Listingoffers />
            </Suspense>
          }
        />
        <Route
          path="/listingsearch"
          element={
            <Suspense fallback={<Loader />}>
              <ListingSearch />
            </Suspense>
          }
        />
        <Route
          path="/edit-profile"
          element={
            <Suspense fallback={<Loader />}>
              <EditProfile />
            </Suspense>
          }
        />
        <Route
          path="/Search"
          element={
            <Suspense fallback={<Loader />}>
              <SearchPageForPhone />
            </Suspense>
          }
        />
        <Route
          path="/wish-list"
          element={
            <Suspense fallback={<Loader />}>
              <WishList />
            </Suspense>
          }
        />
        <Route
          path="/order-list"
          element={
            <Suspense fallback={<Loader />}>
              <OrderList />
            </Suspense>
          }
        />
        <Route
          path="/blogs"
          element={
            <Suspense fallback={<Loader />}>
              <Blogs />
            </Suspense>
          }
        />
        <Route
          path="/blog/:name"
          element={
            <Suspense fallback={<Loader />}>
              <Blog />
            </Suspense>
          }
        />
        <Route
          path="*"
          element={
            <Suspense fallback={<Loader />}>
              <NotFound />
            </Suspense>
          }
        />
          </Routes>
        </Suspense>
      </ErrorBoundary>
      {isDesktop ? (
        <Suspense fallback={<Loader />}>
          <Footer />
        </Suspense>
      ) : null}
    </>
  )
}

export default App
