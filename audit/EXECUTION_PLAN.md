# WATCHIZER — EXECUTION PLAN

## Total steps: 44 (covering all 53 remaining roadmap references)
## Estimated total effort: ~7–9 weeks (1 dev) / ~4–5 weeks (2 devs)
> Dominated by STEP 42 (SSR, 1.5–2 wks), STEP 14 (Bootstrap removal, ~4 d), STEP 26 (context split, ~3 d), STEP 32 (image CDN, 1.5 d). Batches 1–2 (STEPs 1–11) are all sub-day quick wins totalling ~1.5 days and deliver most of the fast Lighthouse/SEO gains.

### How to use:
1. Start from STEP 1.
2. Copy the prompt under each step (the block after `### Prompt:`).
3. Send it to Claude Code.
4. Wait for completion + its checklist output.
5. Move to the next step.
6. Do NOT skip steps — they're dependency-ordered. Where a step says "(can parallel with #X)" you may run both before moving on.

### Standing constraints (already known to the project, repeat in any prompt if unsure):
- Use **pnpm**, never npm. Build check: `cd Frontend && pnpm build`.
- `backend/.env` holds **live secrets** (Paymob live keys, JWT_SECRET, MAIL_PASSWORD, PUBLIC_API_KEY) — never print, commit, or alter secret values.
- Do NOT commit or push unless explicitly asked.
- Backend auth: the SPA is JWT — always resolve identity via `auth('api')` or the `guest.cart` middleware's `$request->identity`, never the default web guard.

---

# BATCH 1 — Quick security fixes (5 min – 2 h each)

## STEP 1 — Flip backend to production config
**From:** NEW-5
**Effort:** Small (30 min)
**Unblocks:** safe deploy; correct absolute URLs for sitemap/OAuth/OG
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/.env (do NOT print secret values)
- backend/config/services.php (frontend_url / asset_base resolution)

Task: Prepare backend/.env for production WITHOUT touching any secret values.
- Set APP_ENV=production
- Set APP_DEBUG=false
- Set APP_URL to the real backend/dashboard URL (e.g. https://dash.watchizereg.com)
- Set FRONTEND_URL to https://watchizereg.com
Leave JWT_SECRET, PUBLIC_API_KEY, PAYMOB_*, MAIL_* and every other secret exactly as-is.
Do NOT change any other file. If a .env.example exists, mirror the non-secret keys there.

Verify:
- Confirm APP_ENV/APP_DEBUG/APP_URL/FRONTEND_URL now read as above.
- Note that `php artisan config:cache` should be run on deploy (do not run it here).

Output checklist:
- APP_ENV=production ✅/❌
- APP_DEBUG=false ✅/❌
- APP_URL updated ✅/❌
- FRONTEND_URL updated ✅/❌
- No secret values touched ✅
```

> Note: this STEP 1 is the effective **PRE-DEPLOY** step (production config + the `config:cache`-on-deploy reminder). The proxy note below belongs here.

### Also: Sitemap & Robots.txt Proxy
The sitemap.xml and robots.txt are served by
Laravel on dash.watchizereg.com. The public domain
watchizereg.com needs to proxy these paths.

Add to nginx config for watchizereg.com:
```
location = /robots.txt {
proxy_pass https://dash.watchizereg.com/robots.txt;
}
location = /sitemap.xml {
proxy_pass https://dash.watchizereg.com/sitemap.xml;
}
```
Without this, Google can't find the sitemap at
the URL specified in robots.txt.

## STEP 2 — Fix ratings auth guard (can parallel with #1)
**From:** NEW-1
**Effort:** Small (30 min)
**Unblocks:** ratings work for logged-in users; correct per-user dedupe
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/DetailsProductController.php (AddProductRating ~:88-118, AddOfferRating ~:168-194; lines 91,101,171,181 use auth()->check()/auth()->id())
- backend/routes/api.php (add_product_rating :50, add_offer_rating :53 — currently in the CheckApi group, NOT auth:api)
- Compare with the CORRECT pattern already used in DetailsProductController DeleteWishlist (~:262 uses auth('api')->id()).

Task: Make rating submission use the JWT guard.
- In AddProductRating and AddOfferRating, replace every auth()->check() with auth('api')->check() and auth()->id() with auth('api')->id().
- In routes/api.php, move add_product_rating and add_offer_rating into a group that includes the auth:api middleware (so unauthenticated POSTs return 401). Keep CheckApi.

Verify:
- `php -l` on the controller passes.
- Reason through: a JWT-authenticated POST now resolves a real user id; an unauthenticated POST returns 401; a second rating by the same user updates (updateOrCreate on user_id+product_id/offer_id).

Output checklist:
- auth('api') used in both methods ✅/❌
- routes behind auth:api ✅/❌
- php -l clean ✅/❌
```

## STEP 3 — Fix DeleteCart auth guard (can parallel with #1, #2)
**From:** NEW-2
**Effort:** Small (30 min)
**Unblocks:** server-side cart-item removal for users AND guests
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/OrderController.php DeleteCart (~:231-249; line 234 uses auth()->id())
- backend/app/Http/Middleware/GuestCartMiddleware.php (sets $request->identity = ['user_id'=>…] or ['guest_token'=>…])
- backend/routes/api.php (delete_cart/{id} :68 carries the guest.cart middleware)

Task: Resolve the cart owner from $request->identity instead of the web guard.
- Read $identity = $request->input('identity') (or $request->identity).
- Scope the CartItem via its cart: whereHas('cart', fn($q) => match identity — if user_id present filter user_id, else filter guest_token) then findOrFail($id)->delete().
- Keep the 404-on-not-found and the Log::error + ref-uuid 500 catch already present.

Verify:
- `php -l` passes.
- Reason: a JWT user deletes only their own cart item; a guest with X-Guest-Token deletes only their guest cart item; someone else's id → 404.

Output checklist:
- identity-based scoping ✅/❌
- works for user + guest ✅/❌
- php -l clean ✅/❌
```

## STEP 4 — Delete unused Bootstrap JS import (can parallel with #5)
**From:** ADD-4
**Effort:** Small (15 min)
**Unblocks:** cheaper Bootstrap removal (STEP 14); less entry JS
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/main.jsx (line 7: import 'bootstrap/dist/js/bootstrap.bundle.min')

Task: Remove ONLY the Bootstrap JS bundle import at main.jsx:7. Do NOT remove the Bootstrap CSS import yet (that is a later step). A React SPA does not use Bootstrap's JS (dropdowns/modals are custom/MUI).

Verify:
- `cd Frontend && pnpm build` succeeds.
- Grep confirms no remaining `bootstrap.bundle` import in src.

Output checklist:
- bootstrap JS import removed ✅/❌
- build clean ✅/❌
```

## STEP 5 — Add preconnect to API/image origin
**From:** ADD-7
**Effort:** Small (15 min)
**Unblocks:** faster first image + first API call (LCP/FCP)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/index.html (head; currently preconnects only fonts at lines 7-8)

Task: Add resource hints for the API/image origin dash.watchizereg.com in index.html <head>:
  <link rel="preconnect" href="https://dash.watchizereg.com" crossorigin />
  <link rel="dns-prefetch" href="https://dash.watchizereg.com" />
Do not remove the existing font preconnects.

Verify:
- `cd Frontend && pnpm build` succeeds and the tags are present in dist/index.html.

Output checklist:
- preconnect added ✅/❌
- dns-prefetch fallback added ✅/❌
- build clean ✅/❌
```

---

# BATCH 2 — Quick perf/SEO wins (15–30 min each, unless noted)

## STEP 6 — Harden robots.txt
**From:** NEW-11
**Effort:** Small (15 min)
**Unblocks:** correct crawl behaviour; sitemap discovery
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/public/robots.txt (currently "User-agent: * / Disallow:" — allows everything, no Sitemap line)
- backend/routes/web.php (serves /robots.txt and /sitemap.xml)

Task: Replace backend/public/robots.txt content with:
  User-agent: *
  Allow: /
  Disallow: /cart
  Disallow: /checkout
  Disallow: /login
  Disallow: /register
  Disallow: /account
  Disallow: /api
  Sitemap: https://watchizereg.com/sitemap.xml
Confirm the sitemap is reachable on the public frontend domain (note if it is only served from the dash/backend domain — if so, flag that a proxy/rewrite is needed so watchizereg.com/sitemap.xml resolves).

Output checklist:
- private routes disallowed ✅/❌
- Sitemap line added ✅/❌
- sitemap-domain reachability noted ✅/❌
```

## STEP 7 — Add missing static assets (can parallel with #6)
**From:** NEW-4
**Effort:** Small (30 min)
**Unblocks:** favicon, OG image, PWA manifest (STEP 44)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/index.html and Frontend/src/App.jsx Helmet (reference /favicon.ico, /logo.svg, /manifest.json)
- Frontend/public/ (currently only placeholder.png + Watchizer_Gold.glb — the referenced assets are MISSING → 404)

Task: Create the missing public assets so the referenced paths resolve:
- Frontend/public/manifest.json — a valid PWA manifest (name "Watchizer", short_name, start_url "/", display "standalone", theme_color "#000000", background_color, and icon entries).
- Ensure Frontend/public/favicon.ico and Frontend/public/logo.svg exist. If the real brand files are not available, create sensible placeholders and clearly note that final brand assets must replace them.
Do NOT change how they are referenced.

Verify:
- `cd Frontend && pnpm build` copies them into dist/.

Output checklist:
- manifest.json created ✅/❌
- favicon.ico present ✅/❌
- logo.svg present ✅/❌
- placeholders flagged (if any) ✅/❌
```

## STEP 8 — Preload the LCP hero image
**From:** P4-2
**Effort:** Small (0.5 d) — do the quick version now; STEP 28 refines
**Unblocks:** better LCP
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Pages/Home/HomeSlider.jsx (hero slider first slide <img>)
- Frontend/index.html <head>

Task: Prioritize the hero LCP image.
- On the FIRST hero slide image only: add fetchpriority="high" and loading="eager" (all other slide images stay lazy).
- Add a <link rel="preload" as="image" href="{first hero image url}"> to index.html if the first image URL is static/known; if it is data-driven, add the preload dynamically via the existing Helmet as early as possible.
Do NOT convert the hero to a static poster yet (that is STEP 28).

Verify:
- `cd Frontend && pnpm build` succeeds.

Output checklist:
- fetchpriority/eager on first slide ✅/❌
- preload added ✅/❌
- build clean ✅/❌
```

## STEP 9 — Fix font loading (can parallel with #8)
**From:** P4-3
**Effort:** Small (0.5 d)
**Unblocks:** removes render-blocking font request; less CLS
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/index.html lines 7-12 (render-blocking Google Fonts <link rel="stylesheet"> for Lato + Dosis, no preload)

Task: Make fonts non-render-blocking.
- Preload the two woff2 weights actually used, OR self-host Lato + Dosis locally and reference them with @font-face + font-display: swap.
- Keep display=swap. Remove any unused font weights/families (confirm which are actually used in the CSS before dropping).

Verify:
- `cd Frontend && pnpm build` succeeds; no render-blocking stylesheet remains for fonts (preloaded or self-hosted).

Output checklist:
- fonts preloaded/self-hosted ✅/❌
- font-display swap kept ✅/❌
- unused weights removed ✅/❌
- build clean ✅/❌
```

## STEP 10 — Descriptive alt text on product images (can parallel with #11)
**From:** ADD-15
**Effort:** Small (30 min)
**Unblocks:** accessibility + image SEO
### Prompt:
```
Execute this task ONLY.

Read first (each has alt="" on content images):
- Frontend/src/Pages/Account/Account.jsx:118, :475 (and :871 already uses it.title — keep)
- Frontend/src/Components/Product/ProductDetail.jsx:781 (gallery)
- Frontend/src/Pages/Cart/CartModal.jsx:94, :114
- Frontend/src/Pages/Checkout/Checkout.jsx:69 (already alt={d.name} — keep)

Task: Give content-bearing product images a descriptive alt (product title, or "{brand} {model}", or d.name/it.title as available in scope). Keep alt="" ONLY for genuinely decorative images (e.g. logos already labelled elsewhere).

Verify:
- `cd Frontend && pnpm build` succeeds.
- Grep the touched files: no content <img> left with alt="".

Output checklist:
- Account gallery/thumb alts ✅/❌
- ProductDetail gallery alt ✅/❌
- CartModal alts ✅/❌
- build clean ✅/❌
```

## STEP 11 — Add `<h1>` to listing/category/brand pages
**From:** ADD-9
**Effort:** Small (30 min)
**Unblocks:** SEO heading order; ranking signal
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Pages/Listing/Listing.jsx, ListingGrades.jsx, ListingSearch.jsx, Listingoffers.jsx (no <h1> currently)
- Frontend/src/Components/Product/ProductDetail.jsx:820 (<h1 class="wz-pd-name wz-pd-hide-mobile"> — hidden on mobile)

Task:
- Add exactly one semantic <h1> per listing page reflecting the active context (e.g. "{Category} Watches", "{Brand}", search query, "Offers"). Style it to match existing headings; it may be visually compact but must be a real <h1>.
- Make the PDP <h1> visible on mobile too (ensure a single <h1> renders on mobile — remove/adjust wz-pd-hide-mobile so mobile isn't left without an h1, without producing two h1s on desktop).

Verify:
- `cd Frontend && pnpm build` succeeds.
- Each listing page renders exactly one <h1>; PDP renders exactly one <h1> at all breakpoints.

Output checklist:
- Listing h1 ✅/❌
- ListingGrades/Search/offers h1 ✅/❌
- PDP h1 visible on mobile, single ✅/❌
- build clean ✅/❌
```

---

# BATCH 3 — Dead code removal (unblocks Bootstrap/AOS removal)

## STEP 12 — Delete dead legacy files
**From:** P3-4
**Effort:** Medium (2–4 h)
**Unblocks:** STEP 13, 14, 15 (fewer Bootstrap/AOS/slick/zoom importers)
### Prompt:
```
Execute this task ONLY.

Read first — confirm each is NOT imported by any live route/component before deleting (grep each basename across Frontend/src):
- Frontend/src/Pages/EditProfile/EditProfile.jsx
- Frontend/src/Pages/WishList/WishList.jsx
- Frontend/src/Pages/OrderList/OrderList.jsx
- Frontend/src/Components/Loader/Loader.jsx
- Frontend/src/Components/Product/ProductDisplay.jsx
- Frontend/src/Components/Product/OfferDisplay.jsx
- Frontend/src/Components/Product/ProductModel.jsx
- Frontend/src/Components/Product/OfferModel.jsx
- Frontend/src/Pages/Cart/CartProductModel.jsx
- Frontend/src/Pages/Cart/CartOfferModel.jsx
- Frontend/src/Pages/Auth/Login/LoginModal.jsx
Note: App.jsx lazy-imports ProductDisplay and OfferDisplay but does NOT route them — remove those dead lazy imports too. The live PDP is ProductDetail.

Task: Delete the confirmed-dead files and remove their now-dead imports. Do NOT delete CategoryNavPhone.jsx or MegaMenu.jsx. If any file turns out to be imported by live code, STOP and report it instead of deleting.

Verify:
- `cd Frontend && pnpm build` succeeds with no missing-import errors.
- Grep confirms the deleted basenames are no longer imported anywhere.

Output checklist:
- files deleted (list) ✅/❌
- dead App.jsx lazy imports removed ✅/❌
- build clean ✅/❌
- anything kept-because-still-used (list) 
```

## STEP 13 — Migrate HomeSlider to Embla; drop react-slick + slick
**From:** ADD-3
**Effort:** Small (3 h)
**Unblocks:** STEP 16 (clean entry); removes duplicate carousel libs
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Pages/Home/HomeSlider.jsx (line 4 lazy-imports react-slick)
- Frontend/src/main.jsx lines 10-11 (import slick.css + slick-theme.css)
- Frontend/src/Components/UI/Carousel.jsx + Carousel.css (the existing Embla wrapper used by every other slider)

Task: Convert HomeSlider to the existing Embla Carousel wrapper (same pattern as ProductSlider/OfferSlider). Preserve autoplay/behaviour and the STEP 8 LCP hints on the first slide. Then:
- Remove the two slick CSS imports from main.jsx.
- Remove react-slick and slick-carousel from Frontend/package.json (pnpm).

Verify:
- `cd Frontend && pnpm build` succeeds; no react-slick/slick chunk remains.
- Hero still autoplays and drags; first image keeps fetchpriority=high.

Output checklist:
- HomeSlider on Embla ✅/❌
- slick CSS imports removed ✅/❌
- react-slick + slick-carousel deps removed ✅/❌
- build clean ✅/❌
```

---

# BATCH 4 — Major removals (biggest bundle wins)

## STEP 14 — Remove Bootstrap CSS
**From:** P3-5
**Effort:** Large (~4 d)
**Unblocks:** ~231 kB CSS off the critical path
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/main.jsx line 6 (import bootstrap/dist/css/bootstrap.min.css)
- Grep Frontend/src for className usage of Bootstrap grid/utility classes: row, col-, d-flex, d-none, p-, m-, text-center, etc. (NOTE: STEP 12 already deleted the dead legacy files — do NOT reintroduce them; only migrate LIVE components.)

Task: Migrate remaining Bootstrap grid/utility classes in LIVE components to CSS (flex/grid + the project's wz- classes), page by page in this order: Footer → Header → Home → Listing → PDP → Cart/Checkout. Then remove the bootstrap CSS import from main.jsx and remove `bootstrap` from package.json.

Verify after EACH page and at the end:
- `cd Frontend && pnpm build` succeeds; dist no longer emits a bootstrap CSS chunk.
- Visually spot-check the migrated pages for layout regressions (describe what you checked).
- Grep Frontend/src returns no Bootstrap class usage.

Output checklist:
- pages migrated (list) ✅/❌
- bootstrap import removed ✅/❌
- bootstrap dep removed ✅/❌
- no bootstrap classes remain ✅/❌
- build clean ✅/❌
```

## STEP 15 — Remove AOS
**From:** P5-5
**Effort:** Medium (~1.5 d)
**Unblocks:** ~26 kB CSS + AOS JS off entry
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/main.jsx lines 8-9 (import aos/dist/aos.css + aos/dist/aos, and any AOS.init call)
- Grep Frontend/src for data-aos attributes.

Task: Replace AOS scroll reveals with CSS + IntersectionObserver (or the already-installed framer-motion; note framer is currently only used by the now-deleted Loader). Map data-aos="fade-up" → opacity/translateY reveal, "fade" → opacity, "zoom-in" → scale, all honoring prefers-reduced-motion. Remove the AOS imports/init from main.jsx and remove `aos` from package.json.

Verify:
- `cd Frontend && pnpm build` succeeds; no aos.css chunk remains.
- Grep returns no data-aos and no 'aos' import.
- Reveals run on transform/opacity only (60fps).

Output checklist:
- data-aos replaced ✅/❌
- AOS imports/init removed ✅/❌
- aos dep removed ✅/❌
- prefers-reduced-motion respected ✅/❌
- build clean ✅/❌
```

## STEP 16 — Verify entry module is free of render-blocking framework imports
**From:** ADD-1
**Effort:** Small (2 h)
**Unblocks:** confirms FCP/LCP gain from Batches 3–4
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/main.jsx (after STEPs 4, 13, 14, 15 it should no longer import bootstrap JS/CSS, slick CSS, or AOS)

Task: Confirm the entry module imports nothing render-blocking or unused. Any CSS that must stay should be scoped to the component/route that needs it, not imported globally in main.jsx. If any framework CSS/JS remains at the entry, scope or lazy-load it. Do NOT change app behaviour.

Verify:
- `cd Frontend && pnpm build`; inspect the build output — the entry/index chunk should be smaller than the pre-Batch-3 baseline (state before/after sizes).

Output checklist:
- entry has no bootstrap/aos/slick imports ✅/❌
- remaining CSS scoped appropriately ✅/❌
- entry chunk size before → after (record) ✅/❌
- build clean ✅/❌
```

---

# BATCH 5 — Medium security/backend

## STEP 17 — Server-side price/total verification on order
**From:** NEW-3 / P1-9
**Effort:** Medium (1 d)
**Unblocks:** closes price-tampering hole; correct decimals
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/OrderController.php AddOrder (~:476+), OrderItem create (~:626-636 writes client piece_price/total_price), validateCart (~:259)
- how prices are stored on Product/Offer (selling_price, sale_price_after_discount)

Task: Inside the AddOrder DB transaction, recompute each line price and the order total from authoritative DB prices (do NOT trust request piece_price/total_price). Reject with 422 if the client total diverges beyond a rounding epsilon. Settle money precision so .99 prices survive (use numeric/decimal consistently; if the DB column is integer, standardize on integer piastres end-to-end and adjust validation).

Verify:
- `php -l` passes.
- Reason through: a tampered client total is rejected; a correct order stores server-computed totals; a 3990.50 price checks out correctly.

Output checklist:
- server recomputes line + order totals ✅/❌
- tamper rejected (422) ✅/❌
- decimal precision preserved ✅/❌
- php -l clean ✅/❌
```

## STEP 18 — Queue order emails + run a worker
**From:** P1-8 / NEW-6
**Effort:** Small (0.5 d) + infra
**Unblocks:** fast checkout response; also activates restock/re-engagement mail
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/OrderController.php sendOrderEmails (~:718-733, uses Mail::to()->send())
- backend/app/Mail/OrderCreatedMail.php (comment notes it intentionally does NOT implement ShouldQueue)
- backend/.env (QUEUE_CONNECTION=database already set)
- backend/app/Observers/ProductObserver.php + SendReEngagementEmails command (already use ->queue())

Task: Make OrderCreatedMail implement ShouldQueue and switch the customer/admin sends in sendOrderEmails to ->queue(). Document (in a comment and in your output) that a queue worker must run: `php artisan queue:work` (Supervisor in prod). Move the hard-coded admin recipient list to config if trivial.

Verify:
- `php -l` passes.
- Reason: AddOrder returns without blocking on SMTP; jobs land in the jobs table and send when the worker runs. Note restock + re-engagement mail already depend on this worker.

Output checklist:
- OrderCreatedMail implements ShouldQueue ✅/❌
- sends switched to ->queue() ✅/❌
- worker requirement documented ✅/❌
- php -l clean ✅/❌
```

## STEP 19 — Guest-capable wishlist (or gate to logged-in) (can parallel with #17, #18)
**From:** NEW-9
**Effort:** Small (2 h)
**Unblocks:** consistent guest model / correct wishlist UX
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/DetailsProductController.php AddWishlist (~:205-225; line 210 requires user_id, line 215 firstOrCreate on user_id)
- backend/routes/api.php (add_wishlist :54 carries guest.cart)
- GuestCartMiddleware ($request->identity)

Task: DECIDE and implement one:
(A) Make wishlist guest-capable — resolve owner from $request->identity (support a guest_token column on wishlists, mirroring carts), remove the user_id-required rule; OR
(B) Gate wishlist strictly to logged-in users — require auth:api, resolve user via auth('api')->id(), and on the frontend hide the heart / prompt login for guests.
Pick (B) if a guest wishlist schema change is undesirable. Implement the chosen option end-to-end (this step may touch the wishlist heart handler in Frontend if you choose B).

Verify:
- `php -l` passes; if frontend touched, `pnpm build` passes.
- Reason through the guest and logged-in paths.

Output checklist:
- option chosen (A/B) + rationale ✅
- backend updated ✅/❌
- frontend updated (if B) ✅/❌
- lint/build clean ✅/❌
```

## STEP 20 — HTTP cache headers on cacheable API GETs
**From:** ADD-6
**Effort:** Small (0.5 d)
**Unblocks:** repeat-visit performance
### Prompt:
```
Execute this task ONLY.

Read first:
- backend/app/Http/Controllers/Api/ProductListingController.php (index/show), CatalogMetaController, BannerController, DetailsProductController (all_* endpoints) — they use Cache::remember server-side but return no HTTP cache headers.

Task: Add Cache-Control (public, max-age tuned per endpoint) and an ETag to the responses of cacheable GET endpoints: products, products/{id}, catalog/meta, all_banner_*, all_brand/all_grade/etc. Do NOT cache authenticated/guest cart, orders, or account endpoints. Prefer a small response macro/middleware over per-method duplication if clean.

Verify:
- `php -l` passes.
- Reason: a repeat request can be served 304/from browser cache; private endpoints remain uncached.

Output checklist:
- Cache-Control added to catalog endpoints ✅/❌
- ETag added ✅/❌
- private endpoints excluded ✅/❌
- php -l clean ✅/❌
```

---

# BATCH 6 — SEO (biggest ranking wins)

## STEP 21 — Per-route meta (title/description/canonical)
**From:** P2-2
**Effort:** Large (1 d)
**Unblocks:** STEP 22, 23, 24; unique indexable pages
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx:176-256 (the single global <Helmet> — Arabic homepage title/description/canonical shared by every route)
- backend ProductResource (seo fields) + catalog/meta (localized names)
- react-helmet-async is already installed and HelmetProvider is mounted.

Task: Add a per-page <Helmet> to each route type with a unique <title>, <meta description>, and a self-referencing <link rel="canonical"> to that page's own URL:
  Home:     "Watchizer | Luxury Watches & Accessories in Egypt"
  Listing:  "{Category} Watches | {brand?} | Watchizer" + count in description
  PDP:      "{product.title} – {brand} | Watchizer", desc = short description (≤160)
  Brand:    "{Brand} Watches in Egypt | Watchizer"
  Category: "{Category} | Watchizer"
Keep the global defaults as fallback. Pull data from the already-fetched product/catalog data.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Each route type renders a unique title/description/canonical (inspect in the running app or reason from the code).

Output checklist:
- per-route titles ✅/❌
- per-route descriptions ✅/❌
- self-canonical per route ✅/❌
- build clean ✅/❌
```

## STEP 22 — Product + Breadcrumb + AggregateRating JSON-LD
**From:** P2-3
**Effort:** Medium (0.5 d)
**Unblocks:** rich results
**Depends on:** STEP 21
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx:208 (only the homepage Store JSON-LD exists)
- ProductDetail.jsx (product data) + breadcrumb rendering

Task: Emit JSON-LD via Helmet on the PDP and category pages:
- Product (PDP): name, brand, image[], sku(id), description, offers{priceCurrency EGP, price, availability InStock/OutOfStock, url}. Include aggregateRating ONLY when rating_count > 0.
- BreadcrumbList (PDP + category): Home → {category} → {title}.
Do not duplicate the homepage Store schema.

Verify:
- `cd Frontend && pnpm build` succeeds.
- The JSON validates structurally (well-formed, correct @type); note that Google Rich Results Test needs a live URL and depends on SSR (STEP 42) to be crawlable.

Output checklist:
- Product JSON-LD on PDP ✅/❌
- BreadcrumbList on PDP + category ✅/❌
- aggregateRating gated on count>0 ✅/❌
- build clean ✅/❌
```

## STEP 23 — Per-PDP Open Graph / Twitter tags (can parallel with #22)
**From:** ADD-10
**Effort:** Small (0.5 d, with STEP 21)
**Depends on:** STEP 21
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/index.html (static homepage OG/Twitter tags)
- The per-route Helmet added in STEP 21.

Task: Add per-PDP og:title, og:description, og:image (the product image URL), og:url, og:type=product, and twitter:card=summary_large_image + twitter:title/description/image via the PDP Helmet. Do the same for listing/category with category imagery where sensible.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Note explicitly in output: JS-injected OG tags are NOT read by most social scrapers — full effect requires SSR (STEP 42).

Output checklist:
- per-PDP OG tags ✅/❌
- twitter card tags ✅/❌
- SSR dependency noted ✅/❌
- build clean ✅/❌
```

## STEP 24 — Consolidate duplicate product URLs with canonicals
**From:** ADD-11
**Effort:** Medium (0.5 d)
**Depends on:** STEP 21
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx routes: /products/:id, /product/:slug, /offer/:slug
- backend SitemapController.php:89 emits /product/{title}

Task: Choose ONE canonical product URL pattern (recommend /product/{seo_slug}). Ensure:
- Every PDP emits a self-canonical to that chosen URL (extends STEP 21).
- The other patterns 301-redirect (frontend redirect + note any host/SSR-level redirect needed) to the canonical.
- The sitemap emits the same canonical pattern (align SitemapController).
Report the decision.

Verify:
- `cd Frontend && pnpm build` succeeds; `php -l` on the sitemap controller passes.
- One product resolves to one canonical URL; others redirect.

Output checklist:
- canonical pattern chosen ✅
- self-canonical aligned ✅/❌
- alternates redirect ✅/❌
- sitemap aligned ✅/❌
```

## STEP 25 — Fix soft-404 (can parallel with #24)
**From:** ADD-12
**Effort:** Small (0.5 d interim; full via SSR)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx (the "*" route → NotFound renders with HTTP 200)

Task (interim, SPA): Add a <meta name="robots" content="noindex"> via Helmet on the NotFound route so crawlers don't index unknown paths as valid pages. Document that a real HTTP 404 requires host-level config or SSR (STEP 42).

Verify:
- `cd Frontend && pnpm build` succeeds; NotFound emits noindex.

Output checklist:
- noindex on NotFound ✅/❌
- real-404 dependency (SSR/host) documented ✅/❌
- build clean ✅/❌
```

---

# BATCH 7 — Performance (render optimization)

## STEP 26 — Split the god-context; move server data to TanStack Query; retire bulk fetch
**From:** P3-1 / ADD-2 / ADD-8
**Effort:** Large (~3 d)
**Unblocks:** STEP 40 (error/retry UI); fewer re-renders; less initial payload
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Context/MyProvider.jsx (~40 values in one memo; server + UI state mixed)
- Frontend/src/Context/FetchTablesAndProducts.jsx (downloads the full catalog + lookup tables on mount)
- Frontend/src/main.jsx:13 (QueryClient already configured)
- Existing stores: authStore, cartStore, uiStore, toastStore

Task:
- Move SERVER data (products, offers, tables/lookups, banners, ratings, shipping) into TanStack Query hooks, fetched per-route (listing → /products?page=…) instead of one bulk mount fetch.
- Keep UI/language/filter state in the Zustand UI store; alerts in toastStore; cart/wishlist in their stores; user in authStore.
- Add memo/useCallback where a provider value still spans many consumers so a toast/resize no longer re-renders product lists.
Do this incrementally and keep the app working after each move.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Reason/verify with React DevTools Profiler notes: firing a toast or resizing no longer re-renders product lists; homepage no longer bulk-downloads the whole catalog.

Output checklist:
- server data in TanStack Query ✅/❌
- bulk mount fetch retired ✅/❌
- context split (UI/cart/auth/toast) ✅/❌
- re-render reduction verified ✅/❌
- build clean ✅/❌
```

## STEP 27 — Intrinsic dimensions + lazy on non-grid images
**From:** P4-1 / ADD-13
**Effort:** Small (0.5 d)
**Unblocks:** CLS reduction
### Prompt:
```
Execute this task ONLY.

Read first (ProductCard.jsx:109-115 is already correct — 300x300 + lazy; do NOT change it). These are missing dimensions/lazy:
- Frontend/src/Pages/Checkout/Checkout.jsx:69
- Frontend/src/Pages/Cart/CartModal.jsx:94, :114
- Frontend/src/Pages/Account/Account.jsx:118, :475, :871
- Frontend/src/Components/Product/ProductDetail.jsx:781 (gallery)
- Frontend/src/Components/Header/Header.jsx:364 (logo)
- Frontend/src/Components/Hero/WatchCanvas.jsx:113 (logo)
- Home banner <img> tags

Task: Add explicit width/height (or an aspect-ratio-sized container) and loading="lazy" to each — EXCEPT the hero LCP image, which stays eager+fetchpriority=high (STEP 8/28).

Verify:
- `cd Frontend && pnpm build` succeeds.
- No layout shift from these images (reason from fixed dimensions).

Output checklist:
- dimensions added to listed imgs ✅/❌
- lazy added (non-LCP) ✅/❌
- build clean ✅/❌
```

## STEP 28 — LCP poster + CLS space reservations
**From:** ADD-18 / ADD-19
**Effort:** Medium (1 d)
**Unblocks:** core LCP + CLS metrics
**Depends on:** STEP 8, 27
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Components/Hero/WatchCanvas.jsx + HomeSlider.jsx (hero; three.js 3D is expensive as an LCP candidate)
- Frontend/src/Components/Home/FeaturedBanner.jsx and grade/slider sections (mount after data → push content down)

Task:
- LCP: render a lightweight, preloaded static poster image (AVIF/WebP) as the hero's first paint; hydrate/mount the 3D WatchCanvas AFTER first paint (e.g. defer/on-idle). Keep fetchpriority=high + preload on the poster.
- CLS: give every async-mounted section a fixed min-height or same-size skeleton so content doesn't jump; ensure the heading font is preloaded (STEP 9) to cut swap shift.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Reason: LCP element is now the poster (cheap), not the 3D canvas; async sections reserve space.

Output checklist:
- hero poster LCP + deferred 3D ✅/❌
- async sections reserve space ✅/❌
- build clean ✅/❌
```

## STEP 29 — Wire the compression plugin (can parallel with #26–28)
**From:** NEW-7
**Effort:** Small (15 min)
**Unblocks:** brotli/gzip precompressed assets
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/vite.config.js (plugins array — vite-plugin-compression is a dependency but NOT in plugins)
- Frontend/package.json (confirms vite-plugin-compression present)

Task: Add vite-plugin-compression to the vite.config.js plugins (brotli + gzip). Do not change other build options.

Verify:
- `cd Frontend && pnpm build` emits .br/.gz alongside JS/CSS assets.

Output checklist:
- plugin wired ✅/❌
- .br/.gz emitted ✅/❌
- build clean ✅/❌
```

---

# BATCH 8 — Accessibility

## STEP 30 — Skip link + focus management + modal focus trap
**From:** ADD-16
**Effort:** Medium (0.5 d)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx (route outlet), Frontend/src/Components/ScrollToTop/ScrollToTop.jsx
- Frontend/src/Pages/Cart/CartModal.jsx, Frontend/src/Components/LanguageDropdown/LanguageDialog.jsx (overlays)

Task:
- Add a "skip to main content" link as the first focusable element, targeting the main content landmark (add id/landmark if missing).
- On route change, move focus to the main heading/region.
- Trap focus within CartModal and LanguageDialog while open and restore focus to the trigger on close.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Keyboard-only walkthrough (reason): Tab reaches skip link first; modals trap + restore focus; route change moves focus.

Output checklist:
- skip link ✅/❌
- route-change focus ✅/❌
- modal focus trap/restore ✅/❌
- build clean ✅/❌
```

## STEP 31 — Contrast audit + carousel keyboard access (can parallel with #30)
**From:** ADD-17
**Effort:** Medium (0.5 d)
**Depends on:** STEP 37 tokens help but not required
### Prompt:
```
Execute this task ONLY.

Read first:
- CSS using gold #C8A45C and low-opacity grays (rgba(0,0,0,0.4)–0.55) on light backgrounds
- Frontend/src/Components/UI/Carousel.jsx (Embla wrapper)

Task:
- Audit text/background pairs for WCAG AA (4.5:1 normal, 3:1 large); darken failing tokens (adjust the gold/gray usages that fail; note exact selectors changed).
- Add keyboard support to the Embla carousel: arrow-key navigation, focusable slides/controls, and aria-roledescription="carousel" with appropriate labels.

Verify:
- `cd Frontend && pnpm build` succeeds.
- List the contrast fixes and confirm carousel is keyboard-operable.

Output checklist:
- failing contrasts fixed (list) ✅/❌
- carousel keyboard nav ✅/❌
- aria added ✅/❌
- build clean ✅/❌
```

---

# BATCH 9 — Advanced (larger investments)

## STEP 32 — Image transform CDN + responsive srcset; compress .glb
**From:** P4-5 / ADD-14
**Effort:** Medium (1.5 d)
**Unblocks:** next-gen formats, properly-sized images (LCP)
### Prompt:
```
Execute this task ONLY.

Read first:
- The central image URL builder (Frontend/src/utils/imageUrl.js — getImageUrl) and where catalog images resolve (dash.watchizereg.com/Uploads_Images)
- backend ProductResource / ProductListResource (image fields)

Task:
- Front the image origin with a resizing/format CDN (Cloudflare Images / imgproxy / Bunny) — centralize URL construction in getImageUrl to emit width variants + format=auto.
- Emit srcset + sizes on catalog <img> (cards, PDP gallery, hero poster) from the resource-provided variants.
- Compress the 3D model Frontend/public/Watchizer_Gold.glb (Draco/meshopt) and load the compressed version.
If CDN infra isn't available yet, implement the URL-builder + srcset plumbing behind a config flag and document the pending CDN setup.

Verify:
- `cd Frontend && pnpm build` succeeds; `php -l` on touched resources passes.
- Catalog images request device-appropriate widths in AVIF/WebP (reason/inspect).

Output checklist:
- CDN URL builder ✅/❌
- srcset/sizes on catalog imgs ✅/❌
- .glb compressed ✅/❌
- pending-infra flagged (if any) ✅
```

## STEP 33 — three.js optimization (can parallel with #32)
**From:** ADD-5
**Effort:** Medium (1 d)
**Depends on:** STEP 28 (hero deferral)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Components/Hero/WatchCanvas.jsx and the react-three imports
- vite.config.js manualChunks (three / react-three)

Task: Confirm WatchCanvas is lazy and mounts below/after the LCP (from STEP 28). Reduce three.js cost: import only the three modules actually used (tree-shake), enable Draco/meshopt decoding for the .glb (pairs with STEP 32), and keep three/react-three in their own async chunks. Do not change the visual result.

Verify:
- `cd Frontend && pnpm build`; record three/react-three chunk sizes before → after.

Output checklist:
- WatchCanvas confirmed lazy/deferred ✅/❌
- three imports tree-shaken ✅/❌
- Draco/meshopt enabled ✅/❌
- chunk size before → after ✅/❌
```

## STEP 34 — Reduce main-thread work for INP (can parallel with #32, #33)
**From:** ADD-20
**Effort:** Medium (0.5 d)
**Depends on:** STEP 16, 26, 28
### Prompt:
```
Execute this task ONLY.

Read first:
- The results of STEPs 16 (clean entry), 26 (context split), 28 (deferred 3D)

Task: Reduce interaction latency (INP): defer any remaining non-critical JS to idle, break up long tasks (chunk heavy loops/derivations, memoize expensive computations), and ensure event handlers aren't doing layout-thrashing work. Do not change features.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Reason/trace: no long tasks block interaction on the home/listing routes; describe what you deferred.

Output checklist:
- non-critical JS deferred ✅/❌
- long tasks split/memoized ✅/❌
- build clean ✅/❌
```

## STEP 35 — Retire the 11-table lookup fetch
**From:** P1-4
**Effort:** Medium (1.5 d)
**Depends on:** STEP 26 (TanStack in place)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Context/FetchTablesAndProducts.jsx (legacy per-table calls + transformProductData)
- backend CatalogMetaController (catalog/meta) — currently omits 7 tables the transform needs (categoryTypes, materials, shapes, sizeTypes, displayTypes, closureTypes, movementTypes) and uses a flat shape.

Task: Expand catalog/meta to carry those 7 tables in the raw shape transformProductData expects (or adapt the transform to the flat shape), then remove the legacy per-table fetches, consuming catalog/meta via TanStack Query.

Verify:
- `php -l` on the controller; `cd Frontend && pnpm build` succeeds.
- Homepage/nav render from a single meta call; no `.find(locale==='en')` transforms left orphaned.

Output checklist:
- catalog/meta carries all needed tables ✅/❌
- legacy fetches removed ✅/❌
- nav/filters render correctly ✅/❌
- lint/build clean ✅/❌
```

---

# BATCH 10 — Polish + features

## STEP 36 — Trust signals on PDP + cart + checkout
**From:** P5-4
**Effort:** Small (0.5 d)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Components/Merchandising/TrustSignals.jsx (exists)
- Frontend/src/Components/Product/ProductDetail.jsx, Pages/Cart/CartModal.jsx, Pages/Checkout/Checkout.jsx

Task: Mount TrustSignals on the PDP sidebar, the cart drawer (CartModal), and the checkout header with the appropriate variant. Wire live review count/stars and low-stock/"sold X" urgency from the product data where available.

Verify:
- `cd Frontend && pnpm build` succeeds; badges render on all three surfaces (mobile + desktop).

Output checklist:
- PDP ✅/❌
- CartModal ✅/❌
- Checkout ✅/❌
- live counts wired ✅/❌
- build clean ✅/❌
```

## STEP 37 — Central design tokens + display typography (can parallel with #36)
**From:** P5-6 / P5-7
**Effort:** Medium (1.5 d)
### Prompt:
```
Execute this task ONLY.

Read first:
- Existing wz- CSS variables (App.css motion/gold tokens) and hardcoded literals (e.g. #262626, #C8A45C) scattered in components

Task:
- Create a single source of design tokens (Frontend/src/theme/tokens.js and/or CSS custom properties): palette (ink #111, charcoal #262626, gold #C8A45C, bone, line, success, danger), spacing scale (4·8·12·16·24·32·48·64), type scale.
- Introduce a display/serif heading face paired with the body font (via the STEP 9 font setup); apply a consistent type scale to headings site-wide.
- Replace hardcoded color/spacing literals with token references in the main components.

Verify:
- `cd Frontend && pnpm build` succeeds; changing a token visibly cascades; headings use the display face.

Output checklist:
- tokens centralized ✅/❌
- display font applied ✅/❌
- hardcoded literals replaced (scope) ✅/❌
- build clean ✅/❌
```

## STEP 38 — Analytics event coverage + defer TikTok pixel (can parallel with #36, #37)
**From:** NEW-8
**Effort:** Medium (0.5 d)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/index.html (FB pixel deferred to DOMContentLoaded; TikTok pixel loads eagerly; both fire PageView only)
- Frontend/src/scripts/useFacebookPixel.js
- add-to-cart / PDP / order-confirmation flows

Task:
- Defer the TikTok pixel load like the FB one.
- Fire ViewContent (PDP), AddToCart (add-to-cart action), and Purchase (order confirmation) for both FB and TikTok. De-duplicate any double FB init.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Reason through which events fire on which action.

Output checklist:
- TikTok deferred ✅/❌
- ViewContent/AddToCart/Purchase wired ✅/❌
- no double init ✅/❌
- build clean ✅/❌
```

## STEP 39 — Mount or remove MegaMenu (can parallel with #36–38)
**From:** NEW-10
**Effort:** Small (2 h)
**Depends on:** STEP 35 (catalog/meta data) if mounting
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Components/Header/MegaMenu.jsx (exists but Header.jsx never imports it)
- Frontend/src/Components/Header/Header.jsx (current nav)

Task: DECIDE — mount MegaMenu in the header (hover + keyboard focus, fed by catalog/meta, mobile accordion fallback) OR delete the dead component. If mounting, ensure no `.find(locale==='en')` crash and mobile fallback works. Report the decision.

Verify:
- `cd Frontend && pnpm build` succeeds.

Output checklist:
- decision (mount/delete) ✅
- implemented ✅/❌
- build clean ✅/❌
```

## STEP 40 — Per-section error/retry UI
**From:** ADD-22
**Effort:** Small–Medium (0.5 d)
**Depends on:** STEP 26 (TanStack isError/refetch)
### Prompt:
```
Execute this task ONLY.

Read first:
- The TanStack Query hooks introduced in STEP 26 (expose isError/refetch)
- Home/Listing/PDP data sections

Task: Give each data-driven section an error state with a "retry" affordance (using TanStack isError/refetch) instead of rendering blank on API failure. Keep the existing render-level ErrorBoundary for thrown renders.

Verify:
- `cd Frontend && pnpm build` succeeds.
- Reason: a failed fetch shows an inline error + retry, not a blank gap.

Output checklist:
- section error states ✅/❌
- retry wired ✅/❌
- build clean ✅/❌
```

## STEP 41 — Back-to-top + async action loading states (can parallel with #40)
**From:** ADD-23
**Effort:** Small (0.5 d)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/Components/ScrollToTop/ScrollToTop.jsx (route scroll only)
- add-to-cart / wishlist toggle handlers

Task:
- Add a scroll-triggered "back to top" button on long listing/PDP pages (appears after N px, smooth scroll, accessible label).
- Ensure add-to-cart and wishlist actions show an inline pending/disabled state during their request.

Verify:
- `cd Frontend && pnpm build` succeeds.

Output checklist:
- back-to-top button ✅/❌
- action loading states ✅/❌
- build clean ✅/❌
```

---

# BATCH 11 — Biggest investment (SSR / hreflang / PWA)

## STEP 42 — SSR / prerender for PDP + listing
**From:** P2-1
**Effort:** Large (1.5–2 weeks, incremental)
**Unblocks:** STEP 43; real crawlable meta/JSON-LD/OG; real 404
**Depends on:** STEP 21, 22, 23, 24 (meta/JSON-LD ready to server-render)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/src/App.jsx routes; the per-route Helmet (STEP 21) + JSON-LD (STEP 22) + OG (STEP 23)
- backend endpoints /products, /products/{id}, /catalog/meta

Task: DECIDE the rendering target and implement incrementally, starting with PDP + listing/category/brand:
- Option A: migrate to Next.js (App Router) with ISR for the mostly-static catalog and generateMetadata per segment (also unlocks next/image for STEP 32 and real 404 for STEP 25/ADD-12).
- Option B: add a Vite prerender/dynamic-rendering layer for those routes.
Keep account/cart/checkout client-rendered. Server-render PDP + listing HTML with title/price/description/JSON-LD in the initial response.
Report the decision and migrate route-by-route, verifying each.

Verify:
- `curl` a PDP and a listing URL: product title/price/description appear in the initial HTML (not an empty #root).
- Unknown paths return a real 404.

Output checklist:
- rendering target chosen (A/B) + rationale ✅
- PDP SSR ✅/❌
- listing/category/brand SSR ✅/❌
- initial HTML contains content (curl proof) ✅/❌
- real 404 ✅/❌
```

## STEP 43 — hreflang for AR/EN
**From:** P2-6
**Effort:** Medium (2 d)
**Depends on:** STEP 42
### Prompt:
```
Execute this task ONLY.

Read first:
- The SSR routing from STEP 42; current language toggle (uiStore)

Task: Add localized URL routing (/en/…, /ar/…) and emit per-page <link rel="alternate" hreflang="en" …>, hreflang="ar", and x-default. Ensure both language URLs are crawlable and cross-reference each other.

Verify:
- An EN PDP carries hreflang=ar to its Arabic URL and vice-versa; both render server-side.

Output checklist:
- localized routes ✅/❌
- hreflang alternates + x-default ✅/❌
- both crawlable ✅/❌
```

## STEP 44 — PWA: manifest + service worker (can parallel with #43)
**From:** ADD-21
**Effort:** Medium (1 d)
**Depends on:** STEP 7 (manifest/icons exist)
### Prompt:
```
Execute this task ONLY.

Read first:
- Frontend/public/manifest.json (from STEP 7) + icons
- Frontend build (vite-plugin-pwa/Workbox not yet present)

Task: Add a service worker (Workbox / vite-plugin-pwa) providing an offline app shell and runtime caching for catalog images + cacheable API GETs (respecting the STEP 20 cache headers). Ensure installability (valid manifest + icons + SW). Do not cache auth/cart/checkout responses.

Verify:
- `cd Frontend && pnpm build` emits a service worker; the app is installable; offline shows the cached shell.

Output checklist:
- service worker registered ✅/❌
- runtime caching (images/API) ✅/❌
- private endpoints not cached ✅/❌
- installable ✅/❌
- build clean ✅/❌
```

---

## COVERAGE MAP (every remaining roadmap reference → step)

| Ref | Step | | Ref | Step |
|---|---|---|---|---|
| NEW-5 | 1 | | ADD-1 | 16 |
| NEW-1 | 2 | | ADD-2 | 26 |
| NEW-2 | 3 | | ADD-3 | 13 |
| NEW-3 / P1-9 | 17 | | ADD-4 | 4 |
| NEW-4 | 7 | | ADD-5 | 33 |
| NEW-6 / P1-8 | 18 | | ADD-6 | 20 |
| NEW-7 | 29 | | ADD-7 | 5 |
| NEW-8 | 38 | | ADD-8 | 26 |
| NEW-9 | 19 | | ADD-9 | 11 |
| NEW-10 | 39 | | ADD-10 | 23 |
| NEW-11 | 6 | | ADD-11 | 24 |
| P1-4 | 35 | | ADD-12 | 25 |
| P2-1 | 42 | | ADD-13 | 27 |
| P2-2 | 21 | | ADD-14 | 32 |
| P2-3 | 22 | | ADD-15 | 10 |
| P2-6 | 43 | | ADD-16 | 30 |
| P3-1 | 26 | | ADD-17 | 31 |
| P3-4 | 12 | | ADD-18 | 28 |
| P3-5 | 14 | | ADD-19 | 28 |
| P4-1 | 27 | | ADD-20 | 34 |
| P4-2 | 8 | | ADD-21 | 44 |
| P4-3 | 9 | | ADD-22 | 40 |
| P4-5 | 32 | | ADD-23 | 41 |
| P5-4 | 36 | | | |
| P5-5 | 15 | | | |
| P5-6 | 37 | | | |
| P5-7 | 37 | | | |

All 53 remaining references are assigned. Tightly-coupled refs share a step: 17 (NEW-3/P1-9), 18 (P1-8/NEW-6), 26 (P3-1/ADD-2/ADD-8), 27 (P4-1/ADD-13), 28 (ADD-18/ADD-19), 37 (P5-6/P5-7).
