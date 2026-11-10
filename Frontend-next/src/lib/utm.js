/*
 * THE VISIT'S UTM TAGS, kept for the session (2026-11-07, WATCHIZER). An ad link lands with `utm_source`,
 * `utm_medium`, `utm_campaign`, `utm_content`; a client-side navigation drops the query string, and until today
 * nothing in the storefront kept them (GA4 attributes the visit on its own, by its cookie). The wheel records which
 * ad brought a spin, so the FIRST tags of the session are remembered here and sent with the spin — first touch wins:
 * a visitor who arrived from one ad and later clicked a plain link is still that ad's visitor.
 */

const KEY = 'wz_utm'
const FIELDS = ['source', 'medium', 'campaign', 'content']

/** The utm_* of a query string, as `{ source, medium, campaign, content }` — only the ones present. */
export function utmFromSearch(search) {
  const params = new URLSearchParams(String(search || '').replace(/^\?/, ''))
  const out = {}
  for (const field of FIELDS) {
    const value = (params.get(`utm_${field}`) || '').trim()
    if (value) out[field] = value.slice(0, 120)
  }
  return out
}

/** Remember the landing's tags unless the session already has some; returns what the session now holds. */
export function keepUtm(search) {
  const fresh = utmFromSearch(search)
  try {
    const kept = JSON.parse(globalThis.sessionStorage?.getItem(KEY) || 'null')
    if (kept && typeof kept === 'object' && Object.keys(kept).length) return kept
    if (Object.keys(fresh).length) globalThis.sessionStorage?.setItem(KEY, JSON.stringify(fresh))
  } catch {
    // private mode: the landing's own tags still count for this page view
  }
  return fresh
}

/** The session's tags, or `{}`. */
export function readUtm() {
  try {
    const kept = JSON.parse(globalThis.sessionStorage?.getItem(KEY) || 'null')
    return kept && typeof kept === 'object' ? kept : {}
  } catch {
    return {}
  }
}
