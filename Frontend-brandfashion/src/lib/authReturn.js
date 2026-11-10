/*
 * Where to go back to after a sign-in (2026-11-09, a TWIN): a page that sends the visitor to /login or /register — the
 * wheel's "sign in to activate your invite" — names the path first; the sign-in pages and the social callback take it
 * once the session exists, else home. Session storage only (one tab, one visit); never a full URL (an open redirect is
 * not a thing here: a path of this site or nothing).
 */

const KEY = 'wz_auth_return'

export function setAuthReturn(path) {
  try {
    if (typeof path === 'string' && /^\/(?!\/)[\w\-./?=&%]*$/.test(path)) sessionStorage.setItem(KEY, path)
  } catch {
    // storage unavailable: the sign-in pages fall back to home
  }
}

/** The kept path, forgotten on read; `fallback` when none. */
export function takeAuthReturn(fallback = '/') {
  try {
    const value = sessionStorage.getItem(KEY)
    sessionStorage.removeItem(KEY)
    return value && /^\/(?!\/)[\w\-./?=&%]*$/.test(value) ? value : fallback
  } catch {
    return fallback
  }
}
