'use client'
import { useCallback, useEffect, useState, useMemo } from 'react'
import { useRouter } from 'next/navigation'
import { MyContext } from './Context'
import './loader.css'
import useCart from '../Hooks/useCart'
import { useUIStore } from '../Store/uiStore'
import { useAuthStore } from '../Store/authStore'
import { useToastStore } from '../Store/toastStore'
import http, { fetchWishList } from './api'
import { useTables } from '../Hooks/queries/useTables'
import { useProducts } from '../Hooks/queries/useProducts'
import { useOffers } from '../Hooks/queries/useOffers'
import { useShipping } from '../Hooks/queries/useShipping'

export const MyProvider = ({ children }) => {
  const { cart } = useCart()

  // Cross-cutting state now lives in focused Zustand stores.
  const language = useUIStore((s) => s.language)
  const user_id = useAuthStore((s) => s.userId)
  const showToast = useToastStore((s) => s.showToast)

  const [filteredProducts, setFilteredProducts] = useState([])
  const [productsCount, setProductsCount] = useState(0)
  const [wishList, setwishList] = useState([])
  const router = useRouter()
  const [total_cart_price, settotal_cart_price] = useState()
  const [shippingid, setShippingid] = useState('')
  const [shipping, setShipping] = useState('')
  const [shippingname, setShippingName] = useState('')

  // Server data lives in TanStack Query and consumers read it directly from the
  // query hooks (useCatalog/useOffers/…). MyProvider only pulls what it still
  // needs INTERNALLY: the localized catalog + offers (for the wishlist fetch) and
  // the shipping cities (for shippingPrices). Products/tables/offers/banners are
  // NO LONGER exposed on context — see the trimmed `values` below.
  const { data: tablesData } = useTables()
  const catalog = useProducts(tablesData).data
  const productsEn = catalog?.productsEn ?? []
  const productsAr = catalog?.productsAr ?? []
  const { data: offers = [] } = useOffers()
  const { data: shippingData = [] } = useShipping()

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

  // Single toggle used by every heart button across the app. If the item is
  // already wishlisted it is removed (DELETE by wishlist_item id); otherwise it
  // is added. State updates immediately so all hearts stay in sync.
  const handleAddTowishlist = useCallback(
    async (id, type) => {
      if (!user_id) {
        showToast(
          language === 'ar' ? 'يجب تسجيل الدخول أولاً!' : 'You must login first!',
          'warning',
        )
        router.push('/login')
        return
      }

      const existing = wishList.find((w) =>
        type === 'p'
          ? Number(w.product_id) === Number(id)
          : Number(w.offer_id) === Number(id),
      )

      try {
        if (existing) {
          // Remove — delete the wishlist_item row, then drop it from state.
          await http.delete(`/delete_wishlist/${existing.id}`)
          setwishList((prev) => prev.filter((w) => w.id !== existing.id))
          showToast(
            language === 'ar' ? 'تمت الإزالة من المفضلة' : 'Removed from the Wish List!',
            'success',
          )
        } else {
          // Add — then re-fetch so the new entry carries full product data.
          const payload = {
            user_id: user_id,
            ...(type === 'p' ? { product_id: id } : { offer_id: id }),
          }
          await http.post('/add_wishlist', payload)
          await fetchWishList(user_id, products, offers, language, setwishList)
          showToast(
            language === 'ar' ? 'تمت الإضافة إلى المفضلة' : 'Added to the Wish List!',
            'success',
          )
        }
      } catch {
        showToast(
          language === 'ar' ? 'حدث خطأ، حاول مرة أخرى.' : 'Something went wrong, please try again.',
          'error',
        )
      }
    },
    [language, router, user_id, offers, products, showToast, wishList],
  )

  // Trimmed context value — ONLY the non-server state still read by consumers:
  // wishlist, cart-derived counters, shipping selection + derived prices, and the
  // listing-search setter. All server data (products/tables/offers/banners) now
  // comes from the TanStack Query hooks, so it's intentionally not exposed here.
  const values = useMemo(
    () => ({
      // Wishlist (stays in context)
      wishList,
      setwishList,
      handleAddTowishlist,
      // Cart-derived counters
      productsCount,
      total_cart_price,
      // Shipping selection + derived city prices
      shippingid,
      setShippingid,
      setShipping,
      setShippingName,
      shippingPrices,
      // Listing search results setter (SearchBox writes; /listingsearch reads)
      setFilteredProducts,
    }),
    [
      wishList,
      handleAddTowishlist,
      productsCount,
      total_cart_price,
      shippingid,
      shippingPrices,
    ],
  )
  return <MyContext.Provider value={values}>{children}</MyContext.Provider>
}
