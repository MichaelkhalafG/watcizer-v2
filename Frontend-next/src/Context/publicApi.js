import axios from 'axios'
import { API_BASE, PUBLIC_API_KEY } from '../lib/env'

// The catalogue reads a shopper repeats per filter tap (C-1 stage 3 follow-up, 2026-09-27):
// `catalog/listing`, `catalog/cards`. They carry NO custom header — no `Api-Code`, no
// `Authorization`, no `X-Guest-Token` — so the browser sends them as "simple" cross-origin requests,
// with no CORS preflight round trip in front of each one. The key rides in the query string instead
// (core's CheckApiCode accepts `api_code` on a GET); it is public, it ships in this bundle anyway.
// These reads need no session: never add an interceptor here. Everything else uses `api.jsx`.
const publicHttp = axios.create({
  baseURL: API_BASE,
  params: { api_code: PUBLIC_API_KEY },
})

export default publicHttp
