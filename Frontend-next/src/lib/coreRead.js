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
const recentMiss = new Map() // key → at: a definite miss, not asked again within a hold's `fresh` (held reads only)

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

/*
 * HELD READS (the performance pass, 2026-11-05, Lighthouse "server-response-time").
 *
 * Every page asked core again for every visitor and waited for the answer before its first byte — measured on the
 * local production builds, old and new alike: 0.7–0.9 s for the Watchizer home (its 640 KB of sliders AND the
 * campaign banner's product, on every request), 0.35–0.45 s for a category page, 0.4 s for a product page. The copy
 * this process already keeps for "core is down" (`lastGood`, below) is the same data a moment older.
 *
 * With a `hold`, a copy younger than `fresh` IS the answer — no request. One older than that but younger than `stale`
 * is still the answer, and core is asked again BEHIND the request, so the next visitor has the new one. Older than
 * `stale` (a page nobody opened for a while), or no copy at all: the request waits for core exactly as before.
 * A definite miss (404/422) drops the copy, as before, so a deleted product is not served from here — and the miss
 * itself is held for `fresh`, so a page that reads something core does not have (the home's campaign banner once its
 * product is retired; a crawler on a dead URL) does not pay a round trip to hear "no" again on every request.
 *
 * What it costs: a price or a stock figure on a card can be `stale` old at the very worst — the windows are below,
 * and they are short. Nobody is charged a held price: the cart and the checkout re-validate every price and every
 * stock level against core (the note at the top of this file). To switch it off, set both numbers of a window to 0.
 */
export const HOLD = {
  // the home's sliders and its campaign banner: two minutes at most (five until 2026-11-06, Michael's decision)
  home: { fresh: 30_000, stale: 120_000 },
  // a category, brand or search page's cards
  listing: { fresh: 20_000, stale: 120_000 },
  // a product page: its price and stock are the ones a shopper acts on, so the window is the shortest
  product: { fresh: 10_000, stale: 60_000 },
}

// `load()` performs the request and returns the value to keep. Returns MISS for a definite miss.
export function coreRead(key, load, hold = null) {
  if (hold && hold.stale > 0) {
    const missedAt = recentMiss.get(key)
    if (missedAt !== undefined) {
      if (Date.now() - missedAt < hold.fresh) return Promise.resolve(MISS)
      recentMiss.delete(key)
    }
    const hit = lastGood.get(key)
    if (hit) {
      const age = Date.now() - hit.at
      if (age < hold.fresh) return Promise.resolve(hit.value)
      if (age < hold.stale) {
        // answered now, refreshed behind: a failed refresh keeps the copy (attempt() falls back to it itself)
        if (!inFlight.has(key)) {
          const refresh = attempt(key, load).finally(() => inFlight.delete(key))
          inFlight.set(key, refresh)
          refresh.catch(() => {})
        }
        return Promise.resolve(hit.value)
      }
    }
  }
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
      recentMiss.delete(key)
      return value
    } catch (err) {
      if (isDefiniteMiss(err)) {
        lastGood.delete(key) // it is gone now; never resurrect it from an old copy
        recentMiss.delete(key)
        recentMiss.set(key, Date.now())
        if (recentMiss.size > MAX_ENTRIES) recentMiss.delete(recentMiss.keys().next().value)
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

/*
 * A PROCESS-WIDE VALUE, ANSWERED AT ONCE AND REFRESHED BEHIND (from serverV2.js `memo`, 2026-11-05; moved here on
 * 2026-11-06 so its rules can be tested, and given `until`).
 *
 * A value younger than `ttl` is the answer. One older than that but younger than `stale` is still the answer, and
 * core is asked again behind the request. Older than `stale`, or none yet: the request waits for core.
 *
 * `until(value, receivedAt)` — optional — names the instant, on THIS machine's clock, at which the value stops
 * being true (a campaign ends, a campaign starts). From that instant the held value is NOT an answer, however
 * young: the request waits for core, exactly as if nothing were held. `settle(value, now, receivedAt)` — optional
 * — is applied to whatever is handed out; it is what keeps an ended campaign off the page when core cannot be
 * asked at that moment and the last good copy is all there is.
 *
 * `keep(value)` — optional — says whether an answer is one to hold. One that is not (core said "no such thing") is
 * handed to the request that asked and to nobody else: whatever was held is dropped, and the next request asks core.
 */
export function heldValue(key, fn, { ttl = 300_000, stale = ttl * 2, until = null, settle = null, keep = null } = {}) {
  let promise = null
  let at = 0 // when the last read STARTED
  let value
  let have = false
  let receivedAt = 0 // when `value` really came from core (a fallback to the last good copy does not move it)
  let expires = null
  const out = (v) => (settle ? settle(v, Date.now(), receivedAt) : v)
  const read = () =>
    coreRead(key, fn).then(
      (v) => {
        if (keep && !keep(v)) {
          have = false
          value = undefined
          expires = null
          promise = null
          return v
        }
        if (!have || v !== value) {
          receivedAt = Date.now()
          expires = until ? until(v, receivedAt) : null
        }
        value = v
        have = true
        promise = null
        return v
      },
      (e) => {
        promise = null
        throw e
      },
    )
  return async () => {
    const now = Date.now()
    const age = now - at
    const due = have && expires != null && now >= expires
    if (have && !due && age < ttl) return out(value)
    if (have && !due && age < stale) {
      if (!promise) {
        at = now
        promise = read()
        promise.catch(() => {}) // a failed refresh keeps the value we have; the next request tries again
      }
      return out(value)
    }
    if (!promise) {
      at = now
      promise = read()
    }
    return promise.then(out)
  }
}

/*
 * THE TWO SMALL LOOKUPS EVERY PAGE READS — the category names (`catalog/meta`) and the menu's facts (`catalog/nav`)
 * (2026-11-06, Michael's decision).
 *
 * They were kept five minutes and then read again WHILE a request waited: once every five minutes one visitor's
 * page waited for three small requests to core before its first byte (measured on the local builds: about 1.3 s
 * here, where core answers one request at a time; the old build does the same). Now the copy is the answer for five
 * minutes, as before, and after that it is STILL the answer while core is asked again behind the request — the
 * next visitor has the new one. No request waits for them, except the first one a server process ever serves
 * (nothing is held yet) and one that comes after a whole day in which nobody opened any page.
 *
 * What it costs: a category renamed on the dashboard shows on the first page opened after the five minutes have
 * passed AND one more page has been opened — "five minutes and one page view" where it was "five minutes". On a
 * shop nobody visited for hours, the first visitor back is given the names as they were when the last visitor left,
 * and the second the current ones. A definite "no such thing" from core is never held: that request hears it, as
 * before, and the next one asks core again (before, the menu's "no" was kept for the five minutes; the names' never).
 */
// Not in HOLD on purpose: HOLD is prices and stock (two minutes at most); these are names and which menu entries exist.
export const LOOKUP_HOLD = { fresh: 300_000, stale: 24 * 60 * 60 * 1000 }
const isAnswer = (value) => value !== MISS
export const heldLookup = (key, fn) => heldValue(key, fn, { ttl: LOOKUP_HOLD.fresh, stale: LOOKUP_HOLD.stale, keep: isAnswer })

// For tests only: forget every copy.
export const __resetCoreRead = () => {
  lastGood.clear()
  recentFailure.clear()
  recentMiss.clear()
}
