# Watchizer — Full QA + Three-Version Comparison Report

**Date:** 2026-07-09
**Scope:** Full QA of the Next.js app (`Frontend-next/`, "V3") + strict comparison of V1 (original), V2 (React/Vite SPA), V3 (Next.js 15).
**Method:** Production build (`next build` + `next start`) against the **live local Laravel backend** (`127.0.0.1:8000`, MySQL `watchizer`, 2150 seeded products), page-source inspection via curl, interactive QA via Chrome automation, and Lighthouse (desktop preset).
**Ground rule honored:** No code changed. The only environment fix was starting the Laravel backend with a raised PHP `memory_limit` (see Test Environment Notes) — no app/source edits.

---

## Test Environment Notes (important for reading the results)

1. **Backend was down at first.** The dev Laravel server was not running. Starting it revealed a **PHP `memory_limit=128M` exhaustion** (`Illuminate\Cache\FileStore.php`) on the large full-catalog endpoint (`/api/all_product` returns 9.3 MB / 2150 products). It only serves once launched with a raised limit:
   `php -d memory_limit=1024M -S 127.0.0.1:8000 <framework router>` run from `backend/public`.
   → **Deployment implication:** the production PHP pool must run with `memory_limit ≥ 256–512M` or the catalog endpoint 500s. This is a **backend/ops** finding, not a frontend one, but it blocks the whole site (SSR renders empty on a 500).
2. **Seed data has no discounts** — 0 of 2150 products have `sale_price < price`. So the `/offer/[slug]` route legitimately 404s for every product (strict-match), and the "30% OFF" home banner is a **deterministic marketing banner**, not real offer data.
3. **Seed data is missing product-gallery images** — `Uploads_Images/Product_image/*.webp` (the detail-page gallery files) return **404**; only `Uploads_Images/Product/*.webp` (catalog thumbnails) exist. This breaks the product detail **main image** in both V2 and V3 equally — a **data gap, not a migration regression**.
4. **Mobile visual testing was limited** — the automation screenshot viewport stayed at desktop width even after window resize, so mobile layout was verified structurally (viewport meta + mobile-nav toggle present) rather than visually.

---

## PART 1 — Full QA of V3 (Next.js)

### A. SEO / page source (viewed page SOURCE, not rendered DOM)

| # | Route | Result |
|---|---|---|
| 1 | `/` | **PASS** — `<title>`Watchizer \| Luxury Watches & Accessories in Egypt`</title>`; **Store JSON-LD** present; **2137 product cards server-rendered in HTML**. |
| 2 | `/product/gucci-visor-silver-cap` | **PASS** — HTTP 200; title `Gucci Visor Silver Cap – Gucci \| Watchizer`; canonical ✓; og:title ✓; **og:image ✓**; JSON-LD: **Product + Brand + AggregateRating + Offer + BreadcrumbList**. |
| 3 | `/offer/[slug]` | **N/A (correct 404)** — HTTP 404 + noindex. Strict-match: no product has a discount in seed data, so every offer URL 404s by design. |
| 4 | `/listing` | **PASS** — title `Shop All Luxury Watches & Accessories \| Watchizer`; **BreadcrumbList** JSON-LD; server-rendered "Showing 24 of 2150 products". |
| 5 | `/listing?brand=rolex` | **PASS** — title `Rolex Watches in Egypt \| Watchizer` (brand injected). |
| 6 | `/brand/rolex`, `/brand/omega` | **PASS** — HTTP 200, 24 products in HTML, BreadcrumbList, canonical `/brand/rolex`. |
| 7 | `/category/watches` | **PASS** — HTTP 200, title `Watches \| Watchizer`, 24 products, correct canonical. |
| 8 | `/blogs` | **PARTIAL** — HTTP 200, renders, but **no crawlable `/blog/…` post links** in HTML (no published posts / links are non-anchor). |
| 9 | `/blog/[name]` | **NOT TESTED** — no blog-post slug available in seed data. (Code sets OG `type:article` but **no Article JSON-LD** — see SEO gaps.) |
| 10 | random fake URL | **PASS** — real **HTTP 404** + `robots: noindex`. Bonus: `/products/5878` → **HTTP 308** permanent redirect to `/product/{slug}`. |

**SEO verdict: excellent.** Real HTTP status codes (404/308), full structured data on product pages, SSR content in HTML, per-page metadata, brand/facet titles. Lighthouse SEO: **Home 100, Product 92.**

### B. Functionality (interactive)

| # | Feature | Result |
|---|---|---|
| 11 | Home | **PASS** — 3D hero watch renders & animates (WebGL); category carousel; brand strip; featured banner. |
| 12 | Listing | **PASS** — filter chip → URL updates to `?brand=gucci`; count recalculates **2150 → 163**; active-filter pill + "Clear all"; contextual facet counts; SORT dropdown present. |
| 13 | Product Detail | **PASS w/ data caveat** — title, brand, ★5.0 · 2 reviews, price, "Express · 14 in stock", qty ±, add-to-cart, wishlist, share. **Main gallery image broken (seed-data 404, not code).** |
| 14 | Cart | **PASS** — line item w/ image, qty ±, remove (×), Ship-to governorate dropdown, Subtotal/Shipping/Total breakdown. |
| 15 | Checkout | **PASS** — guest CONTACT (email/first/last + sign-in link), SHIPPING (governorate dropdown, street, apt), order summary, PLACE ORDER (validation-gated). *Order not actually placed — avoided creating a real DB record.* |
| 16 | Order Confirmation | **NOT TESTED** — requires placing a real order. |
| 17 | Account (logged in) | **NOT TESTED** — no test credentials. |
| 18 | Account (logged out) | **PASS** — `/account` redirects to `/login`. |
| 19 | Login | **PASS** — email/password (show-hide), Remember me, Forgot password, SIGN IN, **Continue with Google**, Create-one link; (auth) layout has no header/footer chrome. |
| 20–23 | Register / Forgot / Reset / Auth callback | **NOT visually tested** — routes exist and build; reset/callback are Suspense-wrapped for `useSearchParams`. |

### C. Navigation & UX

| # | Check | Result |
|---|---|---|
| 24 | Nav → listing loads fast | **PASS** — filter-chip navigation is instant (the earlier multi-second freeze was fixed via the catalog memo + `<Link>` prefetch). |
| 27 | Language EN⇄AR | **PASS (excellent)** — full RTL mirror (logo→right, cart→left), complete translation of nav/filters/labels, El Messiri Arabic font, Arabic numerals (١٠٠ ج.م). |
| 28 | Cart badge updates | **PASS** — "0 ITEMS / EGP 100" → "1 ITEM / EGP 49,900" on add. |
| 29 | Mobile responsive | **PARTIAL** — viewport meta + mobile-nav toggle present (structure OK); visual not confirmable in this environment. |
| 30 | Hydration warnings | **PASS** — none observed. |
| 31 | React errors | **PASS** — **zero console errors** across the whole session. |
| 26, 32 | Back/forward, scroll-to-top | **NOT explicitly verified.** |

**Caveat found (minor):** Language selection **does not persist across a hard reload / direct URL entry** (SSR renders EN, and the header total **includes default shipping (EGP 100) even when the cart is empty** → confusing "0 ITEMS / EGP 100").

### D. Performance (Lighthouse, desktop preset, against live backend)

| Metric | Home `/` | Product `/product/[slug]` |
|---|---|---|
| **Performance** | **44** ⚠️ | **56** |
| **Accessibility** | **93** | **97** |
| Best Practices | not scored¹ | not scored¹ |
| **SEO** | **100** | **92** |
| First Contentful Paint | 1.7 s | 1.6 s |
| Largest Contentful Paint | 2.1 s | 1.9 s |
| Cumulative Layout Shift | **0.002** ✅ | **0** ✅ |
| **Total Blocking Time** | **12,930 ms** 🔴 | 860 ms |
| Speed Index | 6.3 s | 1.9 s |
| Time to Interactive | 16.3 s | 5.0 s |
| Main-thread work | 23.5 s | — |

¹ Lighthouse returned a null Best-Practices score in this local http environment (inconclusive, not a failure).

- **33. First paint:** FCP 1.7 s, LCP 2.1 s — good.
- **34. 3D watch:** loads & animates ✅.
- **35. Images WebP/AVIF:** ✅ `next/image` optimizer serves **AVIF** to AVIF clients and **WebP** to WebP clients with `cache-control: public, max-age=3600`. (Intentionally-kept raw `<img>`s — banner, brand strip, avatars — load origin WebP directly.)

**🔴 The single biggest problem: the home page server-renders and hydrates ALL ~2137 product cards** (`[class*="wz-pc"]` = 39,566 DOM nodes), producing **12.9 s of total blocking time** and 23.5 s of main-thread work. LCP/CLS are fine, but the page is not interactive for ~16 s. This is why Home Performance is 44 while the (equivalent-stack) Product page is 56 with 860 ms TBT.

**QA tally:** Routes probed: 20+. Working: the large majority. Broken: 1 (product **main gallery image** — seed-data 404). Not testable with current data/credentials: offer detail, blog post, order confirmation, logged-in account, register/forgot/reset visuals.

---

## PART 2 & 3 — Three-Version Comparison

**Version history (git):** earliest `Frontend/` commit is `5b1da95` ("v2.0"); audit docs (`audit/watchizer-audit.md`, dated 2026-05-29) describe V1 as *"a functional but architecturally fragile client-rendered SPA (Vite + React 18)."* V2 is the hardened SPA; V3 is the Next.js 15 migration (`Frontend-next/`).

### Bundle / build facts (measured)

- **V3 First Load JS:** shared 103 kB; Home 167 kB; Listing 174 kB; Product/Offer 191 kB. Static (ISR): Home (300 s), Blogs (3600 s). Dynamic (SSR-on-demand): product, offer, listing, brand, category, subtypes, grade, `[suptype]/[brand]`, `blog/[name]`.
- **V2 bundle (measured, gzipped):** app `index` 79 kB + react 58 kB + mui 45 kB + CSS 14 kB ≈ **~196 kB gzip critical path**; three 168 kB + react-three 87 kB gzip are lazy (WatchCanvas dynamic). `dist/` total 5.8 MB, 24 JS chunks.
- **V2 SSR content (measured):** home HTML is literally `<div id="root"></div>` — **0 products in HTML** (crawlers see an empty shell). V3 home ships **2137 products** in HTML.
- **🔴 V2 production build is BROKEN (measured):** `pnpm build` succeeds, but the served build **white-screens** — console throws `TypeError: Cannot read properties of undefined (reading 'jsx')` in the split `mui` chunk (`manualChunks` breaks React's `jsx-runtime` resolution). `#root` stays empty (0 children), no paint. The SPA renders **nothing** in production (works in dev only). This is why V2's Lighthouse run returned **NO_FCP** — the app is unmeasurable because it crashes on load.

### Full comparison table

*(Winner column: which version does it best. "V2/V3 tie" = no meaningful difference.)*

| Dimension | V1 (Original) | V2 (React/Vite) | V3 (Next.js) | Winner |
|---|---|---|---|---|
| Framework | React 18 + Vite, React Router, **MUI + Bootstrap (two design systems)**, legacy polyfills | React 18.3 + Vite 6, React Router 7, MUI 6 (Bootstrap removed) | **Next.js 15 App Router**, React 18.3, SSR/ISR | **V3** |
| Bundle size (JS) | Largest (Bootstrap+slick+AOS+polyfills) | ~196 kB gzip critical (react+mui+app), three/R3F lazy | 103 kB shared / 167–191 kB first load (uncompressed) | **V3** |
| Bundle size (CSS) | Bootstrap + MUI + AOS | Component CSS + MUI | Component CSS + MUI, tokenized | V2/V3 |
| First paint speed | Slow (empty shell, then hydrate) | **Never paints — prod build crashes** | **FCP 1.7 s / LCP 2.1 s (SSR HTML)** | **V3** |
| SEO: meta tags | One shared `<title>` (App.jsx) | Per-page react-helmet-async (client-injected) | **Per-page `generateMetadata` (server)** | **V3** |
| SEO: JSON-LD | None | 3 files, client-injected | **Store/Product/Brand/AggregateRating/Offer/BreadcrumbList, server** | **V3** |
| SEO: SSR content | None (empty `#root`) | None (empty `#root`) | **Full product HTML server-rendered** | **V3** |
| SEO: real 404 | No (soft 200) | No (soft 200) | **Real HTTP 404 + noindex** | **V3** |
| SEO: OG for social | Basic (index.html) | OG + Twitter (client) | OG + Twitter (server) — **but no og:image on listing/brand/category** | **V3** |
| Performance: LCP | Poor | Poor | **2.1 s (home) / 1.9 s (product)** | **V3** |
| Performance: CLS | Poor (no img dims) | Improved (dims added) | **0.002 / 0** | **V3** |
| Performance: TBT | High | High | **Product 860 ms good; Home 12,930 ms 🔴** | Product **V3**, Home **regression** |
| Lighthouse Performance | Not measured | **NO_FCP — build crashes** 🔴 | Home **44** / Product **56** | **V3** (V2 doesn't run) |
| Lighthouse Accessibility | Not measured | Unmeasurable (crash) | Home **93** / Product **97** | **V3** |
| Lighthouse Best Practices | Not measured | Unmeasurable (crash) | not scored (local http) | — |
| Lighthouse SEO | Low (no SSR) | Low-Med (client meta) | **Home 100 / Product 92** | **V3** |
| State management | God-object Context (419 lines) | **Zustand** slices | Zustand (SSR-guarded) + HydrationBoundary | V2/V3 |
| Data fetching | Full catalog to browser, transformed ×2 on main thread | **React Query** hooks | React Query + **server prefetch** into hydration cache | **V3** |
| Caching strategy | localStorage + 2 forced-reload timers 🔴 | React Query (5 min stale) | React Query + **cross-request catalog memo (5 min TTL)** | **V3** |
| Image optimization | lazy-load lib | AVIF/WebP presets + dims | **`next/image` AVIF/WebP + per-device srcset** (partial adoption) | **V3** |
| Font loading | Unoptimized | Preload + swap (Georgia/El Messiri) | **`next/font` (El Messiri self-hosted) + Georgia system** | **V3** |
| Code splitting | Minimal | React.lazy + manualChunks | Automatic route splitting + `next/dynamic` (3D) | V2/V3 |
| 3D hero handling | Not present | R3F, WebGL-guarded, idle-mount | R3F v9, `ssr:false` dynamic, poster LCP, idle-mount | V2/V3 |
| RTL support | JS-driven, fragile | dir + token flip | **HtmlDirSync + token flip + El Messiri** (full mirror verified) | V2/V3 |
| Mobile responsive | Present | Present | Present (viewport + mobile nav) | tie |
| Auth flow | **JWT secret in bundle 🔴** (audit C1) | JWT in sessionStorage, interceptors | JWT SSR-guarded + AuthHydrator + guest token | **V3** |
| Guest checkout | **Broken** (audit C7) | Guest cart works | Guest + logged-in, COD + Paymob-gated, governorate shipping | V2/V3 |
| Error handling | Blank screen | ErrorBoundary + Suspense | try/catch per server page + ErrorBoundary — **but no `error.jsx`** | V2 |
| Loading states | None | Skeletons | SSR data in HTML; **only `listing/loading.jsx`**, other Suspense = null | V2/V3 |
| Accessibility | Not measured | Unmeasurable (crash) | **93–97** (2 issues: color-contrast, label mismatch) | **V3** |
| Design system tokens | None | CSS custom props | CSS custom props (globals.css) | V2/V3 |
| ISR / static caching | None | None (CSR only) | **ISR: home 300 s, blogs 3600 s** | **V3** |
| Deployment complexity | Static SPA (simple) | Static SPA (simple) | **Node server + image optimizer + ISR cache** (heavier) | **V1/V2** |
| Developer experience | God-object, dead code | Zustand + RQ, ESLint | Well-commented migration; **lint disabled at build** | V2/V3 |

---

## PART 4 — Verdict + Gaps

### Step 7 — Winner

**V3 (Next.js 15) is the clear winner** on everything that moves the business: SEO (real SSR HTML, structured data, real 404s, per-page metadata → Lighthouse SEO 100/92), correctness of the purchase flow, caching, image/font optimization, and security posture. V1 was architecturally fragile (JWT secret in bundle, broken guest checkout, forced-reload timers); V2 hardened the SPA but remains client-only with an empty crawlable shell — **and, critically, V2's current production build white-screens on load** (MUI/jsx-runtime chunk error), so in its present state V2 doesn't even run in production. V3 is the only version a search engine, a social scraper, *and a customer* can actually use right now.

**The one place V3 currently loses to V2/V1: raw interactivity on the home page**, because it renders the entire 2137-product catalog server-side and hydrates it (TBT 12.9 s). This is a self-inflicted, fixable regression — not a framework limitation.

### Step 8 — Every remaining issue

**A) BUGS (broken now)**
1. **Product detail main gallery image is broken** — `Uploads_Images/Product_image/*.webp` 404s. *Root cause: missing seed files (affects V2 too). Fix data or add a graceful fallback to the catalog `Product/` image.* Severity: High (visual), but data-side.
2. **Home total shows "EGP 100" with an empty cart** — the header bakes default Cairo shipping into the total even at 0 items. Severity: Low (confusing, not functional).
3. **Language resets to EN on hard reload / direct URL** — no persisted locale; SSR is always EN. Severity: Low-Med.

**B) MISSING FEATURES (vs V2)**
1. **`error.jsx` error boundaries** — V3 has none at any route level; an uncaught render error falls back to the raw Next error page.
2. **Loading skeletons** — only `listing/loading.jsx`; other route Suspense fallbacks are `null` (V2 had per-component skeletons).
3. **Article JSON-LD on blog posts** — blog sets OG `type:article` but emits no structured data (product/offer/listing all do).

**C) DESIGN REGRESSIONS** — None observed. Home, listing, product, cart, checkout, login, and RTL all match the V2 luxury design and render cleanly. (Product gallery looks broken only because of the missing seed images.)

**D) PERFORMANCE GAPS (V3 slower than V2)**
1. **Home TBT 12,930 ms / TTI 16.3 s / 23.5 s main-thread / 39,566 DOM nodes** — from hydrating all 2137 cards. A CSR SPA that lazy-mounts a few rails is *more interactive* here. **This is the #1 fix.**
2. **`next/image` gains are marginal on already-tiny seed images** (2 KB source → 2.2–2.5 KB optimized); real benefit appears with larger source images.

**E) SEO GAPS**
1. **`sitemap.xml` emits malformed, non-canonical product URLs** 🔴 — 475 product `<loc>`s use the **raw product title with literal spaces** (e.g. `/product/Original Maserati Watch For Men  With Black Dial…R8873638005`) instead of the clean slug (`gucci-visor-silver-cap`). Spaces are invalid in a URL, and each one **308-redirects** to the canonical slug — so every sitemap entry is a redirect hop. Should emit the canonical slug directly.
2. **Product cards are `<div role="button" onClick>`, not `<a href>`** → detail pages aren't reachable via crawlable *internal* links. **Mitigated** by the sitemap (all 475 products listed), but real `<a href>` links would still help crawl depth and PageRank flow.
3. **No `og:image` on listing/brand/category** pages (social shares of those pages have no image).
4. **Brand/facet landing pages are absent from the sitemap** — 0 `/brand/*` and only 2 `/category/*` URLs (sitemap is products + home + 2 categories). Add brand/category/subtype facet URLs.
5. `?brand=rolex` canonicalizes to `/listing` (not `/brand/rolex`) — defensible; robots also `Disallow: /*?*`, so query-filter URLs are correctly kept out of the index.
6. **Blog posts are `Disallow: /blog/` in robots.txt AND have no Article JSON-LD** — blog content is effectively invisible to search. Intentional? If blogs are a content-marketing channel, this defeats them.

*(Correction to an earlier assumption: **`sitemap.xml` and `robots.txt` DO exist** and are served — robots.txt disallows `/cart`, `/checkout`, profile/order/wishlist, search, `/blog/`, and all `?*` URLs, allows `/`, and references the sitemap. The gap is the malformed product URLs and missing facet URLs, not their absence.)*

**F) ACCESSIBILITY GAPS (below WCAG AA)**
1. **Color contrast** failures (Lighthouse) — likely gold-on-light / muted grays.
2. **`label-content-name-mismatch`** — visible label text differs from accessible name on some control.
   (Overall a11y 93–97 is good; these are the specific misses.)

**G) CODE QUALITY / TECH DEBT**
1. **ESLint disabled at build** (`ignoreDuringBuilds: true`, ~30 errors + 46 warnings deferred).
2. **Partial `next/image` adoption** — ~15 raw `<img>` remain (some intentional: LCP poster, avatars, logos; some not yet migrated: BrandStrip).
3. **README is create-next-app boilerplate**; committed **`dev.log`** in the folder.
4. **`Context/MyProvider.jsx` still present** alongside Zustand (transitional).

**H) SECURITY (new vs V1/V2)**
1. No new client-side vulnerability introduced; V3 **improves** on V1 (JWT no longer shipped in a readable global; server-only `LARAVEL_ORIGIN`, `Api-Code` header used only server-side).
2. **Backend `memory_limit` DoS-ish fragility** — the catalog endpoint 500s under 128M; not introduced by V3 but exposed by it (SSR depends on that endpoint). Ops must raise it.
3. TikTok/Meta pixels load third-party scripts (pre-existing).

**I) DEPLOYMENT READINESS**
1. Node runtime + persistent ISR cache required (not static export).
2. `images.remotePatterns` must match the prod asset host; **prod PHP `memory_limit ≥ 256–512M`**.
3. **`sitemap.xml` + `robots.txt` are present** — fix the sitemap's raw-title product URLs → canonical slugs, and add facet URLs.
4. Seed/verify **product-gallery images** (`Product_image/`) and real **discount data** (or hide the offer route/banner).
5. Set env: `PUBLIC_API_KEY`, `PAYMOB_ENABLED`, `LARAVEL_ORIGIN`, asset base.

### Step 9 — Prioritized gaps

**CRITICAL (before launch)**
1. **Home page: paginate / limit / virtualize the product grid** (render ~24–48, not 2137). Removes the 12.9 s TBT — the single highest-impact fix.
2. **Fix the sitemap product URLs** — emit canonical **slugs** (not raw titles with spaces); currently all 475 product entries are malformed and 308-redirect. Add brand/category facet URLs. (sitemap.xml + robots.txt already exist and are otherwise well-configured.) Ideally also make product cards real `<a href>` links.
3. **Fix product gallery images** (seed `Product_image/` files or fall back to the catalog image) — the detail page currently shows a broken main image.
4. **Backend prod `memory_limit ≥ 256–512M`** (or paginate `/all_product`) — SSR is empty if this 500s.

**IMPORTANT (soon after)**
1. Add **`error.jsx`** boundaries + real loading skeletons for non-listing routes.
2. Fix **empty-cart total** (don't add shipping at 0 items) and **persist language** across reloads.
3. Add **og:image** to listing/brand/category; add **Article JSON-LD** to blogs.
4. Resolve the **2 accessibility** misses (contrast, label mismatch).

**NICE TO HAVE (future)**
1. Re-enable ESLint at build; finish `next/image` migration (BrandStrip etc.).
2. Remove `MyProvider` transitional Context; clean README + `dev.log`.
3. Restore real discount data to exercise the `/offer` route + banner.

---

## Summary

✅ **QA COMPLETE**
Routes tested: 20+ / 23 functional + 10 SEO (rest blocked by data/credentials)
Working: large majority
Broken: 1 (product main gallery image — seed-data 404, not code)

✅ **COMPARISON COMPLETE**
Winner: **V3 (Next.js 15)** — decisively, on SEO, correctness, caching, security, and optimization
V3 bugs: 3 (1 high/data, 2 low)
V3 missing features vs V2: 3 (error boundaries, skeletons, blog JSON-LD)
V3 design regressions: 0
V3 critical fixes before launch: 4 (home-page hydration/2137 cards, sitemap product-URL format, product gallery images, backend PHP memory_limit)
Report saved: `audit/VERSION_COMPARISON_REPORT.md`
