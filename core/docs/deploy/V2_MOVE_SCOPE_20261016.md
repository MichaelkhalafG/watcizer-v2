# Variants on the live path through the v2 API — the coverage map, the seams, the number

**Status: READ-ONLY scoping, 2026-10-16. Nothing here is built. Nothing is built until Michael picks a rollout.**

**The decision this scopes** (Michael, 2026-10-16): variants go live on BOTH storefronts through the v2 API,
not by teaching compat `variant_id`. Every visible product sits on both shops (1,014 / 0 Brand-Fashion-only on
the 2026-10-16 export), so a variant product has to be sellable on both; extending compat means growing an API
that is being retired.

**Why nothing is urgent:** compat refuses a variant product with a 422 at the cart and at checkout, and no
visible product has variants (the 24 size-only products are hidden; the 205 `DEMO-` rows exist on the dev
database only). Nothing is broken and waiting.

**Measured from the code** — core `wave-4d` at `c6fd45b`, `Frontend-next` and `Frontend-brandfashion` as
checked out 2026-10-16, the dev database (production export of 2026-10-16). Hours are estimates and say so.

---

## (a) The chain, step by step: compat today, v2 today, what v2 is missing

Compat = the legacy-shaped endpoints under `/api/…`, pinned to one storefront per API host
(`CompatStorefront` resolves the host, falling back to `config('compat.storefront_id')` = 1). v2 = `/api/v2/{storefront}/…`,
the storefront resolved from the URL by `ResolveStorefront`. Both live in the same application and share the
domain layer (`InventoryService`, `PaymentInitiator`, `OrderRecovery`, promotions).

| # | Step | Compat today (file → method) | v2 today | MISSING from v2 |
|---|---|---|---|---|
| 1 | Product page | `CatalogCompatController::product` → `CompatProductDetail` (server render, `catalog/product?slug=`), then `CatalogCompatController::show` / `showByName` (`products/{id}`, `products/by-name/{name}`) for the second fetch. Emits product-level stock only; **no variants, no `price_delta`**. | `V2\ProductController::show` → `ProductDetail::bySlug` (`products/{slug}`): emits `variants[]` with `color`, `size`, `price` = base + delta (`StorefrontPricing`), `stock` per row (`ProductDetail.php:183-225`). `V2\ProductController::index` → `ProductListing` for cards. | Nothing for the page itself. The frontend does not call it (see b). |
| 2 | Add to cart | `CartCompatController::add` (`add_to_cart`) → `CompatCart::upsertItem`. Identity from JWT or `X-Guest-Token` (`CompatGuestCart`). **Refuses a product with variants** (`:85-89`, 422 "This product requires selecting an option"); line written with `variant_id => null` (`:107`). | **none** | A cart write that takes `variant_id`, prices the row (base + delta, sale likewise), checks the ROW's stock in the chosen bucket, and keys the line by (product, variant, offer). |
| 3 | Cart read | `CartCompatController::show` (`me/cart`) → `CompatCart::payload / itemRows / totals / warnings`. | **none** | The same payload with the variant label (colour / size) and the row's price and stock on each line. |
| 4 | Cart update / remove | `CartCompatController::remove` (`remove_from_cart`, by product + offer), `::destroy` (`delete_cart/{id}`), `::merge` (`cart/merge` on login → `CompatCart::merge`). | **none** | Remove by line id (already by id) and by (product, variant, offer); merge that keeps `variant_id` distinct lines distinct. |
| 5 | Cart validate | `CartCompatController::validateCart` (`cart/validate`) → `CompatCart::warnings` (price moved, out of stock, product gone). | **none** | The same at the variant row: price moved (base or delta), the row out of stock, the row deactivated (`is_active = 0`). |
| 6 | Checkout / order creation | `CheckoutCompatController::addOrder` (`add_order`) → `requestLines` (**sets `variant_id => null` by design**, `:753-780`) / `cartLines` (reads `variant_id` from `cart_items`, `:784`) → `CompatCheckout::priceLines` (**refuses any variant product**, `:94-96`) → `::placeOrder` (writes `order_items.variant_id`, `:196`; rewards `:227`; stamps `storefront_id`, `:178`) → `InventoryService::commitOrder` (ledger by `StockTarget::fromLine(product, variant)` — **already variant-aware**). Promotions evaluated with `variantId` (`:687`). | **none** | The order creation itself: validate address/guest fields, price variant lines (row stock, delta), place, commit — the domain calls exist; the HTTP door and the pricing of variant rows do not. |
| 7 | Payment initiation | Inside `addOrder`: `CheckoutCompatController::paymob` → `PaymentInitiator::initiate(request, storefrontId, locale, postedMethod, orderId, orderNumber, amount, billing)` (`app/Domain/Payment/PaymentInitiator.php:54`), the method from `MethodList::resolve` (the same list v2's `payment-methods` serves). | `V2\PaymentMethodController::index` serves the METHOD LIST only. | Only the call: the initiator is storefront-aware domain code, reusable as is. |
| 8 | Payment callback | **Not compat.** `PaymentCallbackController::handle` on `pay/{storefront}/{provider}/callback` (storefront by code, host checked by `StorefrontHost::serves`), plus the permanent Watchizer alias `callback_payment` → `::alias` → the same `process()`. Four checks before any write; the verdict-to-state transition is one transaction. The URL the provider is given comes from `CallbackDestination::for` (`/api/pay/{code}/{provider}/callback` on the request host; `settings->callback_base` override). | the same routes — they are not under `/api/v2/` and not under compat; they are the application's | **Nothing.** See (c). |
| 9 | Order read | `AccountCompatController::orders` (`me/orders`) → `CompatAccount::orders`: emits `variant_id` on each line (`:208`) but **no variant label**. The dashboard already shows label and SKU (`Manage\OrderController.php:836-867`). | **none** | `me/orders` with the variant label on the line (a join on `catalog_product_variants`, as the dashboard does). Addresses (`me/addresses`, `add_address`) have no variant dependence and can stay on compat during a pilot. |
| 10 | Cart recovery (card order expired) | `CartRecoveryController` (`cart/recover {token}`) → `OrderRecovery::verify` → `OrderRecovery::payload(orderId, CompatCart, storefrontId)`; the e-mailed link is `{storefront host}/cart/recover?t=…` (`OrderRecovery::url`, `:88-95`); the page re-adds lines through `add_to_cart`. | **none** | The payload built for a v2 cart (variant rows with labels), and the page re-adding through the v2 cart write. The token and the e-mail do not change. |

What is already there and is the reason the move is cheaper than it looks: the ledger commits by variant,
`order_items` and `cart_items` carry `variant_id`, promotions take `variantId`, the payment initiator and the
callback are storefront-scoped domain code outside compat, v2 already serves the product with its variants and
the payment method list. **What is missing is one thing in six shapes: a storefront cart and checkout door
(steps 2–6, 9, 10) that carries `variant_id`.**

## (b) What each frontend actually calls today

Measured by grepping every `http.get/post/delete(...)` and `fetch(...)` call site under `src/` and `app/`:

| | Frontend-next (Watchizer) | Frontend-brandfashion |
|---|---|---|
| Call sites | 59 | 59 |
| **Set of endpoints** | identical | identical |
| v2 calls | **one**: `/v2/${STOREFRONT_CODE}/payment-methods` | the same one |
| Product page | server: `catalog/product?slug=` (`src/lib/serverCatalog.js:85`); client: `products/${id}` or `products/by-name/…` (`src/Components/Product/ProductDetailClient.jsx:183-184`, `src/Hooks/queries/useProductDetail.js:14`) — all compat | the same files, same lines |
| Cart | `src/Store/cartStore.js`: `add_to_cart` (`:98`), `remove_from_cart` (`:156`), `cart/validate` (`:317`), `me/cart` (`:344`, `:372`), `delete_cart/{id}` (`:359`), `cart/merge` (`:394`); guest token in `localStorage['wz_guest_token']`, sent as `X-Guest-Token`; a line is `{product_id, offer_id, quantity, piece_price, total_price, type_stock, color_band, color_dial}` — **no `variant_id` anywhere**; `getItemKey` keys lines by product/offer | the same |
| Checkout | `src/PageViews/Checkout/Checkout.jsx` builds `items[]` with the same eight fields and posts `add_order` with `payment_method`, `payment_method_id`, address or guest fields, `total_price_for_order` | the same |
| Recovery | `src/PageViews/Cart/Recover.jsx:39` posts `cart/recover`, re-adds through the store, `router.push('/checkout')` | the same |
| API base | `NEXT_PUBLIC_API_BASE` (dev `.env.local` points at `127.0.0.1:8000/api`) | `https://api.michaelkhalaf.com/api` |
| Files that differ between the apps | 24 under `src/`, 10 under `app/` — every one a theme, CSS, trust-page, SEO-lib, layout or icon file (`Header.jsx`, `Footer.jsx`, `trust.config.js`, `trustPages.js`, `listingSeo.js`, `detailSeo.js`, `requestLang.js`, `globals.css`, `layout.jsx`, `page.jsx`, icons…). **No data-call file differs.** | |

So for the cart and checkout the twins ARE identical today — the assumption that caught us before does not
bite here, and it means the frontend work is done once and copied.

Sizes of what moves on the frontend (identical in both apps): `cartStore.js` 412 lines, `Checkout.jsx` 964,
`Cart.jsx` 648, `CartModal.jsx` 214, `Recover.jsx` 206, `OrderConfirmation.jsx` 406, `ProductCard.jsx` 270,
`ProductDetailClient.jsx` (the PDP) — plus the variant picker itself, which is B5 and costed in the spec, not here.

## (c) The payment side: Paymob and valU

- **valU is not a provider; it is a METHOD.** `storefront_payment_methods` row 5846, `method = valu`, under
  contract 8963 (storefront 1, provider `paymob`), with its own Paymob integration id — beside `card`,
  `wallet`, `kiosk`, `bank_installment`, `apple_pay`. The code knows three providers (`ProviderRegistry`:
  `paymob`, `cod`, `whatsapp`); valU rides Paymob's initiation and Paymob's callback. Storefront 2 holds one
  contract (8965) with `cod` only — no card contract yet.
- **The callback does not depend on compat.** `pay/{storefront}/{provider}/callback` resolves the storefront
  by code and the contract row by (storefront, provider), verifies the signature with THAT row's secret, and
  asserts the order belongs to the route's storefront before anything is written. The `callback_payment` alias
  is the same controller for (storefront 1, paymob). Neither route is prefixed `/api/v2/` and neither touches a
  compat class. An order created by a v2 checkout and called back on these routes is **not a split**: the
  callback finds the order by the provider's merchant reference and the storefront, exactly as it does today.
- **The seam that does exist, named:** the callback URL handed to Paymob is built by `CallbackDestination::for`
  from the request host serving `add_order`. A v2 checkout served from the same API host produces the same URL.
  A v2 checkout served from a different host (it will not be — both storefronts' v2 routes live on their own
  `api.*` host) would move the callback's host, and the `.htaccess` allow-list on the API hosts already passes
  `/api/pay/…`. No change.
- **The landing page after payment** (`Landing`, per storefront, built 2026-10-12) is also outside compat.

## (d) A cart that already exists when a shop switches

The facts the answer rests on: `carts` is identified by `user_id` XOR `guest_token` (`CartIdentity`); the guest
token lives in `localStorage['wz_guest_token']` and travels as `X-Guest-Token`; `cart_items.variant_id` exists
and is NULL on every compat-written line; `OrderRecovery`'s token names an ORDER, not a cart, and the e-mailed
link lands on the storefront's own `/cart/recover` page.

| Case | If the v2 cart uses the SAME tables and the SAME identity (the recommendation) | What breaks |
|---|---|---|
| A customer mid-session on the old path (lines in `cart_items`, token in localStorage) | The v2 cart read finds the same rows under the same token. Lines have `variant_id = NULL`; for a product WITHOUT variants that is complete, nothing changes. | **Nothing**, except the frontend's `sessionStorage` cart cache: its line shape gains `variant_id` and a label, so the cache key must be versioned (`CART_KEY` → `…:v2`) or the first v2 load re-hydrates from `me/cart`. One line of code; without it a stale cached shape renders for one page view. |
| A stored line for a product that GAINS variants between the write and the checkout | Today compat refuses the whole checkout with a 422. The v2 checkout must do the same per line and SAY which line ("choose a colour and size for X") — the `warnings` channel of cart validate already exists for price moves and stock. | Breaks only if left unhandled: the rule is "a variant product's line without `variant_id` is not sellable" — the same rule compat has, moved to the line and explained. |
| A recovery link already e-mailed | The token names the order (`OrderRecovery::token`); the page posts it to `cart/recover`. If the page is switched to a v2 `cart/recover`, the SAME token verifies (the HMAC covers order id and expiry only). The payload gains variant rows. | **Nothing** in the e-mail or the token. The page must post to whichever cart it now uses — if the page moves to v2 but `cart/recover` stays compat, the re-added lines land in the compat cart which is the same rows anyway. The only break would be a page that re-adds a VARIANT line through `add_to_cart` (compat refuses) — so the recovery page moves with the cart, in the same deploy. |
| A logged-in customer's cart (`user_id`) | Same rows, same JWT. `cart/merge` on login keeps working; the v2 merge must treat (product, variant) as the line key. | Nothing. |
| An order placed on compat, paid or pending | Orders are not carts; the callback and the order read are unchanged. | Nothing. |

**Short answer:** with the v2 cart on the same `carts` / `cart_items` rows and the same identity, no existing
cart, token, link or order breaks at the switch; the two code changes that keep it so are the cache-key version
and the per-line "needs a variant" warning. A v2 cart on NEW tables or a NEW identity would break every one of
the rows above and is not proposed.

## (e) The estimate, the rollout options, one recommendation

Hours are estimates from the code sizes above and the existing domain layer; tests included where named.

| Gap | Hours (est.) |
|---|---|
| Core: `Storefront\Cart` — identity, upsert by (product, variant, offer), read with labels, remove, merge, validate warnings at the row; extracted from `CompatCart` (515 lines) rather than copied; v2 routes in the UNCACHED `v2/{storefront}` group (where `payment-methods` sits); tests | 14–18 |
| Core: `Storefront\Checkout` — validation, variant-row pricing (base + delta, sale), guest/address, `placeOrder` and `commitOrder` through the existing domain, promotions; extracted from `CompatCheckout` + `CheckoutCompatController::addOrder` (950 lines, most of it validation and payment wiring that stays); tests including the "line needs a variant" refusal | 14–18 |
| Core: payment initiation from the v2 checkout — the `PaymentInitiator::initiate` call and the landing | 1–2 |
| Core: payment callback | 0 — already storefront-scoped, outside compat |
| Core: `me/orders` with the variant label (the dashboard's join) | 2–3 |
| Core: `cart/recover` payload for the v2 cart | 2–3 |
| Core: `.htaccess` allow-list | 0 — `/api/v2/` is already passed whole (runbook §4.1.1, `RewriteCond !^/api/v2/`); `ApiAllowListTest` gains the new paths | 0.5 |
| Frontend (once, then copied): `cartStore.js` on v2 with `variant_id` and the row's label/price, `Cart.jsx` + `CartModal.jsx` line rendering, `Checkout.jsx` items with `variant_id`, `Recover.jsx`, `OrderConfirmation.jsx`, the cache-key version; the PDP wiring from the picker (B5 itself is costed in the spec) | 16–22 |
| Frontend: the copy to the second app, the twin diff checked file by file | 3–4 |
| Gate runs, a browser pass on a scratch database per shop, the deploys (core once, each app once) | 6–8 |
| **Total, both shops, same code** | **≈ 59–79 h** |

Rollout options, with their risk:

| Option | Extra cost | Risk |
|---|---|---|
| **A — both shops at once**, one core deploy, both apps the same day | 0 | **Highest.** Watchizer's money path (cart → order → Paymob → callback) changes with no real traffic behind the new code; a defect is found by a paying customer. |
| **B — a per-storefront runtime flag** (`NEXT_PUBLIC_CART_API=v2|compat` in each app, or a storefront setting read by the core), Brand Fashion first | +3–4 h, and two live code paths in the frontend for as long as the flag exists | Medium. The flag buys a rollback switch, but two paths means two sets of bugs and a cache shape that must suit both. |
| **C — Brand Fashion first as the pilot, through the twin apps, no runtime flag** | **0 extra** — the apps are already separate deploys; "the flag" is which app's code calls which API. BF's app moves to v2; Watchizer's app stays on compat (its variant refusal stays as the guard) until the path has carried real BF traffic; then the same files are copied to Watchizer's app (the 3–4 h already in the total) | **Lowest.** Zero revenue risk on Watchizer during the pilot. Brand Fashion has **0 orders on storefront 2** on the 2026-10-16 export (9 NULL-storefront, 20 on storefront 1), so the pilot risks no revenue either. Rollback on BF = redeploy the previous build. One code path at a time. |

**Recommendation: C, at ≈ 59–79 h with nothing added for the rollout.** It is the cheapest safe path because the
architecture already gives it for free: two apps, two API hosts, one core that serves compat and v2 side by
side. Watchizer keeps today's exact behaviour (compat, refusing variants) until the v2 path has carried real
Brand Fashion orders through Paymob and the callback; then the copy. The one thing to add to the plan before
B5 ships on BF: storefront 2 has no card contract yet (cod only), so the pilot's first card payment needs the
Brand Fashion Paymob contract (PAY-GENERIC / the handover's C-1+) — otherwise the pilot exercises cash orders only.

Michael decides.
