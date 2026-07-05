import axios from 'axios'
import { getImageUrl } from '../utils/imageUrl'
import { useAuthStore } from '../Store/authStore'
import { API_BASE, PUBLIC_API_KEY } from '../lib/env'

const http = axios.create({
  baseURL: API_BASE,
})

http.interceptors.request.use((config) => {
  config.headers['Api-Code'] = PUBLIC_API_KEY

  // Browser-only: the JWT / guest token live in web storage. On the server
  // (SSR) there is no session, so we send only the public Api-Code header.
  if (typeof window !== 'undefined') {
    const jwt = sessionStorage.getItem('token')
    if (jwt) {
      config.headers['Authorization'] = `Bearer ${jwt}`
    } else {
      // Guest: generate a token if absent and send it on every request
      let guestToken = localStorage.getItem('wz_guest_token')
      if (!guestToken) {
        guestToken = crypto.randomUUID()
        localStorage.setItem('wz_guest_token', guestToken)
      }
      config.headers['X-Guest-Token'] = guestToken
    }
  }
  return config
})

// Capture the X-Guest-Token the server mints/echoes and persist it; also clear
// auth on a 401 (expired/invalid JWT) so the app falls back to a guest session.
http.interceptors.response.use(
  (response) => {
    if (typeof window !== 'undefined') {
      const serverToken = response.headers['x-guest-token']
      if (serverToken && !sessionStorage.getItem('token')) {
        localStorage.setItem('wz_guest_token', serverToken)
      }
    }
    return response
  },
  (error) => {
    if (
      typeof window !== 'undefined' &&
      error.response?.status === 401 &&
      sessionStorage.getItem('token')
    ) {
      useAuthStore.getState().logout()
    }
    return Promise.reject(error)
  },
)

export default http

export const fetchShippingCities = async (setShippingData) => {
  // Versioned + TTL'd cache. The old `shippingCities` key never expired and
  // would happily cache an empty `[]` (truthy as a string), so a session that
  // loaded before the cities were seeded stayed empty forever. The new key
  // forces every client to refetch, and an empty list is never treated as a hit.
  const CACHE_KEY = 'wz_shipping_v2'
  const TTL = 24 * 60 * 60 * 1000 // 24 hours

  try {
    // drop the legacy never-expiring cache
    localStorage.removeItem('shippingCities')

    const raw = localStorage.getItem(CACHE_KEY)
    if (raw) {
      const { data, timestamp } = JSON.parse(raw)
      if (Array.isArray(data) && data.length > 0 && Date.now() - timestamp < TTL) {
        setShippingData(data)
        return
      }
    }

    const response = await http.get(`/show_shipping_city`)
    const data = Array.isArray(response.data) ? response.data : []
    setShippingData(data)
    if (data.length > 0) {
      localStorage.setItem(CACHE_KEY, JSON.stringify({ data, timestamp: Date.now() }))
    }
  } catch {
    // keep whatever state already exists
  }
}

export const fetchBanners = async (
  setSideBanners,
  setBottomBanners,
  setHomeBannersPc,
  setHomeBannersMob,
) => {
  try {
    const cacheKey = 'bannersCache'
    const cacheExpirationKey = 'bannersCacheExpiration'
    const cacheDuration = 10 * 60 * 1000 // Cache for 10 minutes

    // Check local storage for cached data
    const cachedData = localStorage.getItem(cacheKey)
    const cacheExpiration = localStorage.getItem(cacheExpirationKey)

    if (cachedData && cacheExpiration && new Date().getTime() < Number(cacheExpiration)) {
      const parsedData = JSON.parse(cachedData)
      setSideBanners(parsedData.sideBanners || [])
      setBottomBanners(parsedData.bottomBanners || [])
      setHomeBannersPc(parsedData.homeBannersPc || [])
      setHomeBannersMob(parsedData.homeBannersMob || [])
      return
    }

    // Fetch data from API
    const endpoints = ['all_banner_side', 'all_banner_bottom', 'all_banner_home']
    const responses = await Promise.allSettled(
      endpoints.map((endpoint) => http.get(`/${endpoint}`)),
    )

    // Extract successful responses
    const [side, bottom, home] = responses.map((res) =>
      res.status === 'fulfilled' ? res.value.data : [],
    )

    // Separate banners by `type_show` (pc or mob)
    const homeBannersPc = home.filter((banner) => banner.type_show === 'pc')
    const homeBannersMob = home.filter((banner) => banner.type_show === 'mob')

    // Store data in local storage
    const bannersData = { sideBanners: side, bottomBanners: bottom, homeBannersPc, homeBannersMob }
    localStorage.setItem(cacheKey, JSON.stringify(bannersData))
    localStorage.setItem(cacheExpirationKey, new Date().getTime() + cacheDuration)

    // Update React state
    setSideBanners(side)
    setBottomBanners(bottom)
    setHomeBannersPc(homeBannersPc)
    setHomeBannersMob(homeBannersMob)
  } catch {
    // intentionally ignored
  }
}

export const fetchOffers = async (setOffers) => {
  try {
    const CACHE_KEY = 'offersCache'
    const EXPIRATION_KEY = 'offersCacheExpiration'
    const CACHE_DURATION = 10 * 60 * 1000

    const isCacheValid = () => {
      const expiration = localStorage.getItem(EXPIRATION_KEY)
      return expiration && new Date().getTime() < Number(expiration)
    }

    if (isCacheValid()) {
      const cachedOffers = JSON.parse(localStorage.getItem(CACHE_KEY))
      setOffers(cachedOffers)
      return
    }

    const response = await http.get(`/all_offer`)
    const offerData = (response.data || []).map((offer) => {
      const offerNameen =
        offer.translations.find((translation) => translation.locale === 'en')?.offer_name ||
        'Unnamed Offer'
      const offerNamear =
        offer.translations.find((translation) => translation.locale === 'ar')?.offer_name ||
        'Unnamed Offer'
      const short_descriptionen =
        offer.translations.find((translation) => translation.locale === 'en')?.short_description ||
        'No Description'
      const short_descriptionar =
        offer.translations.find((translation) => translation.locale === 'ar')?.short_description ||
        'No Description'
      const long_descriptionen =
        offer.translations.find((translation) => translation.locale === 'en')?.long_description ||
        'No Description'
      const long_descriptionar =
        offer.translations.find((translation) => translation.locale === 'ar')?.long_description ||
        'No Description'

      return {
        id: offer.id,
        main_product_id: offer.main_product_id,
        category_type_id: offer.category_type_id,
        gift_product_ids: offer.gift_product_ids.map((id) => parseInt(id)),
        selling_price: parseFloat(offer.selling_price),
        sale_price_after_discount: parseFloat(offer.sale_price_after_discount),
        stock: offer.stock,
        image: getImageUrl(offer.image, 'Offer'),
        average_rate: offer.average_rate ? parseFloat(offer.average_rate) : null,
        created_at: offer.created_at,
        updated_at: offer.updated_at,
        short_description_en: short_descriptionen,
        short_description_ar: short_descriptionar,
        long_description_en: long_descriptionen,
        in_season: offer.in_season,
        long_description_ar: long_descriptionar,
        offer_name_en: offerNameen,
        offer_name_ar: offerNamear,
        offer_rating: offer.offer_rating.map((rating) => ({
          id: rating.id,
          user_id: rating.user_id,
          offer_id: rating.offer_id,
          rating: parseInt(rating.rating),
          comment: rating.comment,
          created_at: rating.created_at,
          updated_at: rating.updated_at,
        })),
      }
    })
    setOffers(offerData)
    localStorage.setItem(CACHE_KEY, JSON.stringify(offerData))
    localStorage.setItem(EXPIRATION_KEY, new Date().getTime() + CACHE_DURATION)
    return
  } catch {
    return
  }
}

export const fetchCart = async (user_id, products, offers, language, setCart) => {
  try {
    const response = await http.get(`/show_cart`)

    if (!response.data || !Array.isArray(response.data)) {
      return
    }

    const cartData = response.data.find((cart) => cart.user_id === user_id)
    if (!cartData || !Array.isArray(cartData.cart_item)) {
      setCart([])
      return
    }

    const formattedCartItems = cartData.cart_item.map((item) => {
      const product = products?.find((p) => p.id === item.product_id) || null
      const offer = offers?.find((o) => o.id === item.offer_id) || null
      return {
        id: item.id,
        product_id: item.product_id,
        product_image: product?.image || 'https://via.placeholder.com/150',
        product_title: product?.product_title || 'Unknown Product',
        product_rating: product?.average_rate || 0,
        offer_id: item.offer_id,
        type_stock: item.type_stock,
        offer_image: offer?.image || 'https://via.placeholder.com/150',
        offer_title:
          language === 'ar'
            ? offer?.offer_name_ar || 'عرض غير معروف'
            : offer?.offer_name_en || 'Unknown Offer',
        offer_rating: offer?.average_rate || 0,
        quantity: item.quantity,
        piece_price: parseFloat(item.piece_price) || 0,
        total_price: parseFloat(item.total_price) || 0,
        color_band: item.color_band ? item.color_band.toString() : null,
        color_dial: item.color_dial ? item.color_dial.toString() : null,
      }
    })

    setCart(formattedCartItems)
  } catch {
    // intentionally ignored
  }
}

export const fetchWishList = async (user_id, products, offers, language, setwishList) => {
  try {
    const response = await http.get(`/all_wishlist`)

    // The wishlist header's user_id comes back as an integer while the store's
    // user_id is a string (sessionStorage) — compare numerically so the match
    // actually succeeds (a strict === here silently left the wishlist empty).
    const wishlistData = response.data.find(
      (WishList) => Number(WishList.user_id) === Number(user_id),
    )
    if (wishlistData) {
      const formattedWishListItems = wishlistData.wishlist_item.map((item) => {
        const product = products.find((p) => p.id === item.product_id)
        const offer = offers.find((o) => o.id === item.offer_id)

        return {
          id: item.id,
          product_id: item.product_id,
          // Build a usable src (products store a bare filename, not a URL).
          product_image: getImageUrl(product?.image, 'Product'),
          product_title: product?.product_title || 'Unknown Product',
          product_rating: product?.average_rate || 0,
          offer_id: item.offer_id,
          offer_image: offer?.image || null,
          offer_title:
            language === 'ar' ? offer?.offer_name_ar : offer?.offer_name_en || 'Unknown Offer',
          offer_rating: offer?.average_rate || 0,
          // sale price is NULL when not discounted → fall back to selling price.
          product_price: product?.sale_price_after_discount || product?.selling_price,
          offer_price: offer?.sale_price_after_discount || offer?.selling_price,
        }
      })
      setwishList(formattedWishListItems)
      return
    }
  } catch {
    return
  }
}
