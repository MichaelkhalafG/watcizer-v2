# V3 — Fix Prompts (Deploy-Ready)
Generated from: VERSION_COMPARISON_REPORT.md

Each fix below has a **self-contained, copy-paste prompt** for a fresh Claude Code session.
The prompts do **not** require reading the report — every fact needed is inline.

Path facts verified during QA (so prompts are precise):
- Home render tree: `Frontend-next/app/(main)/page.jsx` → `Frontend-next/app/(main)/HomeClient.jsx`
- Product card: `Frontend-next/src/Components/Product/ProductCard.jsx` (navigates via `router.push`, not `<a href>`)
- Product detail gallery: `Frontend-next/src/Components/Product/ProductDetailClient.jsx` + `Frontend-next/src/Components/UI/ImageZoom.jsx`; URL builder `Frontend-next/src/utils/imageUrl.js`
- Cart store: `Frontend-next/src/Store/cartStore.js`; header badge in `Frontend-next/src/Components/Header/Header.jsx`
- Language store: `Frontend-next/src/Store/uiStore.js`; dir sync in `Frontend-next/src/app/providers.jsx` (`HtmlDirSync`) + `Frontend-next/app/layout.jsx`
- Listing/facet SEO: `Frontend-next/src/lib/listingSeo.js`; blog page: `Frontend-next/app/(main)/blog/[name]/page.jsx`
- **Served** sitemap/robots are STATIC: `Frontend-next/public/sitemap.xml`, `Frontend-next/public/robots.txt`
- Laravel sitemap generator (already emits canonical slugs): `backend/app/Http/Controllers/SitemapController.php`
- Next config (rewrites/redirects/eslint): `Frontend-next/next.config.js`
- V2 (rollback SPA) build config: `Frontend/vite.config.js`

---

## CRITICAL FIXES (must do before launch)

### FIX C1 — Home page renders all 2137 cards (TBT 12.9 s)
**Problem:** The home page server-renders AND hydrates the ENTIRE catalog (~2137 `ProductCard`s → 39,566 DOM nodes).
**Impact:** Lighthouse Perf 44, Total Blocking Time **12,930 ms**, Time-to-Interactive **16.3 s**. The page is frozen for ~16 s even though LCP/CLS are fine.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only. Do not touch the backend.

Goal: The home page currently renders every product in the catalog (~2137 cards),
causing ~13s of Total Blocking Time. Cap each home product section to a small
visible slice (target: 24–48 cards TOTAL across the page) WITHOUT losing SSR
(products must still appear in the HTML source for SEO).

Read first:
- Frontend-next/app/(main)/page.jsx  (thin server wrapper; do not change SSR/ISR)
- Frontend-next/app/(main)/HomeClient.jsx  (THIS renders the product sections)
- Frontend-next/src/Components/Product/ProductCard.jsx  (memoized card)
- Any home sub-components HomeClient imports that map over products
  (e.g. rails/sliders like "ICONIC LUXURY", "featured", "new arrivals").

Do this:
1. In HomeClient.jsx (and any product-rail sub-component), find every place that
   does products.map(...) / renders a full array of ProductCard. Each section must
   render at most a small slice via .slice(0, N) — use N = 12 for a horizontal
   rail, N = 24 for a "grid" section. Do NOT render the whole catalog anywhere.
2. If a section is a horizontal carousel (Embla), rendering ~12 is plenty; the
   rest are not visible anyway.
3. Keep the data source as-is (the (main) layout still prefetches the full catalog
   into React Query for filtering elsewhere) — you are only limiting how many are
   RENDERED on the home page, not what is fetched.
4. Do NOT add "use client" anywhere new, do NOT change page.jsx's
   `export const revalidate = 300`, do NOT remove the Store JSON-LD.

Verify:
- rm -rf .next && NODE_OPTIONS=--max-old-space-size=4096 pnpm build   (must exit 0)
- pnpm start, then:
  curl -s http://127.0.0.1:3000/ | grep -o 'wz-pc__title' | wc -l
    → expect ~24–48 (NOT ~2137). Products still present in HTML = SSR intact.
  curl -s http://127.0.0.1:3000/ | grep -c 'application/ld+json'  → still ≥ 1 (Store JSON-LD kept)
- (Optional) npx lighthouse http://127.0.0.1:3000/ --preset=desktop --only-categories=performance
    → Total Blocking Time should drop from ~12,900 ms to under ~1,000 ms.

Rollback: git checkout -- Frontend-next/app/(main)/HomeClient.jsx (and any sub-file changed).

Checklist:
[ ] Every home products.map is capped with .slice()
[ ] Home HTML still contains product cards (24–48) for SEO
[ ] Store JSON-LD still present
[ ] Build exits 0
[ ] TBT measured lower
```

---

### FIX C2 — Sitemap serves malformed raw-title product URLs
**Problem:** The **served** `Frontend-next/public/sitemap.xml` is a STALE static snapshot: 475 product `<loc>`s are raw product titles WITH SPACES (e.g. `/product/Original Maserati Watch For Men  With…R8873638005`), only 2 `/category/` URLs, 0 `/brand/`. Every product URL is invalid (spaces) and 308-redirects to its canonical slug. Meanwhile the Laravel `SitemapController.php` is ALREADY CORRECT (it slugifies titles and includes brands/categories/blogs) — but it is never served, because the static public file shadows it and `/sitemap.xml` is not proxied to Laravel.
**Impact:** Google crawls 475 redirect hops on malformed URLs; brand/facet pages absent from discovery.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ primarily (one optional backend regen step).

Context (already verified):
- The URL that Google will fetch is https://<domain>/sitemap.xml, served by Next
  from the STATIC file Frontend-next/public/sitemap.xml. That file is STALE: product
  <loc>s are raw titles with spaces (invalid, they 308-redirect), only 2 categories,
  no brands.
- backend/app/Http/Controllers/SitemapController.php ALREADY generates the CORRECT
  sitemap: canonical /product/{slugify(en_title)}, plus /brand/*, /category/*,
  /blogs, /blog/*, with <image:image> tags. It just isn't reachable through Next.

Pick ONE approach (Approach A preferred — always fresh, no stale file):

APPROACH A — Proxy /sitemap.xml (and /robots.txt) to Laravel, delete the static files:
1. Read Frontend-next/next.config.js. In async rewrites(), ADD (before the /api rule):
     { source: '/sitemap.xml', destination: process.env.LARAVEL_ORIGIN + '/sitemap.xml' },
2. Confirm backend routes/web.php has:
     Route::get('/sitemap.xml', [App\Http\Controllers\SitemapController::class, 'index']);
   If missing, add it (routes/web.php only — this is the one allowed backend edit).
3. DELETE the stale static Frontend-next/public/sitemap.xml so it stops shadowing the proxy.
4. Leave robots.txt as a static public file BUT sync its content (see FIX N6 for /blog/).

APPROACH B — Regenerate the static file from the fixed controller (no proxy):
1. Ensure the Laravel server is running against the same DB.
2. curl -s "http://127.0.0.1:8000/sitemap.xml" > Frontend-next/public/sitemap.xml
   (if the route exists) — this overwrites the stale file with the slug-correct output.
3. Verify it now contains /product/{slug} (no spaces), /brand/*, and more /category/*.

Verify (either approach), with Next running (pnpm build && pnpm start):
- curl -s "http://127.0.0.1:3000/sitemap.xml" | grep -o '<loc>[^<]*</loc>' | grep ' '  → MUST be empty (no spaces in any URL)
- curl -s "http://127.0.0.1:3000/sitemap.xml" | grep -c '/product/'  → ~475
- curl -s "http://127.0.0.1:3000/sitemap.xml" | grep -c '/brand/'    → > 0
- Pick one product loc and curl it: it should return HTTP 200 directly (NOT 308).

Rollback: git checkout -- Frontend-next/next.config.js Frontend-next/public/sitemap.xml
          (and backend/routes/web.php if edited).

Checklist:
[ ] No <loc> contains a space
[ ] Product URLs return 200 (not 308)
[ ] Brands + categories present
[ ] Build exits 0
```
> Note: making the product cards real `<a href>` links (so internal crawl works too) is a separate, related fix — see **FIX N5**.

---

### FIX C3 — Product detail gallery images 404
**Problem:** The product detail MAIN image (and thumbnails) point to `Uploads_Images/Product_image/<file>.webp`, which returns **404** — those gallery files do not exist on disk (only `Uploads_Images/Product/<file>.webp`, the catalog thumbnails, exist). So the detail page shows a broken image.
**Impact:** Every product detail page looks broken.

**Prompt:**
```
Execute this task ONLY.

Context (verified): product cards work because they use the catalog image folder
Uploads_Images/Product/*.webp (exists). The product DETAIL gallery uses
Uploads_Images/Product_image/*.webp, which returns 404 (files missing in seed data).

Step 1 — Confirm the gap (Backend, read-only):
  cd backend
  ls public/Uploads_Images/Product | head        # should list files
  ls public/Uploads_Images/Product_image | head  # likely empty / missing files
  (Also: the product_images table rows reference filenames that aren't on disk.)

Choose the correct fix:

OPTION A (data fix, Backend only) — if the real gallery files exist elsewhere,
copy/restore them into backend/public/Uploads_Images/Product_image/. If they are
genuinely gone, write a one-off seeder/command that, for each product with no valid
gallery image, points its product_images record at the product's main catalog image
(the working Product/<file>.webp). Do NOT invent images.

OPTION B (frontend graceful fallback, Frontend-next only) — RECOMMENDED, robust
regardless of data:
1. Read Frontend-next/src/Components/Product/ProductDetailClient.jsx and
   Frontend-next/src/Components/UI/ImageZoom.jsx and
   Frontend-next/src/utils/imageUrl.js (PLACEHOLDER_IMG lives here).
2. On the main gallery image and thumbnails, add an onError handler that falls back
   to (in order): the product's main catalog image (product.image via
   getImageUrl(product.image, 'Product')), then PLACEHOLDER_IMG. Ensure no infinite
   onError loop (null the handler after first fallback).
3. If the gallery array is empty or all entries 404, render the single catalog image.

Verify:
- Backend + Next running. curl -sI "http://127.0.0.1:8000/Uploads_Images/Product_image/<one-file>.webp"
  (Option A → 200). For Option B, load a product page in a browser and confirm the
  main image renders (fallback to catalog image), no broken-image icon.
- Frontend-next/: rm -rf .next && pnpm build  (exit 0).

Rollback: git checkout the touched files (frontend) / drop the seeder migration (backend).

Checklist:
[ ] Product detail main image renders (real or fallback)
[ ] No broken-image icon
[ ] No infinite onError loop
[ ] Build exits 0
```

---

### FIX C4 — Backend PHP memory_limit / heavy /all_product endpoint
**Problem:** `/api/all_product` returns **9.3 MB** (2150 products). At PHP's default `memory_limit=128M` it throws `Allowed memory size … exhausted` in `Illuminate\Cache\FileStore.php` and returns 500 → SSR renders an EMPTY site. Only works with `memory_limit ≥ ~512M`.
**Impact:** With default PHP settings the ENTIRE site renders empty (no products anywhere).

**Prompt:**
```
Execute this task ONLY. Backend only.

Context (verified): GET /api/all_product returns ~9.3MB for 2150 products and 500s
under PHP memory_limit=128M (fatal in Illuminate\Cache\FileStore.php). The Next SSR
layer depends on this endpoint; when it 500s the whole site renders empty.

Do BOTH of the following:

1) Raise the limit for production (ops):
   - Set memory_limit = 512M in the production php.ini (or the PHP-FPM pool config).
   - Document this in backend/README (or a DEPLOY.md): "PHP memory_limit must be >= 512M".
   - For php artisan serve / built-in server, document launching with:
     php -d memory_limit=512M ...

2) Reduce the payload / memory pressure (code, so 128M is no longer fatal):
   - Read the controller behind /api/all_product (search: routes/api.php for 'all_product',
     then the controller method). Also check app/Http/Resources/ProductListResource.php.
   - select() only the columns the frontend actually uses (id, slug, image, prices,
     stock, brand_id, translations) instead of SELECT *.
   - Eager-load only needed relations; avoid N+1.
   - If the response is cached via FileStore and the blob is what OOMs, either switch
     this cache entry to a lighter store or reduce the cached payload size.
   - STRONGLY CONSIDER adding optional pagination (?page=&per_page=) while keeping a
     full-catalog mode for SSR — but if you add pagination, coordinate with the
     Frontend-next server fetch (src/lib/serverCatalog.js) so SSR still gets all
     products. Do NOT break the existing full-catalog contract without updating the
     consumer.

Verify:
- Start the API with the DEFAULT limit to prove the fix:
  php -d memory_limit=128M -S 127.0.0.1:8000 -t public \
      "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"   (run from backend/public)
- curl -s -H "Api-Code: <key>" -o /dev/null -w "%{http_code} %{size_download}\n" \
      "http://127.0.0.1:8000/api/all_product"   → expect 200 (not 500), payload smaller than 9.3MB.
- Frontend-next: pnpm build && pnpm start, then
  curl -s http://127.0.0.1:3000/listing | grep -o 'wz-pc__title' | wc -l  → 24 (products present).

Rollback: revert the controller/resource change; production memory_limit change is config-only.

Checklist:
[ ] /api/all_product returns 200 under 128M (or payload materially reduced)
[ ] Prod memory_limit >= 512M documented
[ ] SSR listing/home show products
[ ] No behavior change for the SSR catalog consumer
```

---

## IMPORTANT FIXES (soon after launch)

### FIX I1 — Add error.jsx boundaries
**Problem:** No `error.jsx` at any route level; an uncaught render error falls back to Next's raw error page.
**Impact:** Ugly, off-brand error screen; no recovery.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Add branded error boundaries so uncaught render errors show a Watchizer-styled
fallback with a "Try again" button, not Next's default error page.

Read first:
- Frontend-next/app/layout.jsx and app/(main)/layout.jsx (design tokens, fonts, dir)
- Frontend-next/app/globals.css (--wz-* tokens: --wz-gold, --wz-ink, spacing, serif)
- An existing styled screen for tone, e.g. app/not-found.jsx.

Do this (error.jsx MUST be a Client Component — add 'use client' at top):
1. Create Frontend-next/app/error.jsx  — top-level boundary. Props: { error, reset }.
   Render a centered card: short apology, a "Try again" button calling reset(), and a
   link home. Use the --wz-* tokens. Also console.error(error) (keep this one).
2. Create Frontend-next/app/(main)/error.jsx — same, but rendered inside the chrome
   (header/footer stay). Keep it minimal.
3. (Optional) app/(main)/product/[slug]/error.jsx and app/(main)/listing/error.jsx
   with context-appropriate copy.
Do NOT add a global-error.jsx unless you also fully re-declare <html>/<body> (only
needed if the root layout itself throws).

Verify:
- rm -rf .next && pnpm build  (exit 0).
- Temporarily throw in a client component to confirm the boundary catches it, then revert.

Rollback: delete the new error.jsx files.

Checklist:
[ ] app/error.jsx + app/(main)/error.jsx created, 'use client', reset() wired
[ ] Uses --wz-* design tokens
[ ] Build exits 0
```

---

### FIX I2 — Loading skeletons for non-listing routes
**Problem:** Only `app/(main)/listing/loading.jsx` exists; other routes' Suspense fallbacks are `null`, so navigations show no feedback.
**Impact:** Perceived slowness on route transitions.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Add route-level loading.jsx skeletons matching the existing listing skeleton,
for the heavier routes.

Read first:
- Frontend-next/app/(main)/listing/loading.jsx  (copy its skeleton style/animation)
- Frontend-next/app/globals.css  (skeleton pulse classes / --wz-* tokens)
- The target pages to mirror their layout:
  app/(main)/page.jsx (home), product/[slug]/page.jsx, blogs/page.jsx,
  cart/page.jsx, checkout/page.jsx, account/page.jsx.

Do this — create loading.jsx (Server Component, no 'use client') for:
1. app/(main)/product/[slug]/loading.jsx — gallery block + text lines skeleton.
2. app/(main)/blogs/loading.jsx — card grid skeleton.
3. app/(main)/account/loading.jsx — form/tabs skeleton.
(Home/cart/checkout SSR their content quickly; add skeletons only if they show a gap.)
Reuse the SAME skeleton CSS classes the listing skeleton uses; don't invent new heavy CSS.

Verify:
- rm -rf .next && pnpm build  (exit 0).
- Navigate between routes (throttle network in devtools) and confirm skeletons flash.

Rollback: delete the added loading.jsx files.

Checklist:
[ ] loading.jsx added for product, blogs, account
[ ] Reuses existing skeleton styles
[ ] Build exits 0
```

---

### FIX I3 — Empty cart header shows "EGP 100"
**Problem:** The header cart total includes the DEFAULT governorate shipping (Cairo = EGP 100) even when the cart has 0 items, producing a confusing "0 ITEMS / EGP 100".
**Impact:** Confusing; looks like a phantom charge.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: When the cart has 0 items, the header total must read EGP 0 (do NOT add shipping
to an empty cart). Shipping should only be included once there is at least 1 item.

Read first:
- Frontend-next/src/Store/cartStore.js  (item list, totals, shipping selectors)
- Frontend-next/src/Components/Header/Header.jsx  (the badge that renders "N ITEMS / EGP X")
- Any shipping/governorate state (uiStore or MyProvider) feeding the total.

Do this:
1. Find where the header total is computed (subtotal + shipping). Guard it: if
   itemCount === 0 (or items.length === 0), the displayed total is 0 and no shipping
   is added. Keep the cart page + checkout behavior (there, shipping applies because
   items > 0).
2. Do not change the shipping value itself or the checkout math — only the empty-cart
   header display.

Verify:
- pnpm build && pnpm start. Clear cart (or fresh guest). Header must show "0 ITEMS / EGP 0".
- Add one item → header shows "1 ITEM / EGP (price + shipping)".
- Cart page total and checkout total unchanged for non-empty carts.

Rollback: git checkout -- the touched files.

Checklist:
[ ] Empty cart header = EGP 0
[ ] Non-empty cart total still includes shipping
[ ] Build exits 0
```

---

### FIX I4 — Language doesn't persist on hard reload
**Problem:** Locale lives only in a client store; SSR always renders `<html lang="en" dir="ltr">`, so a hard reload / direct link flashes English before `HtmlDirSync` flips it (and SSR HTML/SEO is always EN).
**Impact:** Arabic users get an English flash and EN-only server HTML.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Persist the chosen language to a cookie and read it server-side in the root
layout so the FIRST server render already has the correct lang/dir (no EN flash).

Read first:
- Frontend-next/src/Store/uiStore.js  (language state + setter)
- Frontend-next/src/app/providers.jsx  (HtmlDirSync — currently flips dir on mount)
- Frontend-next/app/layout.jsx  (<html lang dir> — currently hardcoded en/ltr)

Do this:
1. When language changes (uiStore setter), also write a cookie, e.g.
   document.cookie = `wz_lang=${lang}; path=/; max-age=31536000; samesite=lax`.
2. In app/layout.jsx (Server Component), read the cookie:
   import { cookies } from 'next/headers'
   const lang = (await cookies()).get('wz_lang')?.value === 'ar' ? 'ar' : 'en'
   const dir = lang === 'ar' ? 'rtl' : 'ltr'
   <html lang={lang} dir={dir}> ...
3. Seed the client store from the same cookie on init so HtmlDirSync doesn't fight the
   server value (avoid hydration mismatch — server and client must agree on lang/dir).
4. Keep HtmlDirSync for runtime toggles, but it should no longer cause a flash on load.

Verify:
- pnpm build && pnpm start. Switch to Arabic, then HARD reload (Ctrl+F5): page loads
  directly in RTL/Arabic, no English flash.
- View source: <html lang="ar" dir="rtl"> when the cookie is ar.
- Check console: NO hydration mismatch warnings.

Rollback: git checkout -- the touched files.

Checklist:
[ ] Cookie written on language change
[ ] layout.jsx reads cookie → correct lang/dir in SSR HTML
[ ] No hydration mismatch
[ ] Build exits 0
```

---

### FIX I5 — No og:image on listing/brand/category pages
**Problem:** Listing/brand/category `generateMetadata` sets title/description/canonical but no `og:image`; social shares of those pages have no preview image.
**Impact:** Poor link previews on social/WhatsApp for category & brand pages.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Add og:image to listing / brand / category / facet metadata (product pages
already have og:image; only the listing family is missing it).

Read first:
- Frontend-next/src/lib/listingSeo.js  (builds listing/facet metadata)
- Frontend-next/src/lib/detailSeo.js   (see how product og:image is built — mirror it)
- The facet page files under app/(main)/ (brand/[brand], category/[category],
  subtypes/[subtype], grade/[grade], listing, [suptype]/[brand]) — they call listingSeo.

Do this:
1. In listingSeo.js, add openGraph.images: use the FIRST matching product's image
   (getImageUrl(product.image, 'Product')) when products are available for that facet;
   otherwise a stable default (e.g. https://watchizereg.com/logo.svg or a branded OG
   banner in public/). Set width/height if known.
2. Keep absolute URLs (metadataBase is set in app/layout.jsx).

Verify:
- pnpm build && pnpm start.
- curl -s http://127.0.0.1:3000/brand/rolex   | grep -c 'og:image'  → ≥ 1
- curl -s http://127.0.0.1:3000/category/watches | grep -c 'og:image' → ≥ 1
- curl -s http://127.0.0.1:3000/listing        | grep -c 'og:image'  → ≥ 1

Rollback: git checkout -- Frontend-next/src/lib/listingSeo.js

Checklist:
[ ] og:image on listing/brand/category/facets
[ ] Absolute URL, falls back to default when no product
[ ] Build exits 0
```

---

### FIX I6 — Blog posts have no Article JSON-LD
**Problem:** Blog detail sets OG `type: article` but emits no Article structured data (product/offer/listing all have JSON-LD).
**Impact:** No rich results / weak SEO for blog content.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Emit Article (or BlogPosting) JSON-LD on blog detail pages.

Read first:
- Frontend-next/app/(main)/blog/[name]/page.jsx  (server page + generateMetadata)
- Frontend-next/app/(main)/product/[slug]/page.jsx  (copy the <script type="application/ld+json"> pattern)
- The blog data shape (title, excerpt/description, image, author, published/updated dates).

Do this:
1. In blog/[name]/page.jsx, build a JSON-LD object:
   { "@context":"https://schema.org", "@type":"BlogPosting", headline, description,
     image: <absolute>, datePublished, dateModified, author: {"@type":"Organization","name":"Watchizer"},
     publisher: {"@type":"Organization","name":"Watchizer","logo":{...}},
     mainEntityOfPage: <canonical url> }
2. Inject it exactly like the product page does (dangerouslySetInnerHTML, server-rendered).
   Handle missing fields gracefully (omit rather than emit empty strings).

Verify:
- pnpm build && pnpm start; open a real blog URL.
- curl -s "http://127.0.0.1:3000/blog/<name>" | grep -c 'application/ld+json' → ≥ 1
- curl -s "http://127.0.0.1:3000/blog/<name>" | grep -o '"@type":"BlogPosting"' → present
- Validate with Google Rich Results test after deploy.
NOTE: also see FIX N6 — robots.txt currently Disallows /blog/, so also un-block it if
blogs should be indexed.

Rollback: git checkout -- Frontend-next/app/(main)/blog/[name]/page.jsx

Checklist:
[ ] BlogPosting JSON-LD on blog detail
[ ] Absolute image/url, graceful missing fields
[ ] Build exits 0
```

---

### FIX I7 — Accessibility: color contrast + label/name mismatch
**Problem:** Lighthouse a11y flags `color-contrast` (likely gold/muted text on light) and `label-content-name-mismatch` on some control. Scores 93 (home) / 97 (product).
**Impact:** Below AAA and some AA text; screen-reader/name mismatch.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Push Lighthouse Accessibility to ~100 by fixing the two flagged issue classes.

Read first:
- Frontend-next/app/globals.css  (--wz-gold #C8A45C, --wz-muted, text colors)
- Run the audit to get exact offenders:
    npx lighthouse http://127.0.0.1:3000/ --preset=desktop --only-categories=accessibility \
      --output=json --output-path=lh-a11y.json --chrome-flags="--headless=new"
    Then read the "color-contrast" and "label-content-name-mismatch" audit "items"
    for the exact selectors/nodes.

Do this:
1. color-contrast: for each failing node, darken the foreground (or the token it uses)
   until it meets 4.5:1 for normal text / 3:1 for large text. Prefer fixing the shared
   token (e.g. a slightly darker --wz-muted) over per-element overrides. Keep the gold
   accent for large headings only (large text has a lower threshold).
2. label-content-name-mismatch: find the control whose visible text differs from its
   aria-label/accessible name; make aria-label START WITH (or equal) the visible text,
   or drop the redundant aria-label so the visible text becomes the name.

Verify:
- Re-run the lighthouse a11y command → color-contrast and label-content-name-mismatch pass;
  Accessibility ≈ 100.
- Visually confirm the darker text still matches the luxury design.
- pnpm build (exit 0).

Rollback: git checkout -- the touched CSS/JSX.

Checklist:
[ ] color-contrast passes (4.5:1 normal text)
[ ] label/name mismatch resolved
[ ] Design still on-brand
[ ] Build exits 0
```

---

### FIX I8 — V2 (Vite SPA) production build white-screens
**Problem:** `Frontend/` `pnpm build` succeeds but the served build throws `TypeError: Cannot read properties of undefined (reading 'jsx')` in the split `mui` chunk — `vite.config.js` `manualChunks` separates `react`/`mui` so MUI initializes without React's jsx-runtime. `#root` stays empty; nothing paints. This kills V2 as a rollback option.
**Impact:** The fallback SPA is unusable in production.

**Prompt:**
```
Execute this task ONLY. Frontend/ only (the Vite SPA — kept as rollback).

Context (verified): `pnpm build` in Frontend/ succeeds, but `pnpm exec vite preview`
white-screens with console error:
  TypeError: Cannot read properties of undefined (reading 'jsx')
  at assets/mui.<hash>.js
Root cause: vite.config.js manualChunks puts 'mui' in a separate chunk that loads
without react/jsx-runtime being available (chunk init order / React not in the same
chunk graph).

Read first:
- Frontend/vite.config.js  (build.rollupOptions.output.manualChunks — splits react,
  mui, three, react-three, utils, thirdParty)

Do ONE of these:
1. SIMPLEST: keep React and MUI/Emotion in the SAME chunk. In manualChunks, route
   'react', 'react-dom', 'react/jsx-runtime', '@emotion/*', and '@mui/*' all to the
   same chunk name (e.g. return 'react' for all of them), OR remove the separate 'mui'
   bucket so MUI stays with the vendor/react chunk. Ensure react/jsx-runtime is never
   isolated from its consumers.
2. Or convert manualChunks to a function that groups all of react-dom/react/jsx-runtime/
   scheduler/@emotion/@mui together, keeping three/react-three separate (those are fine).

Verify:
- cd Frontend && rm -rf dist && NODE_OPTIONS=--max-old-space-size=4096 pnpm build  (exit 0)
- pnpm exec vite preview --port 4173 --host 127.0.0.1
- curl -s http://127.0.0.1:4173/ ...   AND load it in a browser:
  the app must PAINT (home renders), and DevTools console must show NO "reading 'jsx'"
  TypeError. Check: document.getElementById('root').childElementCount > 0.

Rollback: git checkout -- Frontend/vite.config.js

Checklist:
[ ] manualChunks keeps React + jsx-runtime + MUI/Emotion together
[ ] vite build exits 0
[ ] vite preview renders (root not empty), no jsx TypeError
```

---

## NICE TO HAVE (future)

### FIX N1 — Re-enable ESLint at build
**Problem:** `Frontend-next/next.config.js` has `eslint: { ignoreDuringBuilds: true }`, masking ~30 errors + 46 warnings.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Fix the lint violations and remove the build-time ESLint bypass.

Read first:
- Frontend-next/next.config.js  (eslint.ignoreDuringBuilds)
- Run: cd Frontend-next && pnpm exec next lint   → capture ALL errors + warnings.

Do this:
1. Fix real issues: exhaustive-deps (add deps or justify with eslint-disable-next-line
   + reason), react-hooks/set-state-in-effect, no-img-element (either convert to
   next/image per FIX N2 or add a scoped eslint-disable for the INTENTIONAL raw <img>s:
   LCP poster, avatars, logos — with a comment explaining why).
2. Once `pnpm exec next lint` is clean (0 errors; warnings acceptable if <5 and
   justified), remove `eslint: { ignoreDuringBuilds: true }` from next.config.js.
3. If a handful of warnings are truly intentional, keep ignoreDuringBuilds only if you
   cannot get errors to 0 — but prefer removing it.

Verify:
- pnpm exec next lint  → 0 errors.
- rm -rf .next && pnpm build  (exit 0) WITHOUT the ignore flag.

Rollback: restore `eslint: { ignoreDuringBuilds: true }` in next.config.js.

Checklist:
[ ] next lint → 0 errors
[ ] ignoreDuringBuilds removed
[ ] Build exits 0
```

---

### FIX N2 — Complete the next/image migration
**Problem:** ~15 raw `<img>` remain (some intentional: LCP poster, avatars, logos; some not: BrandStrip).

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Convert the remaining APPROPRIATE raw <img> tags to next/image; leave the ones
that must stay raw and document why.

Read first:
- grep for raw imgs: (in Frontend-next) search `<img` across src/ and app/.
- Frontend-next/next.config.js images.remotePatterns (already covers dash.watchizereg.com,
  127.0.0.1:8000, localhost:8000). qualities: [70,75,80,90] — reuse only these values.
- Reference conversions already done: ProductCard.jsx, ProductDetailClient.jsx,
  CategoryTiles.jsx, Header.jsx logo.

Convert (catalog/content imagery): BrandStrip brand logos → next/image
  (width={90} height={36} quality={70} sizes="90px", getImageUrl(brand.image,'Brand')).
  Also any FeaturedBanner / SmartSuggestions product imagery that is stable-size.

DO NOT convert (leave as raw <img>, add a one-line comment): the WatchHero 3D poster
(LCP, uses fetchPriority=high), WatchCanvas fallback logo, user avatars, small SVG/PNG
logos where next/image adds no value. next/image needs correct sizes/fill+relative
parent — verify each converted image has a sized container (no CLS).

Verify:
- rm -rf .next && pnpm build (exit 0).
- Load home/product; confirm converted images request /_next/image?... and show no CLS.

Rollback: git checkout the touched components.

Checklist:
[ ] BrandStrip (and other stable imagery) on next/image
[ ] Intentional raw <img>s documented
[ ] No CLS regression; build exits 0
```

---

### FIX N3 — Remove the transitional MyProvider Context
**Problem:** `Frontend-next/src/Context/MyProvider.jsx` still exists alongside Zustand (transitional), holding some shipping state.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Move any remaining MyProvider state into Zustand and delete MyProvider.

Read first:
- Frontend-next/src/Context/MyProvider.jsx  (what state/values it still provides)
- Everywhere it's consumed: grep for useContext(MyContext) / useMy... hooks and the
  <MyProvider> mount in app/(main)/layout.jsx.
- Existing Zustand stores: src/Store/{uiStore,cartStore,authStore,toastStore,orderConfirmStore}.js

Do this:
1. Identify the state MyProvider still owns (e.g. shipping/governorate). Add it to the
   most appropriate Zustand store (likely uiStore or a new shippingStore), SSR-guarded
   like the others.
2. Replace each consumer's context read with the store selector.
3. Remove <MyProvider> from the layout and delete the file. Ensure the HydrationBoundary
   / prefetch still works (MyProvider currently sits below the boundary — make sure
   nothing depended on that ordering).

Verify:
- rm -rf .next && pnpm build (exit 0).
- Exercise cart/checkout shipping selection — behavior unchanged; no console errors.

Rollback: git checkout -- src/Context/MyProvider.jsx app/(main)/layout.jsx and consumers.

Checklist:
[ ] MyProvider state moved to Zustand
[ ] All consumers updated
[ ] MyProvider deleted; shipping still works
[ ] Build exits 0
```

---

### FIX N4 — Clean README + remove committed dev.log
**Problem:** `Frontend-next/README.md` is create-next-app boilerplate; a `dev.log` is present in the folder.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Do this:
1. Replace Frontend-next/README.md with a real project README: what the app is
   (Next.js 15 migration of Watchizer), required env vars (LARAVEL_ORIGIN,
   NEXT_PUBLIC_API_BASE, NEXT_PUBLIC_ASSET_BASE, NEXT_PUBLIC_PUBLIC_API_KEY,
   NEXT_PUBLIC_PAYMOB_ENABLED), how to run (pnpm install / pnpm dev / pnpm build /
   pnpm start), the Laravel backend + memory_limit note, and remotePatterns note.
2. Delete Frontend-next/dev.log and add `dev.log` (and `*.log`) to Frontend-next/.gitignore.

Verify:
- git status shows dev.log removed and README updated; `*.log` ignored.

Rollback: git checkout -- Frontend-next/README.md ; restore dev.log if needed.

Checklist:
[ ] README real
[ ] dev.log removed + gitignored
```

---

### FIX N5 — Product cards as `<a href>` links (crawlable + accessible)
**Problem:** `ProductCard` navigates via `router.push(productUrl(product))` on a `<div role="button">`, not an `<a href>` — not a crawlable internal link and weaker a11y.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only.

Goal: Wrap the product card in a real Next <Link href={productUrl(product)}> so it is a
crawlable internal link and keyboard/AT-navigable, WITHOUT breaking the add-to-cart /
wishlist buttons inside it.

Read first:
- Frontend-next/src/Components/Product/ProductCard.jsx  (currently <article role="button"
  onClick={goToProduct}> + inner "Add to Cart" button with e.stopPropagation()).
- Frontend-next/src/utils/productUrl.js  (productUrl()).

Do this:
1. Make the card's main clickable region an <Link href={productUrl(product)}> wrapping
   the media + title/price (replace the div onClick navigation). Keep styles.
2. The "Add to Cart" and wishlist controls must remain <button>s and must call
   e.preventDefault() + e.stopPropagation() so clicking them does NOT navigate.
3. Remove the now-redundant role="button"/tabIndex/onKeyDown on the outer element
   (the <a> is natively focusable). Keep aria-label if useful.
4. Preserve the memo() comparison.

Verify:
- pnpm build && pnpm start.
- curl -s http://127.0.0.1:3000/listing | grep -oE 'href="/product/[^"]+"' | head → product hrefs present.
- In browser: clicking the card navigates; clicking "Add to Cart" adds WITHOUT navigating.
- Keyboard: Tab to a card, Enter navigates.

Rollback: git checkout -- Frontend-next/src/Components/Product/ProductCard.jsx

Checklist:
[ ] Card is an <a href> (crawlable) 
[ ] Add-to-cart/wishlist don't navigate
[ ] Keyboard accessible; build exits 0
```

---

### FIX N6 — Blog visibility (robots.txt + sitemap + JSON-LD)
**Problem:** `Frontend-next/public/robots.txt` has `Disallow: /blog/`, AND blog posts lack Article JSON-LD (FIX I6), AND blog URLs aren't cleanly in the served sitemap — so blog content is invisible to search.

**Prompt:**
```
Execute this task ONLY. Frontend-next/ only (coordinate with FIX I6 + FIX C2).

Decide first: SHOULD blogs be indexed? If blogs are a content-marketing channel, YES.
If they're thin/placeholder, leave them blocked and skip this.

If blogs should be indexed:
Read first:
- Frontend-next/public/robots.txt  (currently: Disallow: /blog/ , Disallow: /*?* , sitemap ref)
- FIX I6 (Article JSON-LD) and FIX C2 (sitemap source).

Do this:
1. Remove the `Disallow: /blog/` line from public/robots.txt (keep Disallow: /cart,
   /checkout, profile/order/wishlist, search, /*?*). Keep the sitemap: line.
2. Ensure blog URLs are in the sitemap (the Laravel SitemapController already emits
   /blog/{en-title}; if you adopted FIX C2 Approach A the proxy handles it; if static,
   regenerate). Ideally slugify blog URLs the same way as products.
3. Apply FIX I6 so each blog post carries BlogPosting JSON-LD.

Verify:
- curl -s http://127.0.0.1:3000/robots.txt  → no "Disallow: /blog/"; sitemap: line present.
- curl -s http://127.0.0.1:3000/sitemap.xml | grep -c '/blog/'  → > 0
- A blog page returns HTTP 200 with BlogPosting JSON-LD.

Rollback: git checkout -- Frontend-next/public/robots.txt (and revert I6/C2 pieces).

Checklist:
[ ] /blog/ un-blocked (only if blogs should index)
[ ] Blog URLs in sitemap
[ ] BlogPosting JSON-LD present
```

---

## DEPLOYMENT CHECKLIST

### Pre-Deploy Steps
1. Set env vars (Frontend-next `.env` / host env):
   `LARAVEL_ORIGIN`, `NEXT_PUBLIC_API_BASE`, `NEXT_PUBLIC_ASSET_BASE`,
   `NEXT_PUBLIC_PUBLIC_API_KEY`, `NEXT_PUBLIC_PAYMOB_ENABLED` (and `NEXT_PUBLIC_IMAGE_CDN_BASE` if used).
2. **Set PHP `memory_limit ≥ 512M`** in prod php.ini / PHP-FPM (see FIX C4) — else the catalog endpoint 500s and the whole site renders empty.
3. Configure `Frontend-next/next.config.js` `images.remotePatterns` for the PROD asset host (`dash.watchizereg.com`) — keep it in sync with `NEXT_PUBLIC_ASSET_BASE`.
4. **Fix the served sitemap** (FIX C2) — canonical slug product URLs, brands/categories added, no spaces.
5. **Seed / verify product gallery images** (FIX C3) — `Uploads_Images/Product_image/*` exist OR the frontend falls back to the catalog image.
6. `cd Frontend-next && rm -rf .next && pnpm build` → **must exit 0**.
7. Test SSR content: `curl -s https://<domain>/ | grep -c 'wz-pc__title'` → products present (after FIX C1: ~24–48).
8. Test structured data: `curl -s https://<domain>/product/<slug> | grep -c 'application/ld+json'` → ≥ 1 (Product/Brand/BreadcrumbList).
9. Test real 404: `curl -s -o /dev/null -w "%{http_code}" https://<domain>/some-random-url` → **404**.

### Deploy Steps
1. Upload `Frontend-next/` to the server.
2. `pnpm install --frozen-lockfile`.
3. `pnpm build`.
4. Configure reverse proxy (nginx/Apache):
   - `/` → Next.js (port 3000)
   - `/api/*` → Laravel
   - `/Uploads_Images/*` → Laravel `public/`
   - `/sitemap.xml` → per FIX C2 (Laravel proxy or static)
5. `pnpm start` under a process manager (PM2 / systemd) with a persistent ISR cache dir.
6. Smoke-test every route family: home, listing, `/brand/*`, `/category/*`, product, cart, checkout, login, 404.
7. Point DNS.

### Post-Deploy
1. Submit `sitemap.xml` to Google Search Console; request indexing of key pages.
2. Verify OG tags with the Facebook Sharing Debugger + WhatsApp preview.
3. Monitor Lighthouse (target: Home Performance ≥ 85 after FIX C1; SEO 100).
4. Keep `Frontend/` (Vite SPA) for one release cycle as rollback — but only AFTER FIX I8 (its prod build currently white-screens).
5. Once V3 is stable: delete `Frontend/`, update CI/CD, remove the transitional `MyProvider` (FIX N3).

---

## Index

| ID | Priority | Scope | Title |
|----|----------|-------|-------|
| C1 | Critical | Frontend-next | Home renders 2137 cards (TBT 12.9s) |
| C2 | Critical | Frontend-next (+routes/web.php) | Sitemap malformed product URLs (stale static file) |
| C3 | Critical | Backend or Frontend-next | Product gallery images 404 |
| C4 | Critical | Backend | PHP memory_limit / heavy /all_product |
| I1 | Important | Frontend-next | error.jsx boundaries |
| I2 | Important | Frontend-next | loading.jsx skeletons |
| I3 | Important | Frontend-next | Empty cart shows EGP 100 |
| I4 | Important | Frontend-next | Language persist on reload |
| I5 | Important | Frontend-next | og:image on listing/brand/category |
| I6 | Important | Frontend-next | Blog Article JSON-LD |
| I7 | Important | Frontend-next | A11y contrast + label mismatch |
| I8 | Important | Frontend (Vite) | V2 prod build white-screens |
| N1 | Nice | Frontend-next | Re-enable ESLint at build |
| N2 | Nice | Frontend-next | Complete next/image migration |
| N3 | Nice | Frontend-next | Remove MyProvider Context |
| N4 | Nice | Frontend-next | Clean README + remove dev.log |
| N5 | Nice | Frontend-next | Product cards as `<a href>` |
| N6 | Nice | Frontend-next | Blog visibility (robots + sitemap + JSON-LD) |
