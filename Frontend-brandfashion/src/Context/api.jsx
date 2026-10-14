import axios from 'axios'
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
