# Watchizer — Execution Roadmap

> Generated: 2026-05-29
> Based on: `watchizer-audit.md` (initial) + second-pass findings (Section 15)
> Status: Planning complete. Ready for execution.

---

## How to read this file:

- Phases are ordered by priority and dependency.
- Each task has: **Why / File+Line / Exact change / Definition of Done / Effort / Blocks**.
- 🔴 = do today · 🟠 = do this week · 🟡 = do this sprint · 🟢 = backlog
- Tasks within a phase can be parallelized **unless** a dependency is stated.
- Mark tasks `[DONE]` as you complete them.
- All line numbers reference the repo state at audit time; re-grep if the file moved.

---

## PHASE 0 — Security & Stability (do before anything else)

### P0-1 — Rotate JWT secret and decouple it from the public API key 🔴

**Why:** The string in `CheckApiMiddleware` is `config('jwt.secret')` and it is shipped in the JS bundle — anyone can forge JWTs for any user.
**File:** `backend/app/Http/Middleware/CheckApiMiddleware.php` (`handle`), `backend/config/jwt.php`, `backend/.env`
**Exact change:**
- Add a new env var `PUBLIC_API_KEY` (fresh random 64 chars) distinct from `JWT_SECRET`.
- In `CheckApiMiddleware`, compare against `config('services.public_api_key')` instead of `config('jwt.secret')`.
- Run `php artisan jwt:secret` to rotate `JWT_SECRET` (invalidates existing tokens — acceptable).
**Definition of done:** `CheckApiMiddleware` no longer references `jwt.secret`; `grep -r "jwt.secret" app/Http/Middleware` returns nothing; existing JWTs are invalidated; API still authorizes with the new `PUBLIC_API_KEY`.
**Effort:** 2 h
**Blocks:** P0-2 (frontend must use the new key)

---

### P0-2 — Centralize the API key into one axios instance 🔴

**Why:** The key is copy-pasted in 26 files (NEW-18); rotation is impossible without one source.
**File:** `Frontend/src/Context/api.jsx` line 1–4 (create instance); 25 consumer files
**Exact change:**
- In `api.jsx`, create `export const http = axios.create({ baseURL: import.meta.env.VITE_API_BASE })` with a request interceptor injecting `config.headers["Api-Code"] = import.meta.env.VITE_PUBLIC_API_KEY`.
- Add `VITE_API_BASE` and `VITE_PUBLIC_API_KEY` to `Frontend/.env`.
- Replace every literal `"NbmFylY0...0"` and inline `axios.get/post` with `http.get/post`. Affected files include `Checkout.jsx:44,64`, `ProductDisplay.jsx:219`, `FetchTablesAndProducts.jsx:283`, `MyProvider.jsx:324`, `OfferDisplay.jsx`, `ProductSlider.jsx`, `Login.jsx`, `Register.jsx`, `Cart.jsx`, `WishList.jsx`, `OrderList.jsx`, `EditProfile.jsx`, `Blog*.jsx`, `ProfileSpeed*.jsx`, `SearchPageForPhone.jsx`, `LoginModal.jsx`.
**Definition of done:** `grep -rc "NbmFylY0" Frontend/src` returns 0; app boots and loads products with the key sourced from `.env`.
**Effort:** 0.5 d
**Blocks:** P1.5-4 (interceptor will also inject guest token)
**Depends on:** P0-1

---

### P0-3 — Verify Paymob callback HMAC signature 🔴

**Why:** `CallbackPayment` trusts `request->success` with no signature check and sits outside auth — anyone can mark any order paid (NEW-8/C9).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` line 440–469; `backend/routes/api.php` (last line, `callback_payment`)
**Exact change:**
- Add `PAYMOB_HMAC_SECRET` to `.env`.
- At the top of `CallbackPayment`, compute Paymob's HMAC over the documented ordered field set, compare to `request('hmac')`; on mismatch return `403` and do not touch the order.
- Make it idempotent: skip if a `PaymentStatus` for `pay_transaction_id` already exists.
**Definition of done:** A `curl` to `/api/callback_payment?merchant_order_id=X&success=true` **without** a valid `hmac` returns 403 and leaves the order status unchanged; a request with a correctly computed HMAC updates it.
**Effort:** 1 d
**Blocks:** nothing

---

### P0-4 — Scope `ShowOrder` / `ShowAddress` / `ShowCart` / `AllUser` to the caller 🔴

**Why:** Each returns the entire table; the frontend filters client-side, so the full order book + PII is one request away (NEW-7/C8, C2).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 91–99, 154–164, 474–481; `backend/app/Http/Controllers/Api/AuthController.php` lines 19–28; `backend/routes/api.php`
**Exact change:**
- Add new auth-scoped routes `GET /me/orders`, `GET /me/addresses`, `GET /me/cart` resolving identity from the JWT (or guest token after P1.5).
- Change `ShowOrder/ShowAddress/ShowCart` to `where('user_id', $authId)` and remove the cached `->get()` of all rows.
- Delete `all_user` from the public group (move to an admin-guarded route).
- Update callers: `Checkout.jsx:45` (`show_cart`) → `me/cart`; `Checkout.jsx:67` (`show_address`) → `me/addresses`; `OrderList.jsx` (`show_order`) → `me/orders`; remove `fetchUsers` from `MyProvider.jsx:70` and `api.jsx:6`.
**Definition of done:** `curl /api/show_order` (old) is gone or returns only the authed user's rows; the response body never contains another user's record; `grep "all_user" Frontend/src` returns 0.
**Effort:** 1.5 d
**Blocks:** P1.5-5 (merge needs scoped cart)

---

### P0-5 — Fix the cart↔checkout disconnect (hotfix) 🔴

**Why:** Items go to sessionStorage but checkout reads the never-populated server cart, so `AddOrder` returns "Cart is empty" — no order can complete (NEW-1/C7).
**File:** `Frontend/src/Pages/Checkout/Checkout.jsx` lines 37, 45–62, 186–243; `backend/app/Http/Controllers/Api/OrderController.php` lines 212–225
**Exact change:**
- In `Checkout.jsx`, replace the `show_cart` fetch (`:45-62`) with `const { cart } = useCart()` and render `cart.cart_item`.
- In `addOrder` (`:209`), add `items: cart.cart_item.map(i => ({ product_id:i.product_id, offer_id:i.offer_id, quantity:i.quantity, piece_price:i.piece_price, total_price:i.total_price, type_stock:i.type_stock, color_band:i.color_band, color_dial:i.color_dial }))`.
- In `AddOrder` backend, make the **logged-in** branch (`:212-218`) accept `items[]` exactly like the guest branch when the DB cart is empty.
**Definition of done:** A logged-in user adds 2 items, opens checkout, sees both in the summary, submits, and receives `{success:true, order_number}`; an `orders` row with 2 `order_items` exists.
**Effort:** 0.5 d
**Blocks:** P1.5 (durable cart supersedes this)

---

### P0-6 — Remove the two forced-reload timers 🔴

**Why:** Hard `window.location.reload()` every 10 min mid-browse/checkout destroys UX and can drop in-flight orders (C4).
**File:** `Frontend/src/App.jsx` lines 83–92; `Frontend/src/Context/FetchTablesAndProducts.jsx` lines 385–392
**Exact change:**
- Delete the `setInterval(... localStorage.clear(); window.location.reload() ...)` block in `App.jsx`.
- Delete the `setInterval(... window.location.reload() ...)` block in `FetchTablesAndProducts.jsx` (keep cache-write logic; let TTL expire naturally on next mount).
**Definition of done:** App left open 15 min does not reload; `grep -rn "location.reload" Frontend/src` returns only intentional callers (none expected).
**Effort:** 0.5 h
**Blocks:** nothing

---

### P0-7 — Add `$hidden` to the `Product` model 🔴

**Why:** No `$hidden` means `purchase_price` (cost), `wa_code`, `hs_code`, `created_by/updated_by`, `sku_unique` serialize to every client (NEW-9).
**File:** `backend/app/Models/Product.php` (after the `$fillable` block, ends line 88)
**Exact change:**
```php
protected $hidden = [
    'purchase_price', 'wa_code', 'hs_code', 'sku_unique',
    'created_by', 'updated_by', 'low_stock_threshold',
];
```
**Definition of done:** `curl /api/all_product` response contains no `purchase_price`/`wa_code`/`hs_code` keys.
**Effort:** 0.5 h
**Blocks:** superseded by P1-2 (ProductResource) but ship now as stopgap

---

### P0-8 — Stop leaking `file`/`line`/`getMessage` in error responses 🔴

**Why:** `AddOrder` returns absolute server paths in JSON regardless of `APP_DEBUG` (NEW-11).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 313–322 (and other catch blocks echoing `$e->getMessage()`)
**Exact change:**
- Replace the catch body with `Log::error($e); return response()->json(['success'=>false,'message'=>'Error placing order','ref'=>$ref],500);` where `$ref = Str::uuid()`.
- Remove `'error'`, `'line'`, `'file'` from all API JSON responses (grep `getLine\|getFile\|getMessage` in `app/Http/Controllers/Api`).
**Definition of done:** Forcing an exception in checkout returns a generic message + `ref`, no path; full detail appears in `storage/logs`.
**Effort:** 2 h
**Blocks:** nothing

---

### P0-9 — Add ownership checks to `DeleteCart` / `DeleteWishlist` 🟠

**Why:** Both delete any row by id with no owner check (NEW-6 IDOR).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 166–174; `backend/app/Http/Controllers/Api/DetailsProductController.php` lines 244–266
**Exact change:**
- `CartItem::whereHas('cart', fn($q)=>$q->where('user_id',$authId))->findOrFail($id)->delete();`
- Same pattern for `WishlistItem` via its `wishlist`.
**Definition of done:** Deleting another user's cart-item id returns 404; deleting your own returns 200.
**Effort:** 2 h
**Blocks:** nothing

---

### P0-10 — Gate and dedupe rating submission 🟠

**Why:** `AddProductRating`/`AddOfferRating` accept unauthenticated, unlimited ratings → review bombing (NEW-10).
**File:** `backend/app/Http/Controllers/Api/DetailsProductController.php` lines 84–118, 160–194
**Exact change:**
- Require auth; take `user_id` from the token, not the body.
- Add unique `(user_id, product_id)` / `(user_id, offer_id)` constraint via migration; switch `create` → `updateOrCreate`.
**Definition of done:** A second rating from the same user updates rather than inserts; an unauthenticated POST returns 401.
**Effort:** 0.5 d
**Blocks:** nothing

---

### P0-11 — Add static trust badges 🟠

**Why:** Zero trust signals today vs competitor's strong stack; static badges are zero-risk and close the biggest UX gap immediately (NEW / §15.3-4).
**File:** new `Frontend/src/Components/Merchandising/TrustSignals.jsx`; mount in `ProductDisplay.jsx`, `CartModal.jsx`, `Checkout.jsx`
**Exact change:**
- Create component rendering: 14-day returns, authenticity guarantee, secure-checkout, WhatsApp CTA (`wa.me/201551096234`), payment icons (InstaPay/Vodafone Cash/COD/card). Content from a local `trust.config.js`.
- Render `<TrustSignals variant="pdp" />` in the PDP sidebar, `variant="checkout"` in the checkout header.
**Definition of done:** Badges visible on PDP and checkout on mobile + desktop; no API calls added.
**Effort:** 1 d
**Blocks:** nothing

---

## PHASE 1 — Backend API Reshape

> Goal: server owns filtering/shaping/pagination; client stops downloading the full catalog (C5) and stops doing DB joins in JS.

### P1-1 — Create paginated, filtered product listing endpoint 🟠

**Why:** `all_product` returns the whole table (incl. inactive — NEW-14); the client filters/paginates in memory (`Listing.jsx:87-144`).
**File:** new `App\Http\Controllers\Api\ProductCatalogController@index`; `backend/routes/api.php`
**Exact change — route:**
```php
Route::get('products', [ProductCatalogController::class, 'index']);
```
**Request params:**
```
page (int, default 1), per_page (int, default 20, max 60),
brand_id[] (int), sub_type_id[] (int), category (Watches|Fashion),
grade_id[] (int), min_price (int), max_price (int),
sort (newest|price_asc|price_desc|rating), q (string), lang (en|ar)
```
**Response shape:**
```jsonc
{
  "data": [{
    "id": 142, "slug": "tommy-hilfiger-chrono-1791",
    "title": "Tommy Hilfiger Chronograph", "brand": "Tommy Hilfiger",
    "image": "https://cdn.watchizereg.com/142/card.avif",
    "image_srcset": "…320w, …640w, …960w",
    "price": { "was": 4500, "now": 3990, "discount_pct": 11, "currency": "EGP" },
    "in_stock": true, "rating": 4.6, "rating_count": 23, "is_new": false
  }],
  "meta": { "page": 1, "per_page": 20, "total": 184, "last_page": 10 },
  "facets": { "brands": [{ "id": 3, "name": "Tommy Hilfiger", "count": 22 }],
              "price_range": { "min": 900, "max": 5800 } }
}
```
- Query: `Product::where('active',1)->with('translations','brand')->when(...filters)->orderBy(...)->paginate(...)`.
**Frontend to update:** `Listing.jsx` (remove client filter `:87-118` + slice `:137-144`; call `/products` with query), `ListingGrades.jsx`, `ListingSearch.jsx`, `Listingoffers.jsx`.
**Definition of done:** `curl '/api/products?brand_id[]=3&min_price=1000&sort=price_asc&page=2'` returns ≤20 shaped items + correct `meta`; no inactive products present; `Listing.jsx` renders from it with working pagination.
**Effort:** 2 d
**Blocks:** P1.5 (cart needs shaped product), P5-3 (recommendations reuse it)
**Depends on:** P0-6

---

### P1-2 — Wrap listing output in `ProductListResource` 🟠

**Why:** Raw models leak schema fields; need an allow-list (NEW-9 done properly).
**File:** new `app/Http/Resources/ProductListResource.php`
**Expose (exact):** `id, slug, title, brand, image, image_srcset, price{was,now,discount_pct,currency}, in_stock, rating, rating_count, is_new`
**Hide (exact):** `purchase_price, wa_code, hs_code, sku_unique, created_by, updated_by, low_stock_threshold, model_number, extra_attributes, *_id` raw FKs, `search_keywords`, `market_stock`, `selling_price` (collapsed into `price`)
**Definition of done:** Listing JSON contains exactly the exposed keys and none of the hidden ones.
**Effort:** 0.5 d
**Depends on:** P1-1

---

### P1-3 — Create dedicated PDP endpoint with `ProductDetailResource` 🟠

**Why:** PDP currently relies on the full-catalog dump + re-fetches all ratings (`ProductDisplay.jsx:213-228`).
**File:** new `ProductCatalogController@show`; new `app/Http/Resources/ProductDetailResource.php`; `routes/api.php`
**Route:** `Route::get('products/{slug}', [ProductCatalogController::class, 'show']);`
**Response shape:** all `ProductListResource` fields **plus**
```jsonc
{
  "images": ["https://cdn.../142/1.avif", "…"],
  "descriptions": { "short": "…", "long": "…" },
  "specs": { "movement": "Quartz", "case_material": "Steel", "water_resistance": "50m", "...": "..." },
  "colors": { "dial": [{ "id":1,"name":"Black","hex":"#000" }], "band": [ ... ] },
  "features": ["Chronograph","Date"],
  "availability": { "express": 4, "market": 0 },
  "seo": { "title": "…", "meta_description": "…" },
  "related": [ /* 6 × ProductListResource */ ]
}
```
**Frontend to update:** `ProductDisplay.jsx` — fetch `/products/{slug}` once; delete `fetchRatings` (`:213-228`) and the client-side `related` filter (`:238-248`); use `related` from the payload.
**Definition of done:** Opening a PDP fires exactly **one** product request (verified in Network tab); ratings + related render from it.
**Effort:** 1.5 d
**Depends on:** P1-1, P1-2

---

### P1-4 — Collapse 11 lookup-table calls into one `catalog/meta` endpoint 🟠

**Why:** `FetchTablesAndProducts.jsx:286-316` makes 11 parallel calls then joins in JS for both languages (`:343-360`).
**File:** new `ProductCatalogController@meta`; `routes/api.php`
**Route:** `Route::get('catalog/meta', [ProductCatalogController::class, 'meta']);` (params: `lang`)
**Response shape:**
```jsonc
{ "brands": [{ "id":3, "name":"Tommy Hilfiger", "slug":"tommy-hilfiger", "product_count":22, "logo":"…" }],
  "sub_types": [{ "id":7, "name":"Chronograph", "slug":"chronograph", "product_count":40 }],
  "grades": [...], "categories": [...] }
```
- Cache 30 min; return names already localized for `lang`.
**Frontend to update:** `FetchTablesAndProducts.jsx` (replace 11 calls + transforms), `CategoryNav.jsx` (consume localized names — also fixes NEW-15).
**Definition of done:** Homepage cold load makes 1 meta call instead of 11; nav renders from it; no `.find(locale==='en')` left in `CategoryNav.jsx`.
**Effort:** 1.5 d
**Blocks:** P5-1, P5-2 (tiles/megamenu feed from this)

---

### P1-5 — Add missing DB indexes 🟠

**Why:** Price filter + `active` filter + search will full-scan once moved server-side (NEW-12). FK columns are already indexed.
**File:** new migration `database/migrations/XXXX_add_catalog_indexes.php`
**Exact change:**
```php
Schema::table('products', function (Blueprint $t) {
    $t->index(['active','sale_price_after_discount'], 'idx_active_price');
    $t->index(['active','created_at'], 'idx_active_created');
    $t->fullText('search_keywords', 'idx_search');
});
// verify main_category_id/sub_category_id/product_type_id are indexed; add if not
```
**Definition of done:** `EXPLAIN` on the P1-1 listing query with a price filter shows `idx_active_price` used, not a full scan.
**Effort:** 2 h
**Depends on:** none (can run before P1-1, recommended)

---

### P1-6 — Fix order-number generation 🟠

**Why:** String `max()+1` collides at 10,000 orders and races under concurrency (NEW-2).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 228–231
**Exact change:**
- Replace with `selectRaw('MAX(CAST(order_number AS UNSIGNED)) as m')` inside the existing transaction with `->lockForUpdate()`, or add an auto-increment numeric column and format on display.
**Definition of done:** Seeding 10,001 orders yields strictly increasing unique numbers; two concurrent `AddOrder` calls (parallel curl) produce different `order_number`s.
**Effort:** 0.5 d
**Depends on:** none

---

### P1-7 — Lock stock on decrement + restore on failed/abandoned payment 🟠

**Why:** Read-modify-write without lock oversells (NEW-3); abandoned Paymob permanently destroys stock (NEW-4).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 262–283 (decrement), 358–435 (paymob), 440–469 (callback)
**Exact change:**
- Decrement atomically: `Product::where('id',$id)->where($field,'>=',$qty)->decrement($field,$qty)`; if 0 rows affected → rollback "insufficient stock".
- For `paymob`: decrement **only after** verified-success callback, OR add a `RestoreStock` step on session-creation failure, on `cancelled` callback, and a job expiring `pending` paymob orders after 30 min.
**Definition of done:** Two concurrent orders for the last unit → one succeeds, one gets "insufficient stock"; abandoning a Paymob checkout returns stock within the expiry window.
**Effort:** 1 d
**Depends on:** P0-3 (callback must be trustworthy first)

---

### P1-8 — Queue order emails 🟠

**Why:** 6 synchronous `Mail::send` calls block the checkout response (NEW-5).
**File:** `backend/app/Http/Controllers/Api/OrderController.php` lines 25–31 (admin list), 328–353 (`sendOrderEmails`)
**Exact change:**
- `Mail::to($x)->queue(new OrderCreatedMail(...))`; ensure `OrderCreatedMail implements ShouldQueue`.
- Move `$adminEmails` to `config/order.php` / DB.
- Configure a queue connection + worker (`php artisan queue:work`).
**Definition of done:** `AddOrder` returns in <300 ms with mail server stopped; emails appear after the worker runs.
**Effort:** 0.5 d (+ infra if no queue exists)
**Depends on:** none

---

### P1-9 — Handle money as integer minor units end-to-end 🟡

**Why:** `parseInt`/`Math.floor` truncate decimals and backend validates `integer`, so `.99` prices are lost (NEW-16).
**File:** `Frontend/src/Components/Product/ProductDisplay.jsx` lines 73–74; `Frontend/src/Pages/Checkout/Checkout.jsx` lines 187–193; `backend/.../OrderController.php` lines 112–113, 187
**Exact change:**
- Store/transmit prices as integer **piastres** (EGP × 100) or switch backend validation to `numeric` and stop `parseInt`/`floor` on the client.
- Server recomputes authoritative totals from `items` (also closes a price-tampering hole) — do not trust the client `total_price_for_order`.
**Definition of done:** A product priced `3990.50` checks out at the correct total; backend rejects a client-tampered total.
**Effort:** 1 d
**Depends on:** P0-5

---

## PHASE 1.5 — Guest Cart

> Resolves NEW-1 durably + NEW-13. The backend `AddOrder` already has a guest `items[]` branch (`OrderController.php:185-225`); this adds a **persistent** guest cart, identity middleware, merge-on-login, cleanup, and UI.

### P1.5-1 — Migration: make carts guest-capable 🟠

**File:** new migration `XXXX_add_guest_support_to_carts.php`
**Exact change:**
```php
Schema::table('carts', function (Blueprint $t) {
    $t->foreignId('user_id')->nullable()->change();
    $t->char('guest_token', 36)->nullable()->unique()->after('user_id');
    $t->timestamp('expires_at')->nullable()->after('updated_at');
});
Schema::table('cart_items', function (Blueprint $t) {
    $t->unique(['cart_id','product_id','offer_id','color_band','color_dial','type_stock'], 'uniq_cart_line');
});
```
**Definition of done:** A cart row can exist with `user_id NULL` + `guest_token` set; duplicate identical cart lines are rejected by `uniq_cart_line`.
**Effort:** 3 h
**Blocks:** P1.5-2..5

---

### P1.5-2 — `GuestCartMiddleware` 🟠

**File:** new `app/Http/Middleware/GuestCartMiddleware.php`; register in `routes/api.php` for cart/order routes
**Pseudocode:**
```text
handle(request):
    if valid Bearer JWT:        request->identity = ['user_id' => jwt.sub]
    elseif X-Guest-Token header: request->identity = ['guest_token' => header]
    else:
        token = (string) Str::uuid()
        request->identity = ['guest_token' => token]
        response->header('X-Guest-Token', token)   // client stores it
    return next(request)
```
**Definition of done:** A cart request with no auth and no token comes back with an `X-Guest-Token` response header; with the header, the same cart is resolved.
**Effort:** 0.5 d
**Depends on:** P1.5-1

---

### P1.5-3 — `resolveCart` + cart write/read endpoints 🟠

**File:** `OrderController` (or new `CartController`) `AddToCart` (`:104-152`), `me/cart` (P0-4)
**Exact change:**
```text
resolveCart(identity):
    return Cart::firstOrCreate(identity, ['expires_at' => identity.user_id ? null : now()->addDays(7)])
```
- `AddToCart` uses `resolveCart($request->identity)` instead of `Cart::firstOrCreate(['user_id'=>...])`; drop the `user_id exists` validation rule.
**Definition of done:** A guest can `POST /add_to_cart` with only `X-Guest-Token` and read it back from `GET /me/cart`.
**Effort:** 0.5 d
**Depends on:** P1.5-2, P0-4

---

### P1.5-4 — Frontend guest token + interceptor 🟠

**File:** `Frontend/src/Context/api.jsx` (the instance from P0-2); `Frontend/src/Store/cartStore.js`
**Exact change:**
- On first cart write with no JWT: `localStorage.setItem("wz_guest_token", crypto.randomUUID())` if absent.
- Interceptor: `if jwt → Authorization: Bearer; else → X-Guest-Token: localStorage["wz_guest_token"]`.
- Capture `X-Guest-Token` from responses and persist it.
- Point `cartStore` writes at `/add_to_cart` (server) with the client mirror as optimistic state.
**Definition of done:** Add to cart as guest → refresh page → cart persists (served from DB, not sessionStorage).
**Effort:** 1 d
**Depends on:** P0-2, P1.5-3

---

### P1.5-5 — `MergeGuestCart` on login/register 🟠

**File:** new `app/Services/MergeGuestCart.php`; called in `AuthController@login` and `@register`
**Pseudocode:**
```text
merge(user_id, guest_token):
    DB::transaction:
        guest = Cart::where('guest_token',guest_token)->with('cart_item')->first(); if none: return
        user  = Cart::firstOrCreate(['user_id'=>user_id])
        foreach guest.cart_item as gi:
            user.cart_item()->updateOrCreate(
                {product_id,offer_id,color_band,color_dial,type_stock},
                {quantity: existing+gi.quantity, piece_price: gi.piece_price})
        guest.cart_item()->delete(); guest->delete()
```
- Frontend: after successful login/register, send the guest token, then `localStorage.removeItem("wz_guest_token")` and refetch `/me/cart`.
**Definition of done:** Guest adds 2 items → logs in → those items appear in the user cart; the guest cart row is gone; `wz_guest_token` cleared.
**Effort:** 0.5 d
**Depends on:** P1.5-3, P0-4

---

### P1.5-6 — Cleanup job for abandoned guest carts 🟡

**File:** new `app/Console/Commands/PruneGuestCarts.php`; schedule in `app/Console/Kernel.php`
**Exact change:**
```php
Cart::whereNotNull('guest_token')->where('expires_at','<',now())
    ->each(fn($c)=>tap($c)->cart_item()->delete()->delete());
// $schedule->command('carts:prune')->daily();
```
**Definition of done:** `php artisan carts:prune` deletes guest carts with `expires_at` in the past; user carts untouched.
**Effort:** 3 h
**Depends on:** P1.5-1

---

### P1.5-7 — Guest vs logged-in UI 🟡

**File:** `Frontend/src/Pages/Checkout/Checkout.jsx`; cart badge in `Header.jsx`
**Exact change:**
- When no JWT: show `guest_name`/`guest_phone`/`guest_email` fields and send them in `addOrder`; CTA copy "Checkout as guest" + secondary "Login to save your cart".
- Cart badge counts from the (now server-backed) `useCart()` for both states.
**Definition of done:** A logged-out user completes a full order with guest fields; logged-in user sees saved addresses instead.
**Effort:** 0.5 d
**Depends on:** P1.5-4, P0-5

---

## PHASE 2 — SSR / SEO Foundation

### P2-1 — Choose and scaffold the rendering target 🟠

**Decision:** **Next.js (App Router)**. Justification: the catalog is large and SEO-critical (Section 6); App Router gives per-route SSR/ISR + streaming, file-based metadata, and built-in `next/image` (covers Phase 4 image work). Remix is viable but `next/image` + ISR caching for a mostly-static catalog is the better fit; Vite-prerender can't do per-product ISR cleanly.
**File:** new Next.js app (migrate `Frontend/src` routes incrementally), starting with PDP + listing.
**Exact change:** scaffold `app/`, port `App.jsx` routes (`:257-422`) to App Router segments; render PDP/listing server-side fetching the P1-1/P1-3 endpoints.
**Definition of done:** `curl` of a PDP returns product title/price/description in the initial HTML (not an empty `#root`).
**Effort:** 1.5–2 weeks (incremental)
**Depends on:** P1-1, P1-3

---

### P2-2 — Per-route metadata templates 🟠

**File:** Next.js `generateMetadata` per segment (replaces the single `App.jsx:106-210` Helmet)
**Exact templates:**
```
Home:     "Watchizer | Luxury Watches & Accessories in Egypt"
Listing:  "{Category} Watches | {brand?} | Watchizer" + count in description
PDP:      "{product.title} – {brand} | Watchizer"  desc = product.descriptions.short (≤160)
Brand:    "{Brand} Watches in Egypt | Watchizer"
Category: "{Category} | Watchizer"
```
- Per-page `canonical` = the page's own URL (fixes the site-wide homepage canonical at `App.jsx:124`).
**Definition of done:** Each route type returns a unique `<title>`, `<meta description>`, and self-referencing `<link rel=canonical>`.
**Effort:** 1 d
**Depends on:** P2-1

---

### P2-3 — Product + Breadcrumb JSON-LD 🟠

**File:** PDP segment; listing/category segments
**Exact blocks:**
```jsonc
// Product (PDP)
{ "@context":"https://schema.org","@type":"Product","name":"{title}","brand":{"@type":"Brand","name":"{brand}"},
  "image":["{images}"],"sku":"{id}","description":"{short}",
  "offers":{"@type":"Offer","priceCurrency":"EGP","price":"{now}","availability":"{in_stock?InStock:OutOfStock}","url":"{url}"},
  "aggregateRating":{"@type":"AggregateRating","ratingValue":"{rating}","reviewCount":"{rating_count}"} }
// BreadcrumbList (PDP + category)
{ "@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
  {"@type":"ListItem","position":1,"name":"Home","item":"https://watchizereg.com/"},
  {"@type":"ListItem","position":2,"name":"{category}","item":"{categoryUrl}"},
  {"@type":"ListItem","position":3,"name":"{title}","item":"{url}"}]}
```
- Only emit `aggregateRating` when `rating_count > 0`.
**Definition of done:** Google Rich Results Test passes for Product + Breadcrumb on a live PDP.
**Effort:** 0.5 d
**Depends on:** P2-1, P1-3

---

### P2-4 — `robots.txt` 🟠

**File:** `public/robots.txt`
**Exact content:**
```
User-agent: *
Allow: /
Disallow: /cart
Disallow: /checkout
Disallow: /edit-profile
Disallow: /order-list
Disallow: /login
Disallow: /register
Sitemap: https://watchizereg.com/sitemap.xml
```
**Definition of done:** `curl https://watchizereg.com/robots.txt` returns the above.
**Effort:** 0.5 h
**Depends on:** none

---

### P2-5 — Dynamic `sitemap.xml` 🟠

**File:** Next.js `app/sitemap.ts` (or backend `GET /sitemap.xml`)
**Exact change:** enumerate home, all category/brand/subtype URLs, all active product slugs, blog posts; include `<lastmod>` from `updated_at`. Replace the client-only `src/scripts/SitemapGenerator.jsx`.
**Definition of done:** `curl /sitemap.xml` lists every active product URL with `lastmod`; submitted in Search Console without errors.
**Effort:** 0.5 d
**Depends on:** P1-1

---

### P2-6 — `hreflang` for AR/EN 🟡

**File:** Next.js i18n routing (`/en/...`, `/ar/...`) replacing the client `language` toggle (`MyProvider.jsx`)
**Exact change:** localized URL segments; emit `<link rel="alternate" hreflang="en" …>` + `hreflang="ar"` + `x-default` per page.
**Definition of done:** An EN PDP carries `hreflang=ar` pointing to its Arabic URL and vice-versa; both are crawlable.
**Effort:** 2 d
**Depends on:** P2-1

---

## PHASE 3 — Frontend Architecture Cleanup

### P3-1 — Split the god-context 🟠

**Why:** `MyProvider.jsx` holds ~40 values in one memo (`:372-418`); any change re-renders all consumers.
**File:** `Frontend/src/Context/MyProvider.jsx`
**Exact split:**
| State | Destination |
|---|---|
| `products`, `tables`, `offers`, `ratings`, `shippingPrices`, `sideBanners`, `bottomBanners`, `HomeBanners*` | **TanStack Query** (server cache, replaces P1-1/P1-4 calls) |
| `cart`, `wishList`, `productsCount`, `WishListCount`, `total_cart_price` | **cart/wishlist store** (already `useCart`; add wishlist store) |
| `language`, `windowWidth`(→delete, P3-2), `filters`, `gradesfilters`, `offersfilters`, `currentPage` | **Zustand UI store** |
| `openAlert`, `alertType`, `alertMessage`, `Loader` | **local** / small toast store |
| `user_id`, `users`(→delete) | **auth store** |
**Definition of done:** Resizing the window or firing an alert no longer re-renders product lists (verify with React DevTools Profiler); `MyProvider` no longer wraps the whole tree in one memo.
**Effort:** 3 d
**Depends on:** P1-1, P1-4 (server data moves to Query)

---

### P3-2 — Remove `windowWidth` JS branching 🟠

**Why:** JS-decided desktop/mobile causes CLS, re-render storms, duplicate trees.
**File:** every `windowWidth` user — `App.jsx:95-98,285,379,423`, `MyProvider.jsx:351-371`, `Checkout.jsx:248`, `Home.jsx:19-25,68`, plus `Cart`/`PhoneCart`, `WishList`/`PhoneWishList`, `ProfileSpeed`/`ProfileSpeedPhone`
**Exact change:** replace each width branch with CSS (`@media (min-width:768px)`), `display:none` toggles, or container queries. Render one component; CSS hides/show variants.
**Definition of done:** `grep -rn "windowWidth" Frontend/src` returns 0; layout switches at 768px with no JS and no flash.
**Effort:** 2 d
**Depends on:** P3-3 (merge the duplicate components first)

---

### P3-3 — Merge duplicate phone/desktop components 🟡

**File (delete after merge):** `Frontend/src/Pages/Cart/PhoneCart.jsx`, `Frontend/src/Pages/WishList/PhoneWishList.jsx`, `Frontend/src/Components/Header/Nav/ProfileSpeedPhone.jsx`
**Exact change:** fold each phone variant into its desktop sibling using responsive CSS; update routes in `App.jsx:284-285,378-389` to a single component.
**Definition of done:** The three `Phone*` files are deleted; cart/wishlist/profile work on both breakpoints.
**Effort:** 1.5 d
**Depends on:** none (enables P3-2)

---

### P3-4 — Delete dead code 🟢

**File + ranges:** `MyProvider.jsx:124-139,338-349` (commented blocks); `useCart.jsx:28-189` (commented legacy hook); `ProductDisplay.jsx:134-179` (commented handler); `Home.jsx:37-39` (empty effect); `api.jsx` commented logs; dedupe `getItemKey` (`useCart.jsx:4` imports from `cartStore.js:6` — keep one).
**Definition of done:** Listed ranges removed; `npm run build` succeeds; `getItemKey` defined once.
**Effort:** 2 h
**Depends on:** none

---

### P3-5 — Remove Bootstrap; standardize on MUI + tokens 🟠

**Decision:** keep **MUI** (already deep in `Checkout`, dialogs, snackbars), remove **Bootstrap** (`main.jsx:5-6`). Rationale: removing the framework with the smaller blast radius first; Bootstrap is mostly grid/utility classes replaceable by MUI `Grid`/`Box` + a tokens layer.
**File:** `Frontend/src/main.jsx:5-6` (imports); every `className="row/col-*/d-*/p-*"` usage
**Exact change:** migrate Bootstrap grid/utility classes to MUI `Grid2`/`Stack`/`sx` (or a small utility CSS), then remove the `bootstrap` import + dependency.
**Migration order:** Footer → Header → Home → Listing → PDP → Cart/Checkout.
**Definition of done:** `bootstrap` removed from `package.json`; `grep -rn "bootstrap" Frontend/src` returns 0; no visual regressions on the migrated pages.
**Effort:** 4 d
**Depends on:** P3-1 (do after state is stable)

---

## PHASE 4 — Performance & Media

### P4-1 — Add intrinsic dimensions to all `<img>` 🟠

**Why:** Missing width/height causes CLS.
**File + line:** `Home.jsx:137-143` (bottom banners — `<img>` has style but no `width`/`height` attrs), `Home.jsx:76-85` (LazyLoadImage — set explicit `width`/`height`), any `<img>` in `ProductSlider.jsx`, `OfferSlider.jsx`, `Footer.jsx`
**Exact change:** add concrete `width`/`height` (or `aspect-ratio` + sized container) to every `<img>`/`LazyLoadImage`.
**Definition of done:** Lighthouse CLS < 0.1 on home + listing; no image causes layout shift in the Performance panel.
**Effort:** 0.5 d
**Depends on:** none

---

### P4-2 — Mark and preload the LCP image 🟠

**Why:** Hero slider image is the LCP element with no priority hint.
**File:** `Frontend/src/Pages/Home/HomeSlider.jsx` (first slide `<img>`); document `<head>` (or Next metadata after P2-1)
**Exact change:** first slide image gets `fetchpriority="high"` and `loading="eager"` (not lazy); add `<link rel="preload" as="image" href="{firstBanner}">`.
**Definition of done:** Lighthouse "Largest Contentful Paint element" is the hero and LCP improves vs baseline; preload present in `<head>`.
**Effort:** 0.5 d
**Depends on:** none (revisit under P2-1)

---

### P4-3 — Fix font loading 🟠

**Why:** The `media="print" onLoad="this.media='all'"` trick via Helmet (`App.jsx:148-153`) is unreliable and can FOUT/never-apply.
**File:** `App.jsx:136-153` (or Next `app/layout` + `next/font`)
**Exact change:** self-host Dosis + Lato (or `next/font/google`) with `font-display: swap`; preload the two woff2 weights actually used; remove the print-media hack and the duplicate preload+stylesheet pair.
**Definition of done:** No FOIT; fonts load via `next/font` (or preloaded woff2); no `media="print"` font link remains.
**Effort:** 0.5 d
**Depends on:** none

---

### P4-4 — Remove the legacy plugin 🟡

**Why:** `@vitejs/plugin-legacy` ships legacy + polyfill bundles unneeded for the `browserslist` (`not ie <= 11`).
**File:** `Frontend/vite.config.js:19-22` (and `main.jsx:9` polyfill import)
**Exact change:** remove the `legacy({...})` plugin and `react-app-polyfill` import; drop the deps.
**Definition of done:** Build emits no `*-legacy.js` chunks; bundle size drops (record before/after); app still loads on target browsers.
**Effort:** 2 h
**Depends on:** P2-1 if migrating to Next (Vite config goes away)

---

### P4-5 — Route images through a transform CDN 🟠

**Why:** Catalog images are raw origin files (no resize, no AVIF/WebP negotiation) — `FetchTablesAndProducts.jsx:140,149`, `Home.jsx:77,139`.
**Service:** Cloudflare Images / imgproxy / Bunny in front of `dash.watchizereg.com/Uploads_Images`.
**File:** image URL builder (centralize), the P1-2/P1-3 resources emit `image` + `image_srcset` pointing at the CDN; `next/image` consumes them.
**Exact change:** resources return CDN URLs with width variants (`?w=320/640/960&format=auto`); frontend uses `srcset`/`next/image`.
**Definition of done:** PDP/listing images served as AVIF/WebP at device-appropriate widths; total image bytes on listing drop materially vs baseline.
**Effort:** 1.5 d
**Depends on:** P1-2, P1-3

---

## PHASE 5 — Merchandising & Luxury UX

### P5-1 — `CategoryTiles` component 🟡

**File:** new `Frontend/src/Components/Merchandising/CategoryTiles.jsx`; mount in `Home.jsx` directly under the hero (`:69`)
**Props interface:**
```ts
{ tiles: Array<{ id:number; label:string; image:string; count:number; to:string }> }
```
**Data source:** `GET /catalog/meta` (P1-4) → top categories/subtypes with `product_count` + tile image.
**CSS Grid spec:** `grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:16px;` tile `aspect-ratio:4/5`; label+count in a bottom gradient overlay; link to `/subtypes/{slug}`.
**Definition of done:** Homepage shows category tiles under the hero; each links to a filtered listing; counts match the API.
**Effort:** 1 d
**Depends on:** P1-4

---

### P5-2 — `MegaMenu` component (replaces `CategoryNav`) 🟡

**File:** new `Frontend/src/Components/Header/MegaMenu.jsx`; replaces `CategoryNav.jsx`
**Trigger:** hover **and** keyboard focus on a top-nav item.
**Columns:** left = subtypes with thumbnails; right = featured brands with logos; bottom strip = `in_season` offers.
**Data source:** `GET /catalog/meta` (P1-4) + `GET /offers?in_season=yes`.
**Mobile fallback:** accordion drawer (reuse `CategoryNavPhone.jsx` pattern).
**Definition of done:** Mega-menu opens on hover/focus with images; no `.find(locale==='en').x` crash (NEW-15 gone); mobile shows the accordion.
**Effort:** 2 d
**Depends on:** P1-4

---

### P5-3 — `RecommendationsRow` component 🟡

**File:** new `Frontend/src/Components/Product/RecommendationsRow.jsx`; mount in `ProductDisplay.jsx` below product info, above reviews; replaces the `:238-248` filter
**Query/params:** uses `related[]` from the PDP DTO (P1-3), or `GET /products?related_to={id}&limit=6` (same sub_type OR brand OR main_category, exclude self, in stock).
**Layout/scroll:** horizontal scroll-snap on mobile, 4-col grid ≥768px; reuse the listing `ProductCard`.
**Definition of done:** Every in-catalog PDP shows ≥1 recommendation (no longer empty); cards link correctly; drop the color-overlap gate.
**Effort:** 1 d
**Depends on:** P1-3

---

### P5-4 — Trust layer everywhere 🟡

**File:** `TrustSignals.jsx` (from P0-11); add `variant="cart"` to `CartModal.jsx`
**Exact badges:** 14-day returns · authenticity guarantee · 100% secure checkout · WhatsApp CTA · review count + stars · low-stock/"sold X" urgency · payment icons (InstaPay/Vodafone Cash/COD/card).
**Placement:** PDP sidebar (P0-11), cart drawer (`CartModal.jsx`), checkout header (P0-11).
**Definition of done:** All three surfaces render the badge set; review count + sold-count read from the product DTO.
**Effort:** 0.5 d (extends P0-11)
**Depends on:** P0-11, P1-3 (for live counts)

---

### P5-5 — Replace AOS with Framer Motion 🟡

**File:** `main.jsx:7-8` (AOS imports); every `data-aos="..."` attribute
**Exact mapping:** `data-aos="fade-up"` → `<motion.div initial={{opacity:0,y:24}} whileInView={{opacity:1,y:0}} viewport={{once:true}} transition={{duration:.5}}>`; `fade` → opacity only; `zoom-in` → `scale`.
**Definition of done:** AOS removed from `package.json`; `grep -rn "data-aos\|aos" Frontend/src` returns 0; scroll reveals run at 60fps (no layout thrash in Performance panel).
**Effort:** 1.5 d
**Depends on:** P3-5 (do during design-system pass)

---

### P5-6 — Typography 🟡

**Current:** Dosis + Lato via Google Fonts (`App.jsx:146`).
**Proposed:** keep Lato for body; pair a higher-contrast serif/display for headings (e.g. Playfair Display or Cormorant) for luxury feel; load via `next/font` (P4-3).
**File:** font config (P4-3) + a `theme` typography scale (MUI `createTheme`)
**Type scale:** `h1 2.5rem/1.1`, `h2 2rem`, `h3 1.5rem`, `body 1rem/1.6`, `caption .875rem`; heading font = display, body = Lato.
**Definition of done:** Headings render in the display face site-wide via the theme; one place controls the scale.
**Effort:** 0.5 d
**Depends on:** P3-5, P4-3

---

### P5-7 — Luxury design tokens 🟡

**File:** new `Frontend/src/theme/tokens.js` consumed by the MUI theme
**Proposed palette:** `--ink:#111111`, `--charcoal:#262626` (existing `bg-most-used`), `--gold:#C8A45C`, `--bone:#F7F5F1`, `--line:#E6E1D8`, `--success/#2E7D32`, `--danger/#B3261E`.
**Spacing scale:** `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64` (px).
**Type scale:** as P5-6.
**Definition of done:** Components reference tokens (no hardcoded `#262626E0` literals like `CategoryNav.jsx:22`); changing a token updates the site.
**Effort:** 1 d
**Depends on:** P3-5

---

## Cross-phase dependency map

```
P0 ──► P1 ──► P1.5 ──► P2 ──► P3 ──► P4 ──► P5
        │       ▲                        ▲
        │       │                        │
        └───────┴── P1 endpoints feed ───┘
            P1.5 cart, P5 tiles/menu/recs, P4 image srcset
```

| Task | Depends on | Blocks |
|------|------------|--------|
| P0-1 rotate secret | — | P0-2 |
| P0-2 axios instance | P0-1 | P1.5-4 |
| P0-3 callback HMAC | — | P1-7 |
| P0-4 scope endpoints | — | P1.5-5 |
| P0-5 cart hotfix | — | P1.5 (superseded), P1-9 |
| P0-6 remove reloads | — | P1-1 (clean baseline) |
| P1-1 listing endpoint | P0-6, P1-5 | P1-2, P1-3, P1.5, P2-5, P5-3 |
| P1-3 PDP endpoint | P1-1, P1-2 | P2-3, P4-5, P5-3 |
| P1-4 catalog/meta | — | P5-1, P5-2, P3-1 |
| P1-7 stock locks | P0-3 | — |
| P1.5-1 carts migration | — | P1.5-2..6 |
| P1.5-5 merge cart | P1.5-3, P0-4 | — |
| P2-1 Next.js scaffold | P1-1, P1-3 | P2-2..6, P4-3/4-4 |
| P3-1 split context | P1-1, P1-4 | P3-2, P3-5 |
| P3-2 remove windowWidth | P3-3 | — |
| P3-5 remove Bootstrap | P3-1 | P5-5, P5-6, P5-7 |
| P4-5 image CDN | P1-2, P1-3 | — |
| P5-3 recommendations | P1-3 | — |

---

## Effort summary table

| Phase | Tasks | Total effort | Parallelizable? |
|-------|-------|-------------|-----------------|
| P0    | 11    | ~5 days     | Mostly (P0-2 after P0-1; rest independent) |
| P1    | 9     | ~9 days     | Yes (P1-2/3 after P1-1) |
| P1.5  | 7     | ~4 days     | Sequential within phase |
| P2    | 6     | ~3.5 weeks  | After scaffold, metadata tasks parallel |
| P3    | 5     | ~2 weeks    | P3-3/3-4 parallel; P3-2/3-5 gated |
| P4    | 5     | ~3.5 days   | Mostly parallel |
| P5    | 7     | ~1.5 weeks  | Mostly parallel after endpoints |
| **TOTAL** | **50** | **~9–10 weeks** (1 dev) / **~5–6 weeks** (2 devs) | |

---

## Quick wins (do any time, no dependencies)

- [ ] Remove forced-reload timers — `App.jsx:83-92` + `FetchTablesAndProducts.jsx:385-392` — 30 min (P0-6)
- [ ] Add `Product` `$hidden` — `Product.php` after :88 — 30 min (P0-7)
- [ ] Strip `file`/`line` from error JSON — `OrderController.php:319-322` — 30 min (P0-8)
- [ ] Delete dead/commented code — `MyProvider.jsx`, `useCart.jsx`, `ProductDisplay.jsx:134-179`, `Home.jsx:37-39` — 1 h (P3-4)
- [ ] `robots.txt` — `public/robots.txt` — 15 min (P2-4)
- [ ] Per-page canonical (interim) — `App.jsx:124` make it route-aware — 1 h (P2-2 precursor)
- [ ] Add `width`/`height` to bottom-banner imgs — `Home.jsx:137-143` — 30 min (P4-1)
- [ ] `fetchpriority="high"` on hero slide — `HomeSlider.jsx` — 20 min (P4-2)
- [ ] IDOR checks on delete — `OrderController.php:166-174`, `DetailsProductController.php:244-266` — 1 h (P0-9)
- [ ] Remove `legacy()` plugin + polyfill — `vite.config.js:19-22`, `main.jsx:9` — 1 h (P4-4)
