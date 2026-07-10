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
    // Proxy API + uploaded assets to Laravel so relative calls keep working.
    return [
      // Serve the canonical sitemap from Laravel's SitemapController. Target the
      // /en/ prefix directly: the backend's localization middleware 302-redirects
      // the bare /sitemap.xml → /en/sitemap.xml, and a rewrite would forward that
      // redirect (pointing at the backend origin) to crawlers instead of the XML.
      { source: '/sitemap.xml', destination: process.env.LARAVEL_ORIGIN + '/en/sitemap.xml' },
      { source: '/api/:path*', destination: process.env.LARAVEL_ORIGIN + '/api/:path*' },
      { source: '/Uploads_Images/:path*', destination: process.env.LARAVEL_ORIGIN + '/Uploads_Images/:path*' },
    ]
  },
  async redirects() {
    return [
      { source: '/offers', destination: '/listing?offers=true', permanent: true },
      { source: '/listingsearch', destination: '/listing', permanent: true },
      { source: '/edit-profile', destination: '/account?tab=profile', permanent: true },
      { source: '/order-list', destination: '/account?tab=orders', permanent: true },
      { source: '/wish-list', destination: '/account?tab=wishlist', permanent: true },
      { source: '/Search', destination: '/listing', permanent: true },
    ]
  },
  images: {
    // Self-hosted catalog/brand images live on the Laravel origin
    // (dash.watchizereg.com in prod, 127.0.0.1:8000 / localhost:8000 in dev);
    // cdn-images.farfetch-contents.com is the seeder's external placeholder host.
    // next/image rejects any hostname not listed here — keep in sync with
    // NEXT_PUBLIC_ASSET_BASE.
    remotePatterns: [
      { protocol: 'https', hostname: 'dash.watchizereg.com' },
      { protocol: 'http', hostname: '127.0.0.1', port: '8000' },
      { protocol: 'http', hostname: 'localhost', port: '8000' },
      { protocol: 'https', hostname: 'cdn-images.farfetch-contents.com' },
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
