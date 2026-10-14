// Single source of truth for public runtime env. Next inlines NEXT_PUBLIC_* at
// build time (available on both server and client). These replace the Vite
// import.meta.env.VITE_* reads — change a name in ONE place here.
export const API_BASE = process.env.NEXT_PUBLIC_API_BASE || ''
export const ASSET_BASE = process.env.NEXT_PUBLIC_ASSET_BASE || ''
export const IMAGE_CDN_BASE = process.env.NEXT_PUBLIC_IMAGE_CDN_BASE || ''
export const PUBLIC_API_KEY = process.env.NEXT_PUBLIC_PUBLIC_API_KEY || ''
// Matches the Vite default: online payment enabled unless explicitly 'false'.
export const PAYMOB_ENABLED = process.env.NEXT_PUBLIC_PAYMOB_ENABLED !== 'false'
// The storefront's code in core's v2 paths (/api/v2/{code}/…). Watchizer unless the build says otherwise.
export const STOREFRONT_CODE = process.env.NEXT_PUBLIC_STOREFRONT_CODE || 'watchizer'
// Meta (Facebook) pixel ids. Public by nature — they ship in the HTML of every page —
// but they live here rather than pasted into the markup so a pixel can be added,
// swapped or dropped by editing one env line. An empty list = no script, no noscript
// beacon, no events.
//
// The env var is COMMA-SEPARATED and stays singular in name, because that is what it is
// called in the deploy: one setting, one or more pixels. Blanks and duplicates are
// dropped here rather than downstream — the same id listed twice would be initialised
// twice, and THAT is a real double-fire, unlike two different ids (see app/analytics.jsx).
export const META_PIXEL_IDS = [
  ...new Set(
    (process.env.NEXT_PUBLIC_META_PIXEL_ID || '')
      .split(',')
      .map((id) => id.trim())
      .filter(Boolean),
  ),
]

// Google Analytics 4 measurement id, ONE per storefront build (2026-10-10). Read here and nowhere
// else: components never read process.env. Anything that is not a `G-…` id — unset, blank, a typo —
// is treated as no id, and then NOTHING Google is rendered (app/analytics.jsx): no script tag, no
// dataLayer, no request. Watchizer's .env.production carries G-9N3N4BW58B; Brand Fashion's build
// leaves it empty until the client creates a property.
const GA4_RAW = (process.env.NEXT_PUBLIC_GA4_MEASUREMENT_ID || '').trim()
export const GA4_MEASUREMENT_ID = /^G-[A-Z0-9]{4,}$/.test(GA4_RAW) ? GA4_RAW : ''
