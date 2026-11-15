import { headers } from 'next/headers'
import { notFound } from 'next/navigation'
import serverHttp, { HAS_SERVER_KEY } from './serverFetch'
import { STOREFRONT_CODE } from './env'

// THE 404 MONITOR (SEO Phase 1, 2026-11-14). A TWIN. Every place that DECIDES a 404 calls reportedNotFound() instead of
// notFound(): the product, article, offer and facet pages, and the catch-all routes for addresses no page answers.
// NOT app/not-found.jsx: Next runs that component on every page (measured on a production build: /faq, / and
// /listing all reported), so it cannot tell a missing page from a found one. The
// address the visitor asked for (middleware.js's x-wz-path, with its /ar), the page that linked there and the user
// agent go to core (POST /api/v2/{shop}/not-found), which lists them on the dashboard's "Pages not found" screen.
//
// Only the shop's SERVER can report: core refuses a report without the storefront server key, so nothing is sent
// when this server has none (local dev) — the page renders the same either way. Never awaited by the page and never
// throws: a slow or failed report must not hold up or break a 404.

/** The report for a request, or null when there is nothing to report (no path, or not a path). */
export function notFoundReport(headerList) {
  const path = headerList?.get?.('x-wz-path') || ''
  if (!path.startsWith('/') || path.length > 2000) return null
  const referer = headerList.get('referer') || ''
  return {
    path,
    referer: referer.length > 0 && referer.length <= 2000 ? referer : null,
    user_agent: (headerList.get('user-agent') || '').slice(0, 512),
  }
}

// One request can render the 404 page twice (measured in dev: one ask, two reports), so the same address and user
// agent are reported once per few seconds per server process. Bounded: the memory is dropped past 500 entries.
const SEEN = new Map()
const SEEN_MS = 5000

export function firstReport(body, now = Date.now()) {
  const id = `${body.path} ${body.user_agent}`
  const at = SEEN.get(id)
  if (at !== undefined && now - at < SEEN_MS) return false
  if (SEEN.size > 500) SEEN.clear()
  SEEN.set(id, now)
  return true
}

export function reportNotFound(headerList) {
  const body = notFoundReport(headerList)
  if (!body || !HAS_SERVER_KEY || !firstReport(body)) return
  serverHttp.post(`/v2/${STOREFRONT_CODE}/not-found`, body, { timeout: 2000 }).catch(() => {})
}

/** notFound(), reported first (a report never stands in the way of the 404). */
export async function reportedNotFound() {
  try {
    reportNotFound(await headers())
  } catch {
    // outside a request (a build): nothing to report
  }
  notFound()
}
