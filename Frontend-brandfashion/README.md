# Watchizer Storefront (Next.js)

Next.js 15 (App Router) migration of the Watchizer luxury-watch storefront. Built with React 18 and MUI, it features a React Three Fiber 3D watch hero, TanStack Query for server-state and Zustand for client-state, and leans on server-side rendering (SSR) and incremental static regeneration (ISR) for the catalog, product, and listing pages. It talks to a Laravel backend for its API and self-hosted assets.

## Requirements

- Node.js 18+
- [pnpm](https://pnpm.io/)
- A running Laravel backend (see [Backend](#backend))

## Environment variables

Create a `.env.local` file in this directory (it is gitignored). Set the following (names only — never commit real values):

| Variable | Purpose |
| --- | --- |
| `LARAVEL_ORIGIN` | Base origin of the Laravel backend. Used server-side for the `/api/*`, `/Uploads_Images/*`, and `/sitemap.xml` rewrites in `next.config.js`. |
| `NEXT_PUBLIC_API_BASE` | Public base URL for the REST API (typically `LARAVEL_ORIGIN` + `/api`). |
| `NEXT_PUBLIC_ASSET_BASE` | Base URL for self-hosted catalog/brand images. Must line up with a host in `images.remotePatterns` (see [Images](#images)). |
| `NEXT_PUBLIC_IMAGE_CDN_BASE` | Optional CDN base for images; leave empty to serve assets from `NEXT_PUBLIC_ASSET_BASE`. |
| `NEXT_PUBLIC_PUBLIC_API_KEY` | Public API key sent with catalog requests. |
| `NEXT_PUBLIC_PAYMOB_ENABLED` | Toggles the Paymob online-payment flow (`true`/`false`). |

## Getting started

```bash
pnpm install
pnpm dev
```

Open [http://localhost:3000](http://localhost:3000). The backend must be running and reachable at `LARAVEL_ORIGIN`, otherwise API calls and SSR data-fetching will fail.

## Build & run

```bash
pnpm build
pnpm start
```

The static-generation step can be memory-hungry (it pre-renders the full catalog). If the build OOMs, raise Node's heap:

```bash
NODE_OPTIONS=--max-old-space-size=6144 pnpm build
```

## Backend

The storefront depends on the Laravel API in the sibling `backend/` directory. It must run with PHP `memory_limit` ≥ 512M — otherwise `GET /api/all_product` can 500 and every server-rendered page will render empty. See [`backend/DEPLOY.md`](../backend/DEPLOY.md) for the required PHP-FPM / `artisan serve` configuration and catalog-cache notes.

## Images

Image optimization uses `next/image` (AVIF with WebP fallback). Any remote host serving images must be listed in `images.remotePatterns` in `next.config.js`, or `next/image` will reject it. Currently configured hosts:

- `https://dash.watchizereg.com` (production Laravel origin)
- `http://127.0.0.1:8000` and `http://localhost:8000` (dev Laravel origin)
- `https://cdn-images.farfetch-contents.com` (seeder placeholder images)

Keep this list in sync with `NEXT_PUBLIC_ASSET_BASE`.
