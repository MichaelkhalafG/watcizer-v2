# Phase 0 — Completion Report

> Generated: 2026-05-31
> Audited by: Claude Code

## Summary

- Total tasks: 11
- Fully complete: 11
- Partial: 0
- Not done: 0
- Remaining effort: 0 hours (Phase 0 functionally complete; see caveats)

> Note on verification: several DoD checks are behavioural (require a running app, a
> valid JWT, and seeded products/users). The local DB has no seeded data and live
> HTTP/JWT flows could not be exercised, so those items were verified **structurally**
> (route middleware chains, DB indexes, code paths, `php -l`, and `vite build`) rather
> than by a live request. Each such case is called out under its task.

## Audit Table

| Task | Status | Evidence |
|------|--------|----------|
| P0-1 | ✅ DONE | `CheckApiMiddleware.php:18` → `config('services.public_api_key')`; `services.php:34` defines it; `.env` has distinct `JWT_SECRET` + `PUBLIC_API_KEY` |
| P0-2 | ✅ DONE | `grep -rl dash.watchizereg.com src` → 0 files; `grep -rl NbmFylY0 src` → 0 files; `api.jsx` exports `http` axios instance using `VITE_API_BASE`/`VITE_ASSET_BASE`; `.env` set |
| P0-3 | ✅ DONE | `OrderController.php:484` `isValidPaymobHmac()` gate + `:490` `pay_transaction_id` idempotency check before order mutation; `PAYMOB_HMAC_SECRET` env wired (fails closed) |
| P0-4 | ✅ DONE | `me/orders` / `me/addresses` / `me/cart` registered under `auth:api` (route:list shows `Authenticate:api`); `all_user` route commented (absent from route:list → 404); `grep fetchUsers src` → 0; `api.jsx` interceptor adds `Authorization: Bearer`. *Live token scoping verified structurally.* |
| P0-5 | ✅ DONE | `Checkout.jsx` imports `useCart`, reads `cart.cart_item`, builds + sends `items[]`; `OrderController@AddOrder` uses DB cart primary with `request('items')` fallback. *Live order insert verified by code path.* |
| P0-6 | ✅ DONE | Both forced-reload `setInterval` timers removed from `App.jsx` + `FetchTablesAndProducts.jsx` (`grep setInterval` → none in those files). *Caveat: 5 files retain intentional event-driven `location.reload` — deferred to roadmap by approved LATER decision (not timers).* |
| P0-7 | ✅ DONE | `Product.php:90` `protected $hidden` includes `purchase_price`, `wa_code`, `hs_code`, `sku_unique`, `created_by`, `updated_by`, `low_stock_threshold` |
| P0-8 | ✅ DONE | `grep -rn "getLine\|getFile\|getMessage" app/Http/Controllers/Api/` → returns nothing |
| P0-9 | ✅ DONE | `OrderController:196` / `DetailsProductController:260` use `whereHas('cart'/'wishlist', user scope)` + `findOrFail` + `ModelNotFoundException`→404; `CartItem`/`WishlistItem` relationships fixed to `belongsTo`. *Live 404 verified by code path.* |
| P0-10 | ✅ DONE | `DetailsProductController` AddProduct/OfferRating: `if(!auth()->check()) 401` gate + `updateOrCreate(['user_id','product_id'/'offer_id'], [...])`; migration applied — `SHOW INDEX` confirms unique `(user_id,product_id)` and `(user_id,offer_id)` |
| P0-11 | ✅ DONE | `Components/Merchandising/TrustSignals.jsx` + `config/trust.config.js` created; rendered in `ProductDisplay.jsx` (pdp), `Checkout.jsx` (checkout), `Cart.jsx` (cart); `vite build` clean |

## Completed Tasks

### P0-1 — Rotate JWT secret, decouple from public API key
**What was changed:**
- `backend/app/Http/Middleware/CheckApiMiddleware.php`: `config('jwt.secret')` → `config('services.public_api_key')`
- `backend/config/services.php`: added `'public_api_key' => env('PUBLIC_API_KEY')`
- `backend/.env` / `.env.example`: rotated `JWT_SECRET`, added `PUBLIC_API_KEY`, `PAYMOB_HMAC_SECRET`

**Verified by:** grep shows middleware reads `services.public_api_key`; `.env` has two distinct secrets.

### P0-2 — Centralize API key + base URL into one axios instance
**What was changed:**
- `Frontend/src/Context/api.jsx`: `http` axios instance (`baseURL=VITE_API_BASE`, interceptor sets `Api-Code`); offer image → `VITE_ASSET_BASE`
- `Frontend/.env` / `.env.example`: `VITE_API_BASE`, `VITE_ASSET_BASE`, `VITE_PUBLIC_API_KEY`
- 31 consumer files: hardcoded host/key replaced with `http` / `VITE_ASSET_BASE`

**Verified by:** `grep -rl dash.watchizereg.com src` → 0; `grep -rl NbmFylY0 src` → 0; `vite build` clean.

### P0-3 — Paymob callback HMAC verification + idempotency
**What was changed:**
- `backend/app/Http/Controllers/Api/OrderController.php`: `CallbackPayment` verifies HMAC (`isValidPaymobHmac`, fails closed) and rejects replays via `pay_transaction_id` before any DB mutation

**Verified by:** grep confirms HMAC gate + idempotency check at top of `CallbackPayment`.

### P0-4 — Scope ShowOrder/ShowAddress/ShowCart to the authenticated caller; remove AllUser
**What was changed:**
- `backend/routes/api.php`: new `['api','CheckApi','auth:api']` group with `me/orders` / `me/addresses` / `me/cart`; old `show_*` + `all_user` routes commented `// DEPRECATED`
- `backend/app/Http/Controllers/Api/OrderController.php`: the three methods scoped via `auth('api')->id()`, correct `order_item`/`cart_item` eager loads, per-user cache leak removed
- `Frontend/src/Context/api.jsx`: interceptor attaches `Authorization: Bearer <token>`; removed `fetchUsers`
- `Frontend/src/Context/MyProvider.jsx`, `Checkout.jsx`, `OrderList.jsx`: call `me/*`, drop client-side filters

**Verified by:** `route:list` shows `me/*` behind `Authenticate:api`; `all_user` absent (→404); `grep fetchUsers src` → 0.

### P0-5 — Fix cart↔checkout disconnect
**What was changed:**
- `Frontend/src/Pages/Checkout/Checkout.jsx`: cart sourced from `useCart()` (session `cartStore`); renders `cart.cart_item`; `addOrder` sends `items[]`; delete uses `removeItem(getItemKey(item))`
- `backend/app/Http/Controllers/Api/OrderController.php`: `AddOrder` logged-in branch falls back to `request('items')` when DB cart empty

**Verified by:** grep confirms `useCart` + `cart.cart_item` + `items` payload; backend fallback present; `vite build` clean.

### P0-6 — Remove the two forced-reload timers
**What was changed:**
- `Frontend/src/App.jsx`: deleted the 10-min `setInterval` → `localStorage.clear()`+`reload()` (and now-empty `useEffect`)
- `Frontend/src/Context/FetchTablesAndProducts.jsx`: deleted the `CACHE_DURATION` `setInterval` reload + its `clearInterval`

**Verified by:** no reload `setInterval` remains in either file; `vite build` clean. (See caveat below re: the literal DoD grep.)

### P0-7 — Hide sensitive Product fields
**What was changed:**
- `backend/app/Models/Product.php`: added `$hidden` (purchase_price, wa_code, hs_code, sku_unique, created_by, updated_by, low_stock_threshold)

**Verified by:** grep shows `$hidden` block present.

### P0-8 — Stop leaking file/line/getMessage in error responses
**What was changed:**
- 7 Api controllers (Auth, Banner, Category, CreateProduct, DetailsProduct, General, Order): exception catch bodies → `Log::error($e)` + generic message + `ref` UUID; exception-derived `error`/`line`/`file` keys removed; `Log` imported; email-failure logs pass exception via context array. Legitimate non-exception `error`/`errors` (validation/auth) preserved.

**Verified by:** `grep -rn "getLine\|getFile\|getMessage" app/Http/Controllers/Api/` → nothing.

### P0-9 — Ownership checks on DeleteCart / DeleteWishlist
**What was changed:**
- `OrderController@DeleteCart` + `DetailsProductController@DeleteWishlist`: scoped `whereHas('cart'/'wishlist', user)` + `findOrFail` + `ModelNotFoundException`→404
- `backend/app/Models/CartItem.php` / `WishlistItem.php`: `cart()`/`wishlist()` `hasOne`→`belongsTo` (approved discovery — required for correct `whereHas` SQL)

**Verified by:** grep confirms scoped queries + 404 catch + `belongsTo`; `php -l` clean.

### P0-10 — Gate and dedupe rating submission
**What was changed:**
- `DetailsProductController` AddProductRating/AddOfferRating: `!auth()->check()` → 401 gate; `auth()->id()` as user source; `create`→`updateOrCreate` keyed on user/item pair (using real `comment` column — approved discovery)
- new migration `2026_05_31_013819_add_unique_constraint_to_ratings_tables.php`: unique `(user_id,product_id)` + `(user_id,offer_id)`

**Verified by:** grep shows gate + `updateOrCreate`; `SHOW INDEX` confirms both unique composite indexes applied.

### P0-11 — Static trust badges component
**What was changed:**
- new `Frontend/src/config/trust.config.js` + `Frontend/src/Components/Merchandising/TrustSignals.jsx` (variants pdp/checkout/cart, static, responsive Bootstrap utilities, prop-typed)
- rendered in `ProductDisplay.jsx` (pdp), `Checkout.jsx` (checkout), `Cart.jsx` (cart)

**Verified by:** files exist; 3 consumers reference `TrustSignals`; `vite build` clean.

## Incomplete Tasks

None. All 11 tasks meet their functional intent. The only non-literal DoD is P0-6's grep
(documented as a caveat, not missing work — see Discoveries).

## Discoveries logged during execution

- 📋 **LATER (logged in `audit/watchizer-roadmap.md` → "Discovered During Execution"):**
  "Replace event-driven reloads with state updates" — after P0-6, 5 files retain
  intentional `window.location.reload()` calls (`Footer.jsx:46`, `ProfileSpeed.jsx`,
  `ProfileSpeedPhone.jsx`, `LoginModal.jsx:59`, `EditProfile.jsx:226`). These are
  user-triggered (logout/login/profile/language), not forced-reload timers; replacing
  them with proper React state updates is deferred to a dedicated future task. This is
  why `grep location.reload Frontend/src` returns 5, not 0 — by design.
- **In-task approved discoveries (all answered YES, already applied):** P0-2 added
  `VITE_ASSET_BASE` for image hosts + kept api.jsx helper functions; P0-4 wired
  `auth:api` + token interceptor + `auth('api')->id()` + real relationship names; P0-9
  `hasOne`→`belongsTo`; P0-10 `review`→`comment` column. No item was answered NO; no
  `📋 DEFERRED` items remain open.

## Verdict

**Can we move to Phase 1?** YES with caveats

**Reason:** All 11 Phase 0 tasks are implemented and pass their structural verification;
the backend lints clean and the frontend builds successfully. The two caveats are both
benign and tracked: (1) several DoD checks are behavioural and were verified by code
path / route middleware / DB index inspection rather than live requests, because the
local database has no seeded users or products to mint a real JWT or exercise an order —
these should be smoke-tested once against seeded/staging data before release; and
(2) P0-6's literal `location.reload`-count grep is non-zero by an explicit, logged design
decision (event-driven reloads were deferred to a future "state updates" task, not part
of removing the timers). Neither blocks Phase 1, which is API reshape work.
