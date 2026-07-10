# WATCHIZER — CURRENT ROADMAP

> Generated: 2026-07-04
> Based on: full project scan + comparison against `audit/watchizer-roadmap.md` (50 tasks, P0-1 → P5-7)
> Method: read middleware, controllers, models, routes, migrations, stores, config, index.html, vite build output. Every status below reflects the **actual current code**, not what was planned.
> Build at scan time: `vite build` exit 0 (`✓ built in 1m 19s`).

---

## WHAT WAS DONE (verified against old roadmap)

### Phase 0 — Security & Stability
- **P0-1 ✅** Rotate/decouple API key — `CheckApiMiddleware.php:18` uses `config('services.public_api_key')`; no `jwt.secret` reference. `services.php:34` maps `PUBLIC_API_KEY`.
- **P0-2 ✅** Centralize API key — `grep NbmFylY0 Frontend/src` = **0**. One axios instance + interceptor.
- **P0-3 ✅** Paymob HMAC — `OrderController.php:857-944` verifies `hash_hmac('sha512', …)` against `PAYMOB_HMAC_SECRET` before touching any order; 403 on mismatch.
- **P0-4 ✅** Scope endpoints — `api.php:91-100` `me/orders`, `me/addresses`, `me/cart` behind `auth:api`/`guest.cart`; `all_user`, `show_order`, `show_cart`, `show_address` commented out/deprecated.
- **P0-5 🔄 SUPERSEDED** Cart↔checkout hotfix — replaced by the durable guest-cart (Phase 1.5). Checkout reads `useCart` + sends `items[]`.
- **P0-6 ✅** Remove forced-reload timers — no reload `setInterval` remains (only `FeaturedBanner.jsx:44`, a legit banner rotator).
- **P0-7 ✅** `Product` `$hidden` — `Product.php:90-98` hides `purchase_price, wa_code, hs_code, sku_unique, created_by, updated_by, low_stock_threshold`.
- **P0-8 ✅** Stop leaking `file`/`line`/`getMessage` — `OrderController` catches use `Log::error($e)` + `Str::uuid()` `ref`; remaining `getMessage()` in `AuthController` are `Log::` calls, not JSON.
- **P0-9 ✅** IDOR delete — `DeleteCart` (`OrderController.php:235`) and `DeleteWishlist` (`DetailsProductController.php:263`) use `whereHas` ownership scoping. *(See NEW-2: DeleteCart uses the wrong auth guard.)*
- **P0-10 ✅** Gate/dedupe ratings — `AddProductRating/AddOfferRating` check auth + `updateOrCreate` on `(user_id, product_id/offer_id)`; unique migration exists. *(See NEW-1: wrong auth guard.)*
- **P0-11 ✅** Trust badges — `Components/Merchandising/TrustSignals.jsx` exists.

### Phase 1 — Backend API Reshape
- **P1-1 ✅** Paginated listing — `api.php:44` `GET products` → `ProductListingController@index`.
- **P1-2 ✅** `ProductListResource` — `app/Http/Resources/ProductListResource.php` exists.
- **P1-3 ✅** PDP endpoint + resource — `products/{id}`, `products/by-name/{name}`; `ProductResource.php` exists.
- **P1-5 ✅** DB indexes — `2026_05_31_154353_add_performance_indexes_to_products_table.php` + `2026_05_31_013819_add_unique_constraint_to_ratings_tables.php`.
- **P1-6 ✅** Order-number generation — `OrderController.php:599-600` `lockForUpdate()->selectRaw('MAX(CAST(order_number AS UNSIGNED))')` inside the transaction.
- **P1-7 ✅** Stock lock + restore — `OrderController.php:643-654` atomic `where(field,'>=',qty)->decrement` + rollback on 0 rows; restore via `increment` on cancel/failed callback (`:821,842,896`).

### Phase 1.5 — Guest Cart (fully delivered)
- **P1.5-1 ✅** `2026_06_19_150517_add_guest_support_to_carts.php`.
- **P1.5-2 ✅** `GuestCartMiddleware.php` (JWT → `user_id`; else mint/echo `X-Guest-Token`).
- **P1.5-3 ✅** `add_to_cart` + `me/cart` behind `guest.cart`.
- **P1.5-4 ✅** Frontend `wz_guest_token` + api interceptor.
- **P1.5-5 ✅** `cart/merge` route (`api.php:140`).
- **P1.5-6 ✅** `PruneGuestCarts` command + scheduled daily (`Kernel.php:16`).
- **P1.5-7 ✅** Guest vs logged-in checkout UI.

### Phase 2 / 3 / 4 / 5 — partial completions
- **P2-4 ✅** `robots.txt` exists (backend `public/robots.txt` + `web.php` route). *(Weak content — see remaining.)*
- **P2-5 ✅** Dynamic `sitemap.xml` — `SitemapController.php`, cached 6 h, products/brands/categories/blogs + image tags.
- **P3-2 ✅** `windowWidth` removed — `grep windowWidth Frontend/src` = **0**.
- **P3-3 ✅** Phone/desktop duplicates merged — `PhoneCart.jsx`, `PhoneWishList.jsx`, `ProfileSpeedPhone.jsx` all deleted.
- **P4-4 ✅** Legacy plugin removed — no `@vitejs/plugin-legacy` / polyfill in `vite.config.js` or `main.jsx`.
- **P5-1 ✅** `CategoryTiles.jsx` mounted in Home.

**Also shipped beyond the roadmap:** Embla carousels (replaced custom drag), per-component skeletons (no global blocking loader), unified `/account` page, Google OAuth + password reset + email verification, re-engagement email command, `has_password` social-auth handling, WhatsApp order tracking.

---

## WHAT'S STILL NEEDED

### PHASE 1 — SECURITY (do first)

**NEW-1 — Ratings use the wrong auth guard → broken for logged-in users** · Small · **NEW**
- **Where:** `DetailsProductController.php:91,101,171,181` — `auth()->check()` / `auth()->id()`. Routes `add_product_rating`/`add_offer_rating` (`api.php:50,53`) are in the `CheckApi` group, **not** `auth:api`.
- **Wrong because:** default guard is `web` (session); for the stateless JWT SPA it returns null/false — the same bug the team already fixed in `DeleteWishlist` with `auth('api')`. Logged-in users likely get 401, and `updateOrCreate` on `user_id = null` mis-groups any that slip through.
- **Change:** put both routes behind `auth:api` and use `auth('api')->id()`.
- **Deps:** none.

**NEW-2 — `DeleteCart` uses the wrong auth guard → cart-item delete fails** · Small · **NEW**
- **Where:** `OrderController.php:234` `$authId = auth()->id();` on route `delete_cart/{id}` (`guest.cart` middleware, no `auth:api`).
- **Wrong because:** for JWT users `auth()->id()` is null → `whereHas('cart', user_id = null)` → `findOrFail` 404; guest carts key on `guest_token`, never matched. Server-side removal is effectively broken.
- **Change:** resolve identity from `$request->identity` (set by `guest.cart`) and scope by `user_id` **or** `guest_token`.
- **Deps:** none.

**NEW-3 / P1-9 — `AddOrder` trusts client-supplied prices** · Medium · P1-9 (partial)
- **Where:** `OrderController.php:631-632` writes `piece_price`/`total_price` straight from the request items; `validateCart` (`:259`) exists but AddOrder doesn't recompute authoritative totals.
- **Change:** recompute line + order totals server-side from DB prices inside the transaction; reject on mismatch. Also settle money precision (integer piastres or `numeric` validation) so `.99` prices survive.
- **Deps:** none. *(validateCart already provides the price source.)*

**NEW-9 — Wishlist still requires `user_id` in body → guests can't add** · Small · **NEW**
- **Where:** `DetailsProductController.php:210,215` — `'user_id' => 'required|integer|exists:users,id'` even though `add_wishlist` carries `guest.cart`.
- **Change:** resolve from `$request->identity`; make guest wishlist work or explicitly gate wishlist to logged-in users (and hide the heart for guests).
- **Deps:** decide product behaviour first.

**NEW-5 — Production config still in dev mode** · Small · **NEW**
- **Where:** `backend/.env` — `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`, `FRONTEND_URL=http://localhost:5173`.
- **Change:** production values before deploy (leaks stack traces, breaks absolute URLs / sitemap / OAuth redirects otherwise).
- **Deps:** none.

---

### PHASE 2 — PERFORMANCE

**P1-8 / NEW-6 — Order emails block the checkout response** · Small · P1-8 (not done)
- **Where:** `OrderController.php:720,730` `Mail::to()->send()` (customer + up to 5 admins), explicitly synchronous ("no ShouldQueue / no worker").
- **Change:** run `php artisan queue:work` (Supervisor) + switch these to `->queue()` with `OrderCreatedMail implements ShouldQueue`. `QUEUE_CONNECTION=database` is already set; restock + re-engagement mail already use `->queue()` and **need this worker to send at all**.
- **Deps:** queue worker infra.

**P3-5 — Remove Bootstrap** · Large · P3-5 (not done)
- **Where:** `main.jsx` imports bootstrap; build ships `bootstrap.css` **230.97 kB** (gzip 30.6).
- **Change:** migrate remaining `row/col-*/d-*` usage, drop the import + dependency.
- **Deps:** P3-4 cleanup (many Bootstrap users are dead files).

**P5-5 — Remove AOS** · Medium · P5-5 (not done)
- **Where:** `main.jsx:8-9` `import 'aos/dist/aos'` + css; build ships `aos.css` **26 kB**. (Framer Motion is already installed but only used by the dead `Loader.jsx`.)
- **Change:** replace `data-aos` reveals with CSS/IntersectionObserver (or the already-present Framer), drop AOS.
- **Deps:** P3-4.

**P4-3 — Fix font loading** · Small · P4-3 (not done)
- **Where:** `index.html:9-12` render-blocking Google Fonts `<link rel=stylesheet>` (Lato + Dosis), no `preload`.
- **Change:** preload/self-host the two weights actually used; keep `display=swap`.
- **Deps:** none.

**P4-2 — Preload the LCP image** · Small · P4-2 (not done)
- **Where:** hero (`HomeSlider.jsx` react-slick slide / 3D canvas). No `fetchpriority`/`preload`.
- **Change:** `fetchpriority="high"` + `<link rel=preload as=image>` for the first hero image.
- **Deps:** none.

**P4-1 — Intrinsic `<img>` dimensions (CLS)** · Small · P4-1 (partial)
- **Where:** banners/sliders/footer imgs — `loading="lazy"` present but `width`/`height` not guaranteed.
- **Change:** add explicit `width`/`height` or `aspect-ratio` containers.
- **Deps:** none.

**P4-5 — Image transform CDN + srcset** · Medium · P4-5 (not done)
- **Where:** catalog images served raw from `dash.watchizereg.com/Uploads_Images`; `vite-plugin-image-presets` only optimizes local bundled assets.
- **Change:** front the image origin with a resizing/AVIF-WebP CDN; emit `srcset` from the API resources.
- **Deps:** P1-2/P1-3 resources (done).

**NEW-7 — Reduce main + vendor JS** · Medium · **NEW** *(observed in build)*
- **Where:** `index.js` **451 kB** (gzip 146), `mui.js` **277 kB** (gzip 84), `three.js` **667 kB** + `react-three` **278 kB**.
- **Change:** confirm the 3D `WatchCanvas` is lazy/deferred below the fold (chunk exists — verify it isn't on the critical path); trim MUI via the removal work below; wire the already-installed `vite-plugin-compression` (it's a dependency but **not** in `vite.config.js` plugins → no brotli/gzip precompression today).
- **Deps:** P3-5 (MUI), P3-4 (dead code).

**P3-1 — Split the god-context** · Large · P3-1 (partial)
- **Where:** `MyProvider.jsx` still holds `products/offers/tables/wishList/…`; `@tanstack/react-query` is installed but server data isn't moved into it.
- **Change:** move server data to TanStack Query; keep UI/cart/auth in the existing Zustand stores.
- **Deps:** none blocking (endpoints exist).

---

### PHASE 3 — SEO

**P2-2 — Per-route dynamic metadata** · Large · P2-2 (not done) — *highest SEO lever*
- **Where:** only one global `<Helmet>` in `App.jsx:176-256` (Arabic homepage title/description/canonical shared by **every** route). `catalog/meta` and `ProductResource` already carry SEO fields but nothing consumes them per page.
- **Change:** per-page `<Helmet>` (title, description, self-canonical) for product/listing/brand/category/blog.
- **Deps:** none (react-helmet-async already installed).

**P2-3 — Product / Breadcrumb / AggregateRating JSON-LD** · Medium · P2-3 (not done)
- **Where:** only the homepage `Store` schema exists (`App.jsx:208`).
- **Change:** emit `Product` + `BreadcrumbList` (+ `AggregateRating` when `rating_count>0`) on PDP/category.
- **Deps:** P2-2 infra.

**P2-1 — SSR / prerender** · Large · P2-1 (not done)
- **Where:** client-only Vite SPA; crawlers/social scrapers get an empty `#root` + one meta set even after P2-2.
- **Change:** prerender/SSR product + listing routes (Next.js migration, or a Vite prerender/dynamic-render layer). *Decide whether to commit to the Next.js migration the old roadmap assumed, or stay SPA + prerender.*
- **Deps:** P1-1/P1-3 (done).

**P2-6 — hreflang AR/EN** · Medium · P2-6 (not done)
- **Where:** same URL serves both languages via JS toggle; no `hreflang` alternates.
- **Deps:** P2-1/P2-2.

**NEW-11 — Harden robots.txt + sitemap reachability** · Small · **NEW / P2-4 follow-up**
- **Where:** `backend/public/robots.txt` is just `User-agent: * / Disallow:` (allows everything), no `Sitemap:` line, doesn't block `/cart`,`/checkout`,`/login`. Sitemap is served by Laravel (`dash.` domain) while URLs point at `watchizereg.com`.
- **Change:** add `Disallow` for private routes + `Sitemap: https://watchizereg.com/sitemap.xml`; ensure the sitemap resolves on the public domain.
- **Deps:** none.

---

### PHASE 4 — CODE CLEANUP

**P3-4 — Delete dead legacy files** · Medium · P3-4 (not done)
- **Where (confirmed present, superseded):** `Pages/EditProfile/EditProfile.jsx`, `Pages/WishList/WishList.jsx`, `Pages/OrderList/OrderList.jsx`, `Components/Loader/Loader.jsx`, `Components/Product/ProductDisplay.jsx`, `OfferDisplay.jsx`, `ProductModel.jsx`, `OfferModel.jsx`, `Pages/Cart/CartProductModel.jsx`, `CartOfferModel.jsx`, `Pages/Auth/Login/LoginModal.jsx`. Build still emits `ProductDisplay`/`OfferDisplay`/`ProductModel` chunks.
- **Change:** confirm no live import, delete; drop `react-inner-image-zoom` if the new `ProductDetail` doesn't use it. Removes MUI/Bootstrap/AOS/zoom pull-in from these files.
- **Deps:** none (verify imports first).

**NEW-4 — Missing static assets on the frontend domain** · Small · **NEW**
- **Where:** `App.jsx` Helmet + `index.html` reference `/manifest.json`, `/favicon.ico`, `/logo.svg`; `Frontend/public/` contains only `placeholder.png` + `Watchizer_Gold.glb`.
- **Change:** add the real files (PWA manifest, favicon, OG logo) or fix paths — otherwise 404s + broken social preview.
- **Deps:** none.

**P1-4 follow-through — retire the 11-table lookup fetch** · Medium · P1-4 (partial)
- **Where:** `catalog/meta` endpoint is built and used, but `FetchTablesAndProducts.jsx` still fires the legacy per-table calls (the old roadmap "HELD" note: meta omits 7 tables `transformProductData` needs).
- **Change:** expand `catalog/meta` to carry those tables in the raw shape, then remove the legacy fetches.
- **Deps:** none.

**NEW-10 — Mount or remove `MegaMenu`** · Small · **NEW / P5-2**
- **Where:** `Components/Header/MegaMenu.jsx` exists but `Header.jsx` never imports it (dead component).
- **Change:** wire it into the header, or delete it.
- **Deps:** P1-4 (data).

---

### PHASE 5 — FEATURES / POLISH

**P5-4 — Trust layer on cart + checkout** · Small · P5-4 (partial) — `TrustSignals.jsx` exists; verify it's mounted on PDP + `CartModal` + checkout with live review/stock counts.
**P5-6 — Display/luxury typography via theme** · Small · P5-6 (partial) — Georgia serif used ad-hoc in new components; no central theme-driven display font/scale.
**P5-7 — Central design tokens** · Medium · P5-7 (partial) — `--wz-*` CSS vars + gold `#C8A45C` exist, but no single `tokens.js`/MUI theme; some hardcoded literals remain.
**P5-3 🔄 SUPERSEDED** — Recommendations render inside `ProductDetail`; no separate `RecommendationsRow` needed (verify every PDP shows ≥1).
**NEW-8 — Analytics event coverage** · Medium · **NEW** — FB + TikTok pixels fire `PageView` only (`index.html`); add `ViewContent`/`AddToCart`/`Purchase`. TikTok pixel also loads eagerly (defer it).

---

## QUICK WINS (< 30 min, no dependencies)

1. **Flip prod env** — `backend/.env`: `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL`/`FRONTEND_URL` (NEW-5).
2. **Fix ratings guard** — `DetailsProductController.php:91,101,171,181` → `auth('api')` + add `auth:api` on the two routes (NEW-1).
3. **Fix DeleteCart guard** — `OrderController.php:234` → resolve from `$request->identity` (NEW-2).
4. **Wire compression plugin** — add `vite-plugin-compression` (already a dependency) to `vite.config.js` plugins (NEW-7).
5. **Harden robots.txt** — add `Disallow` for private routes + `Sitemap:` line (NEW-11).
6. **Ship favicon/logo/manifest** into `Frontend/public/` (NEW-4).
7. **Defer TikTok pixel** in `index.html` like the FB one (NEW-8).
8. **Preload hero image** — `fetchpriority="high"` + preload (P4-2).
9. **Delete Bootstrap JS import** — `main.jsx:7` `import 'bootstrap/dist/js/bootstrap.bundle.min'` (unused in a React SPA) (ADD-4).
10. **Preconnect to the API/image origin** — add `<link rel="preconnect" href="https://dash.watchizereg.com" crossorigin>` to `index.html` (ADD-7).
11. **Add `<h1>` to listing/category/brand pages** — `Listing.jsx`, `ListingGrades.jsx`, `ListingSearch.jsx`, `Listingoffers.jsx` (ADD-9).
12. **Give product images descriptive `alt`** instead of `alt=""` — `Account.jsx`, `ProductDetail.jsx` gallery, `CartModal.jsx` (ADD-15).

## EXECUTION ORDER (dependency-aware)

1. **Security quick wins** — NEW-1, NEW-2, NEW-5 (guards + prod env). *Blocks correct ratings/cart + safe deploy.*
2. **NEW-3 / P1-9** — server-recompute order totals (price-tamper hole).
3. **P1-8 / NEW-6** — queue worker + non-blocking emails (also activates restock/re-engagement mail).
4. **NEW-9** — decide + fix guest wishlist.
5. **Perf + SEO quick wins** — NEW-4, NEW-7 (compression), NEW-11, P4-2, P4-3.
6. **P3-4** dead-code delete → unblocks **P3-5** (Bootstrap) + **P5-5** (AOS) removal.
7. **P2-2** per-route metadata (biggest SEO win) → **P2-3** JSON-LD → **P2-6** hreflang.
8. **P3-1** context split + **P1-4** lookup retirement (render perf).
9. **P4-1 / P4-5** image dimensions + CDN.
10. **P2-1** SSR/prerender (largest investment — decide Next.js vs stay-SPA first).
11. **P5-4/6/7, NEW-10, NEW-8** polish: trust layer, tokens/typography, mount MegaMenu, analytics events.

**Insertions from the 2026-07-04 addendum (fold into the order above):**
- Alongside step **1 (quick wins):** ADD-4 (drop Bootstrap JS), ADD-7 (preconnect), ADD-9 (`<h1>`s), ADD-15 (alt text) — all sub-30-min.
- Alongside step **5 (perf quick wins):** ADD-1 (de-block entry imports), ADD-3 (drop react-slick/slick), ADD-6 (API cache headers), ADD-18/ADD-19 (LCP poster + CLS reservations), P4-2.
- Alongside step **6 (dead-code → Bootstrap/AOS removal):** ADD-1/ADD-3/ADD-4 make P3-5/P5-5 cheaper.
- Alongside step **7 (per-route metadata):** ADD-9, ADD-10, ADD-11, ADD-12 (headings, per-PDP OG, canonical consolidation, soft-404).
- Alongside step **8 (context split):** ADD-2, ADD-8, ADD-20, ADD-22 (memoization, retire bulk fetch, INP, error/retry UI).
- Alongside step **9 (images):** ADD-13, ADD-14.
- Alongside step **10 (SSR):** unblocks ADD-10/ADD-12 and real 404s.
- New polish bucket with step **11:** ADD-5 (three.js), ADD-16/ADD-17 (a11y), ADD-21 (PWA/service worker), ADD-23 (back-to-top + action states).

---

## STATUS TALLY

| Status | Count | Tasks |
|---|---|---|
| ✅ Done | 29 | P0-1,2,3,4,6,7,8,9,10,11 · P1-1,2,3,5,6,7 · P1.5-1..7 · P2-4,P2-5 · P3-2,P3-3 · P4-4 · P5-1 |
| ⚠️ Partial | 8 | P1-4, P1-9, P3-1, P4-1, P5-2, P5-4, P5-6, P5-7 |
| ❌ Not done | 11 | P1-8, P2-1, P2-2, P2-3, P2-6, P3-4, P3-5, P4-2, P4-3, P4-5, P5-5 |
| 🔄 Superseded | 2 | P0-5, P5-3 |
| **Old-roadmap total** | **50** | |
| 🆕 New issues found | 11 | NEW-1..11 |
| **Total remaining (partial + not-done + new)** | **30** | 8 partial + 11 not-done + 11 new |

> **2026-07-04 addendum:** a deep performance/SEO/UX/Lighthouse scan added **23 more items** (`ADD-*` below) + 4 quick wins. Revised remaining total: **53**. See the new section and the revised tally at the very end.

---

## ADDITIONAL ITEMS DISCOVERED (Performance + SEO + UX + Lighthouse)

> Second-pass scan on 2026-07-04: `main.jsx`, `index.html`, `vite build` output, image/heading/aria greps, backend cache/paginate patterns. These are **in addition to** everything above — nothing here replaces an existing task; several deliberately extend one (cross-referenced).

#### RENDER PERFORMANCE

### RENDER — ADD-1 · Render-blocking framework imports in the entry module 🔴
- **Priority:** 🔴
- **Impact on Lighthouse:** FCP, LCP, TBT ("Eliminate render-blocking resources", "Reduce unused CSS/JS")
- **Where:** `main.jsx:6-11` — `bootstrap.min.css`, `bootstrap.bundle.min` (JS + Popper), `aos/dist/aos` (JS), `aos.css`, `slick.css`, `slick-theme.css` are all imported at the top of the entry bundle, so **every** route (incl. the LCP paint) waits on ~257 kB of CSS (`bootstrap.css` 231 kB + `aos.css` 26 kB) plus Bootstrap/AOS JS it mostly doesn't use.
- **Change:** even before the full Bootstrap/AOS removal (P3-5/P5-5), stop importing them from the entry — scope Bootstrap CSS to the pages that still need it, drop `bootstrap.bundle.min` JS entirely (React app doesn't use Bootstrap JS), lazy-load AOS only where `data-aos` is used.
- **Effort:** Small (2 h) · **Dependencies:** feeds P3-5, P5-5.

### RENDER — ADD-2 · God-context forces full-tree re-renders (concrete memo gaps) 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** INP/TBT (main-thread work)
- **Where:** `MyProvider.jsx` (~1 memo / 4 callbacks for ~40 values); language toggle, alert, and cart writes re-render every consumer. Extends **P3-1** with the concrete symptom.
- **Change:** as part of P3-1, move server data to the already-configured TanStack Query client (`main.jsx:13`) and split UI/alert state out so a toast or resize doesn't re-render product lists.
- **Effort:** Large · **Dependencies:** P3-1.

#### BUNDLE SIZE

### BUNDLE — ADD-3 · Duplicate carousel libraries shipped 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** TBT, "Reduce unused JavaScript"
- **Where:** Embla is now the slider everywhere **except** the hero (`HomeSlider.jsx:4` still lazy-loads `react-slick`), yet `main.jsx:10-11` globally imports `slick.css` + `slick-theme.css`. So `react-slick` + `slick-carousel` **and** `embla-carousel-react` all ship.
- **Change:** migrate `HomeSlider` to Embla, remove `react-slick` + `slick-carousel` deps and the two slick CSS imports.
- **Effort:** Small (3 h) · **Dependencies:** none.

### BUNDLE — ADD-4 · Bootstrap JS bundle (+Popper) is pure dead weight 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** TBT, "Reduce unused JavaScript"
- **Where:** `main.jsx:7` `import 'bootstrap/dist/js/bootstrap.bundle.min'` — a React SPA doesn't use Bootstrap's jQuery-free JS (dropdowns/modals are custom/MUI).
- **Change:** delete the import outright (separate, cheaper win than the full CSS migration in P3-5).
- **Effort:** Small (15 min) · **Dependencies:** none.

### BUNDLE — ADD-5 · `three.js` (667 kB) on/near the hero path 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** LCP, TBT
- **Where:** build ships `three.js` 667 kB + `react-three` 278 kB (`WatchCanvas`). Even though it's a chunk, the 3D hero is above the fold.
- **Change:** confirm `WatchCanvas` is lazy + below the LCP element (see CWV-1); enable Draco/meshopt compression for the `.glb`; self-host + tree-shake three modules actually used.
- **Effort:** Medium · **Dependencies:** CWV-1.

#### NETWORK & LOADING

### NETWORK — ADD-6 · No HTTP cache headers on API responses 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** "Serve static assets with an efficient cache policy", repeat-visit LCP
- **Where:** API controllers use `Cache::remember` server-side (good) but responses carry no `Cache-Control`/`ETag`, so the browser refetches the catalog/meta on every visit.
- **Change:** add `Cache-Control: public, max-age=…` + `ETag` on cacheable GET endpoints (`products`, `catalog/meta`, banners, `all_*`).
- **Effort:** Small · **Dependencies:** none.

### NETWORK — ADD-7 · No preconnect/dns-prefetch to API + image origin 🔴
- **Priority:** 🔴
- **Impact on Lighthouse:** LCP, FCP ("Preconnect to required origins")
- **Where:** `index.html:7-8` preconnects only Google Fonts. The API/images live on `dash.watchizereg.com` — first product image + first API call pay a full DNS+TLS handshake.
- **Change:** add `<link rel="preconnect" href="https://dash.watchizereg.com" crossorigin>` (+ `dns-prefetch` fallback).
- **Effort:** Small (15 min) · **Dependencies:** none.

### NETWORK — ADD-8 · Full-catalog fetch on app mount 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** LCP, TBT, "Avoid enormous network payloads"
- **Where:** `FetchTablesAndProducts.jsx` still downloads the whole catalog + lookup tables on first mount, even though paginated `/products` + `/catalog/meta` now exist. Extends **P1-4**/**P3-1**.
- **Change:** fetch per-route via TanStack Query (listing → `/products?page=…`), retire the bulk mount fetch.
- **Effort:** Medium · **Dependencies:** P3-1, P1-4.

#### SEO — META & STRUCTURED DATA

### SEO-META — ADD-9 · Listing / category / brand pages have no `<h1>` 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** SEO score ("Document doesn't have a valid heading order"), ranking
- **Where:** `<h1>` exists on hero, PDP (desktop-only), auth, cart, checkout — but **not** on `Listing.jsx`, `ListingGrades.jsx`, `ListingSearch.jsx`, `Listingoffers.jsx`. Category/brand pages render without a primary heading.
- **Change:** add one semantic `<h1>` per listing (e.g. "{Category} Watches", "{Brand}") ; make the PDP `<h1>` visible on mobile too (currently `wz-pd-hide-mobile`).
- **Effort:** Small · **Dependencies:** none.

### SEO-META — ADD-10 · No per-product Open Graph / Twitter image for social sharing 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** SEO; social CTR (not a Lighthouse metric but a ranking/traffic signal)
- **Where:** OG/Twitter tags exist **only** statically in `index.html` (Arabic homepage, logo image). Sharing any product link shows the generic homepage card. Extends **P2-2/P2-3**.
- **Change:** per-PDP `og:title/og:description/og:image` (product image) + `twitter:card` via the per-route Helmet work — but note JS-set OG tags aren't read by most social scrapers, so this ultimately depends on SSR (P2-1).
- **Effort:** Small (with P2-2) · **Dependencies:** P2-2, P2-1.

#### SEO — TECHNICAL

### SEO-TECH — ADD-11 · Duplicate product URLs with no consolidating canonical 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** SEO (duplicate content), crawl budget
- **Where:** the same product is reachable at `/products/{id}`, `/product/{slug}` (App.jsx routes) while the sitemap emits `/product/{title}` (`SitemapController.php:89`). No per-page self-canonical exists to pick a winner.
- **Change:** choose one canonical product URL pattern, 301 the others, emit a matching self-canonical per PDP (part of P2-2).
- **Effort:** Medium · **Dependencies:** P2-2.

### SEO-TECH — ADD-12 · SPA returns HTTP 200 for unknown routes (soft 404) 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** SEO; Google indexes error pages as valid
- **Where:** `App.jsx` `*` → `NotFound` renders with a 200 status (client-only). Crawlers see soft-404s.
- **Change:** with SSR (P2-1) return a real 404; interim — host-level rule + a `noindex` meta on the NotFound route.
- **Effort:** Small (interim) / part of P2-1 · **Dependencies:** P2-1.

#### IMAGE OPTIMIZATION

### IMAGE — ADD-13 · Non-grid images lack `width`/`height` + lazy (concrete inventory) 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** CLS, LCP. Extends **P4-1**.
- **Where:** `ProductCard.jsx:113-115` is correct (300×300 + lazy) — but these are **not**: `Checkout.jsx:69`, `CartModal.jsx:94,114`, `Account.jsx:118,475,871`, `ProductDetail.jsx:781` (gallery), `Header.jsx:364`, `WatchCanvas.jsx:113` (logo), plus Home banners. None set intrinsic dimensions; most omit `loading="lazy"`.
- **Change:** add `width`/`height` (or `aspect-ratio` container) + `loading="lazy"` (except the LCP image, which gets `eager`+`fetchpriority=high`) to each.
- **Effort:** Small · **Dependencies:** none.

### IMAGE — ADD-14 · No responsive `srcset` / modern-format delivery for catalog images 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** LCP, "Serve images in next-gen formats", "Properly size images". Extends **P4-5**.
- **Where:** all catalog `<img>` load a single full-size origin file from `dash.watchizereg.com`; no `srcset`/`sizes`, no AVIF/WebP negotiation. The 3D `.glb` (`Watchizer_Gold.glb`) is also served uncompressed.
- **Change:** emit `srcset` width variants + `format=auto` via the image CDN (P4-5); compress the `.glb`.
- **Effort:** Medium · **Dependencies:** P4-5.

#### ACCESSIBILITY

### A11Y — ADD-15 · Product images use empty `alt=""` 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** Accessibility ("Image elements do not have `[alt]` attributes" / non-descriptive), image SEO
- **Where:** `Account.jsx:118,475`, `ProductDetail.jsx:781` (gallery), and other product thumbnails render `alt=""` (marked decorative). Product imagery is content, not decoration.
- **Change:** set descriptive `alt` (product title / "{brand} {model}") on meaningful images; keep `alt=""` only for true decoration.
- **Effort:** Small · **Dependencies:** none.

### A11Y — ADD-16 · No skip-link / focus management / modal focus trap 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** Accessibility, keyboard-nav best practices
- **Where:** no "skip to content" link; route changes don't move focus; `CartModal`/`LanguageDialog` overlays don't trap/restore focus (verify).
- **Change:** add a skip link, focus the main heading on route change, trap focus in modals and restore on close.
- **Effort:** Medium · **Dependencies:** none.

### A11Y — ADD-17 · Contrast + carousel keyboard access audit 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** Accessibility ("Contrast", "Interactive controls are keyboard focusable")
- **Where:** muted gold (`#C8A45C`) and low-opacity gray text (`rgba(0,0,0,0.4-0.55)`) on light backgrounds risk failing 4.5:1; Embla carousels need arrow-key/focus support.
- **Change:** run axe/Lighthouse a11y, fix contrast tokens, add keyboard controls + `aria-roledescription="carousel"` to Embla.
- **Effort:** Medium · **Dependencies:** P5-7 (tokens).

#### CORE WEB VITALS

### CWV — ADD-18 · Define and prioritize a lightweight LCP element 🔴
- **Priority:** 🔴
- **Impact on Lighthouse:** LCP (the headline metric)
- **Where:** the hero is a 3D `WatchCanvas` (three.js 667 kB) and/or a `react-slick` image — an expensive, late LCP candidate.
- **Change:** paint a static, preloaded poster image (AVIF/WebP) as the hero LCP element, hydrate the 3D model **after** first paint; `fetchpriority="high"` + `<link rel=preload as=image>` on that poster (ties to P4-2).
- **Effort:** Medium · **Dependencies:** P4-2, ADD-5.

### CWV — ADD-19 · Reserve space for async-mounted sections (CLS) 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** CLS
- **Where:** sliders, banners (`FeaturedBanner`), and grade rows mount after data and push content down; combined with dimensionless images (ADD-13) and font swap.
- **Change:** give every async section a fixed min-height / skeleton of the same size; set explicit image dimensions; preload the heading font to cut swap shift.
- **Effort:** Small · **Dependencies:** ADD-13, P4-3.

### CWV — ADD-20 · Reduce main-thread blocking for INP 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** INP/TBT
- **Where:** three.js parsing + god-context re-renders (ADD-2) + eager Bootstrap/AOS (ADD-1) block interaction readiness.
- **Change:** defer non-critical JS, split long tasks, ship ADD-1/ADD-2/ADD-5 first; verify INP with a field/lab trace.
- **Effort:** Medium · **Dependencies:** ADD-1, ADD-2, ADD-5.

#### UX POLISH

### UX — ADD-21 · No service worker / offline / installable PWA 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** PWA category, repeat-visit performance
- **Where:** no `serviceWorker`/workbox anywhere; `manifest.json` referenced but missing (**NEW-4**).
- **Change:** add a real manifest + icons and a Workbox service worker (offline shell, runtime-cache catalog images/API).
- **Effort:** Medium · **Dependencies:** NEW-4.

### UX — ADD-22 · No error/retry UI on failed data fetches 🟠
- **Priority:** 🟠
- **Impact on Lighthouse:** best-practices/UX (perceived reliability)
- **Where:** the render-level `ErrorBoundary` (`App.jsx`) catches thrown renders, but a failed catalog/API fetch leaves sections silently blank with no retry.
- **Change:** per-section error state + "retry" (natural once data moves to TanStack Query — it exposes `isError`/`refetch`).
- **Effort:** Small–Medium · **Dependencies:** P3-1/ADD-8.

### UX — ADD-23 · Missing affordances: back-to-top + action loading states 🟡
- **Priority:** 🟡
- **Impact on Lighthouse:** UX (not scored), conversion
- **Where:** long listing/PDP pages have no visible "back to top"; verify add-to-cart / wishlist buttons show an inline spinner/disabled state during the request.
- **Change:** add a scroll-triggered back-to-top button; ensure every async action has a pending state.
- **Effort:** Small · **Dependencies:** none.

#### NEXT.JS MIGRATION

### SSR — Already tracked as P2-1 (reinforced)
- **Status:** **already present** in this roadmap as **P2-1** (Phase 3 — SEO, "SSR / prerender"). Not re-counted as a new item.
- **Why it remains the single biggest SEO lever:** the site is a client-only Vite SPA — crawlers and social scrapers receive an empty `<div id="root"></div>` with one static Arabic homepage meta set. All per-page titles/descriptions/canonicals/OG/JSON-LD set by JS (P2-2/P2-3/ADD-10) are invisible to non-JS crawlers and to Facebook/Twitter/WhatsApp link scrapers. Product pages therefore cannot rank on their own content, and shared links show the generic card.
- **Recommended approach (incremental):** migrate **PDP + listing/category/brand routes first** to server-rendered HTML (Next.js App Router with ISR for the mostly-static catalog, or a Vite prerender/dynamic-render layer), keep account/cart/checkout client-rendered. This also unlocks `next/image` for ADD-13/ADD-14 and real 404s for ADD-12.
- **Cross-refs:** P2-1, P2-2, P2-3, P2-6, ADD-10, ADD-11, ADD-12, ADD-14.

---

## REVISED STATUS TALLY (incl. 2026-07-04 addendum)

| Status | Count | Notes |
|---|---|---|
| ✅ Done | 29 | unchanged |
| ⚠️ Partial | 8 | unchanged |
| ❌ Not done | 11 | unchanged |
| 🔄 Superseded | 2 | unchanged |
| 🆕 NEW-* issues (first pass) | 11 | NEW-1..11 |
| ➕ ADD-* items (second pass) | 23 | ADD-1..23 (Render 2 · Bundle 3 · Network 3 · SEO-meta 2 · SEO-tech 2 · Image 2 · A11y 3 · CWV 3 · UX 3). Next.js = already present (P2-1) |
| **Total remaining (partial + not-done + NEW + ADD)** | **53** | 8 + 11 + 11 + 23 |
