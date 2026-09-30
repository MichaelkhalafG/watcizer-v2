import axios from 'axios'
import { API_BASE, PUBLIC_API_KEY } from './env'

// SERVER-SIDE axios for SSR prefetch. Talks to the internal Laravel origin with
// only the public Api-Code header — NO JWT / guest token (there is no session on
// the server). Falls back to the public API base if LARAVEL_ORIGIN is unset.
const ORIGIN = process.env.LARAVEL_ORIGIN || ''
const baseURL = ORIGIN ? `${ORIGIN.replace(/\/$/, '')}/api` : API_BASE

// A timeout, so a core that hangs becomes a FAILURE (retried, then the last good copy or the error
// page — see coreRead.js) instead of a page render that never finishes (2026-09-30).
const serverHttp = axios.create({ baseURL, timeout: 5000 })

// The server's own rate limit at core (2026-09-30, option b): a SECRET from the server's environment
// only. Not NEXT_PUBLIC_, so Next never inlines it into a browser bundle, and this module is imported
// only by server code (serverCatalog.js, serverBlogs.js). Unset = no header = the per-IP limit.
const SERVER_KEY = process.env.STOREFRONT_SERVER_KEY || ''

serverHttp.interceptors.request.use((config) => {
  config.headers['Api-Code'] = PUBLIC_API_KEY
  if (SERVER_KEY) config.headers['X-Storefront-Server-Key'] = SERVER_KEY
  return config
})

export default serverHttp
