import { NextResponse } from 'next/server'

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
const YEAR = 60 * 60 * 24 * 365

export function middleware(request) {
  const { pathname } = request.nextUrl
  const headers = new Headers(request.headers)
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

  headers.set('x-wz-lang', request.cookies.get('wz-lang')?.value === 'ar' ? 'ar' : 'en')
  headers.set('x-wz-url-lang', 'en')
  return NextResponse.next({ request: { headers } })
}

export const config = {
  // Pages only: not Next's own assets, the API/image proxies, or files with an extension
  // (robots.txt, sitemap.xml, images, the manifest).
  matcher: ['/((?!_next/|api/|Uploads_Images/|.*\\.[a-zA-Z0-9]+$).*)'],
}
