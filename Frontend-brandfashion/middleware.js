import { NextResponse } from 'next/server'
import { PUBLIC_API_KEY, STOREFRONT_CODE } from './src/lib/env'

// Arabic URLs (S-AR stage 1, 2026-10-01). English keeps the bare URLs; Arabic lives under /ar/.
//
//   /ar, /ar/…   → rewritten onto the same route without the prefix, with the request marked
//                  Arabic (x-wz-lang: ar). The page renders Arabic ON THE SERVER, and its canonical
//                  and hreflang name the /ar URL (x-wz-url-lang: ar). Visiting one also makes Arabic
//                  the shopper's preference (the wz-lang cookie), so the bare links inside the page
//                  keep showing Arabic.
//   bare URLs    → unchanged: the wz-lang cookie decides what a person sees (today's behaviour),
//                  while the metadata is always the English page's — Google has no cookie, so it
//                  sees English there and Arabic under /ar.
//
// Both headers are ALWAYS overwritten here, so a client cannot send its own.
//
// DIVERGED from Frontend-next 2026-11-11 (TWINS.txt): Brand Fashion's canonical host is www (D-3, the old
// shop's), the opposite of Watchizer's, and only Brand Fashion inherits the old WordPress shop's URLs (D-17).
const YEAR = 60 * 60 * 24 * 365

// ── The canonical host (D-3, 2026-11-11) ─────────────────────────────────────────────────────────────────────
// SITE_CANONICAL_HOST (server env, set for the switch build: www.brandfashionegy.com) — the bare domain and any
// other spelling of it redirect there, path and query kept. Unset (the temporary domain, which has no www):
// the inherited rule — www → the apex.
const CANONICAL_HOST = (process.env.SITE_CANONICAL_HOST || '').toLowerCase()

// ── The old WordPress shop's URLs (D-17, 2026-11-11) ─────────────────────────────────────────────────────────
// Brand Fashion answers on the host the WordPress shop used, so every link and every Google result that pointed at
// it arrives here. Core holds the map (`storefront_redirects`, built by `bf:legacy-redirects`); this branch asks it
// ONLY for paths that look old, so an ordinary page view never pays for the lookup:
//   • a trailing slash — every WordPress URL has one and this app never links with one (next.config.js hands
//     those to us with skipTrailingSlashRedirect instead of Next's own 308);
//   • the old bases (/product-category/, /brand-category/, /gender-category/, /collection/, /product-tag/),
//     the old post categories /category/blog and /category/uncategorized (they collide with /category/[slug]),
//     /wp-content/uploads/… (old image URLs) and the old Arabic switch ?lang=ar.
// A hit is ONE 301 to the new URL (its /ar twin for ?lang=ar). No row: a trailing slash is dropped (301 — what Next
// would have done), anything else is served as it is. WordPress internals answer 410 Gone here, without asking core.
const LEGACY_BASES = /^\/(product-category|brand-category|gender-category|collection|product-tag)(\/|$)/
const LEGACY_POST_CATEGORY = /^\/category\/(blog|uncategorized)\/?$/
// A bare old base — WordPress's index of every category, brand, collection or tag (2026-11-14: an imported article links
// /product-category, which the map has no row for, and answered 404): the whole catalogue.
const LEGACY_BASE_INDEX = /^\/(product-category|brand-category|gender-category|collection|product-tag)\/?$/
const WP_GONE = /^\/(wp-admin|wp-includes|wp-json|wp-login\.php|xmlrpc\.php|wp-cron\.php|wp-signup\.php|wp-trackback\.php|feed|comments\/feed)(\/|$)|^\/wp-content\/(?!uploads\/)/
// THE OLD SHOP'S QUERY PARAMETERS AND PRODUCT FEEDS (2026-11-17, Michael). WooCommerce and its wishlist plugin put
// their actions in the query (?add-to-wishlist=123, ?add-to-cart=123, ?orderby=price, ?paged=2, …), and every such
// address of a product, a category or a brand was crawled. None of these names is a parameter of this app. They are
// dropped and the address goes on as if it had been asked for without them: the old-path map first (a product's old
// page → its new one), else the same path, clean — ONE 301. WordPress's comment feed of a product, /product/<slug>/feed,
// is the product: the map is asked for /product/<slug>.
const WOO_PARAM = /^(add-to-wishlist|remove_from_wishlist|wishlist_id|wishlist-action|add-to-cart|added-to-cart|remove_item|removed_item|undo_item|_wpnonce|wc-ajax|wc-api|variation_id|product_id|orderby|paged|min_price|max_price|rating_filter|post_type|yith_wcwl_[a-z_]*|attribute_[a-z0-9_-]*|filter_[a-z0-9_-]*|query_type_[a-z0-9_-]*)$/i
const PRODUCT_FEED = /^(\/product\/[^/]+)\/feed\/?$/
const ORIGIN = (process.env.LARAVEL_ORIGIN || '').replace(/\/$/, '')
const SERVER_KEY = process.env.STOREFRONT_SERVER_KEY || ''

function gone() {
  return new NextResponse('Gone', { status: 410, headers: { 'Content-Type': 'text/plain; charset=utf-8', 'X-Robots-Tag': 'noindex' } })
}

// core's answer for one old path, or null (no row, core slow or down — the page is then served as it is)
async function lookup(path) {
  if (!ORIGIN) return null
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), 1500)
  try {
    const headers = { Accept: 'application/json', 'Api-Code': PUBLIC_API_KEY }
    if (SERVER_KEY) headers['X-Storefront-Server-Key'] = SERVER_KEY
    const r = await fetch(`${ORIGIN}/api/v2/${STOREFRONT_CODE}/redirect?path=${encodeURIComponent(path)}`, {
      headers,
      signal: controller.signal,
      cache: 'no-store',
    })
    if (r.status !== 200) return null
    const body = await r.json()
    return typeof body?.data?.to === 'string' && body.data.to !== '' ? body.data.to : null
  } catch {
    return null
  } finally {
    clearTimeout(timer)
  }
}

// A redirect target: an absolute URL (an image on the API host) as it is; a path on this host, in Arabic when the
// old URL asked for Arabic.
function target(request, to, arabic) {
  if (/^https?:\/\//i.test(to)) return to
  const parsed = new URL(to, 'http://local')
  return onThisHost(request, arabic ? (parsed.pathname === '/' ? '/ar' : `/ar${parsed.pathname}`) : parsed.pathname, parsed.search)
}

// A plain URL on the request's own host — not nextUrl.clone(), which remembers the request's trailing slash and
// puts it back when the URL is written out (measured: /category/watches came back as /category/watches/).
function onThisHost(request, pathname, search) {
  const url = new URL(request.nextUrl.origin)
  url.pathname = pathname
  url.search = search
  return url
}

export async function middleware(request) {
  const { pathname, searchParams } = request.nextUrl
  const host = (request.headers.get('host') || '').toLowerCase()

  if (CANONICAL_HOST) {
    const bare = CANONICAL_HOST.replace(/^www\./, '')
    if (host !== CANONICAL_HOST && (host === bare || host === `www.${bare}`)) {
      const url = request.nextUrl.clone()
      url.host = CANONICAL_HOST
      url.protocol = 'https:'
      url.port = ''
      return NextResponse.redirect(url, 301)
    }
  } else if (host.startsWith('www.')) {
    // www → the apex, permanently (the temporary domain; inherited from Watchizer's rule of 2026-10-22).
    const url = request.nextUrl.clone()
    url.host = host.slice(4)
    url.protocol = 'https:'
    url.port = ''
    return NextResponse.redirect(url, 301)
  }

  if (WP_GONE.test(pathname) || (pathname === '/' && searchParams.has('p'))) return gone()

  const lang = searchParams.get('lang')
  const woo = [...searchParams.keys()].some((k) => WOO_PARAM.test(k))
  const feed = PRODUCT_FEED.exec(pathname)
  if (woo || feed) {
    const params = new URLSearchParams(searchParams)
    for (const k of [...params.keys()]) if (WOO_PARAM.test(k) || k === 'lang') params.delete(k)
    const query = params.toString()
    const old = feed ? feed[1] : pathname
    const to = await lookup(old)
    if (to) return NextResponse.redirect(target(request, to, lang === 'ar'), 301)
    if (LEGACY_BASE_INDEX.test(old)) return NextResponse.redirect(onThisHost(request, lang === 'ar' ? '/ar/listing' : '/listing', ''), 301)
    const bare = old.replace(/\/+$/, '') || '/'
    const path = lang === 'ar' && !bare.startsWith('/ar') ? (bare === '/' ? '/ar' : `/ar${bare}`) : bare
    return NextResponse.redirect(onThisHost(request, path, query ? `?${query}` : ''), 301)
  }
  const slash = pathname.length > 1 && pathname.endsWith('/')
  if (slash || lang || LEGACY_BASES.test(pathname) || LEGACY_POST_CATEGORY.test(pathname) || pathname.startsWith('/wp-content/uploads/')) {
    const to = await lookup(pathname)
    if (to) return NextResponse.redirect(target(request, to, lang === 'ar'), 301)
    if (LEGACY_BASE_INDEX.test(pathname)) return NextResponse.redirect(onThisHost(request, lang === 'ar' ? '/ar/listing' : '/listing', ''), 301)
    if (slash || lang) {
      const params = new URLSearchParams(searchParams)
      params.delete('lang')
      const query = params.toString()
      const bare = pathname.replace(/\/+$/, '') || '/'
      const path = lang === 'ar' && !pathname.startsWith('/ar') ? (bare === '/' ? '/ar' : `/ar${bare}`) : bare
      return NextResponse.redirect(onThisHost(request, path, query ? `?${query}` : ''), 301)
    }
  }

  const headers = new Headers(request.headers)
  // THE 404 MONITOR (SEO Phase 1, 2026-11-14): the address as it was asked for (with its /ar), for app/not-found.jsx to
  // report to core. Always overwritten here, like the language headers, so a client cannot send its own.
  headers.set('x-wz-path', pathname)
  const arabic = pathname === '/ar' || pathname.startsWith('/ar/')

  if (arabic) {
    const url = request.nextUrl.clone()
    url.pathname = pathname.slice(3) || '/'
    headers.set('x-wz-lang', 'ar')
    headers.set('x-wz-url-lang', 'ar')
    const response = NextResponse.rewrite(url, { request: { headers } })
    if (request.cookies.get('wz-lang')?.value !== 'ar') {
      response.cookies.set('wz-lang', 'ar', { path: '/', maxAge: YEAR, sameSite: 'lax' })
    }
    return response
  }

  // THE ADDRESS DECIDES THE LANGUAGE (Michael, 2026-11-14): a bare URL is English — body, title, canonical, hreflang and
  // structured data alike — whatever cookie the visitor holds. It used to show the Arabic body under the English title to
  // anyone holding wz-lang=ar. The cookie (the language the visitor chose last) now does ONE thing: a visitor who chose
  // Arabic and opens the bare HOME page is sent to /ar — a 302, never cached, and never for a crawler (it carries no cookie).
  if (pathname === '/' && request.cookies.get('wz-lang')?.value === 'ar') {
    const url = onThisHost(request, '/ar', request.nextUrl.search)
    const response = NextResponse.redirect(url, 302)
    response.headers.set('Cache-Control', 'private, no-store')
    response.headers.set('Vary', 'Cookie')
    return response
  }
  headers.set('x-wz-lang', 'en')
  headers.set('x-wz-url-lang', 'en')
  return NextResponse.next({ request: { headers } })
}

export const config = {
  // Pages only: not Next's own assets, the API/image redirects, or files with an extension (robots.txt,
  // sitemap.xml, images, the manifest) — EXCEPT the old shop's files, which must reach the branch above: its upload
  // URLs and the WordPress scripts that answer 410.
  matcher: [
    '/((?!_next/|api/|Uploads_Images/|.*\\.[a-zA-Z0-9]+$).*)',
    '/wp-content/:path*',
    '/wp-login.php',
    '/xmlrpc.php',
    '/wp-cron.php',
    '/wp-signup.php',
    '/wp-trackback.php',
  ],
}
