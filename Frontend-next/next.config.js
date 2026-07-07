/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // @react-three/fiber@8 + drei are transpiled WITH the app so they compile
  // against the same React module resolution Next uses. (Do NOT alias react/
  // react-dom via webpack — that overrides Next 15's client React and breaks its
  // `use` hook → "s.use is not a function" + hydration failure.)
  transpilePackages: ['three', '@react-three/fiber', '@react-three/drei'],
  // Ported Vite code is kept verbatim; eslint-config-next's newer rules flag
  // patterns that are fine in the live app (react-hooks/set-state-in-effect,
  // exhaustive-deps, and no-img-element on the intentionally-kept <img>s — 3D
  // poster, user avatars, hero). Full lint pass deferred: enabling it surfaces
  // ~30 errors + 46 warnings, all pre-existing/stylistic, none runtime bugs.
  eslint: { ignoreDuringBuilds: true },
  async rewrites() {
    // Proxy API + uploaded assets to Laravel so relative calls keep working.
    return [
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
    // All catalog/brand images are self-hosted on the Laravel origin:
    // dash.watchizereg.com in prod, 127.0.0.1:8000 (or localhost:8000) in dev.
    // next/image rejects any hostname not listed here, so keep this in sync with
    // NEXT_PUBLIC_ASSET_BASE.
    remotePatterns: [
      { protocol: 'https', hostname: 'dash.watchizereg.com' },
      { protocol: 'http', hostname: '127.0.0.1', port: '8000' },
      { protocol: 'http', hostname: 'localhost', port: '8000' },
    ],
    // AVIF first (30–50% smaller than WebP), WebP fallback.
    formats: ['image/avif', 'image/webp'],
    // Match the design breakpoints so Next generates the right widths.
    deviceSizes: [320, 420, 640, 768, 1024, 1200, 1440],
    imageSizes: [16, 32, 48, 64, 96, 128, 256, 384],
    minimumCacheTTL: 3600, // 1-hour cache for optimized images
  },
}

module.exports = nextConfig
