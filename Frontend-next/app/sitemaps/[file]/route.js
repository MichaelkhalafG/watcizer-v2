// The shop's sitemaps (S-AR stage 3): /sitemaps/index.xml, /sitemaps/en.xml, /sitemaps/ar.xml —
// and /sitemap.xml, which next.config.js rewrites (internally) onto /sitemaps/index.xml. Core builds
// them (CompatLocaleSitemap); this handler fetches core's copy and answers with the same bytes.
//
// Why a handler and not a rewrite to core (2026-10-01): the rewrite proxied the CRAWLER's request —
// its Accept-Encoding, cookies and all — through to the api host and streamed core's already-encoded
// reply back out through Hostinger's edge. Measured on production from a browser, that chain answered
// 200 with `content-encoding: br` and `content-length: 0`: /sitemap.xml every time, /sitemaps/en.xml
// and /sitemaps/ar.xml intermittently. A plain curl (no Accept-Encoding) always got the full file, so
// the loss never showed up in a curl check. Here the server makes its OWN request (Node's fetch
// negotiates and decodes the encoding itself), so the storefront sends out plain XML like any page.
//
// A reply from core that is not a 200 with at least one <loc> is NOT passed on as a 200: Google
// accepts an empty sitemap as "this site has no pages", but retries a 5xx.
export const dynamic = 'force-dynamic'

const FILES = new Set(['index.xml', 'en.xml', 'ar.xml'])
const ORIGIN = (process.env.LARAVEL_ORIGIN || '').replace(/\/$/, '')

function fail(status) {
  return new Response(status === 404 ? 'Not Found' : 'Sitemap temporarily unavailable', {
    status,
    headers: {
      'Content-Type': 'text/plain; charset=UTF-8',
      'Cache-Control': 'no-store',
      ...(status === 503 ? { 'Retry-After': '300' } : {}),
    },
  })
}

export async function GET(_request, { params }) {
  const { file } = await params
  if (!FILES.has(file) || !ORIGIN) return fail(404)

  let upstream
  try {
    upstream = await fetch(`${ORIGIN}/sitemaps/${file}`, {
      cache: 'no-store',
      headers: { Accept: 'application/xml' },
      signal: AbortSignal.timeout(20000),
    })
  } catch (err) {
    console.error(`[sitemap] core unreachable for ${file}:`, err?.message || err)
    return fail(503)
  }
  if (upstream.status === 404) return fail(404)
  if (upstream.status !== 200) {
    console.error(`[sitemap] core answered ${upstream.status} for ${file}`)
    return fail(503)
  }

  const body = new Uint8Array(await upstream.arrayBuffer())
  if (!new TextDecoder().decode(body).includes('<loc>')) {
    console.error(`[sitemap] core answered 200 with no <loc> for ${file} (${body.byteLength} bytes)`)
    return fail(503)
  }

  return new Response(body, {
    status: 200,
    headers: {
      'Content-Type': upstream.headers.get('content-type') || 'application/xml; charset=UTF-8',
      'Cache-Control': 'no-cache',
    },
  })
}
