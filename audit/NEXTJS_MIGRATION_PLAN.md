# WATCHIZER — NEXT.JS MIGRATION PLAN
Generated: 2026-07-05
Author: automated project study (read-only — no project code was changed)

> **Scope of this document.** This is a *plan only*. It contains no edits to the
> running app. Every phase below is an independently-executable prompt.
>
> **Honesty note on the constraints.** "Design EXACTLY the same / zero visual
> regression / zero functionality loss / works after EVERY step" is achievable
> for this codebase **because the styling is plain portable CSS + design tokens
> and data already flows through TanStack Query + a thin axios layer**. BUT an
> SPA→Next.js move is fundamentally a **routing-layer replacement**, which cannot
> be done "half in React Router, half in the App Router" inside one running app.
> The only way to keep "works after every step" literally true is to **build the
> Next app in parallel (`Frontend-next/`)** and cut traffic over route-by-route
> at the very end (Phase 6). Until then, the current Vite app stays the
> production app and is never broken. This is the approach used throughout.
>
> Three constraint risks are called out explicitly and cannot be hand-waved:
> 1. **Language/RTL flash.** `uiStore.language` defaults to `'en'` and is *not*
>    persisted; `dir`/`lang` are set in a `useEffect`. SSR therefore renders LTR
>    English first for everyone. This exact behaviour already exists in the SPA
>    (reload resets to English), so it is **not a new regression** — but it means
>    Arabic-first SSR is out of scope unless language is persisted (noted in
>    Phase 1 as optional).
> 2. **Auth is `sessionStorage`-based.** The server has no session, so
>    authenticated data (account, orders, wishlist, cart) **must stay
>    client-rendered**. Only public catalog data is server-fetched. This matches
>    the SEO need (only public pages need SSR) so it is not a blocker.
> 3. **`authStore.js` reads `sessionStorage` at module top-level** — it will
>    throw on the server the instant it is imported. This is the single most
>    important SSR fix (Phase 1) and is why stores must be guarded before any
>    page imports them.

---

## CURRENT STATE

| Metric | Value |
|---|---|
| Framework | Vite 6 + React 18.3.1 SPA |
| Package manager | pnpm@11.5.0 |
| Routing | react-router-dom v7 (`App.jsx`, ~28 route entries) |
| Page components | 15 (`src/Pages` + `ProductDetail` in `src/Components`) |
| Total `.jsx` files | 47 (25 under `Components/`) |
| CSS files | 26 (co-located per component + `App.css` global tokens) |
| CSS imports | 31 |
| Router-hook usages | 117 (`useParams`/`useNavigate`/`useLocation`/`useSearchParams`/`navigate()`) |
| TanStack Query hooks | 7 query hooks, 12 `useQuery`/`useMutation` call sites |
| Zustand stores | 4 (auth, cart, ui, toast) + 1 context (`MyProvider`, 186 LOC) |
| Browser-only API usages | ~103 (localStorage 25, sessionStorage 30, window 20, document 25, navigator 3) |
| SEO | react-helmet-async — 5 `<Helmet>`, 4 JSON-LD blocks |
| 3D | `@react-three/fiber` + `drei` + `three` (WatchCanvas, 1 component) |
| Carousels | embla-carousel-react (2 usages) |
| UI lib | MUI v6 + emotion (Snackbar/Alert/useMediaQuery only, in `App.jsx`) |
| Animation | framer-motion v12 |
| Images | `getImageUrl`/`getResponsiveImageUrl` (35 usages); catalog mixes external CDN URLs + backend-relative |
| Backend | Laravel — 73 API routes, 11 API controllers, JWT + `Api-Code` header |
| Env vars | `VITE_API_BASE`, `VITE_ASSET_BASE`, `VITE_IMAGE_CDN_BASE`, `VITE_PAYMOB_ENABLED`, `VITE_PUBLIC_API_KEY` |

---

## SSR ROUTE CLASSIFICATION

| Current route(s) | Component | Class | Next.js target | Rendering |
|---|---|---|---|---|
| `/` | Home | **SSR** | `app/page.tsx` | Server shell + client islands; prefetch products/tables |
| `/listing`, `/category/:category`, `/brand/:brand`, `/subtypes/:subtype`, `/grade/:grade`, `/:suptype/:brand` | Listing | **SSR** | `app/listing/page.tsx` + catch-all `app/[[...facets]]` OR keep query-param `/listing` | Server initial list + client filters |
| `/products/:id`, `/product/:slug`, `/offer/:slug` | ProductDetail | **SSR** | `app/product/[slug]/page.tsx`, `app/offer/[slug]/page.tsx`, `app/products/[id]/page.tsx` (redirect→slug) | `generateMetadata` + JSON-LD server; gallery/cart client |
| `/blogs` | Blogs | **SSR/ISR** | `app/blogs/page.tsx` | Server list |
| `/blog/:name` | Blog | **SSR/ISR** | `app/blog/[name]/page.tsx` | `generateMetadata` |
| `/cart` | Cart | **Client** | `app/cart/page.tsx` `'use client'` | CSR |
| `/checkout` | Checkout | **Client** | `app/checkout/page.tsx` `'use client'` | CSR |
| `/order-confirmation` | OrderConfirmation | **Client** | `app/order-confirmation/page.tsx` `'use client'` | CSR |
| `/account` (protected) | Account | **Client** | `app/account/page.tsx` `'use client'` + client guard | CSR |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/auth/callback` | Auth pages | **Client** | `app/(auth)/…` route group (no header/footer) | CSR |
| `/offers`, `/listingsearch`, `/edit-profile`, `/order-list`, `/wish-list`, `/Search` | — | **Redirects** | `redirect()` in `next.config.js` `redirects()` or route handlers | 301/308 |
| `*` | NotFound | **Static** | `app/not-found.tsx` | Real 404 status |

**Summary:** SSR page-types **5** (Home, Listing, ProductDetail, Blogs, Blog) across ~11 URL patterns · Client page-types **9** · Redirects **6** · 404 **1**.

---

## BROWSER API AUDIT

Legend for SSR FIX: **A** = `typeof window !== 'undefined'` guard · **B** = move into `useEffect` · **C** = `'use client'` directive · **D** = `dynamic(() => …, { ssr:false })`.

| File | Line(s) | API | Context | SSR FIX |
|---|---|---|---|---|
| `Store/authStore.js` | 44–47 | sessionStorage (**module top-level**) | Initial store state read at import time | **A** — wrap reads in `typeof window` guard returning null on server; **rehydrate in a client `<AuthHydrator>`** on mount |
| `Store/authStore.js` | 15–34, 50, 75 | sessionStorage | read/persist user inside actions | A (actions only run client-side; guard defensively) |
| `Store/cartStore.js` | 23–26, 45, 54, 240, 254 | localStorage / sessionStorage | guest token, cart snapshot, merge | A — already mostly lazy (inside functions); add guards to `getGuestToken`/`readCart` |
| `Context/api.jsx` | 12, 17–20, 32–33, 38 | sessionStorage/localStorage | axios interceptor (jwt, guest token) | A — runs per-request; guard so server-side import is safe |
| `Context/api.jsx` | 57–203 | localStorage | `fetchShippingCities`/`fetchBanners`/`fetchOffers` client caches | C — these are client-only helpers; keep in client components |
| `Components/Header/Header.jsx` | 141–142 | session/localStorage.clear | logout | C (Header is `'use client'`) |
| `Components/Header/Header.jsx` | scroll | window.scrollY / add/removeEventListener | sticky-bar state | B/C (already in `useEffect`) |
| `Components/Header/Nav/Nav.jsx` | window.innerWidth/innerHeight | dropdown edge-flip | B/C (in `useLayoutEffect`) |
| `Components/BackToTop/BackToTop.jsx` | window.scrollY, matchMedia, listeners | scroll button | B/C (in `useEffect`) |
| `Components/Hero/WatchCanvas.jsx` | window.innerWidth; three/canvas/WebGL | 3D hero | **D** — `dynamic(ssr:false)` (already `React.lazy`+idle) |
| `Components/Hero/WatchHero.jsx` | requestIdleCallback/cancelIdleCallback | defer 3D mount | B/C |
| `Pages/Auth/Login/Login.jsx` | 27–30, 65–66 | localStorage remember-email | form init | C (client page) |
| `Pages/Auth/AuthCallback.jsx` | 32, 40 | sessionStorage token | OAuth token capture | C (client page) |
| `Pages/Checkout/Checkout.jsx` | 162, 294 | sessionStorage/localStorage | login check, guest cleanup | C (client page) |
| `Pages/Account/Account.jsx` | window.confirm, window.open | delete address, external links | C (client page) |
| `Components/Product/ProductDetail.jsx` | 596–604 | navigator.share / navigator.clipboard | share button | B — guard behind `onClick` (already event-driven); feature-detect |
| `scripts/pixels.js` | window.fbq / window.ttq | analytics | B — event-driven, feature-detected already |
| `App.jsx` | 164–165 | document.documentElement.dir/lang | RTL sync | Move to `useEffect` in a client `<HtmlDirSync>`; also set static default in `<html>` |
| various | document.title, document.body, createElement, activeElement | focus trap, portals, scroll-lock | B/C (all already in effects/handlers) |

**No usage requires a rewrite** — every browser call is either already inside an effect/handler (client-safe) or lives in a store that needs a top-level guard. The **one true blocker** is `authStore.js:44-47`.

---

## STATE MANAGEMENT COMPATIBILITY

**Zustand (4 stores).**
- `toastStore` — pure in-memory, **SSR-safe as-is**.
- `uiStore` — pure in-memory (no storage). SSR-safe. Language default `'en'`; not persisted (see RTL caveat).
- `authStore` — **needs guarding** (top-level sessionStorage). Pattern: guard reads with `typeof window`, initialize to logged-out on server, and rehydrate on mount via a `'use client'` `<AuthHydrator/>` in the root layout. No persist middleware in use.
- `cartStore` — `useSyncExternalStore`-based; storage reads are lazy (inside functions). Guard `getGuestToken`/`readCart`. SSR renders an empty cart, hydrates client-side — matches SPA behaviour.

**TanStack Query.**
- Works natively with Next. Strategy: create a `QueryClient` per request on the server, **`prefetchQuery` the public catalog** (`tables`, `products`, offers) in the SSR page components, then `<HydrationBoundary>` so client hooks (`useCatalog`, `useProducts`, `useTables`, `useOffers`) resolve instantly from cache — **the existing hooks do not change**.
- Keep client-side (never prefetch): anything auth-gated — `me/cart`, `me/orders`, `me/addresses`, wishlist. These need the JWT that only exists client-side.

**Context (`MyProvider`, 186 LOC).**
- Holds wishlist + cart-derived counters + shipping selection + `setFilteredProducts`. All client state. Becomes a **`'use client'` provider** wrapping the app in `app/layout.tsx` (alongside `QueryClientProvider` + `HelmetProvider`→removed). No server logic inside → safe.

---

## COMPONENT CLASSIFICATION

| Component | Class | Reason / Next treatment |
|---|---|---|
| `Home` (page) | **B → server shell** | Split: server `page.tsx` prefetches; render client islands |
| `Listing` (page) | **B (client)** | Heavy `useState`/`useSearchParams`/filter effects → client island under a thin server page that seeds initial data |
| `ProductDetail` | **B (client)** but wrapped by **server** `page.tsx` for `generateMetadata` + JSON-LD | Interactive gallery/cart/qty |
| `Header`, `Nav`, `SearchBox`, `MegaMenu`(deleted), drawer | **B** `'use client'` | scroll, state, navigation |
| `Footer` | **A/B** | mostly static; `'use client'` if it has interactivity |
| `WatchCanvas` / `WatchHero` | **C/D** | `dynamic(ssr:false)` — Three.js/WebGL |
| `CategoryTiles`, `ProductSlider`, `OfferSlider`, `HomeSlider`, `FeaturedBanner` | **B** | embla + click handlers → client islands |
| `ProductCard`, `TrustSignals`, `BackToTop`, `CartModal`, `SideBar`, `SmartSuggestions` | **B** | state/handlers |
| `Cart`, `Checkout`, `OrderConfirmation`, `Account`, all Auth pages | **B** `'use client'` | forms, storage, cart |
| `ScrollToTop` | **B** | becomes Next route-change effect (or removed — App Router scrolls on nav) |
| `ProtectedRoute` | **B** | client guard reading `authStore` |
| `NotFound` | **A** | `app/not-found.tsx` (server, real 404) |
| pure presentational bits (icons, skeleton markup) | **A** | server-safe |

**Target shape:** page-level `page.tsx` files are **server components** that fetch public data + emit metadata/JSON-LD; all interactivity lives in `'use client'` islands imported into them. Design is unchanged because every island keeps its existing JSX + CSS import.

---

## MIGRATION PHASES

> Each phase is a standalone prompt. Effort assumes one engineer familiar with
> the codebase. Risk is for *that phase only*. The Vite app remains the live
> production app until **Phase 6**.

---

### PHASE 0 — Setup (parallel Next app, no functional changes)
**Effort:** ~0.5 day · **Risk:** None (nothing touches the running Vite app)

**Prompt:**
```
Execute this task ONLY. Do not touch the existing Frontend/ app.

Create a NEW Next.js app beside it at Frontend-next/ (App Router, JS not TS to
match the codebase, no src/ dir prompt = use app/).

Files to create:
- Frontend-next/  (via: pnpm create next-app@latest Frontend-next
    --js --eslint --app --no-tailwind --no-src-dir --import-alias "@/*")
- Frontend-next/next.config.js:
    /** @type {import('next').NextConfig} */
    const nextConfig = {
      reactStrictMode: true,
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
- Frontend-next/.env.local: copy every VITE_* value, renamed:
    NEXT_PUBLIC_API_BASE, NEXT_PUBLIC_ASSET_BASE, NEXT_PUBLIC_IMAGE_CDN_BASE,
    NEXT_PUBLIC_PAYMOB_ENABLED, NEXT_PUBLIC_PUBLIC_API_KEY,
    LARAVEL_ORIGIN (server-only, e.g. http://127.0.0.1:8000)
- Copy Frontend/public/* → Frontend-next/public/ (logo, manifest.json, .glb, favicons)
- Install matching deps: @tanstack/react-query zustand axios dompurify
    embla-carousel-react framer-motion react-icons react-medium-image-zoom
    @mui/material @mui/icons-material @emotion/react @emotion/styled
    three @react-three/fiber @react-three/drei
    (DROP react-router-dom + react-helmet-async — replaced by Next primitives)

Verify:
- cd Frontend-next && pnpm build  → succeeds with the default page
- The existing Frontend/ still builds untouched.

Rollback: delete Frontend-next/ — the live app is unaffected.

Checklist:
- Next app scaffolded ✅/❌
- next.config redirects + API/asset rewrites ✅/❌
- env vars mapped to NEXT_PUBLIC_* + LARAVEL_ORIGIN ✅/❌
- public/ assets copied ✅/❌
- default build clean ✅/❌
```

---

### PHASE 1 — Shared code + SSR guards (no routes yet)
**Effort:** ~1 day · **Risk:** Low

**Prompt:**
```
Execute this task ONLY. Work only inside Frontend-next/.

1. Copy verbatim into Frontend-next/src (or /lib): utils/ (imageUrl, slugs,
   productUrl, listingParams, filterPredicate, transformProduct, hasWebGL),
   Store/ (auth, cart, ui, toast), Hooks/ (useCart + queries/*), Context/api.jsx,
   Context/MyProvider.jsx, scripts/ (pixels).

2. Rename env access: import.meta.env.VITE_X → process.env.NEXT_PUBLIC_X
   (imageUrl.js, authStore.js, api.jsx, Checkout paymob flag). Add a tiny
   lib/env.js that re-exports them so there is ONE place to change.

3. SSR-guard the stores (CRITICAL):
   - authStore.js: replace every top-level sessionStorage read with a guarded
     helper:  const ss = (k) => (typeof window === 'undefined' ? null : sessionStorage.getItem(k))
     Initial state becomes logged-out on the server; keep actions as-is.
   - cartStore.js: guard getGuestToken()/readCart() with typeof window checks
     (return a fresh empty cart / no-op token on the server).
   - api.jsx: the axios interceptor already reads storage lazily; add typeof
     window guards so importing it on the server never throws.

4. Create Frontend-next/app/providers.jsx  ('use client'):
   - QueryClientProvider (new QueryClient per client) + MyProvider
   - <AuthHydrator/> : a client component that calls authStore rehydrate on mount
     (reads sessionStorage AFTER hydration) to avoid server/client mismatch.
   - <HtmlDirSync/> : client component that sets document.documentElement.dir/lang
     from uiStore.language in a useEffect (replaces App.jsx:164-165).

Verify:
- pnpm build succeeds (proves nothing imports a browser API at module top level).
- Add a throwaway server component that imports authStore + cartStore and renders
  their non-storage fields — build must NOT crash. Delete it after.

Rollback: git restore Frontend-next/ (live Vite app untouched).

Checklist:
- utils/stores/hooks/context/api copied ✅/❌
- env renamed to NEXT_PUBLIC_* ✅/❌
- authStore top-level sessionStorage guarded ✅/❌
- cartStore storage guarded ✅/❌
- providers.jsx + AuthHydrator + HtmlDirSync created ✅/❌
- build clean (no top-level browser-API crash) ✅/❌
```

---

### PHASE 2 — Root layout + Header + Footer + global CSS
**Effort:** ~1 day · **Risk:** Low–Medium (chrome must look identical)

**Prompt:**
```
Execute this task ONLY. Work only in Frontend-next/.

1. app/layout.jsx (server component):
   - <html lang="en" dir="ltr"> (default; HtmlDirSync flips it client-side)
   - import './globals.css' where globals.css = the exact contents of
     Frontend/src/App.css (design tokens, skip-link, header wrapper, etc.)
   - <head>: port the STATIC bits from Frontend/index.html — favicons, El Messiri
     Google-fonts <link> (or migrate to next/font/google El_Messiri), manifest,
     preconnects. FB/TikTok pixel bootstrap → a client <Analytics/> using next/script.
   - <body>: <Providers>{children}</Providers>, and inside Providers render the
     Header (client), the CartModal (client), the MUI Snackbar (client), ScrollToTop
     behaviour, and Footer (client, desktop-only as today).

2. Copy Header/, Footer/, Nav/, SearchBox/, CartModal, ScrollToTop, BackToTop and
   their .css files. Add 'use client' to each interactive one. Replace:
   - react-router <Link> → next/link <Link>
   - useNavigate()/navigate(x) → useRouter() from next/navigation, router.push(x)
   - useLocation().pathname → usePathname()
   - useParams()/useSearchParams() → next/navigation equivalents
   Keep ALL classNames + CSS imports identical (design unchanged).

3. Auth route group: create app/(auth)/layout.jsx that renders WITHOUT header/footer
   (mirrors AUTH_ROUTES full-bleed behaviour).

Verify:
- pnpm dev: header, strip/marquee, nav dropdowns, language toggle, cart drawer,
  footer all render pixel-identically to the Vite app (compare side by side).
- Navigation between two placeholder pages works via next/link.

Rollback: git restore Frontend-next/app + copied components.

Checklist:
- layout.jsx with globals.css + head parity ✅/❌
- Header/Footer/Nav/CartModal ported to next/link + next/navigation ✅/❌
- (auth) route group = no chrome ✅/❌
- pixels via next/script ✅/❌
- visual parity of chrome verified ✅/❌
```

---

### PHASE 3 — SSR routes, one at a time

#### 3A — Home (`/`)
**Effort:** ~1 day · **Risk:** Medium (3D hero + prefetch)

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

1. app/page.jsx (SERVER component):
   - Create lib/serverFetch.js: an axios/fetch that talks to LARAVEL_ORIGIN with
     the Api-Code header (NEXT_PUBLIC_PUBLIC_API_KEY) — SERVER-SIDE, no JWT.
   - const qc = new QueryClient(); await qc.prefetchQuery(['tables'], …);
     await qc.prefetchQuery(['products'], …)  (reuse the SAME queryFns as
     useTables/useProducts so keys/shapes match exactly).
   - Return <HydrationBoundary state={dehydrate(qc)}><HomeClient/></HydrationBoundary>

2. HomeClient.jsx ('use client') = the existing Home.jsx body verbatim
   (useCatalog resolves instantly from the hydrated cache). Keep all sections/CSS.

3. Hero 3D: const WatchHero = dynamic(() => import('.../WatchHero'), { ssr:false,
   loading: () => <the existing poster fallback> }). Keeps LCP poster, no WebGL on server.

4. Emit the homepage JSON-LD (the Store schema currently in App.jsx) from the
   server page via a <script type="application/ld+json"> in the returned JSX so it
   is in the initial HTML.

Verify:
- View source of / → products + Store JSON-LD present in server HTML (SEO win).
- Hero, category tiles, sliders, brand strip look identical; no hydration warning.
- Failure of the catalog fetch → the existing inline error+retry still shows.

Rollback: delete app/page.jsx + HomeClient.jsx (Next default page returns).

Checklist:
- server prefetch + HydrationBoundary ✅/❌
- HomeClient parity ✅/❌
- 3D hero dynamic ssr:false ✅/❌
- Store JSON-LD in initial HTML ✅/❌
- no hydration mismatch ✅/❌
```

#### 3B — Product Detail (`/product/[slug]`, `/offer/[slug]`, `/products/[id]`→redirect)
**Effort:** ~1 day · **Risk:** Medium–High (metadata + canonical + JSON-LD)

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

1. app/product/[slug]/page.jsx (SERVER):
   - export async function generateMetadata({ params }): server-fetch the product
     by slug (public endpoint), return { title, description, alternates:{canonical},
     openGraph, twitter } — replacing the ProductDetail <Helmet> block exactly.
   - The page prefetches the catalog (or the by-name endpoint), emits Product +
     BreadcrumbList JSON-LD as <script> in server HTML, then renders
     <ProductDetailClient/> inside a HydrationBoundary.
   - If not found → notFound() (renders app/not-found.jsx with a 404 status).
2. app/offer/[slug]/page.jsx — same pattern, offer JSON-LD.
3. app/products/[id]/page.jsx — server: resolve id → slug, permanent redirect() to
   /product/[slug] (mirrors the SPA canonical redirect).
4. ProductDetailClient.jsx ('use client') = existing ProductDetail.jsx body MINUS
   its <Helmet> (now in generateMetadata) and MINUS the client-side <Navigate>
   canonical redirect (now server redirect). Keep gallery/zoom/cart/qty/reviews.

Verify:
- View source of a product URL → correct <title>, canonical, og:*, Product +
  Breadcrumb JSON-LD ALL in initial HTML (this is the core SEO deliverable —
  scrapers now see it without running JS).
- Add-to-cart, image zoom, thumbnails, reviews, related slider all work.
- Bad slug → real 404.

Rollback: delete app/product, app/offer, app/products dirs.

Checklist:
- generateMetadata replaces Helmet ✅/❌
- Product + Breadcrumb JSON-LD server-rendered ✅/❌
- canonical + /products/:id redirect ✅/❌
- client interactivity intact ✅/❌
- 404 on unknown slug ✅/❌
```

#### 3C — Listing (`/listing` + facet aliases)
**Effort:** ~1 day · **Risk:** Medium (URL-param filters + slug routes)

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

1. app/listing/page.jsx (SERVER): prefetch tables + products, emit the listing
   BreadcrumbList JSON-LD + generateMetadata (title/description/canonical mirroring
   the current Listing <Helmet> logic), render <ListingClient/> in a HydrationBoundary.
   searchParams are passed through so first paint reflects the URL filters.
2. ListingClient.jsx ('use client') = existing Listing.jsx body. Replace
   useSearchParams/useNavigate/useParams with next/navigation equivalents; keep the
   URL <-> store filter sync, pagination, sort, drawer, error/retry states as-is.
3. Slug aliases → keep them working:
   - app/category/[category]/page.jsx, app/brand/[brand]/page.jsx,
     app/subtypes/[subtype]/page.jsx, app/grade/[grade]/page.jsx,
     app/[suptype]/[brand]/page.jsx  → each seeds the same ListingClient with the
     parsed facet (reuse listingParams). (Verify the two-segment [suptype]/[brand]
     does not collide with other top-level routes; if it does, gate it or drop it —
     it is a legacy pattern.)

Verify:
- /listing?brands=… server-renders the count + first page; filters/sort/pagination
  update the URL and results client-side identically to today.
- Each slug alias lands on the correct pre-filtered listing.

Rollback: delete the listing + facet route dirs.

Checklist:
- server list + Breadcrumb JSON-LD ✅/❌
- ListingClient filter/sort/pagination parity ✅/❌
- all facet slug routes work ✅/❌
- no route collision on [suptype]/[brand] ✅/❌
```

#### 3D — Blogs (`/blogs`, `/blog/[name]`)
**Effort:** ~0.5 day · **Risk:** Low

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.
- app/blogs/page.jsx (server, ISR: export const revalidate = 3600) → BlogsClient.
- app/blog/[name]/page.jsx (server) + generateMetadata → BlogClient.
- Port Blog/Blogs components with next/link + next/navigation. Keep CSS.
Verify: both render; blog metadata in initial HTML.
Rollback: delete app/blogs + app/blog.
Checklist: blogs list ✅/❌ · blog detail + metadata ✅/❌ · ISR set ✅/❌
```

---

### PHASE 4 — Client-only routes
**Effort:** ~2 days total · **Risk:** Low–Medium (forms, cart, guards)

**Prompt (covers 4A cart · 4B checkout · 4C order-confirmation · 4D account · 4E auth):**
```
Execute this task ONLY. Frontend-next/ only. These are 'use client' pages —
no SSR data, no metadata beyond a static title.

4A app/cart/page.jsx           = 'use client' + existing Cart.jsx
4B app/checkout/page.jsx       = 'use client' + Checkout.jsx (Paymob flag via
                                 NEXT_PUBLIC_PAYMOB_ENABLED; redirect_url handoff
                                 via window.location — client only)
4C app/order-confirmation/page.jsx = 'use client' + OrderConfirmation.jsx
   (NOTE: it reads router state passed from checkout — replace react-router
    location.state with a client store or query param, since Next navigation does
    not carry location.state the same way. Simplest: stash the confirmation payload
    in a Zustand slice on order success, read it here.)
4D app/account/page.jsx        = 'use client' + Account.jsx, wrapped by a client
   <ProtectedRoute> that reads authStore and router.replace('/login') if logged out.
4E app/(auth)/login|register|forgot-password|reset-password|callback/page.jsx
   = 'use client' + the existing auth pages, under the (auth) no-chrome layout.
   AuthCallback captures the OAuth token from the URL (useSearchParams) → sessionStorage.

For all: swap react-router hooks/links → next/navigation + next/link. Keep every
form, validation, toast, className, and CSS import unchanged.

Verify (per page): full flow works — add to cart → checkout (guest + logged-in) →
order confirmation; login/register/forgot/reset; OAuth callback; account tabs
(profile/orders/wishlist/addresses) incl. window.confirm delete + window.open.

Rollback: delete the specific app/<route> dir.

Checklist: cart ✅/❌ · checkout (COD + Paymob) ✅/❌ · order-confirmation state ✅/❌
· account + guard ✅/❌ · auth group (no chrome) + OAuth ✅/❌
```

**Watch-out (4C):** react-router's `location.state` used by OrderConfirmation does
**not** survive a Next.js navigation identically. Migrate the payload to a small
Zustand slice set on order success. This is the only genuine behavioural rewrite in Phase 4.

---

### PHASE 5 — Polish (SEO + images + 404 + loading)
**Effort:** ~2 days · **Risk:** Medium (next/image host config)

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

1. Confirm react-helmet-async is fully removed (all metadata now via
   generateMetadata / server <script> JSON-LD). Delete HelmetProvider usage.
2. next/image migration (optional but recommended): configure
   images.remotePatterns for EVERY external catalog host (audit product image
   URLs — e.g. cdn-images.farfetch-contents.com, dash.watchizereg.com, ASSET_BASE
   host). Replace <img> with next/image ONLY where dimensions are known (cards,
   PDP gallery). Keep getImageUrl as the src builder. If a host list is unbounded,
   keep <img> for those to avoid runtime errors — document which.
3. app/not-found.jsx = the NotFound design, returns a real 404 status (noindex).
4. Add loading.jsx skeletons per SSR route (Home, Listing, Product) reusing the
   existing skeleton markup.
5. ISR for product/listing/blog: export const revalidate = <sensible TTL> so pages
   are statically cached + refreshed (matches the current 5–30 min HTTP caches).

Verify:
- No react-helmet imports remain. next/image (where used) renders correct sizes,
  no layout shift, no "hostname not configured" errors. 404 returns status 404.
  loading skeletons show on slow nav.

Rollback: revert next/image swaps to <img>; remove loading.jsx/not-found.jsx.

Checklist: helmet gone ✅/❌ · remotePatterns for all hosts ✅/❌ · real 404 ✅/❌ ·
loading skeletons ✅/❌ · ISR TTLs ✅/❌
```

---

### PHASE 6 — Cutover + cleanup
**Effort:** ~0.5 day (+ QA) · **Risk:** High (this is the go-live switch)

**Prompt:**
```
Execute this task ONLY.

1. Run the FULL testing checklist (below) against Frontend-next in production mode
   (pnpm build && pnpm start) pointed at the real Laravel origin. Fix any parity gap.
2. Deployment: point the web host / reverse proxy at the Next server (or Vercel).
   Ensure /api and /Uploads_Images proxy to Laravel (rewrites) OR CORS is configured.
3. Only after green QA: make Frontend-next the deployed app. Keep Frontend/ in the
   repo (do not delete yet) for one release cycle as instant rollback.
4. After a stable cycle: remove Vite-specific dev deps from the OLD app, and
   uninstall react-router-dom + react-helmet-async there. Update CI/CD + README.

Verify: production Next build serves every route; SEO source-view has metadata +
JSON-LD; Lighthouse SEO ≥ current; all flows pass.

Rollback: repoint the host back to the Vite build (Frontend/ still intact). This is
why Frontend/ is retained for a full cycle.

Checklist: full QA green ✅/❌ · proxy/CORS to Laravel ✅/❌ · host cut over ✅/❌ ·
old app retained for rollback ✅/❌ · deps/CI updated ✅/❌
```

---

## RISK ASSESSMENT

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `authStore` top-level sessionStorage crashes SSR | High (if unguarded) | Build/runtime crash | Phase 1 guard + AuthHydrator (done before any page imports it) |
| Hydration mismatch (server LTR/EN vs client language/auth) | Medium | Console warnings, flicker | Render neutral logged-out/LTR on server; hydrate in effects. Matches existing SPA reset-to-EN behaviour |
| Language/RTL flash for Arabic users | Medium | Cosmetic (brief LTR) | Pre-existing in SPA; optionally persist language to a cookie and read it in layout for true SSR RTL (out of scope, noted) |
| `OrderConfirmation` relies on router `location.state` | High | Confirmation page empty | Phase 4C: move payload to a Zustand slice |
| next/image "hostname not configured" for external catalog images | Medium | Runtime error on images | Enumerate every host in remotePatterns; keep `<img>` for unbounded hosts |
| MUI/emotion SSR (Snackbar/Alert) | Low | Style flash | MUI is client-only here; render in a `'use client'` boundary. Optional emotion cache if flashes appear |
| `[suptype]/[brand]` two-segment dynamic route collides with other routes | Medium | Wrong route matches | Verify precedence; gate with a matcher or drop the legacy pattern |
| Three.js on server | Low | Crash if not dynamic | `dynamic(ssr:false)` (Phase 3A) |
| CSS global-order differences (26 files) | Low | Minor visual diff | App Router bundles imported CSS; verify cascade order matches; keep App.css → globals.css first |
| SEO regression during parallel period | Low | None (Vite stays live) | Cutover only after source-view verification |

**Overall risk: MEDIUM–HIGH** — not because any single piece is hard, but because it is a full routing/rendering-layer replacement with auth, RTL, and SEO all in play. The parallel-build strategy removes production risk until Phase 6.

---

## TESTING CHECKLIST (run after Phase 5, and again at Phase 6 cutover)

**SEO (view-source, not rendered DOM):**
- [ ] `/` — Store JSON-LD + title/description in initial HTML
- [ ] `/product/[slug]` — title, canonical, og:*, twitter:*, Product + BreadcrumbList JSON-LD
- [ ] `/offer/[slug]` — metadata + JSON-LD
- [ ] `/listing?...` — title/description/canonical + BreadcrumbList
- [ ] `/products/[id]` → 301/308 to `/product/[slug]`
- [ ] unknown slug → HTTP 404 (`not-found`), `noindex`
- [ ] `/blog/[name]` — metadata

**Functionality (per route):**
- [ ] Home: hero 3D (with WebGL) + poster fallback (without), category tiles, sliders, brand strip, catalog error→retry
- [ ] Listing: filters, URL sync, sort, pagination, mobile drawer, empty vs fetch-error states, all facet slug routes
- [ ] Product: gallery + zoom, thumbnail switch, qty, add-to-cart (pending state), wishlist toggle (pending), reviews read+submit, related slider, share (navigator.share/clipboard), sticky buy bar, back-to-top
- [ ] Cart drawer auto-open on add; Cart page; Checkout (guest COD, guest Paymob redirect, logged-in, saved address, governorate shipping); Order confirmation payload + guest account creation
- [ ] Account: profile edit, avatar, orders, wishlist, addresses (delete confirm, add)
- [ ] Auth: login (+remember email), register, forgot/reset password, OAuth callback token capture, ProtectedRoute redirect
- [ ] Language EN⇄AR toggle flips `dir`/`lang`, fonts (Georgia/El Messiri), all copy
- [ ] Analytics: FB + TikTok PageView on nav, ViewContent/AddToCart/Purchase events
- [ ] Redirects: /offers, /listingsearch, /edit-profile, /order-list, /wish-list, /Search

**Non-functional:**
- [ ] No hydration mismatch warnings in console
- [ ] Lighthouse SEO ≥ current; Performance not regressed (LCP poster intact)
- [ ] Visual diff of every page vs Vite build = pixel-identical

---

## ROLLBACK PLAN

- **Phases 0–5** happen entirely in `Frontend-next/`. The live Vite app in
  `Frontend/` is never modified, so rollback = *stop working on Frontend-next / delete it*.
- **Phase 6 (cutover)** is the only production-affecting step. Rollback = repoint
  the web host/reverse-proxy (or Vercel project) back at the existing `Frontend/`
  Vite `dist` build. **`Frontend/` is retained in the repo for one full release
  cycle after cutover** precisely so this repoint is instant and lossless.
- Backend is untouched throughout (same Laravel API, same routes, same `Api-Code`
  + JWT contract), so there is never a data-layer rollback to perform.

---

## APPENDIX — Key API/route inventory (for server fetching)

- Public (server-fetchable with `Api-Code` header, no JWT): `catalog/meta`,
  `all_product`, `all_product_image`, `all_product_rating`, `all_offer`,
  `all_offer_rating`, `products`, `products/{id}`, `products/by-name/{name}`,
  `all_banner_*`, `show_shipping_city`, blog/category endpoints.
- Client-only (need JWT from sessionStorage): `me/cart`, `me/orders`,
  `me/addresses`, `add_wishlist`/`delete_wishlist`, `add_*_rating`, `add_order`,
  `updateProfile`/`updatePassword`, `auth/*`.
- Backend: 11 API controllers, 73 routes, unchanged by this migration.
```
