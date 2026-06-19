import axios from 'axios'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE,
})

http.interceptors.request.use((config) => {
  config.headers['Api-Code'] = import.meta.env.VITE_PUBLIC_API_KEY

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
  return config
})

// Capture the X-Guest-Token the server mints/echoes and persist it
http.interceptors.response.use((response) => {
  const serverToken = response.headers['x-guest-token']
  if (serverToken && !sessionStorage.getItem('token')) {
    localStorage.setItem('wz_guest_token', serverToken)
  }
  return response
})

export default http

export const fetchShippingCities = async (setShippingData) => {
  try {
    const cachedData = localStorage.getItem('shippingCities')
    if (cachedData) {
      setShippingData(JSON.parse(cachedData))
      return
    }

    const response = await http.get(`/show_shipping_city`)
    setShippingData(response.data)
    localStorage.setItem('shippingCities', JSON.stringify(response.data))
    return
  } catch {
    // console.error("Error fetching shipping cities:", error);
    return
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
    // console.error("Error fetching banners:", error);
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
        image: `${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Offer/${offer.image}`,
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
    // console.error("Error fetching offers:", error);
    return
  }
}

export const fetchCart = async (user_id, products, offers, language, setCart) => {
  try {
    const response = await http.get(`/show_cart`)

    if (!response.data || !Array.isArray(response.data)) {
      // console.error("Invalid cart data format:", response.data);
      return
    }

    const cartData = response.data.find((cart) => cart.user_id === user_id)
    if (!cartData || !Array.isArray(cartData.cart_item)) {
      // console.warn("No cart data found for user:", user_id);
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
    // console.log("🛒 Cart Data:", formattedCartItems);
  } catch {
    // console.error("❌ Error fetching cart data:", error.message || error);
  }
}

export const fetchWishList = async (user_id, products, offers, language, setwishList) => {
  try {
    const response = await http.get(`/all_wishlist`)

    const wishlistData = response.data.find((WishList) => WishList.user_id === user_id)
    if (wishlistData) {
      const formattedWishListItems = wishlistData.wishlist_item.map((item) => {
        const product = products.find((p) => p.id === item.product_id)
        const offer = offers.find((o) => o.id === item.offer_id)

        return {
          id: item.id,
          product_id: item.product_id,
          product_image: product?.image || null,
          product_title: product?.product_title || 'Unknown Product',
          product_rating: product?.average_rate || 0,
          offer_id: item.offer_id,
          offer_image: offer?.image || null,
          offer_title:
            language === 'ar' ? offer?.offer_name_ar : offer?.offer_name_en || 'Unknown Offer',
          offer_rating: offer?.average_rate || 0,
          product_price: product?.sale_price_after_discount,
          offer_price: offer?.price,
        }
      })
      setwishList(formattedWishListItems)
      return
    }
  } catch {
    // console.error("Error fetching wishlist data:", error);
    return
  }
}
