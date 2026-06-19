import { startTransition, useCallback, useEffect, useState, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { MyContext } from './Context'
// import { BiLoaderCircle } from "react-icons/bi";
import './loader.css'
import useFetchTablesAndProducts from './FetchTablesAndProducts'
import useCart, { getItemKey } from '../Hooks/useCart'
import Loader from '../Components/Loader/Loader'
import http, {
  fetchShippingCities,
  fetchBanners,
  fetchOffers,
  fetchCart,
  fetchWishList,
} from './api'

export const MyProvider = ({ children }) => {
  const { cart, updateQuantity } = useCart()
  const [version, setVersion] = useState(0)
  const [language, setLanguage] = useState('en')
  const [watches, setWatches] = useState([])
  const [fashion, setFashion] = useState([])
  const [windowWidth, setwindowWidth] = useState()
  const [productsEn, setProductsEn] = useState([])
  const [currentPage, setCurrentPage] = useState(1)
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
  const [user_id, setuser_id] = useState(null)
  const [users, setusers] = useState([])
  const [total_cart_price, settotal_cart_price] = useState()
  const [shippingid, setShippingid] = useState('')
  const [shipping, setShipping] = useState('')
  const [shippingData, setShippingData] = useState([])
  const [shippingname, setShippingName] = useState('')
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [openAlert, setOpenAlert] = useState(false)
  const [filters, setFilters] = useState({
    categories: [],
    brands: [],
    subTypes: [],
    price: [0, 6000],
  })
  const [gradesfilters, setgradesfilters] = useState({
    categories: [],
    brands: [],
    subTypes: [],
    grades: [],
    price: [0, 6000],
  })
  const [offersfilters, setOffersFilters] = useState({
    categories: [],
    price: [0, 6000],
    ratings: [],
  })

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
    setuser_id(
      sessionStorage.getItem('user_id') ? parseInt(sessionStorage.getItem('user_id')) : null,
    )
  }, [])

  useEffect(() => {
    if (user_id) {
      // fetchCart(user_id, products, offers, language, setCart);
      fetchWishList(user_id, products, offers, language, setwishList)
    }
  }, [user_id, offers, products, language])

  // useEffect(() => {
  //     setProductsCount(cart.reduce((total, item) => total + (item.quantity || 0), 0));
  //     setWishListCount(wishList.reduce((total) => total + 1, 0));
  //     const calculateTotalCartPrice = () => {
  //         const subtotal = cart.reduce((total, item) => {
  //             const piecePrice = parseFloat(item.piece_price || 0);
  //             const quantity = parseInt(item.quantity || 1, 10);
  //             return total + piecePrice * quantity;
  //         }, 0);
  //         const shippingCost = parseFloat(shipping || 0);
  //         const totalPrice = subtotal + shippingCost;

  //         settotal_cart_price(totalPrice.toFixed(2));
  //     };
  //     calculateTotalCartPrice();
  // }, [cart, wishList, shipping]);

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

  const showAlert = (message, type) => {
    setAlertMessage(message)
    setAlertType(type)
    setOpenAlert(true)
  }

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
        showAlert(
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
            showAlert(
              language === 'ar' ? 'تمت الإضافة إلى المفضل!' : 'Added to the Wish List!',
              'success',
            )
            fetchWishList(user_id, products, offers, language, setwishList)
          })
          .catch(() => {
            // console.error("Error adding to cart:", error);
            showAlert(
              language === 'ar'
                ? 'حدث خطأ أثناء الإضافة إلى المفضل.'
                : 'An error occurred while adding to the Wish List.',
              'error',
            )
          })
      }
    },
    [language, navigate, user_id, offers, products],
  )

  // const handleQuantityChange = useCallback((index, value) => {
  //     setCart((prevCart) => {
  //         const updatedCart = [...prevCart];
  //         const item = updatedCart[index];
  //         if (!item) return updatedCart;
  //         const newQuantity = (item.quantity || 0) + value;
  //         if (newQuantity > 0) {
  //             item.quantity = newQuantity;
  //         }
  //         return updatedCart;
  //     });
  // }, []);

  function getWindowWidth() {
    return window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth
  }

  useEffect(() => {
    startTransition(() => {
      setwindowWidth(getWindowWidth())
    })

    const handleResize = () => {
      startTransition(() => {
        setwindowWidth(getWindowWidth())
      })
    }

    window.addEventListener('resize', handleResize)

    return () => {
      window.removeEventListener('resize', handleResize)
    }
  }, [])
  const values = useMemo(
    () => ({
      // cart, setCart,
      user_id,
      setuser_id,
      wishList,
      setwishList,
      language,
      setLanguage,
      shippingid,
      setShippingid,
      currentPage,
      setCurrentPage,
      productsCount,
      setProductsCount,
      WishListCount,
      setWishListCount,
      windowWidth,
      shipping,
      setShipping,
      filteredProducts,
      setFilteredProducts,
      total_cart_price,
      settotal_cart_price,
      gradesfilters,
      setgradesfilters,
      offersfilters,
      setOffersFilters,
      version,
      setVersion,
      filters,
      setFilters,
      fashion,
      setFashion,
      watches,
      setWatches,
      users,
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
      alertMessage,
      alertType,
      openAlert,
      setOpenAlert,
    }),
    [
      // cart, setCart,
      user_id,
      setuser_id,
      wishList,
      setwishList,
      language,
      setLanguage,
      shippingid,
      setShippingid,
      currentPage,
      setCurrentPage,
      productsCount,
      setProductsCount,
      WishListCount,
      setWishListCount,
      windowWidth,
      shipping,
      setShipping,
      filteredProducts,
      setFilteredProducts,
      total_cart_price,
      settotal_cart_price,
      gradesfilters,
      setgradesfilters,
      offersfilters,
      setOffersFilters,
      version,
      setVersion,
      filters,
      setFilters,
      fashion,
      setFashion,
      watches,
      setWatches,
      users,
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
      Loader,
      alertMessage,
      alertType,
      openAlert,
      setOpenAlert,
    ],
  )
  return <MyContext.Provider value={values}>{children}</MyContext.Provider>
}
