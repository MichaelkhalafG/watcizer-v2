// ── Build-time asset-base guard (I-2) ───────────────────────────────────────
// Every crawlable image URL (og:image, twitter:image, JSON-LD image[], next/image
// src) is built from NEXT_PUBLIC_ASSET_BASE (src/lib/env.js → getImageUrl). A
// production build MUST have it set and MUST NOT point at localhost, or crawlers
// get unresolvable image URLs. Missing = hard fail; localhost-in-prod = loud warn
// (a LOCAL prod build legitimately has it via the dev-only .env.local, which is
// absent on the deploy server where .env.production's HTTPS host wins).
const _assetBase = process.env.NEXT_PUBLIC_ASSET_BASE || ''
if (process.env.NODE_ENV === 'production') {
  if (!_assetBase) {
    throw new Error(
      'NEXT_PUBLIC_ASSET_BASE is not set for a production build. Set it in .env.production ' +
        '(e.g. https://dash.watchizereg.com) so image URLs resolve. Aborting build.',
    )
  }
  if (/localhost|127\.0\.0\.1/.test(_assetBase)) {
    console.warn(
      `\n⚠  NEXT_PUBLIC_ASSET_BASE="${_assetBase}" points at localhost during a production build.\n` +
        '   og:image / JSON-LD image[] would be unresolvable for crawlers. Expected for a LOCAL prod\n' +
        '   build (.env.local shadows .env.production); on the deploy server .env.local is absent so the\n' +
        "   HTTPS host in .env.production is used. If this shows in CI/deploy, fix NEXT_PUBLIC_ASSET_BASE.\n",
    )
  }
}

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // @react-three/fiber@8 + drei are transpiled WITH the app so they compile
  // against the same React module resolution Next uses. (Do NOT alias react/
  // react-dom via webpack — that overrides Next 15's client React and breaks its
  // `use` hook → "s.use is not a function" + hydration failure.)
  transpilePackages: ['three', '@react-three/fiber', '@react-three/drei'],
  // ESLint runs at build time (0 errors). eslint-config-next's newer rules still
  // emit ~44 warnings (react-hooks/exhaustive-deps on the ported useMemo/useCallback
  // patterns, and no-img-element on the intentionally-raw <img>s — 3D LCP poster,
  // canvas fallback logo, avatars, cart/checkout thumbs). Warnings do not fail the
  // build; clearing them is a stylistic follow-up.
  async rewrites() {
    return [
      // The sitemaps, one per language (S-AR stage 3, 2026-10-01): /sitemap.xml is an INDEX naming
      // /sitemaps/en.xml (bare URLs) and /sitemaps/ar.xml (/ar URLs), each with hreflang
      // alternates — all served by core (CompatLocaleSitemap) and listing only URLs this storefront
      // answers. It used to be the legacy single sitemap (core's /en/sitemap.xml), which listed
      // 404s, a redirect and an empty /blogs, and no Arabic URL at all.
      //
      // The sitemaps are NOT proxied to core (2026-10-01): proxying passed the crawler's
      // Accept-Encoding through to the api host, and production answered 200 with an EMPTY body
      // (content-encoding: br, content-length: 0). app/sitemaps/[file]/route.js fetches core itself
      // and serves /sitemaps/{index,en,ar}.xml; /sitemap.xml is an INTERNAL rewrite onto the index.
      { source: '/sitemap.xml', destination: '/sitemaps/index.xml' },
    ]
  },
  async redirects() {
    return [
      // /api/* and /Uploads_Images/* on the storefront's own host are REDIRECTED to core, no longer
      // proxied (2026-10-01). The proxy had the sitemaps' fault: measured live from Chrome (Brotli on),
      // /api/catalog/meta, /api/show_shipping_city and /api/all_product_rating answered 200 with an
      // EMPTY body (content-encoding: br, content-length: 0), and product images intermittently 200
      // with no bytes — as a browser and as Googlebot; curl never showed it. Nothing on the site
      // requests these paths (every page uses api.watchizereg.com directly), so only old links and
      // indexed image URLs arrive here. 308 keeps the method, and the image URL Google holds moves
      // to the one that serves it.
      { source: '/api/:path*', destination: process.env.LARAVEL_ORIGIN + '/api/:path*', permanent: true },
      { source: '/Uploads_Images/:path*', destination: process.env.LARAVEL_ORIGIN + '/Uploads_Images/:path*', permanent: true },
      { source: '/offers', destination: '/listing?offers=true', permanent: true },
      { source: '/listingsearch', destination: '/listing', permanent: true },
      { source: '/edit-profile', destination: '/account?tab=profile', permanent: true },
      { source: '/order-list', destination: '/account?tab=orders', permanent: true },
      { source: '/wish-list', destination: '/account', permanent: true }, // the wishlist is gone (batch 1)
      { source: '/Search', destination: '/listing', permanent: true },
    ]
  },
  images: {
    // Self-hosted catalog/brand images live on the Laravel origin
    // (api.watchizereg.com in prod, 127.0.0.1:8000 / localhost:8000 in dev);
    // cdn-images.farfetch-contents.com is the seeder's external placeholder host.
    // next/image rejects any hostname not listed here — keep in sync with
    // NEXT_PUBLIC_ASSET_BASE.
    //
    // The dev-only hosts are left out of a PRODUCTION build (2026-09-26): an allowed
    // plain-http loopback host lets anyone ask the live optimiser to fetch from the
    // server's own port 8000, and no production image names either of them or
    // Farfetch (none in the 2026-09-17 data).
    remotePatterns: [
      /*
       * Storefront Phase 2 (2026-09-22): the API and asset host moves to a sub-domain of this
       * site's own domain, which is what lets `CompatStorefront` resolve the shop from the request
       * host rather than from a configured default.
       *
       * `next/image` REFUSES any hostname not on this list, so without this entry every optimised
       * image 400s while the raw <img> tags keep working — a half-broken catalogue that reads as a
       * CDN fault. `dash.watchizereg.com` stays until the rollback window has closed: reverting
       * `.env.production` alone must be enough to put the storefront back.
       */
      { protocol: 'https', hostname: 'api.watchizereg.com' },
      { protocol: 'https', hostname: 'dash.watchizereg.com' },
      ...(process.env.NODE_ENV === 'production'
        ? []
        : [
            { protocol: 'http', hostname: '127.0.0.1', port: '8000' },
            { protocol: 'http', hostname: 'localhost', port: '8000' },
            { protocol: 'https', hostname: 'cdn-images.farfetch-contents.com' },
          ]),
    ],
    // AVIF first (30–50% smaller than WebP), WebP fallback.
    formats: ['image/avif', 'image/webp'],
    // Allowed quality values — must cover every `quality` prop used in components
    // (ProductCard 80, thumbs 70, brand logo 80, category 75, header logo 90, strip 70).
    qualities: [70, 75, 80, 90],
    // Candidate widths for srcset generation, matched to the design breakpoints.
    deviceSizes: [320, 420, 640, 768, 1024, 1200],
    imageSizes: [16, 32, 48, 64, 96, 128, 256],
    minimumCacheTTL: 3600, // 1-hour cache for optimized images
  },
}

module.exports = nextConfig
