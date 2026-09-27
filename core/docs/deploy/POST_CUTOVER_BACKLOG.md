# After the cutover — everything deferred, in one place

**Written 2026-09-24, the night of the Phase 2 flip.** Nothing here is broken in a way a shopper
cannot get past tonight; every item is a known, named state. Pick batches from it.

**How batches cost.** A *storefront* item ships with a push to `main` → one Hostinger rebuild →
§7 step 0's bundle check. A *core* item ships as a tar deploy (runbook §2, minus the migrations) →
`config:cache`/`route:cache`. Items in the same lane share that cost, so batch within a lane.

---

## ▶ START HERE — session record, 2026-09-26 (evening)

### State of the code, exactly

- **Batch 1 is fully live.** Commits `23e2b5a` (core) and `3333a1f` (storefront) are on `main` and
  `origin/main` (and `wave-4d`). The core half is DEPLOYED — verified: production answers
  `GET https://api.watchizereg.com/api/v2/watchizer/payment-methods` with the live methods. The
  storefront half is built and live.
- **Written, tested, NOT committed, NOT deployed** (working tree of `wave-4d`, on top of `5704077`):
  items 1 and 3 below, plus two small fixes found on the way. Code is complete, not half-written.
  *(Corrected 2026-09-27: this line first said "full battery green (see Checks)" — no such run
  had happened and no Checks section existed. The real results are in "Checks, 2026-09-27" at the
  end of this record.)* Files:
  `core/app/Domain/Orders/{UnpaidOrders,OrderCustomer}.php`,
  `core/app/Console/Commands/OrdersExpireUnpaidCommand.php`, `core/routes/console.php`,
  `core/config/compat.php` (`compat.unpaid.*`), `core/app/Domain/Payment/PaymobProvider.php`,
  `core/app/Http/Controllers/Compat/CheckoutCompatController.php`,
  `core/app/Http/Controllers/Manage/OrderController.php`,
  `core/app/Domain/Notifications/{OrderEmailData,OrderMailer}.php`,
  `core/tests/Feature/Orders/{UnpaidOrdersTest,OrderCustomerTest}.php`,
  `core/tests/Feature/Payment/CheckoutMethodsTest.php` (+3 cases), and this file.
  Core lane only — no storefront change, no migration, no new `.env` key required (defaults apply).
  Ships as one core tar + `config:cache` + `route:cache`; the new scheduled command rides the
  existing `schedule:run` cron.

### LIVE AND UNFIXED IN PRODUCTION — in this order

1. **Abandoned card payments hold stock forever.** `add_order` reserves stock for every order; a
   card order then waits `pending`, and a shopper who leaves Paymob's page produces NO callback, so
   nothing released it (only a Paymob decline, a failed session, a dashboard cancel, or the
   reconciler for orders already `cancelled` do). Reproduced: open Paymob, come back, pick cash →
   "Insufficient stock". **Built (uncommitted):**
   - `orders:expire-unpaid`, every minute: cancels + releases (`payment_failed`, note
     `payment_expired`) card orders `pending` with a core reservation, no successful attempt, older
     than `compat.unpaid.expire_after_minutes` (default 60; refuses < 15; `--dry-run`). Pre-switch
     legacy orders and WhatsApp orders are excluded by construction. Claim-based: cannot race a
     callback or a dashboard cancel.
   - Same-shopper rule in `add_order`: the SAME account/guest token's unpaid card orders are
     cancelled and released (note `payment_superseded`) inside the new order's transaction, so the
     unit comes back for the shopper who returned; another shopper still waits for the expiry.
   - Intention `expiration`: **sent only when configured, and unset as shipped** (changed
     2026-09-27 — first draft sent 1800 on an assumed unit). Paymob's Create Intention reference
     lists the field with no unit, default or limits, and 1800 is 30 minutes in seconds but 30
     days in minutes. Measure with `scripts/paymob-expiry-probe.php` on the server; then set
     `PAYMOB_INTENTION_EXPIRATION_SECONDS` (must be under the 60-min window, or it is refused).
     Until then a payment landing on an expired order is never lost silently: `CallbackPolicy`
     never reopens a `cancelled` order and records a finding (→ refund or reopen by hand).
   - No customer e-mail on expiry: a card order's confirmation is only sent when the money arrives.
   - Tests: 7 in `UnpaidOrdersTest`; the developer's exact scenario goes RED with the same-shopper
     rule disabled.
2. **FK step 2 — `core:repoint-commerce-fks`, still to BUILD.** Step 1 done by the developer today:
   `order_items_product_id_foreign` and `cart_items_product_id_foreign` (→ legacy `products`)
   dropped by hand; orders for the 159 catalog-only products work. Rollback SQL saved on the server
   at `~/fk-rollback-*.sql`. Background: this is the study's never-built M2 (CLEAN_CORE_STUDY §2.8.2,
   risk R2-02); the Phase 2 runbook never ran it. Build it as an idempotent one-off COMMAND, not a
   migration (the harness runs `migrate` on a bare dump before the catalog is filled, so M2's
   orphan pre-flight would fail there): orphan pre-flight against `catalog_products` → add
   `order_items.product_id → catalog_products` RESTRICT and `cart_items.product_id →
   catalog_products` CASCADE (one deliberate deviation from the spec: `ProductImporter` can
   hard-delete a fresh product sitting in a cart). Test first: order + cart add for a catalog-only
   product, red on the legacy schema. Run it in the harness after `core:transform`;
   `core:drop-clean` already disables FK checks, so rehearsals keep working. Out of scope on
   purpose: `wishlist_items` (gone), `offers` (frozen), legacy junctions (unwritten),
   `product_ratings` — **B1 (ratings) must repoint that key when it is built**, or it hits the same
   bug.
3. **Dashboard order detail shows Name/Phone/Email "—" for a REGISTERED customer.** Cause, verified:
   `Manage\OrderController` (list lines ~185, detail ~282) and the list search read ONLY `guest_*`,
   which the storefront fills for guests alone. **Built (uncommitted):** `OrderCustomer` — the one
   answer (account name/e-mail, else guest; phone = order address → guest → account → address's
   second) — used by the order list, the detail, the search (now matches address and account
   phones), the order e-mails, the mailer's recipient and the Paymob billing. Tests: 5 in
   `OrderCustomerTest`, red on the old controller.
   - Found on the way, fixed in the same change: the Paymob billing sent `phone_number` and the
     provider read `phone`, so EVERY intention went out with phone `-` — a mobile wallet pays by that
     number. Test red on the old code.
4. **Orders 000001 and 000002 — two units cancelled in the legacy (Blade) dashboard that never came
   back** (legacy defect #6: its cancel only set the status). They cannot be cancelled again and the
   reconciler skips them (no core reservation). Correct via Dashboard → Inventory → adjust, per
   order LINE: mode **adjust** (relative, +quantity — never *set*, which would overwrite any sale in
   between), the line's **bucket** (`type_stock` Express → express, anything else → market), the
   variant if the line has one, reason **`adjustment`** ("Stock-count correction"), note e.g.
   "Legacy order 000001 cancelled on <date> in the old dashboard; legacy never returned the stock
   (defect #6)". Only for units that physically exist. (Order 000012 is a REAL order — leave it.)
5. **React hydration mismatch (#418) on the storefront.** Not investigated yet; not reproduced in the
   one home-page load checked tonight. Next: reproduce with the non-minified dev build to get the
   differing node, then look for render-time `Date`/`Math.random`/locale formatting/`window` reads.
6. **The methods screen accepts the same integration ID on two methods silently** (cause of today's
   bank_installment = 5943060 slip). Warn, don't refuse: after save in
   `PaymentSettingsController::storeMethod/updateMethod`, flash "this ID is already used by <key>"
   when another method of the same contract carries it, and mark such rows on the screen
   (`MethodList::forAdmin` can carry a `shares_integration_id_with`). ~1–2 h with a test.
7. **Meta Pixel "Invalid parameter format for currency" — CLOSED 2026-09-27: Meta's, not ours.**
   Verdict: on the home page our code makes only `init` ×2 + `PageView` with no currency, replaying
   `PageView` does not trigger it, and it fires once per pixel inside Meta's code beside Meta's own
   "conflicting pixel versions" warning — so it comes from Meta's per-pixel config scripts (an
   inference: the captured stack URL was redacted). Our ViewContent / AddToCart / InitiateCheckout
   / Purchase send `currency: 'EGP'` and a numeric `value`. **The one check that would prove this
   wrong:** a real Purchase in Events Manager arriving WITHOUT its value in EGP — look at the first
   real order's event; if it is missing, reopen this with `trackPurchase` as the suspect.
   Evidence as recorded the night before: fires twice per home-page
   load = once per pixel (1611910119460872, 1614877760150035); our code makes only `init` ×2 +
   `PageView` there, with no currency; replaying `PageView` alone does not trigger it; neither
   pixel's served config (`signals/config/<id>`) sets a currency rule; the page declares no currency
   (JSON-LD, meta, microdata); the warning comes from Meta's `standardParamChecks` plugin, which
   DELETES the failing parameter from that one event. Meta also reports "multiple pixels with
   conflicting versions" — the two pixels' configs are built on different releases. So it is
   probably Meta's own per-pixel config, not anything we send; our ViewContent/AddToCart/
   InitiateCheckout/Purchase send `currency: 'EGP'` and a numeric `value`. **Next:** check both
   pixels in Events Manager (a value/currency default, or a conversion rule); then prove in the
   browser that an event we send keeps its currency — fire one AddToCart in a probe frame with
   `facebook.com/tr` intercepted so nothing reaches the pixels, `EGP` vs a bad currency as control.
   Also noted, no action: `THREE.Clock` is deprecated (→ `THREE.Timer`) — part of the Next 16 / React
   19 work (A9). The 3D reflow/rAF timings the developer saw are unmeasured on a mid-range phone.

### Lesson: "the third party accepted it" is not "we sent the right thing"

The Paymob billing sent `phone_number`, the provider read `phone`, and every intention since the
switch went out with phone `-`. Nothing on our side could catch it: Paymob accepted the payload,
payments worked, and the only symptom was a field on someone else's page — no phone on any card
transaction in Paymob's dashboard (the day we trace one with their support), and harder wallet /
installment flows. For every OUTGOING integration payload (Paymob intention, Meta/TikTok events,
mail, and CAPI/ERP when built), a test must assert the payload's MEANINGFUL fields carry the
customer's real values end to end — built from a real checkout request, not from a hand-made array
handed to the provider — and a placeholder (`-`, `Guest`, `01000000000`, `no-reply@…`) reaching
the wire for a customer who gave the real value is a failure. `CheckoutMethodsTest` now does this
for the phone; the same assertion is owed for name, e-mail and street.

### Lesson: a browser pass writes real rows, and nothing rolls them back

The Pest suite runs every test inside a transaction that is rolled back, and the compat harness
builds its own scratch database and drops it. A BROWSER pass against the running app does neither:
every account it registers, role it grants, cart it fills and product it saves is a real row in
whatever database `.env` points at. On 2026-09-27 that left a throwaway account, its admin grant,
an activity-log entry, a cart and a re-saved product in the local dev copy — found and removed the
same day, but only because someone looked. Unchecked, the dev copy drifts from production one
session at a time, and the drift is invisible until a count does not match.

**Rule:** any browser testing plans, from the start, EITHER a scratch database (clone the dev copy,
point `.env` at it with a restore trap, drop it afterwards — the harness's own procedure) OR an
inventory-and-clean step: list every row created (accounts, roles, carts, orders, products,
colours, activity log) and delete them before the report, which states what was created and that
it is gone. Row timestamps cannot be restored, so a record of what was touched is part of the
report too.

### C-1 — the catalogue moves to the server (developer's go, 2026-09-27: stages 1–4 in order, then Arabic; ship each as ready)

- **Stage 1 — SHIPPED, verified live:** `/brand/Rolex` 14,250,000 → 3,746,271 bytes (−74%). See S4.
- **Stage 2 — built 2026-09-27:** the header menu, the mobile drawer and the category tiles no longer
  read the catalogue. They read the lookup tables plus core's new `GET /api/catalog/nav` (the brands
  with products, the sub-types and brands per category type, the genders with both names), which is
  derived from the `all_product` rows themselves (`CompatCatalog::nav`, cache family `compat_nav`,
  the same invalidation events as `compat_all_product`), with no legacy counterpart. Proof: every entry
  of the menu and the drawer (203, hovered and opened in a browser) is identical in English. Arabic
  differs only in the gender labels, which is a FIX: the trimmed catalogue copy dropped the localised
  gender name, so the Arabic menu said "رولكس Men".
  - **Prediction that did NOT hold:** the menu scanning the catalogue on every render was expected
    to make filter taps faster once removed. Measured, no change beyond run-to-run noise (±20%). The
    tap cost is the listing itself: the grid, the filter pass and the facet counts. That is stage 3.
  - **Moved to stage 3: the search box.** Its "View all results (N)" leads to `/listing?q=`, which
    still searches in the browser by substring. Server search (FULLTEXT, Arabic folding) matches
    differently, so moving only the dropdown would promise N results and list a different number.
    Both move together in stage 3.
- Stage 3 (listing, sidebar, strip and search on the server) and stage 4 (by-ids for cart, checkout
  and account; related products; home rails; drop the site-wide catalogue copy) follow.

### Listing interaction — measured 2026-09-27

- **Filter taps (sidebar and quick-filter strip): the same cost, the same place.** Live, desktop, real clicks:
  sidebar median 239 ms (199–296), strip median 271 ms (241–426). Every tap re-renders ~630 components,
  192 of them the 24 product cards, in one synchronous task. The developer tested on a phone and it
  feels instant, so it is NOT treated as a defect. It disappears by construction in C-1 stage 3.
- **Strip scrolling is not a React problem.** Phone profile (390×844 @3×, touch, CPU ×4 and ×6), 20
  drags + 18 flicks: 0 grid renders, 0 long tasks, exactly ONE component re-rendered per gesture (the
  carousel's own position). Worst frame 50 ms at ×6, on the first flicks only, while the chip logos
  load. The whole-store subscriptions (`useUIStore()` with no selector, 33 call sites) are NOT what
  stutters it. **OBSERVED BUT UNREPRODUCED** (developer's decision, 2026-09-27): a fast flick of the
  strip hitches on the developer's phone; nobody else has reported it and emulation does not show it
  (candidates it cannot model: raster, image decode, Embla moving the strip from JavaScript every
  frame). Do NOT chase it now: re-check it on a real phone AFTER C-1 lands, because C-1 stage 3
  changes how that page works. If it is still there, take a phone trace first (`chrome://inspect`
  over USB); native `overflow-x` scrolling for the strip is the likely fix.
- Also left, by decision: the 239–271 ms filter taps (not felt on a phone; C-1 stage 3 removes them)
  and moving the 33 `useUIStore()` call sites to selectors (C-1 stage 3 makes it moot).
- **Measurement trap: a Chrome window that is covered or unfocused LIES.** It reports taps under 16 ms
  (it never paints), and it throttles animation frames to one per second while reporting
  `visibilityState: visible`. Launch with `--disable-features=CalculateNativeWinOcclusion
  --disable-backgrounding-occluded-windows --disable-renderer-backgrounding`, call
  `Page.bringToFront`, and throw away any run whose frame intervals jump to ~1000 ms.

### Colours — decided 2026-09-27

- **The ~7,090 products with no colour are a DATA-ENTRY job, not an import fix.** Measured: the
  WooCommerce export (8,614 rows, the bulk of the catalogue) carries a colour attribute on 7 rows.
  Re-importing cannot recover a colour the source never recorded — do not propose an import fix
  again. The team fills them in from the actual products, with the dashboard's colour picker
  (several colours per role since 2026-09-27).
- **Do not infer colours from product titles.** 1,065 product names contain a colour word; guessing
  from them is right most of the time and wrong in a way nobody notices until a customer receives
  the wrong item. Rejected by the developer.
- Open: the Joyroom sheet does carry a colour per row — backfill and linking its variant colours to
  the taxonomy are scoped separately.
- **`catalog:colours-main-to-band` MOVED NOTHING on production.** The developer counted it before
  the deploy (2026-09-27): 0 products, 0 colours in the `main` role on non-watch families. Those
  families never had a colour entered at all. The command ships as a PREVENTIVE fix (the dashboard
  now asks these families for `band`, so nothing new lands in `main`). Its existence is NOT
  evidence that any colour data was migrated: there was none to migrate.
- **Gold is `#D4AF37`, not `#FFD700`** (developer's call, 2026-09-27). `#FFD700` was also Yellow's
  hex, so every gold watch showed a yellow swatch on the product page and in the filter. Changed as
  DATA in the dashboard (Lookups → Colours → Gold → hex), not in code, so it is in the activity
  log. Consequence: an order line saved before the change stores `#FFD700`, and the dashboard's
  hex→name lookup now names it "Yellow". The swatch colour on those lines is unchanged. Count them:
  `SELECT COUNT(*) FROM order_items WHERE color_band LIKE '%#FFD700%' OR color_dial LIKE '%#FFD700%';`

### Fixed today, for context

Image folders (`78a43a6`), host binding + cart option A (`5c3586e`), order totals (`704ac61`), L5
per-storefront URLs (`101d57c`), category filter (`7ff3a26`), batch 1 (`23e2b5a`, `3333a1f`), and
the bank_installment integration ID — the developer's data-entry slip, corrected to 5943061 in the
dashboard. Code was never at fault there: a test now pins that bank installments sends `[5943061]`
and CAGG `[5943060]`.

### Checks, 2026-09-27 — off finished runs, items 1 + 3 in the tree

Full Pest suite: 1753 passed, 38 skipped, 0 failed. PHPStan level 10: no errors. Pint: passed.
`tsc`: clean. Commit-hook self-test: 58/58. Compat harness on a scratch copy of the 2026-09-17
dump: 134 cases, zero unexplained differences; `.env` restored (fingerprint `43fffada70388afe`),
scratch database dropped. No storefront change in this batch, so no `next build` was needed.

**Intention expiry, as shipped:** NOT sent. `PAYMOB_INTENTION_EXPIRATION_SECONDS` is unset, so no
`expiration` goes to Paymob until its unit is measured with `scripts/paymob-expiry-probe.php` on the
server (two 1-EGP test intentions; it reads the lifetime from Paymob's own payment key). Exposure
until then: a shopper can still pay on Paymob's page after `orders:expire-unpaid` has cancelled the
order (after 60 min); that payment is recorded as a finding and needs a refund or a manual re-open
— never lost silently.

---

## A. Storefront lane — one rebuild carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| A1 | **Category filter** — `passesFilters()` never read `filters.categories`; only Watches and Fashion filtered, via a hard-coded English-name pre-split. **Done on `wave-4d`** (2 lines + `FilterPredicateTest`, which runs the real predicate under Node and fails with the fix reverted) | Nothing more — ships with the next storefront build | Every category except Watches/Fashion shows the whole catalogue, in both languages. Pre-existing since July, on legacy too |
| A2 | ~~**Calls to deleted features.**~~ **Done in batch 1 (2026-09-26), on `wave-4d`.** Removed: `all_offer` on every page, `all_wishlist` on every signed-in page, `all_blog` server-side, dead `show_cart`/`fetchBanners`/`fetchOffers`, the unused `useBanners` (`all_banner_*`). `all_offer_rating`/`add_offer_rating` are unreachable (no offers → `/offer/…` 404s). **The wishlist interface is removed** (developer decision: a heart that never saves reads as a broken site) — the product-page heart, the cart's "Move to wishlist" (which REMOVED the item from the cart and then failed to save it), the account tab, the header/footer links; `/wish-list` now redirects to `/account`. Left: `add_product_rating` (B1) | — | — |
| A3 | **Banners — deferred, NOT dropped** (paused for the season; the developer will use them). Production has zero banner rows (2026-09-17 dump) and the storefront has no banner slot, so this is a build, not a switch. **What it needs:** (1) **the home-page slot** — a component that renders `meta.banners[]` with `placement=home`, picks the desktop or mobile image by `type_show` (`pc`/`mob`), links to `link_url` / the product (`product_id`) / the category (`category`, a tree reference), and renders NOTHING when the list is empty (no empty frame); where it sits on the home page is a design decision to make with the developer; (2) **the first v2 client** — the storefront reads only compat today; fetch `GET /api/v2/{STOREFRONT_CODE}/meta` (`STOREFRONT_CODE` exists since batch 1 in `src/lib/env.js`), mind v2's `http.cache` (10 min + 1 h stale, so a CDN purge after scheduling one) and that an image comes back as an `ImageUrl::object`, not a URL string; (3) **the dashboard screen that feeds it** — core already has it (placement is always `home`, by decision; scheduled with `starts_at`/`ends_at`, active flag, sort); check it uploads both a desktop and a mobile image | M: one component, one fetch, a browser pass on desktop and phone | The season's banners cannot be shown |
| A4 | ~~**Offer the new Paymob methods at checkout.**~~ **Built in batch 1 (2026-09-26), on `wave-4d`.** Core: `GET /api/v2/{storefront}/payment-methods` (usable rows only — usable integration id, contract enabled with complete credentials; both labels; per-method `min_total`/`max_total` from the dashboard; `Cache-Control: public, max-age=0, s-maxage=60`), and `add_order` checks `payment_method_id` BEFORE anything is written (`CheckoutMethods`): this shop's, offered, key agrees with `payment_method`, inside its limits — else a 422 in both languages that says the order was not placed and what to do. The wave-3 fallback is closed when the contract is live. Cash is always offered, independent of the rows. Storefront: the list, disabled-with-reason outside a limit, `payment_method_id` posted, Cash + "Pay Online" if the list fails. **Apple Pay:** its dashboard switch is the flag (leave the row disabled until Paymob confirms the domain verification for `watchizereg.com`); the storefront also shows it only where `ApplePaySession.canMakePayments()` is true | — | — |

| A5 | **Before promotions go on:** the storefront's account order view (`Account.jsx:453-456`) derives shipping as `total − lines`. Correct today; with a money promotion the discount would be shown as CHEAPER SHIPPING. Core's `OrderTotals` (2026-09-26) is the one definition — the view needs the promotion amount, and `me/orders` is frozen compat, so this is a sanctioned compat deviation or a v2 order endpoint | S–M, and a harness deviation if compat carries it | Wrong-looking shipping on every promoted order in a customer's history |
| A6 | **Before promotions go on: raise the installment fee.** Promotions are keyed on `paymob` (`PromotionRules::PAYMENT_METHODS` = cash, paymob, whatsapp), not on the method row. Since batch 1 (2026-09-26) every Paymob method — card, wallet, valU/CAGG, bank installments, Apple Pay — posts `payment_method=card` and so counts as `paymob` for a promotion. Installment providers charge the merchant a higher fee, and shops commonly exclude installments from discounts. Kept as is by decision; decide per promotion whether an installment order may take it, and if not, key the rule on the method row (the id `add_order` validates; today it is recorded only on the payment attempt, `payment_statuses.storefront_payment_method_id`) | S: one more condition in `PromotionRules`, one dashboard field | A promotion stacked on an installment order the margin was never meant to carry |
| A7 | **Next.js 15.5.27 on or after 30 September** (Vercel's scheduled security release: 1 critical, 2 high, 5 medium, 1 low). Batch 1 ships 15.5.26; this is its own follow-up, decided 2026-09-26. **A ten-minute job:** the `next` line in `package.json` → `pnpm install` (lockfile) → one storefront build → a check of product images (AVIF served, not broken) and one checkout to the payment page | ~10 min + the build | A published critical left open on the storefront |
| A8 | **AVIF quality — decided 2026-09-26: keep 15.5.2x's lower AVIF quality.** Since 15.5.24 Next encodes AVIF at `quality × 50/80` (was `quality − 20`): measured on 40 product photos, files 20–33% smaller, the only visible difference a slightly softer dial print at 2× zoom. **The one-line undo if anyone complains:** raise the `quality` props 80→96, 70→80, 75→88, 90→100 and `images.qualities` in `next.config.js` to match. **Do not misread #97954** (the 15.5.24 follow-up that "re-enables AVIF"): it re-enables DECODING AVIF *sources* when sharp's libheif is ≥ 1.23.2 (`isAvifDecodeSafe`) — that is why the sharp override is now `^0.35.4` (libheif 1.23.2). It does NOT change the output quality, and our masters are WebP anyway | One line per component | — |
| A9 | **Next.js 16 — after Brand Fashion's launch, not before** (decided 2026-09-26). 15.5 is Maintenance LTS (security fixes only); 16.x is Active LTS. **Estimate: about a week, mostly testing.** The real work is **React 18 → 19.2** (Next 16 requires it) — and it is OVERDUE rather than optional: `@react-three/fiber` 9 and `drei` 10 already require React 19 and are unmet peers today (`pnpm peers check`), i.e. the 3D home runs on a combination nobody supports. MUI 6, emotion, framer-motion 12, TanStack Query and zustand support 19; `prop-types` is used in one file. Already compatible: `params` awaited, no `middleware` file, no `next lint`, `eslint-config-next` on 16, `qualities`/`minimumCacheTTL` set explicitly. **Watch:** Turbopack becomes the default build — check the React-resolution note for the 3D packages in `next.config.js` (`transpilePackages`, "do NOT alias react"). **Then:** a browser pass through home (3D), listing, product, cart and checkout. **Also in this work:** `THREE.Clock` is deprecated (console warning on every home load, 2026-09-27) — move to `THREE.Timer` with the three/fiber upgrade | ~1 week | Security fixes only on 15.5; the 3D stack stays on an unsupported React |

## B. Core lane — one tar deploy carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| B1 | **The rating write** (`add_product_rating`) — the week agreed in §8 | Core endpoint + tests + a compat-harness case, **and one line in §4's `.htaccess` allow-list** (it is not on it — without that line the endpoint 404s even once built). No storefront change if the shape matches legacy | "Could not send" on every review; ratings stay at zero forever |
| B2 | **Meta Conversions API for card payments** (§11.1) | About a day: one server-side `POST` beside `flush($mailIds)` in the payment callback, hashed-email `user_data`, an `event_id` that dedupes against the pixel, a retry path; a Meta system-user access token (new secret, env by name only); Events Manager's test-events tool to prove it | **Card sales never reach Meta.** Campaigns optimise on cash orders only and under-report return on ad spend — for as long as this is open |
| B3 | **Verification lands on raw JSON; no way to ask for a new link.** `verify()` returns `{"message":…}` to a browser (as legacy did); nothing in the storefront calls `resend-verification`. Smallest fix: `verify()` redirects a browser to `FRONTEND_URL/account?verified=1\|already\|invalid\|expired` (JSON callers unchanged); **spans both lanes** — the account page shows a toast and a "send a new link" button | ~15 lines in core + a toast and a button in the storefront + tests | The bare-JSON page (cosmetic). Worse: a link expires after 48 h, or a pre-flip legacy link verifies the LEGACY database — either way the customer can never re-verify, **and guest-order linking happens on verification**, so their earlier guest orders never attach to the account |
| B4 | **Pin the verification link's host** in `CustomerMail::sendEmailVerification()` | A few lines + a test (with B3) | Correct today only because every sender is a customer route on the API host. The first CLI or dashboard sender builds the link on `eleganceeg.com`, which §4 404s |
| B5 | **Reordering payment methods is not in the activity log** — the order decides which contract takes the money | Small: one `ActivityLog::record` in `reorder()` + test | "Who moved card traffic to the other contract?" has no answer |
| B6 | **`PromotionController` has no storefront-scope check** (audit, left open by decision) | Small | Nothing — until anyone issues a SCOPED grant. Close it before that day |
| B7 | **`compat:env-parity`** — the `[key]` arm should say "proxy disabled on purpose, use `--base`" and needs a path legacy actually guards; the `[assets]` rule should check the media root RESOLVES to the served tree, not that it is written absolute | Small, tooling only | The command keeps reporting NOT READY on a healthy host, and people learn to ignore it |

## B+. Sitemap and SEO (found 2026-09-26, after resubmitting the sitemap)

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| B8 | **The sitemap sets cookies.** `/sitemap.xml` and `/{locale}/sitemap.xml` are web routes (`routes/web.php:500-501`) that opt out of Inertia but not of the session and CSRF middleware — every crawl gets `XSRF-TOKEN` and a session cookie. The XML itself is correct (200, `application/xml`, same for Googlebot); Search Console's first "couldn't fetch" was transient | S: take both routes out of the session, cookie and CSRF middleware (or move them to a cookieless group) + a test that the response carries no `Set-Cookie`. No harness change — the body is untouched | Every crawl opens a server session that is never used, and no cache or CDN can store the response |
| B9 | **The sitemap lists `/offers` and `/blogs`**, hard-coded in the static list (`CompatSitemap.php:31`). `/offers` is the deleted legacy offers page and now a REDIRECT (`next.config.js` → `/listing?offers=true`); `/blogs` has no articles | S: drop both from the static list (put `/blogs` back when articles exist). **The harness compares `sitemap:en` and `sitemap:ar` byte for byte**, so this is a new sanctioned deviation (core omits two legacy URLs) + a 134-case rerun | Google crawls and indexes an empty page and a redirect every time; both dilute the sitemap's signal |

## BF. Brand Fashion launch prerequisites — FUNCTIONAL only (decided 2026-09-26)

**Decision, not open: hiding that the two brands share an owner is NOT pursued.** It is understood
and accepted that a deliberate observer can connect them — shared e-mail templates and sender, one
Google consent screen, one image tree served on both API hosts, cookie and dashboard names, the
dashboard's login branding, the shared sending server, DMARC at `p=none`. Closed as accepted: L1's
templates and mail identity, N3b, L6, B10, B11, L1b. **Do not re-propose them as privacy work.**
Already done and kept, because each also fixed something real: the repo is private; v2 and the
payment callback answer only their own host; Watchizer's EHLO is `watchizereg.com`.

What remains is what would otherwise be BROKEN on Brand Fashion:

| # | Item | When | Cost |
|---|---|---|---|
| L5 | **Per-storefront values** — the post-payment return URL (a Brand Fashion shopper must land back on `brandfashionegy.com`), the password-reset and sign-in landing host, CORS for Brand Fashion's origin, and the asset host its payloads name | **Now** | S–M |
| L3 | **Google sign-in per storefront** — a redirect URI (and credentials) per shop, or sign-in cannot complete on Brand Fashion. The consent screen's name is irrelevant | When Brand Fashion's frontend starts | M |
| L2 | **Accounts per storefront** — design first; built last, immediately before launch, so it is the freshest change when the second shop goes up | Last | M–L |
| — | **Ordering:** set `storefronts.domain` for Brand Fashion BEFORE pointing `api.brandfashionegy.com` at the server — `CompatStorefront` falls back to Watchizer for an unknown host and would serve Watchizer's catalogue | At DNS time | none |
| L4, L7 | Paymob merchant account, legal entity, owner checklist | Owner | — |

## SEO. Audit of the live storefront, 2026-09-27 (read-only; M = measured, I = inferred)

**Before anything else — the bot wall (M, cause; I, effect on Google).** Hostinger's CDN answers
any request that asks for compressed content (`Accept-Encoding`) with a 403 "Checking your
browser" challenge, whatever the user agent — which is why Lighthouse/PageSpeed get 403 and plain
curl gets 200. Real Googlebot is PROBABLY let through by IP (Hostinger's docs), but that cannot be
proven from outside. **Owner check (minutes):** Search Console → URL Inspection → Test live URL on
the home page and a product; Settings → Crawl stats → 403 count. If Googlebot is refused, turn off
the bot protection / whitelist verified bots in hPanel → CDN — nothing below matters until then.

| # | Finding | Fix | Cost |
|---|---|---|---|
| S1 | ~~Every product image blocked: `core/public/robots.txt` on api.watchizereg.com was `Disallow: /`~~ **FIXED 2026-09-27** (`Allow: /Uploads_Images/`, everything else still closed; `RobotsTxtTest`) — ships with the next core deploy | — | 15 min |
| S2 | **Arabic is invisible to Google** — language comes only from the `wz-lang` cookie, the server always renders English, no `/ar` URLs, no hreflang. Scoped as a staged project below (S-AR) | see S-AR | project |
| S3 | Product `meta_title` / `meta_description` never used (titles are a template in `src/lib/detailSeo.js`); Arabic filled on ~8–10%, English 83–92%; about half the English meta titles end in the junk text " \| Select…" | S-AR stage 2 | hours–1 day |
| S4 | HTML weight: 3.4–4 MB on home/product pages, 12.8–14 MB on every facet page (`/category/*`, `/brand/*`, `/grade/*`, `/subtypes/*`); HTML is `private, no-store` | **C-1 stage 1 DONE 2026-09-27 (in the tree, ships with the next storefront build):** the facet pages' second, unprojected catalogue copy is gone from `src/lib/facetListing.jsx`. Measured on the local build: every facet route 10.65 MB → 3.22 MB, the same as `/listing`; same counts, same 24 cards, same titles; no catalogue refetch in the browser. Live before: 14.25 MB, 9.8–11 s. The remaining 3.2–4 MB on every page goes in C-1 stage 4. | stages 2–4: days |
| S5 | Sitemap (`core/app/Compat/CompatSitemap.php`): 5 static URLs 404 (`/products`, `/about-us`, `/contact-us`, `/privacy-policy`, `/terms-and-conditions`); 28 multi-word brands encoded with `%20` → 404; `/offers` redirects; `/blogs` empty but indexable; 15 duplicate `<loc>`; zero-product brands listed | drop/fix entries, slugify brands with `LegacySlug`, dedupe — or move to the v2 sitemap in S-AR stage 3 (the compat sitemap is a harness case, so fixing it in place is a sanctioned deviation) | hours |
| S6 | Category/brand landing pages linked only from the sitemap (nav links go to `/listing?…`, canonicalised to `/listing`); listing pagination is `<button>`, not links; no related products on product pages | point nav/footer/breadcrumbs at `/category/*`, `/brand/*`; `<a href="?page=n">` pager | 1–2 days |
| S7 | Fuzzy/case-insensitive facet matching makes `/brand/rol`, `/brand/Rolex`, `/brand/rolex` separate self-canonical pages; facet titles say "Watches" for bags/belts | canonicalise to the slug + 308; titles by category type | hours |
| S8 | Product JSON-LD missing `hasMerchantReturnPolicy`, `shippingDetails`, `itemCondition`, `mpn`; says `InStock` where the page shows "Pre-Order" (market stock); home `Store` block has wrong `sameAs` and generic geo; no `WebSite`/`Organization` | complete the markup; `PreOrder` for market-only stock | hours |
| S9 | `www.watchizereg.com` serves 200 (not a 301 to the apex); root layout's default metadata is Arabic while pages are English; `/blogs` should be noindex | redirects / metadata | hours |

**Owner decision (not an open item):** several products are priced far below genuine retail while
every page and description says "Authentic, certified & guaranteed". Whether those claims stand is
the client's call, raised with them by the developer (2026-09-27). It matters before any Google
Merchant Center feed (counterfeit / misrepresentation policies), which also requires the trust
pages that currently 404 (about, contact, privacy, terms, returns).

### S-AR — Arabic for Google, in stages that each ship

Decision to take first: **English keeps the bare URLs** (they carry today's ranking and links) and
Arabic moves under `/ar/…` — the reverse of the v2 sitemap's current default-locale-unprefixed
rule, which must be flipped for Watchizer.

1. **Arabic pages that exist for Google (4–6 days).** `/ar/…` for home, product, category/brand
   facet pages and listing, served by a middleware rewrite onto the existing routes with the locale
   passed down. The server renders Arabic — the server catalogue already carries `productsAr` — and
   the client store starts from the URL's language, not only after mount (the cookie becomes a
   preference for the language switch, not the source of truth). Per-locale `<title>`, description,
   H1, alt text and JSON-LD names from the Arabic title + short description (≈100% filled, so no
   hand-written meta needed); self-canonical per language; hreflang ar/en/x-default on every page;
   the language switch links between the two URLs. **Buys on its own:** every product and category
   becomes indexable in Arabic — the Arabic queries that are most of Egyptian search volume.
   Browser pass in both languages (RTL), and a check that the English pages are byte-for-byte
   unchanged apart from the hreflang tags.
2. **Metadata quality (1–2 days).** Clean the " | Select…" junk out of `meta_title` (one-off
   command, dry run first); wire `meta_title`/`meta_description` with the fallback
   meta[locale] → title[locale] + brand, and meta_description[locale] → short_description[locale]
   → template. **Buys:** better snippets and click-through in both languages.
3. **Sitemap per language (1–2 days).** Serve the v2 per-locale sitemaps (flipped prefix rule) with
   `xhtml:link` alternates; fold in S5's dead-URL fixes; image host = the host pages use. **Buys:**
   faster, complete discovery of the Arabic URLs, and no 404s in the sitemap.
4. **Optional, later: Arabic slugs** (`/ar/product/ساعة-…`) and Arabic landing copy for
   categories/brands. Marginal ranking gain, real redirect/duplicate risk — decide after stage 1's
   Search Console data.

Total ≈ 1.5–2.5 weeks, stage 1 alone ≈ one week. Prerequisite for all of it: the bot-wall check
above.

## C. Operations and security — no code, or not ours

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| C1 | **Rotate `JWT_SECRET` and the storefront's public API key** — the only two values pasted into a chat. **Paymob is NOT part of this:** nothing exposed its credentials (corrected 2026-09-24; an earlier note here listed it by mistake) | Every customer is signed out (tokens last 30 days). While legacy is up it must change on BOTH hosts at once; **after C3 it is core alone — simpler.** The public key means panel + `.env.production` + core's `COMPAT_API_KEY` together, then a rebuild (a short window of 401s) | **Anyone holding `JWT_SECRET` can mint a valid token for ANY customer id** — full access to that account's profile, addresses and orders. This is the one item whose risk is not cosmetic. Recommend days, not next season |
| C2 | **Next.js 15.5.21 → 15.5.26** (critical RCE advisory GHSA-2xp9-vwfh-vxw4, fixed in 15.5.24) + `sharp` 0.35.3 → 0.35.4 | A lockfile refresh, a build, a visual check. Behaviour changes: AVIF *sources* served unoptimised; AVIF *output* quality rescaled (80 → 50, 70 → 44, 90 → 56 — smaller, slightly softer). Cheap hardening in the same build: remove `127.0.0.1:8000` and `localhost:8000` from production `remotePatterns`, and the Farfetch host if nothing uses it | Exposure is narrow — our uploads are re-encoded to WebP, so no outsider can place an AVIF on our hosts — but an allowed third-party host or a future upload path turns it into unauthenticated remote code execution on the storefront server |
| C3 | **Switch the legacy storefront off** (§4A.4) — after any card payment started there has settled | Small. It also retires C4 and the undeployed `backend/` fixes, and makes C1 single-host | Legacy keeps answering; anyone who reaches it writes orders into the database nobody reads any more |
| C4 | **The legacy gate question** — `dash.watchizereg.com/api/catalog/meta` answered 200 without `Api-Code` | Two reads (cache-busted curl; the key's length in legacy's cached config) | Moot once C3 is done. Until then, if the gate is open, runbook §9.1A's residual-risk row is wrong as written |
| C5 | **One source for the storefront's build values** — the Hostinger panel now overrides `.env.production` for five names (and the pixel list is in both) | Delete the panel variables, rebuild, run §7 step 0 | Two sources drift; rollback must be done in the panel (§9.1's box), and §11.2's "dropping a pixel is one line in `.env.production`" is no longer true |
| C6 | **Git history purge** — the Google OAuth secret, the audit key, the July `api.jsx` literal | A history rewrite, a force-push, every clone re-cloned. All three are already dead or rotated | Nothing live — dead values stay readable in a public repo's history |
| C7 | **55 missing media files** — `media:verify` found 10,003 of 10,058 | Identify the 55, re-upload or clear the references | Up to 55 image slots show a broken image |
| C8 | **Confirm the logs are being written** — the log channel was dead from the first deploy until the night | One look tomorrow: `ls ~/domains/eleganceeg.com/core/storage/logs/` should show a `laravel-2026-09-2*.log` | Callback refusals, amount mismatches and mail failures go unseen again |
| C9 | **User 7's first verification mail never arrived** (before the log fix; a broken log channel does not stop SMTP) | Check that inbox's spam folder | Probably nothing — later mails arrive. Unexplained, so recorded |
| C10 | **Server leftovers** — `~/core-phase1.tar.gz`, `~/deploy-staging/`, `~/s4-block.txt`, the `.bak` snapshots | Delete once the rollback window closes (keep `core-before-phase2.*.tar.gz` and `env.before-phase2.*.bak` until then) | Disk, and one `.env` copy (mode 600) lying in the home directory |

## D. Documentation — owed, cheap

- §3.1's `APP_URL` row says signed links are "built from" it — they are built from the request host.
  `APP_URL` stays `eleganceeg.com` (it sets the mail EHLO name).
- §4.3's probe list — `api/login` and `api/add_order` are POST-only, `api/auth/google` is not a
  route (corrections are in "Found on the night"; fold them into the table).
- §11.2 — the pixel list now also lives in the panel (C5).

---

**Holding, not deciding:** blogs stay unscheduled until the per-storefront scoping decision
(Phase 0, G9). The hidden payment-method icon field comes back with A4, labelled with whatever the
checkout accepts.
