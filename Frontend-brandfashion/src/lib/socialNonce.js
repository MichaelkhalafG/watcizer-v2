// The social sign-in nonce — generated here, verified here.
//
// ── What it is for ───────────────────────────────────────────────────────────
//
// The OAuth flow used to run with no `state` at all (core called Socialite's
// `->stateless()`, which removes the check rather than relocating it). That is
// login CSRF: an attacker completes a Google sign-in as themselves, keeps the
// `code`, and gets a victim's browser to open the callback with it. The victim's
// tab then stored a token for the ATTACKER'S account — and the victim shopped,
// saved an address and placed orders inside somebody else's account.
//
// So this browser generates a nonce, keeps it, and sends it with the redirect
// request. Core signs it into the OAuth `state`, the provider echoes it back, and
// core returns it beside the token. `AuthCallback` then checks that the nonce it
// gets back is the one THIS tab generated, and only then keeps the token.
//
// That last comparison is the whole protection, and it is why the nonce belongs
// to the browser rather than to the server: an attacker's flow carries the
// attacker's nonce, so a victim's tab has nothing to match it against.
//
// ── Why sessionStorage ───────────────────────────────────────────────────────
//
// It survives the round trip through the provider (a full page navigation away
// and back) and dies with the tab, which is exactly the lifetime of one sign-in
// attempt. localStorage would let a nonce outlive the attempt and be reused;
// memory would not survive the navigation at all.

const KEY = 'wz_social_nonce'

// Core's own rule: 22–128 characters of the URL-safe base64 alphabet. 22 is 128
// bits at the low end. Kept here as well so a bad value fails before the request
// rather than as a 422 the shopper cannot act on.
const PATTERN = /^[A-Za-z0-9_-]{22,128}$/

const random = () => {
  const bytes = new Uint8Array(24)
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes)
  } else {
    // No crypto: an unguessable value is not available, so do not pretend. The
    // caller treats null as "cannot start social sign-in".
    return null
  }
  // URL-safe base64, unpadded — `+` and `/` would have to survive two rounds of
  // query-string encoding intact, and `=` is noise in a URL.
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** A fresh nonce, stored for the callback to compare against. Null if impossible. */
export const issueNonce = () => {
  const nonce = random()
  if (!nonce || !PATTERN.test(nonce)) return null
  try {
    sessionStorage.setItem(KEY, nonce)
  } catch {
    // Private mode, or storage disabled. Returning null is correct: without
    // somewhere to keep it there is nothing to compare on the way back, and a
    // sign-in with no comparison is the hole this exists to close.
    return null
  }
  return nonce
}

/**
 * Does `returned` match the nonce this tab issued?
 *
 * Consumes it either way — a nonce is good for exactly one attempt, and leaving a
 * spent one behind would let a second callback in the same tab be accepted.
 */
export const consumeNonce = (returned) => {
  let stored = null
  try {
    stored = sessionStorage.getItem(KEY)
    sessionStorage.removeItem(KEY)
  } catch {
    return false
  }
  if (!stored || typeof returned !== 'string' || returned === '') return false
  // Length-first so the comparison is over equal-length strings; this is not a
  // secret and timing is not the threat, but it costs nothing to be tidy.
  return stored.length === returned.length && stored === returned
}
