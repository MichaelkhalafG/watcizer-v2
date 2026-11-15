import { NextResponse } from 'next/server'

// Arabic URLs (S-AR stage 1, 2026-10-01). English keeps the bare URLs; Arabic lives under /ar/.
//
//   /ar, /ar/…   → rewritten onto the same route without the prefix, with the request marked
//                  Arabic (x-wz-lang: ar). The page renders Arabic ON THE SERVER, and its canonical
//                  and hreflang name the /ar URL (x-wz-url-lang: ar). Visiting one also makes Arabic
//                  the shopper's preference (the wz-lang cookie), so the bare links inside the page
//                  keep showing Arabic.
//   bare URLs    → English, always (2026-11-14: the address decides the language). The wz-lang cookie only sends
//                  a visitor who chose Arabic from the bare home page to /ar (see the end of this function).
//
// Both headers are ALWAYS overwritten here, so a client cannot send its own.
const YEAR = 60 * 60 * 24 * 365

export function middleware(request) {
  const { pathname } = request.nextUrl
  // www → the apex, permanently (Search Console, 2026-10-22): https://www.watchizereg.com/ answered 200 with a
  // canonical to the apex, so Google held two addresses for every page it reached under www. The host header is
  // the request's own; the path and query are kept. Pages only (this matcher), so /robots.txt under www is not
  // redirected here — nothing links it.
  const host = (request.headers.get('host') || '').toLowerCase()
  if (host.startsWith('www.')) {
    const url = request.nextUrl.clone()
    url.host = host.slice(4)
    url.protocol = 'https:'
    url.port = ''
    return NextResponse.redirect(url, 301)
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
    const url = request.nextUrl.clone()
    url.pathname = '/ar'
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
  // Pages only: not Next's own assets, the API/image redirects, or files with an extension
  // (robots.txt, sitemap.xml, images, the manifest).
  matcher: ['/((?!_next/|api/|Uploads_Images/|.*\\.[a-zA-Z0-9]+$).*)'],
}
