# WATCHIZER V3 — FINAL QA REPORT
**Date:** 2026-07-10
**Build:** Next.js 15.5.20 production (`pnpm build` exit 0)
**Products:** 2,150 · **Backend:** Laravel 11 + MySQL (XAMPP)
**Method:** Raw page source via `curl` (SEO), Lighthouse 13.4.0 desktop (perf), live browser (interactive), all read-only. No code changed.

> **Test-environment caveats (read first).** This QA ran on a RAM-constrained dev machine (~5–6 GB free) running the app locally over plain HTTP with `ASSET_BASE=127.0.0.1:8000`. Three consequences colour the numbers below and are **not** production defects:
> 1. **Lighthouse blocking-time metrics (TBT/TTI/Speed Index) are inflated by CPU contention** — Lighthouse ran alongside 6 background processes + a parallel curl agent. FCP/LCP/CLS (far less contention-sensitive) are trustworthy; treat TBT/TTI as upper bounds, not representative.
> 2. **`og:image` / JSON-LD `image[]` point at `http://127.0.0.1:8000`** because the dev env's asset base is localhost. Production env vars (`NEXT_PUBLIC_ASSET_BASE=https://dash.watchizereg.com`) resolve this — verify the prod `.env`, it is not a code bug.
> 3. **The Next server crashed twice under Lighthouse's repeated full-page load** (`next/image` optimizer: `"Single item size exceeds maxSize"`). This is a real stability signal (see Issues) but was provoked by aggressive automated load on a constrained box.

---

## 1. SEO SCORECARD

| Page | Status | Title | Description | Canonical | OG | JSON-LD | SSR content |
|---|---|---|---|---|---|---|---|
| `/` (home) | 200 | `Watchizer \| Luxury Watches & Accessories in Egypt` | ✅ | `watchizereg.com` | website (Arabic) + Twitter | **Store** ×1 | 44 cards |
| `/product/[slug]` ×5 | 200 | name + brand + Watchizer ✅ | ✅ (no price) | `/product/<slug>` ✅ | **product** + twitter summary_large_image | **Product + Brand + Offer + AggregateRating + BreadcrumbList** | title/price/stock + gallery |
| `/listing` | 200 | `Shop All Luxury Watches… \| Watchizer` | "Browse 2150…" | `/listing` | ✅ | **BreadcrumbList** | "Showing 24 of 2150" + 24 cards |
| `/listing?brand=rolex` | 200 | `Rolex Watches in Egypt \| Watchizer` ✅ | ✅ | ✅ | ✅ | ✅ | 24 of 73 |
| `/listing?category=fashion` | 200 | `Fashion \| Watchizer` ✅ | ✅ | ✅ | ✅ | ✅ | 24 of 1278 |
| `/brand/omega`, `/brand/rolex` | 200 | `<Brand> Watches in Egypt` ✅ | ✅ | ✅ | ✅ | ✅ | 24 / 73 |
| `/category/watches`, `/category/fashion` | 200 | `Watches` / `Fashion \| Watchizer` ✅ | ✅ | ✅ | ✅ | ✅ | 24 / 872 · 1278 |
| `/subtypes/chronograph` | 200 | `Chronograph Watches \| Watchizer` ✅ | ✅ | ✅ | ✅ | ✅ | 24 of 151 |
| `/grade/iconic-luxury` | 200 | ⚠ **generic** listing title | — | ⚠ generic | ✅ | ✅ | 24 of 500 (filters OK) |
| `/blogs` | 200 | `Blog \| Watchizer` | ✅ | ✅ | index,follow | — | ⚠ empty (0 posts) |
| `/blog/<any>` | **404** | — | — | — | **noindex** ✅ | — | blog table empty |
| `/offer/<any>` | **404** | — | — | — | **noindex** ✅ | — | no offers seeded |
| `/product/fake-slug` | **404** | — | — | — | **noindex** ✅ | — | real 404 status |
| `/this-does-not-exist` | **404** | — | — | — | **noindex** ✅ | — | real 404 status |

**Product JSON-LD (verified full):** `name`, `brand{Brand}`, `image[]`, `sku`, `description`, `offers{price, priceCurrency:EGP, availability:InStock}`, `AggregateRating{ratingValue, reviewCount}`, `BreadcrumbList{3 items}`. All required fields present and valid.

**Redirects (308, no-follow):** `/products/18 → /product/rolex-submariner-date` · `/offers → /listing?offers=true` · `/listingsearch → /listing` · `/edit-profile → /account?tab=profile`. All ✅.

**Crawlability (FIX N5 confirmed):** Home = **44** real `<a href="/product/…">`, Listing = **24** real anchors (class `wz-pc__link`, with `aria-label`). Zero `<div onClick>` pseudo-links.

---

## 2. SITEMAP & ROBOTS

**Sitemap** (`/sitemap.xml`, proxied to Laravel `/en/sitemap.xml`, 1.4 MB):
- Total `<loc>`: **2,182** — products 2,150 · brands 20 · categories 4 · **subtypes 0** · **grades 0** · blogs 0.
- **Malformed URLs (containing a space): 0** ✅
- 3 sampled product URLs fetched directly → **200 OK (not 308)** ✅

**robots.txt:** `/blog/` **NOT** disallowed ✅ · private routes (`/cart`, `/checkout`, `/edit-profile`, `/order-list`, `/wish-list`, `/search`) disallowed ✅ · `Sitemap:` line present ✅.
- ⚠ `Disallow: /*?*` blocks **all** query-string URLs — including filtered listings (`/listing?brand=…`) and the `/offers → /listing?offers=true` redirect target. Clean facet routes (`/brand/*`, `/category/*`) cover most cases, but `/offers` now 308s to a crawl-blocked URL.

**Verdict:** Sitemap **PASS** (0 malformed). robots.txt **PASS with one important caveat** (`/*?*`).

---

## 3. FUNCTIONALITY SCORECARD

| Feature | Status | Notes / how verified |
|---|---|---|
| Home hero (3D WatchHero) | ✅ | Renders (canvas + poster); markup present |
| Category tiles | ✅ | Render with real images (Sub_type/), horizontally scrollable |
| Product sliders / brand strip / featured banner | ✅ | All present; brand strip logos on next/image (AVIF) |
| Header: logo / search / cart / nav | ✅ | Logo → `/`; search box; cart badge; full nav dropdowns |
| **Language toggle EN→AR** | ✅ | **Full RTL flip** — layout mirrors, Arabic text + El Messiri font, **Arabic-Indic numerals** (٠ منتجات · ١٠٠ ج.م) [browser-verified] |
| **Add to Cart (from card)** | ✅ | Adds **without navigating**; cart badge 0→1 (EGP 49,900) [browser-verified this session] |
| **Product card = crawlable link** | ✅ | Clicking card navigates to `/product/<slug>` [browser-verified] |
| Product detail gallery | ✅ | Main image + thumbnails render (C3 fix); next/image AVIF, no 404s [browser-verified] |
| Product page | ✅ | Brand, name, price, stock, rating, qty ±, Add to Cart, wishlist, share, breadcrumb present |
| Cart page | ✅ | Checkout button, shipping/governorate, subtotal/total, empty-state — all present (SSR) |
| Checkout page | ✅ (200) | Name, order summary, Place Order/COD present; email/address/governorate fields render with cart items (client-persisted) |
| **Account (logged out)** | ✅ | **Client-guard redirects `/account` → `/login`** [browser-verified] |
| Login page | ✅ | email + password, Remember me, Forgot link, Google OAuth, Sign In; **no site header** (auth layout) |
| Register page | ✅ | email + 2×password + text fields; no site header |
| Forgot / Reset password | ✅ | Forms render (email / password fields) |
| Cart persistence | ✅ | localStorage-backed (Zustand persist); survives navigation [prior-session evidence] |
| Console errors | ✅ | 0 errors on home after N3 Zustand refactor [browser-verified this session] |
| Mobile responsive (375–390px) | ⚠ unconfirmed | Responsive CSS/breakpoints + mobile classes exist in code; automated window-resize did not produce a mobile-width capture — **not visually confirmed** |

---

## 4. LIGHTHOUSE SCORES (desktop preset)

| Page | Perf | A11y | BP | SEO | FCP | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|---|
| `/` (home) | 50¹ | 93 | N/A² | **100** | 1.3 s | 1.7 s | 0.002 | 15,400 ms¹ |
| `/product/[slug]` | 63¹ | 97 | N/A² | **92** | 1.3 s | 1.7 s | 0 | 710 ms |
| `/listing` | 56¹ | 87 | N/A² | **100** | 1.3 s | 1.7 s | 0.002 | 720 ms |
| `/login` | **100** | 96 | 77 | **100** | 0.3 s | 0.5 s | 0.002 | 10 ms |

¹ Perf/TBT/TTI inflated by machine contention (see caveats). Home TTI ~43 s / main-thread ~44 s are **not** production-representative; paint metrics (FCP/LCP/CLS) are reliable and good.
² Best-Practices returned `null` on the three content pages (a known Lighthouse degradation on loaded/plain-HTTP runs); Login yielded 77.

**Image optimization:** ✅ **44 `/_next/image` requests on home, all `image/avif`, HTTP 200** (confirmed via Lighthouse network log). Sources include local `/logo.webp` and remote Laravel uploads, all transcoded to AVIF with `w=`/`q=` params. *Cache-Control header not captured (Lighthouse limitation + server was down for the follow-up curl).*

**Bundle:** Shared First Load JS **103 kB** · largest page **191 kB** (`/product`, `/offer`) · Home 167 kB · Listing/facets 174 kB · Login 152 kB. Routes: ~12 static (○) + ~10 dynamic (ƒ) + ISR (home 300 s, blogs 3600 s).

---

## 5. NAVIGATION & UX

| Test | Result |
|---|---|
| `/account` logged out → `/login` | ✅ client-guard redirect (browser-verified) |
| Language EN→AR full RTL flip | ✅ layout/font/numerals all switch (browser-verified) |
| Add-to-cart without navigation | ✅ badge 0→1, stays on page |
| Card click → product page | ✅ navigates to `/product/<slug>` |
| Scroll-to-top on navigation | ✅ product page opened at top (prior-session) |
| Cart persistence across nav / reload | ✅ Zustand `persist` (localStorage) |
| Bad slug → real 404 (not white screen) | ✅ HTTP 404 + noindex |
| Backend-down handling | ✅ (main) layout catches catalog-unreachable (try/catch → client fallback); SSR degrades gracefully rather than crashing |
| Mobile drawer / hamburger | ⚠ not visually confirmed (resize tooling limitation) |
| Back/forward history | Not explicitly re-tested this run |

---

## 6. COMPARISON VS V2

| Metric | V2 (React/Vite SPA) | V3 (Next.js SSR) | Improvement |
|---|---|---|---|
| Lighthouse Performance | **NO_FCP** — prod build white-screened (`mui` chunk `jsx` TypeError), never painted | Home 50, Product 63, Listing 56, **Login 100** | Unmeasurable/broken → measurable, paints in 0.3–1.7 s |
| Lighthouse SEO | Not measurable (no render) | **92–100** every page | Broken → 92–100 |
| SSR / crawlable content | `<div id="root"></div>`, 0 products in HTML | Full product data + JSON-LD in initial HTML | SPA blank shell → real SSR |
| Structured data | none in HTML | Store, Product, Offer, AggregateRating, BreadcrumbList | 0 → 5 schema types |
| Product links | `div onClick` | real `<a href>` | not crawlable → crawlable |

**Categorical win:** V2's production build did not render at all (NO_FCP); V3 renders, is fully indexable, and scores SEO 92–100.

---

## 7. ISSUES FOUND

### Critical (validate before flipping to production)
- **C-1 · `next/image` optimizer stability.** The Next server crashed **twice** under repeated full-page image load (`"Single item size exceeds maxSize"` + native `0xC0000409`). Provoked on a RAM-constrained dev box, but a crashing `/_next/image` route is deploy-critical. **Action:** validate on production-grade infra (more RAM, CDN in front of `/_next/image`, or raise the optimizer cache limit / audit oversized source images).

### Important (fix soon)
- **I-1 · Home client-side work.** Home serializes the full catalog into the page and renders 44 cards client-side → heavy main-thread/TBT even discounting contention; drives Perf to ~50. Consider server-rendering the visible cards. (The ~17–19 MB listing/product HTML from the catalog HydrationBoundary is the same root cause.)
- **I-2 · Production image host.** `og:image` + Product JSON-LD `image[]` = `http://127.0.0.1:8000` in this env → unresolvable for crawlers. Confirm prod `NEXT_PUBLIC_ASSET_BASE` is the HTTPS CDN host. (Env config, not code.)
- **I-3 · `robots.txt Disallow: /*?*`** blocks filtered listings and `/offers → /listing?offers=true`. Consider allowing key facet query URLs or redirecting `/offers` to a clean path.

### Minor (cosmetic / nice-to-have)
- M-1 `/grade/*` returns the generic listing `<title>`/canonical (other facets have specific titles).
- M-2 `/subtypes/*` and `/grade/*` absent from the sitemap (indexable but not discoverable via sitemap).
- M-3 Home `<title>`/description are English while OG/Twitter are Arabic (`ar_EG`) — intentional but inconsistent.
- M-4 Product meta descriptions omit price.
- M-5 `/blogs` indexable but empty (0 posts — data, not code); blog sitemap URLs will populate once posts exist.

---

## 8. DEPLOY READINESS CHECKLIST

- [x] All SEO pages return SSR content (home 44, listing/facets 24, products full)
- [x] All JSON-LD valid (Store, Product, Offer, AggregateRating, BreadcrumbList)
- [x] Sitemap: 0 malformed URLs (2,182 locs, products serve 200 not 308)
- [x] robots.txt correct — *with the `/*?*` caveat (I-3)*
- [x] Real 404 works (HTTP 404 + noindex on all not-found + offers)
- [x] Image optimization works (AVIF via `/_next/image`) — *but optimizer stability needs validation (C-1)*
- [x] Core functionality works (cart, auth pages, account guard, RTL, add-to-cart, gallery)
- [x] No console errors (home, post-refactor)
- [x] Lighthouse SEO ≥ 90 (92–100 every page)
- [x] Build clean (exit 0)
- [ ] `next/image` optimizer stability confirmed under load **(C-1 — open)**
- [ ] Production image host / env vars confirmed **(I-2 — open)**
- [ ] Mobile responsive visually confirmed **(open — tooling limitation this run)**

## VERDICT: **NEARLY READY** — deploy-ready on SEO, functionality, and build; resolve/validate **C-1 (image-optimizer stability)** and **I-2 (production image host env)** before flipping to production. SEO and correctness are in excellent shape; the open items are infrastructure/stability, not feature defects.
