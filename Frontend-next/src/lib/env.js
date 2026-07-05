// Single source of truth for public runtime env. Next inlines NEXT_PUBLIC_* at
// build time (available on both server and client). These replace the Vite
// import.meta.env.VITE_* reads — change a name in ONE place here.
export const API_BASE = process.env.NEXT_PUBLIC_API_BASE || ''
export const ASSET_BASE = process.env.NEXT_PUBLIC_ASSET_BASE || ''
export const IMAGE_CDN_BASE = process.env.NEXT_PUBLIC_IMAGE_CDN_BASE || ''
export const PUBLIC_API_KEY = process.env.NEXT_PUBLIC_PUBLIC_API_KEY || ''
// Matches the Vite default: online payment enabled unless explicitly 'false'.
export const PAYMOB_ENABLED = process.env.NEXT_PUBLIC_PAYMOB_ENABLED !== 'false'
