// CLOUDFLARE TURNSTILE, INVISIBLE (2026-11-17). A TWIN.
//
// The product page's review form asks Cloudflare whether a person is writing — without a puzzle (the widget is created
// as "Invisible" on Cloudflare's dashboard). Nothing is loaded until somebody opens the form: the script is fetched
// then, once per page. core checks the token (TURNSTILE_SECRET_KEY); with no site key (core sends an empty one until
// Michael sets the keys) the form sends no token and core skips the check.
const SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
let loading = null

export function loadTurnstile() {
  if (typeof window === 'undefined') return Promise.reject(new Error('no window'))
  if (window.turnstile) return Promise.resolve(window.turnstile)
  if (!loading) {
    loading = new Promise((resolve, reject) => {
      const s = document.createElement('script')
      s.src = SRC
      s.async = true
      s.defer = true
      s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('turnstile did not start')))
      s.onerror = () => {
        loading = null
        reject(new Error('turnstile could not load'))
      }
      document.head.appendChild(s)
    })
  }
  return loading
}

/**
 * One token for one submission: render the invisible widget into `container`, run it, and remove it after.
 * Resolves '' when there is no site key; rejects when Cloudflare cannot be reached or does not answer in 20 s.
 */
export async function turnstileToken(container, siteKey, lang = 'en') {
  if (!siteKey) return ''
  const ts = await loadTurnstile()
  return new Promise((resolve, reject) => {
    let id = null
    const done = (fn, value) => {
      clearTimeout(timer)
      try {
        if (id !== null) ts.remove(id)
      } catch {
        // already gone
      }
      fn(value)
    }
    const timer = setTimeout(() => done(reject, new Error('turnstile timed out')), 20000)
    id = ts.render(container, {
      sitekey: siteKey,
      execution: 'execute',
      appearance: 'interaction-only',
      language: lang === 'ar' ? 'ar' : 'en',
      callback: (token) => done(resolve, token),
      'error-callback': () => done(reject, new Error('turnstile error')),
    })
    ts.execute(container)
  })
}

// The browser's own random id for the per-device limit (kept in this browser only; never a person's identity).
export function deviceId() {
  try {
    const k = 'wz-device'
    let v = window.localStorage.getItem(k)
    if (!v) {
      v = (window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9-]/g, '')
      window.localStorage.setItem(k, v)
    }
    return v
  } catch {
    return ''
  }
}
