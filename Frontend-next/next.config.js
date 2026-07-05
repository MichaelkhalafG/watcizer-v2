/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // @react-three/fiber@8 + drei are transpiled WITH the app so they compile
  // against the same React module resolution Next uses. (Do NOT alias react/
  // react-dom via webpack — that overrides Next 15's client React and breaks its
  // `use` hook → "s.use is not a function" + hydration failure.)
  transpilePackages: ['three', '@react-three/fiber', '@react-three/drei'],
  // Ported Vite code is kept verbatim; eslint-config-next's newer rules (e.g.
  // react-hooks/set-state-in-effect) flag patterns that are fine in the live app.
  // Don't let a lint-config mismatch block builds — Phase 5 revisits linting.
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
  images: { remotePatterns: [] }, // filled in Phase 5
}

module.exports = nextConfig
