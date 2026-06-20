import { useCallback, useEffect, useState, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { MyContext } from './Context'
import './loader.css'
import useFetchTablesAndProducts from './FetchTablesAndProducts'
import useCart, { getItemKey } from '../Hooks/useCart'
import Loader from '../Components/Loader/Loader'
import { useUIStore } from '../Store/uiStore'
import { useAuthStore } from '../Store/authStore'
import { useToastStore } from '../Store/toastStore'
import http, {
  fetchShippingCities,
  fetchBanners,
  fetchOffers,
  fetchCart,
  fetchWishList,
} from './api'

export const MyProvider = ({ children }) => {
  const { cart, updateQuantity } = useCart()

  // Cross-cutting state now lives in focused Zustand stores.
  const language = useUIStore((s) => s.language)
  const user_id = useAuthStore((s) => s.userId)
  const showToast = useToastStore((s) => s.showToast)

  const [version, setVersion] = useState(0)
  const [watches, setWatches] = useState([])
  const [fashion, setFashion] = useState([])
  const [productsEn, setProductsEn] = useState([])
  const [productsAr, setProductsAr] = useState([])
  const [filteredProducts, setFilteredProducts] = useState([])
  const [ratings, setRatings] = useState([])
  const [tables, setTables] = useState({})
  const [sideBanners, setSideBanners] = useState([])
  const [bottomBanners, setBottomBanners] = useState([])
  const [HomeBannersPc, setHomeBannersPc] = useState([])
  const [HomeBannersMob, setHomeBannersMob] = useState([])
  const [productsCount, setProductsCount] = useState(0)
  const [WishListCount, setWishListCount] = useState(0)
  const [wishList, setwishList] = useState([])
  const [offers, setOffers] = useState([])
  const navigate = useNavigate()
  const [total_cart_price, settotal_cart_price] = useState()
  const [shippingid, setShippingid] = useState('')
  const [shipping, setShipping] = useState('')
  const [shippingData, setShippingData] = useState([])
  const [shippingname, setShippingName] = useState('')

  const helperforsetingcategories = (setCategory, products, categoryTypeName) => {
    const filteredProducts = products.filter(
      (product) => product.category_type === categoryTypeName,
    )
    setCategory(filteredProducts || [])
  }

  useEffect(() => {
    ;(async () => {
      fetchShippingCities(setShippingData)
      fetchBanners(setSideBanners, setBottomBanners, setHomeBannersPc, setHomeBannersMob)
      fetchOffers(setOffers)
    })()
  }, [])

  const isFetching = useFetchTablesAndProducts(setTables, setRatings, setProductsEn, setProductsAr)

  const shippingPrices = useMemo(() => {
    return shippingData.map((city) => ({
      id: city.id.toString(),
      GovernorateEn: city.translations.find((t) => t.locale === 'en')?.city_name || city.city_name,
      GovernorateAr: city.translations.find((t) => t.locale === 'ar')?.city_name || city.city_name,
      Price: parseFloat(city.shipping_cost),
    }))
  }, [shippingData])

  useEffect(() => {
    if (shippingPrices.length > 0) {
      const defaultShipping = shippingPrices[0]
      setShippingid(defaultShipping.id)
      setShipping(defaultShipping.Price.toString())
      setShippingName(
        language === 'ar' ? defaultShipping.GovernorateAr : defaultShipping.GovernorateEn,
      )
    } else {
      setShippingid('')
      setShipping('')
    }
  }, [shippingPrices, language])

  const products = useMemo(() => {
    if (!productsEn || !productsAr) return []
    return language === 'en' ? productsEn : productsAr
  }, [language, productsEn, productsAr])

  useEffect(() => {
    if (products.length > 0) {
      helperforsetingcategories(setWatches, products, 'Watches')
      helperforsetingcategories(setFashion, products, 'Fashion')
    }
  }, [products])

  useEffect(() => {
    if (user_id) {
      // fetchCart(user_id, products, offers, language, setCart);
      fetchWishList(user_id, products, offers, language, setwishList)
    }
  }, [user_id, offers, products, language])

  useEffect(() => {
    const cartItems = Array.isArray(cart.cart_item) ? cart.cart_item : []

    setProductsCount(
      cartItems.reduce((total, item) => total + (parseInt(item.quantity, 10) || 0), 0),
    )

    setWishListCount(wishList.reduce((total) => total + 1, 0))

    const calculateTotalCartPrice = () => {
      const subtotal = cartItems.reduce((total, item) => {
        const piecePrice = parseFloat(item.piece_price || 0)
        const quantity = parseInt(item.quantity || 1, 10)
        return total + piecePrice * quantity
      }, 0)

      const shippingCost = parseFloat(shipping || 0)
      const totalPrice = subtotal + shippingCost

      settotal_cart_price(totalPrice.toFixed(2))
    }

    calculateTotalCartPrice()
  }, [cart, wishList, shipping])

  const handleQuantityChange = useCallback(
    (item, value) => {
      const currentQty = item.quantity || 1
      const newQty = currentQty + value

      if (newQty > 0) {
        const identifier = getItemKey(item)
        updateQuantity(identifier, newQty)
      }
    },
    [updateQuantity],
  )

  const handleAddTowishlist = useCallback(
    (id, type) => {
      if (!user_id) {
        showToast(
          language === 'ar' ? 'يجب تسجيل الدخول أولاً!' : 'You must login first!',
          'warning',
        )
        navigate('/login')
      } else {
        const payload = {
          user_id: user_id,
          ...(type === 'p' ? { product_id: id } : { offer_id: id }),
        }

        http
          .post('/add_wishlist', payload)
          .then(() => {
            showToast(
              language === 'ar' ? 'تمت الإضافة إلى المفضل!' : 'Added to the Wish List!',
              'success',
            )
            fetchWishList(user_id, products, offers, language, setwishList)
          })
          .catch(() => {
            showToast(
              language === 'ar'
                ? 'حدث خطأ أثناء الإضافة إلى المفضل.'
                : 'An error occurred while adding to the Wish List.',
              'error',
            )
          })
      }
    },
    [language, navigate, user_id, offers, products, showToast],
  )

  const values = useMemo(
    () => ({
      wishList,
      setwishList,
      shippingid,
      setShippingid,
      productsCount,
      setProductsCount,
      WishListCount,
      setWishListCount,
      shipping,
      setShipping,
      filteredProducts,
      setFilteredProducts,
      total_cart_price,
      settotal_cart_price,
      version,
      setVersion,
      fashion,
      setFashion,
      watches,
      setWatches,
      handleAddTowishlist,
      fetchWishList,
      ratings,
      fetchCart,
      shippingPrices,
      products,
      isFetching,
      tables,
      sideBanners,
      bottomBanners,
      HomeBannersPc,
      HomeBannersMob,
      handleQuantityChange,
      shippingname,
      setShippingName,
      offers,
      Loader,
    }),
    [
      wishList,
      setwishList,
      shippingid,
      setShippingid,
      productsCount,
      setProductsCount,
      WishListCount,
      setWishListCount,
      shipping,
      setShipping,
      filteredProducts,
      setFilteredProducts,
      total_cart_price,
      settotal_cart_price,
      version,
      setVersion,
      fashion,
      setFashion,
      watches,
      setWatches,
      handleAddTowishlist,
      ratings,
      shippingPrices,
      products,
      isFetching,
      tables,
      sideBanners,
      bottomBanners,
      HomeBannersPc,
      HomeBannersMob,
      handleQuantityChange,
      shippingname,
      setShippingName,
      offers,
    ],
  )
  return <MyContext.Provider value={values}>{children}</MyContext.Provider>
}
