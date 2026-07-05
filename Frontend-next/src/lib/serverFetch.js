import axios from 'axios'
import { API_BASE, PUBLIC_API_KEY } from './env'

// SERVER-SIDE axios for SSR prefetch. Talks to the internal Laravel origin with
// only the public Api-Code header — NO JWT / guest token (there is no session on
// the server). Falls back to the public API base if LARAVEL_ORIGIN is unset.
const ORIGIN = process.env.LARAVEL_ORIGIN || ''
const baseURL = ORIGIN ? `${ORIGIN.replace(/\/$/, '')}/api` : API_BASE

const serverHttp = axios.create({ baseURL })

serverHttp.interceptors.request.use((config) => {
  config.headers['Api-Code'] = PUBLIC_API_KEY
  return config
})

export default serverHttp
