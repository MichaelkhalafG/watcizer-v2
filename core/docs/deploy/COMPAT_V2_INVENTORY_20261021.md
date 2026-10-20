# What Watchizer's storefront calls, compat against v2 — the inventory

**Status: READ-ONLY. 2026-10-21. Nothing here is built and nothing is recommended as urgent.**
**Read from the CODE** — core on `wave-4d` at `47916a3`, `Frontend-next` and `Frontend-brandfashion` as checked
out today. Route lists are `php artisan route:list --path=api`. No row count from the local development database
is quoted as a fact about the client's data; where production data is needed, §7 gives the query to run.

---

## 0. Read this first: the question is not new, and the pilot has already run

`docs/deploy/V2_MOVE_SCOPE_20261016.md` scoped this five days ago and put three rollout options to Michael. It
recommended **option C — Brand Fashion first, through the twin apps, no runtime flag** — on the grounds that it
costs nothing extra and risks no Watchizer revenue.

**That is what happened.** B7 (2026-10-18/19) built the v2 cart, the v2 checkout, v2 `me/orders` and v2 offers,
and moved Brand Fashion's app onto them; Brand Fashion went live on its temporary domain on 2026-10-19 and
**took a real cash-on-delivery order end to end** (`RUN_20261019.md`). So the pilot the scoping document asked
for has been run, on a shop with no revenue to lose, and it worked.

Two consequences for reading the older document:

* **Its "MISSING from v2" column is now substantially stale.** Steps 2–6 (cart write, cart read, update/remove,
  validate, checkout) and step 9 (`me/orders`) were listed as "none" in v2; all of them now exist and carry
  Brand Fashion's traffic. Its estimate of 59–79 h was for building those. **That work is done.** What is left
  for Watchizer is the part that document costed at **3–4 h ("the copy to the second app") plus 6–8 h (gate,
  browser pass, deploys)** — and the frontend copy is no longer a copy, because Brand Fashion's cart, checkout
  and account files have since DIVERGED from the twins for reasons of its own (see §5).
* Its sections (c) payment and (d) "a cart that already exists when a shop switches" are **not** stale and are
  the right reading for the remaining risk. Nothing below contradicts them.

What this document adds is the thing that document did not have: the **endpoint-by-endpoint** map, now that v2
has grown, and which of those endpoints touch money.

---

## 1. The counts

Measured by taking every call site on the three HTTP clients (`src/Context/api.jsx`, `src/Context/publicApi.js`,
`src/lib/serverFetch.js`) plus every `fetch()`, and reducing to distinct endpoints.

| | Watchizer (`Frontend-next`) |
|---|---|
| Distinct API endpoints called | **38** |
| of which **v2** | **1** |
| of which **compat** | **37** |
| Non-API passthrough | 1 (`{ORIGIN}/sitemaps/{file}`, the app's own route proxying core's) |

The single v2 call is `GET /api/v2/{code}/payment-methods` (`src/Hooks/queries/usePaymentMethods.js:16`).
Brand Fashion calls the same one, plus thirteen more.

For scale on the other side: core registers **18** v2 routes and **68** compat routes, of which **21 are
`GoneController`** (a deliberate 410 for a retired legacy path) and **1 is the catch-all `api/{path}` proxy to
the legacy host**. So compat's real surface is about 46 routes, and Watchizer uses 37 of them.

### The list — the 37 compat endpoints, grouped

**Catalogue and content (11)** — all GET, all cached `private`, all through the API key
`catalog/home` · `catalog/meta` · `catalog/nav` · `catalog/listing` · `catalog/cards` · `catalog/related` ·
`catalog/product` · `catalog/blogs` · `catalog/blog` · `products/{id}` · `products/by-name/{name}`

**Ratings, alerts, shipping (4)**
`GET all_product_rating` · `POST add_product_rating` · `GET show_shipping_city` · `POST stock-alerts`

**Authentication (6)**
`POST login` · `POST register` · `GET auth/me` · `POST auth/forgot-password` · `POST auth/reset-password` ·
`GET auth/{provider}/redirect`

**Profile (3)**
`POST updateProfile` · `POST updatePassword` · `DELETE me/avatar`

**Account data (4)**
`GET me/orders` · `GET me/addresses` · `POST add_address` · `DELETE me/addresses/{id}`

**Cart (7)**
`POST add_to_cart` · `POST remove_from_cart` · `GET me/cart` · `DELETE delete_cart/{id}` ·
`POST cart/validate` · `POST cart/merge` · `POST cart/recover`

**Checkout (1)**
`POST add_order`

**Dead by decision (1)**
`GET all_offer_rating` — called by `ProductDetailClient.jsx:650` only when the page is an OFFER. Legacy offers
were deleted in Phase 0 (G11) and the path is on `config('compat.gone')`, so it answers **410**. The offer
pages it serves render empty by design. Counted above for completeness; it needs no v2 equivalent.

**Registered but NOT called by this frontend:** `POST logout` / `POST auth/logout` (the app's `logout` is a
client-side store action that clears local state and makes no request — `src/Store/authStore.js:110`),
`POST auth/resend-verification`, `GET auth/verify-email/{id}/{hash}`, `GET auth/{provider}/callback` (the
browser arrives there from Google, not from the app).

---

## 2. For each compat endpoint: is there a v2 equivalent, and is the shape the same?

**Short answer: for 11 of the 37 there is a v2 equivalent, and in every one of those 11 the shape is
DIFFERENT. For the other 26 there is no v2 equivalent at all.** The shape differences are not cosmetic — compat
emits legacy field names, legacy ids and legacy envelopes, which is its whole purpose.

| compat endpoint | v2 equivalent | same shape? | what differs |
|---|---|---|---|
| `catalog/listing` | `GET v2/{sf}/products` | **no** | compat `{total, page, per_page, products[], ratings[], images[], facets}` with legacy product rows; v2 `{data[], facets?, meta{page, per_page, total, total_capped, last_page, sort, locale, filters, category}}` with card rows `{id, slug, family, image, price{…}, stock{express,market,in_stock}}`. Different envelope, different row, different facet shape (compat facets are value→count only and carry **no price bounds** — see the design survey, item 12). |
| `catalog/cards?ids=` | **none** | — | No v2 way to hydrate a set of product ids. Used by the home page and the cart. |
| `catalog/product?slug=` | `GET v2/{sf}/products/{slug}` | **no** | v2 serves `variants[]` with per-row price and stock; compat serves product-level stock only and no variants. This is the gap the 2026-10-16 document was written about. |
| `products/{id}` | `GET v2/{sf}/products/{slug}` | **no** | v2 addresses a product by **slug**, compat by **legacy id**. `catalog_products.id` is the legacy `products.id`, so the id survives — but there is no by-id v2 route. |
| `products/by-name/{name}` | `GET v2/{sf}/products/{slug}` | close | Both resolve a readable string; the response shapes differ as above. |
| `catalog/related` | `GET v2/{sf}/products/{slug}/related` | **no** | Same selection rules (v2 calls compat's `CompatRelated`), different card shape. Brand Fashion already uses the v2 one. |
| `catalog/home` | `GET v2/{sf}/home` | **no** | compat `{hero, banners, rails[{id,kind,target,title,products[]}], products[], ratings[], images[]}`; v2 `{hero, rails[{…,count,cards[]}], deals[], categories[], banners[], banner, brands[]}`. v2 carries three things compat never served (deals, category entries, brands with counts). `hero` and `banners` were added to **both** yesterday. |
| `catalog/meta` | `GET v2/{sf}/meta` | **no** | compat `{tables{…}, brands[], categories[], sub_types[], genders[], grades[], dial_colors[], band_colors[], features[], banners[], shipping_cities[]}` in legacy ids and `name_en`/`name_ar`; v2 `{storefront{…}, brands[], grades[], filters{…}, tree, banners[]}`. **Brand Fashion still calls compat `catalog/meta`** even though v2 meta exists, because the twins' lookup tables want the legacy shape — that is the measured proof the two are not interchangeable. |
| `catalog/nav` | `GET v2/{sf}/categories` (tree) | **no** | v2 serves the category tree; compat's `nav` is the header menu's own denormalised shape. Brand Fashion calls **both**. |
| `me/orders` | `GET v2/{sf}/me/orders` | **no** | And the difference is behavioural, not only structural: compat's lists the customer's orders on **every** shop (users are shared); v2's lists **this storefront's only**. That was fixed for Brand Fashion in the night run (audit a1). |
| `me/cart`, `add_to_cart`, `remove_from_cart`, `delete_cart/{id}` | `GET/POST/PATCH/DELETE v2/{sf}/cart[/items[/{item}]]` | **no** | compat `{id, user_id, guest_token, created_at, updated_at, expires_at, cart_item[], totals, warnings}`; v2 `{id, items[], totals{count, subtotal, currency}}`. v2 is variant-aware and prices each row on the server; compat refuses a variant product with a 422. |
| `add_order` | `POST v2/{sf}/orders` | **no** | Different request (v2 takes no line items — it reads the shopper's v2 cart) and different errors (stable codes, not legacy sentences). **The writer underneath is the same** — see §4. |

**No v2 equivalent exists today (26):** `catalog/cards`, `catalog/blogs`, `catalog/blog`, `all_product_rating`,
`add_product_rating`, `show_shipping_city`, `stock-alerts`, `login`, `register`, `auth/me`,
`auth/forgot-password`, `auth/reset-password`, `auth/{provider}/redirect`, `updateProfile`, `updatePassword`,
`me/avatar`, `me/addresses`, `add_address`, `me/addresses/{id}`, `cart/validate`, `cart/merge`, `cart/recover`,
`all_offer_rating` (410 by decision), and the three registered-but-uncalled auth paths.

That list is not a guess: **Brand Fashion, which has moved everything it can, still calls every one of them on
compat.** v2 has `auth/providers` (which providers a shop can offer) but no sign-in, no profile, no addresses,
no blogs, no ratings, no shipping cities, no stock alerts, no cart merge and no cart recovery.

---

## 3. What compat does that v2 does not

1. **Storefront resolution from the HOST.** `CompatStorefront` resolves the shop from the request host matched
   against `storefronts.domain`, exact or by sub-domain, longest domain wins. It is chosen over the `Origin`
   header deliberately: `Origin` is absent on the storefront's own server-side renders and is set by the client
   on the rest, so a shopper could ask for another shop's prices by editing one header; the host is set by the
   reverse proxy. An unknown host is a 404 in production. **v2 takes the storefront from the URL path** and then
   *also* checks the host is allowed to serve it (`StorefrontHost::serves`) — so v2 is path-addressed with a host
   guard, compat is host-addressed with no path at all. A compat URL is therefore **not portable between shops**:
   the same path means a different shop on a different host.
2. **An API key.** Every compat route is behind `api.code` (`Api-Code` header, or `api_code` in the query string
   for the two header-less reads). **v2 reads are not behind it.** The key is public — it ships in the storefront
   bundle — so this is a traffic gate, not a secret, but it is a difference in surface.
3. **Legacy identities and legacy field names.** Legacy category-type ids, `name_en`/`name_ar`, `product_title`,
   `selling_price`, `sale_price_after_discount`, legacy timestamp formatting (`LegacyJson`). v2 emits clean ids,
   per-locale names and a `price` object.
4. **A different image host.** A compat payload names `compat.asset_base`; a v2 payload names the storefront's
   own `assetBase` (COMPAT-ASSET-HOST, 2026-10-14). They are separate config values.
5. **Locale negotiated from `Accept-Language`** on the routes whose validation errors reach the shopper
   (`add_address`, `remove_from_cart`, `add_order`) and on the account reads, because the legacy host did that
   and the field errors are rendered straight from the response. v2 answers stable codes instead.
6. **Guest identity.** `compat.guest` mints and echoes `X-Guest-Token`; the v2 cart takes the same header but
   does not mint one.
7. **410 for retired paths.** 21 `GoneController` routes exist so the API host answers a core 410 rather than a
   host 404 — the storefront and the allow-list both depend on it.
8. **A reverse proxy to the legacy application** on `api/{path}` for anything unmatched. v2 has no fallback.
9. **The payment callback aliases.** `callback_payment` (Watchizer's permanent legacy alias) and
   `pay/{storefront}/{provider}/callback` sit outside both surfaces; they are the application's own and need no
   migration. (Section (c) of the 2026-10-16 document, still accurate.)
10. **Cache headers differ by design.** Compat reads are `private` with per-group max-age (1800 for meta and
    shipping, 600 for the catalogue, `no_cache`+ETag for ratings); the v2 read group is `private` too since
    2026-10-19, for the same CDN reason, and three v2 groups are deliberately uncached (payment methods, offers,
    cart, account, auth providers).

---

## 4. Which of these touch money or customer history

| Area | Endpoints | What is at stake | The fact that matters |
|---|---|---|---|
| **Order creation** | `POST add_order` | Every Watchizer order | **v2's checkout does not write orders itself.** `StorefrontCheckout` calls `CompatCheckout::placeOrder()` — compat's writer, unchanged — with the path's storefront id. The order row, its lines, the reward lines and `InventoryService::commitOrder()` are the same code on both doors. |
| **The order-number sequence** | `POST add_order`, `POST v2/{sf}/orders` | Collisions, gaps, duplicate numbers | **There is ONE sequence and both doors already share it** (`CompatCheckout::nextOrderNumber()`, `MAX(CAST(order_number AS UNSIGNED)) + 1` under `lockForUpdate`, backlog ORDER-NUMBER-SHARED). Moving Watchizer to the v2 door does **not** renumber anything and cannot collide with a compat-written order. This is the single biggest risk that is already retired. |
| **Carts** | the 7 cart endpoints | A shopper's basket mid-session | The v2 cart uses **the same `carts` / `cart_items` rows and the same identity** (`user_id` XOR `guest_token`, `X-Guest-Token` from `localStorage`). Section (d) of the 2026-10-16 document works through every mid-session case; the two code changes it names (version the frontend's cart cache key, and warn per line when a line needs a variant) are still the two. |
| **Addresses** | `me/addresses`, `add_address`, `me/addresses/{id}` | A customer's saved delivery addresses | **No v2 equivalent at all.** The v2 checkout accepts an `address_id` and validates it belongs to the caller, so addresses stay on compat even for a v2 order. Nothing to move; nothing at risk. |
| **Accounts with existing orders** | `login`, `register`, `auth/me`, profile, `me/orders` | Sign-in, order history | **No v2 equivalent for sign-in.** The JWT is verified by core with the shared secret; both surfaces read the same `users`. The one behavioural difference is `me/orders`: compat lists the customer's orders on **every** shop, v2 lists this shop's. For Watchizer — the storefront that holds essentially all the history — switching that endpoint **changes what a customer sees**: orders placed before storefront tagging, or on the other shop, would drop out of their history. That is a product decision, not a technical one, and it needs §7's query to size. |
| **Payments** | outside both surfaces | Paymob / valU initiation and callback | Unchanged by any of this. The initiator is storefront-aware domain code; the callback routes are neither compat nor v2. Confirmed again today. |

---

## 5. One thing that got HARDER since 2026-10-16

The older estimate assumed the frontend work is "done once and copied", because the cart and checkout files
were byte-identical twins. **They are not any more.** `TWINS.txt` records, as deliberate divergences from the
night run and the visual reset:

`src/Store/cartStore.js` · `src/PageViews/Cart/Cart.jsx` · `src/PageViews/Checkout/Checkout.jsx` ·
`src/PageViews/Account/Account.jsx` · `app/cart-modal-host.jsx` · `src/lib/env.js`

Each diverged for a Brand-Fashion-specific reason as well as for v2 — Brand Fashion's cart page shows variants,
a sold-out line with a Remove action, a collapsible phone summary; its checkout shows core's exact payment list
and refusal codes; its account reads this shop's orders only. So moving Watchizer is **not** "copy six files".
It is: take the v2 data layer out of those files, leave Brand Fashion's presentation behind, and re-apply it to
Watchizer's presentation. That is more careful work than a copy, and it is the main reason the remaining
estimate below is not simply the 3–4 h the older document carried.

---

## 6. The work, in slices that could ship separately

Hours are estimates. Each slice is independently deployable and independently reversible (redeploy the previous
build of one app). **No slice requires a core deploy that changes compat**, because v2 already exists beside it.

| # | Slice | What it moves | Est. | Why it can ship alone |
|---|---|---|---|---|
| **1** | **Read-only catalogue** | `catalog/listing` → v2 `products`; `catalog/product`/`products/{id}` → v2 `products/{slug}`; `catalog/related` → v2 related | 10–14 h | Touches no money, no session, no write. Exactly the path Brand Fashion has run since 2026-10-17/18, so the adapter (`v2Listing.js`, `listingRequest.js`) already exists and is proven. Also the slice that **fixes the price-slider bound** (v2 serves `facets.price`, compat does not). Rollback = redeploy. |
| **2** | **Home and menu** | `catalog/home` → v2 `home`; `catalog/nav` + `catalog/meta` → v2 `meta` + `categories` | 8–12 h | Read-only. The catch: v2 meta does **not** carry the legacy lookup tables the twins' `useTables` wants, which is why Brand Fashion still calls compat meta. Doing this properly means changing what the menu reads, not just where from — so it is a slice of its own and it is the one I would **not** rush. |
| **3** | **Cart** | the 7 cart endpoints → v2 cart | 12–16 h | Same rows, same identity, so a shopper mid-session is unaffected (§4). Needs the cart-cache key version and the per-line variant warning. Carries real money only at the moment of checkout, which is slice 4. `cart/merge` and `cart/recover` have **no v2 equivalent** and would stay on compat or be built here. |
| **4** | **Checkout** | `add_order` → v2 `orders` | 10–14 h | The money. Same order writer, same sequence, same mailer, same inventory commit, same promotions — the door changes, not the ledger. Must ship **after** slice 3 (v2 checkout reads the v2 cart) and must not ship in the same deploy as anything else. |
| **5** | **Account** | `me/orders` → v2 `me/orders` | 3–5 h + a product decision | Small technically. The decision — whether a Watchizer customer should stop seeing non-Watchizer orders — is the whole content of this slice. |
| **6** | **The long tail** | auth, profile, addresses, blogs, ratings, shipping cities, stock alerts, `catalog/cards` | **not estimated** | **There is nothing in v2 to move to.** This is 26 endpoints of NEW core work before a single line of frontend changes. It is where "retire compat" stops being a migration and becomes a rewrite. |

**First: slice 1.** It is read-only, it is already proven on Brand Fashion, it fixes a visible defect for free,
and if it goes wrong nobody loses an order.

**Last: slice 6** — and I would not plan it at all until somebody wants compat switched off, which nothing
currently requires.

**Slice 4 is the one to think hardest about**, and the honest reason to do it is not the API: it is that
Watchizer cannot sell variant products until it does. That is the original 2026-10-16 driver and it is still
the only forcing function. If no Watchizer product needs variants, slice 4 can wait indefinitely.

**I am not recommending a cutover.** Slices 1–5 leave 26 endpoints on compat and therefore leave compat
running; the API key, the host resolution and the proxy all stay. The gain is not "compat is gone" — it is
variants, the price-slider bound, and one code path shared with the shop that already runs it.

### Decided 2026-10-22: slice 2 is NOT being done, and that is a choice, not an omission

The wave that followed this document took slices **1, 3, 4 and 5**. Slice 2 — the home page, the menu and the
lookup tables — was **deliberately left on compat** by the owner's instruction, in these words: "home, menu,
lookup tables stay on compat".

That is the right call for the reason §6 already gives: v2's `meta` does not carry the legacy lookup shapes
`useTables` feeds to the sidebar, the nav and `transformProductData`. Moving them is not a change of address, it
is a change of what three screens read, and it would have ridden along in a wave whose point was the listing,
the cart and the checkout. Nothing is forcing it: `catalog/home`, `catalog/nav` and `catalog/meta` are
read-only, they carry no money and no session, and they are the three endpoints a shopper hits least often per
visit.

Measured after slice 1 shipped, against the scratch core, by the paths the running app actually requested:

| Page | API calls observed |
|---|---|
| `/` (English) | `catalog/home` — compat |
| `/ar` (Arabic) | `catalog/home` — compat |
| `/listing` | `v2/watchizer/products` — v2 |

**One documented exception.** `useTables` makes a single extra v2 read, `v2/{storefront}/categories`, and
it is not a move: the tables still come from `catalog/meta`. It exists because a v2 category is addressed by the
storefront tree's own slug, which is not always the slug of the node's English name — Chargers & Cables is
`chargers`, Earphones & Headphones is `audio` (measured 2026-10-18) — so each row is tagged `v2_slug` where the
two differ. It is an enrichment and it degrades: if that read fails, the name's slug is sent, exactly as before.

**What this leaves open.** BF-MENU-V2 in the backlog is still the ticket for slice 2, unchanged. Until it is
taken, both storefronts' menus and lookup tables are a compat dependency, and compat cannot be switched off —
which §6 already said about slices 1–5 as a whole.

### Revised 2026-10-22: slice 1 cost more than estimated, for a measured reason

Estimated 10–14 h above, on the premise that "the adapter already exists and is proven" on Brand Fashion. It
does — but reusing it as-is would have **removed two working filters from a live watch shop**, measured before
the move: Movement (3 values, 655 products) and Shape (6 values, 594 products) exist in Watchizer's sidebar and
had no v2 equivalent, and v2's single-path `category` would have turned a multi-select sub-type section into
pick-one. The owner's instruction was to build parity rather than ship the loss.

So slice 1 grew a core half that the estimate did not contain: three watch-spec facets and filters
(`movement`, `shape`, `display_type`) counted from `catalog_product_watch_specs`, a comma-separated multi-path
`category` answering the union, and the tests that pin both. **Revised: 18–22 h**, of which roughly 6–8 h is
that core work. The frontend half came in near the estimate, and it ended up *removing* divergence rather than
adding it — four files became twins again (`v2Listing.js`, `listingRequest.js`, `useListing.js`,
`useTables.js`), taking `TWINS.txt` from 26 recorded divergences to 23.

The lesson for slices 3–5: "Brand Fashion already runs it" bounds the *frontend* risk, not the scope. Where
Watchizer's screen has a control Brand Fashion's does not, the v2 endpoint has to grow it first.

---

## 7. What I could not answer from the code — and the exact query

The local development database holds **29 orders**. That is not production, it carries demo rows and test
orders, and I have quoted no number from it as a fact about the business. Three things need production:

**(a) How big is the order history, and over what period** — this sizes slices 4 and 5, and decides whether the
dashboard index added today matters at all:

```sql
SELECT COUNT(*) AS orders, MIN(created_at) AS first_order, MAX(created_at) AS last_order FROM orders;
SELECT COUNT(*) AS order_items FROM order_items;
```

**(b) How many customers would LOSE order history if `me/orders` moved to v2** (slice 5) — i.e. orders that are
not tagged to Watchizer and would drop out of a per-storefront list:

```sql
SELECT storefront_id, COUNT(*) AS orders, COUNT(DISTINCT user_id) AS signed_in_customers
FROM orders GROUP BY storefront_id ORDER BY storefront_id;
```

**(c) Whether any Watchizer product actually needs variants** — the only forcing function for slice 4:

```sql
SELECT COUNT(DISTINCT v.product_id) AS products_with_variants
FROM catalog_product_variants v
JOIN storefront_product sp ON sp.product_id = v.product_id AND sp.storefront_id = 1 AND sp.is_visible = 1
JOIN catalog_products p ON p.id = v.product_id AND p.is_active = 1 AND p.deleted_at IS NULL;
```

If (c) answers 0, slice 4 has no deadline at all.
