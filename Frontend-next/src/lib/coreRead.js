// SERVER-ONLY: one read from core for a page's server render, telling "core says there is no such
// thing" apart from "core could not answer" (2026-09-30, developer's order: failure ≠ 404).
//
// Before this, every server getter caught ANY error and returned null, and null meant notFound(). So a
// 429 from core's rate limit, a 5xx, a timeout or a dropped connection all answered HTTP 404 "no such
// product" — to the shopper and to Google, which de-indexes a 404. Measured locally: 368–460 of 842
// sitemap URLs 404'd under load, every one of them a real product.
//
// The rule now:
//   • a DEFINITE miss — core answered 404 (unknown slug/id) or 422 (a malformed one) → MISS; the page
//     may 404, because that is the truth;
//   • anything else is a FAILURE → one retry after a short pause → the last good copy this server
//     process holds for the same key, if any → otherwise CoreUnavailableError, which the page lets
//     propagate to its error boundary: a 5xx, so Google comes back later instead of dropping the URL.
//
// The last-good copies live in this process only (per key, newest 2000, kept 24 h). A copy can be up to
// a day old while core is down: the price shown may lag, but the cart and checkout re-validate every
// price and every stock level against core before an order is taken, so nobody is charged a stale one.

export const MISS = null

export class CoreUnavailableError extends Error {
  constructor(key, cause) {
    super(`core unavailable for ${key}: ${cause?.response?.status ?? cause?.code ?? cause?.message ?? 'unknown'}`)
    this.name = 'CoreUnavailableError'
    this.key = key
    this.status = cause?.response?.status ?? null
  }
}

// 404: core looked and found nothing. 422: the request itself can never match (e.g. a 400-character
// slug). Nothing else is a verdict — a 401/403 is a key or firewall problem, never "no such product".
export const isDefiniteMiss = (err) => {
  const status = err?.response?.status
  return status === 404 || status === 422
}

const MAX_ENTRIES = 2000
const MAX_AGE_MS = 24 * 60 * 60 * 1000
const RETRY_DELAY_MS = 300
// A failure is remembered this long, so the page body does not repeat what its generateMetadata just
// tried (measured: a hanging core cost 6 lookups and 34 s for one page view before this), and a rate-
// limited core is not hammered harder while it is refusing.
const FAILURE_HOLD_MS = 5000
const lastGood = new Map() // key → { value, at }; insertion order = recency
const inFlight = new Map() // key → promise: identical reads at the same moment share one request
const recentFailure = new Map() // key → { err, at }

const remember = (key, value) => {
  lastGood.delete(key)
  lastGood.set(key, { value, at: Date.now() })
  if (lastGood.size > MAX_ENTRIES) lastGood.delete(lastGood.keys().next().value)
}

const recall = (key) => {
  const hit = lastGood.get(key)
  if (!hit) return undefined
  if (Date.now() - hit.at > MAX_AGE_MS) {
    lastGood.delete(key)
    return undefined
  }
  return hit.value
}

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// `load()` performs the request and returns the value to keep. Returns MISS for a definite miss.
export function coreRead(key, load) {
  const pending = inFlight.get(key)
  if (pending) return pending
  const promise = attempt(key, load).finally(() => inFlight.delete(key))
  inFlight.set(key, promise)
  return promise
}

async function attempt(key, load) {
  const failed = recentFailure.get(key)
  if (failed && Date.now() - failed.at < FAILURE_HOLD_MS) return fallback(key, failed.err)
  let lastErr
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const value = await load()
      remember(key, value)
      recentFailure.delete(key)
      return value
    } catch (err) {
      if (isDefiniteMiss(err)) {
        lastGood.delete(key) // it is gone now; never resurrect it from an old copy
        return MISS
      }
      lastErr = err
      if (attempt === 0) await pause(RETRY_DELAY_MS)
    }
  }
  recentFailure.set(key, { err: lastErr, at: Date.now() })
  if (recentFailure.size > MAX_ENTRIES) recentFailure.delete(recentFailure.keys().next().value)
  return fallback(key, lastErr)
}

// After a failure: the last good copy if this process has one, else CoreUnavailableError.
function fallback(key, err) {
  const stale = recall(key)
  if (stale !== undefined) {
    console.warn(`[coreRead] serving the last good copy of ${key}: ${err?.response?.status ?? err?.code ?? err?.message}`)
    return stale
  }
  throw new CoreUnavailableError(key, err)
}

// For tests only: forget every copy.
export const __resetCoreRead = () => {
  lastGood.clear()
  recentFailure.clear()
}
