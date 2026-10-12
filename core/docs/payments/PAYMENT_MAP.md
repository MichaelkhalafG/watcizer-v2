# Payment surface — map (2026-10-11)

Read-only survey of every place money is started, routed, verified or recorded: **core**, the **Next
storefront**, and the **dashboard**. Written against `05c1437` (branch `wave-4d`). Nothing was changed
to produce it.

**Every claim is tagged:**
- **read** — the cited lines say it.
- **inferred** — worked out from reading several places; not executed.
- **verified** — something was run. Here that is only the developer's read-only production checks of
  2026-10-11 (env key names, cached config, row counts). No payment test or request was run for this map.

Paths are relative to the repo root. `CCC` = `core/app/Http/Controllers/Compat/CheckoutCompatController.php`,
`PCC` = `core/app/Http/Controllers/Payment/PaymentCallbackController.php`, `CC` =
`core/app/Compat/CompatCheckout.php`, `D/P` = `core/app/Domain/Payment/`.

---

## 0. Production facts this map starts from (verified, developer, 2026-10-11)

| Fact | Consequence |
|---|---|
| `core/.env` has **no** `PAYMOB_SECRET_KEY` / `PAYMOB_PUBLIC_KEY` / `PAYMOB_HMAC_SECRET`; cached config prints `EMPTY` for all three | the wave-3 global Paymob path cannot initiate (§4 F1) and cannot verify a callback (§4 F4) |
| `PAYMOB_INTENTION_EXPIRATION_SECONDS`, `META_CAPI_TEST_EVENT_CODE`, `META_CAPI_TOKEN` are present | `META_CAPI_PIXEL_ID` is **absent** → CAPI runs on the code default `1614877760150035` (`core/config/services.php:99`, read) |
| `carts`: 27 rows, 24 guest, no `storefront_id` column; `orders` with NULL `storefront_id`: **9** | the NULL-means-Watchizer ownership rule in `PCC:204` is load-bearing on 9 real orders |
| Watchizer takes card payments today (developer) | with the env keys empty, they can only be flowing through the database contract (`storefront_payment_providers`, storefront 1, `paymob`) — **inferred** |

---

## 1. Entry points

| # | Entry | Route / schedule | Handler | What it does | Reachable today |
|---|---|---|---|---|---|
| E1 | Method list | `GET /api/v2/{storefront}/payment-methods` (`core/routes/api.php:49`) | `V2/PaymentMethodController::index` → `CheckoutMethods::offered()` (`D/P/CheckoutMethods.php:59`) | the online methods a shopper may pick | yes (read) |
| E2 | Checkout | `POST /api/add_order` (`core/routes/api.php:146`, `throttle:add-order`) | `CCC::addOrder` (`CCC:84`) | creates the order; for card calls `paymob()` (`CCC:385`) | yes (read) |
| E3 | Scoped callback | `GET|POST /api/pay/{storefront}/{provider}/callback` (`core/routes/api.php:283`) | `PCC::handle` (`PCC:78`) | verifies with the contract's secret, settles the order | yes — Watchizer's live path (inferred, §0) |
| E4 | Legacy callback alias | `GET|POST /api/callback_payment` (`core/routes/api.php:294`) | `PCC::alias` (`PCC:117`) | if Watchizer's Paymob contract is live → `process(1,'paymob')` (`PCC:123`); else → wave-3 handler `CCC::callbackPayment` (`CCC:428`) | **only if something still calls that URL** — see §9 U3 (inferred) |
| E5 | Unpaid expiry | `orders:expire-unpaid` every minute (`core/routes/console.php:23`) | `OrdersExpireUnpaidCommand` → `UnpaidOrders::cancel(…, EXPIRED)` (`core/app/Domain/Orders/UnpaidOrders.php:109`) | cancels pending card orders older than `UNPAID_ORDER_EXPIRY_MINUTES` (default 60, `core/config/compat.php:135`), releases stock, mails recovery | yes (read) |
| E6 | Supersede | inside `addOrder` (`CCC:218`) | `UnpaidOrders::supersedeFor` (`UnpaidOrders.php:83`) | cancels the same shopper's own unpaid card orders on this storefront before a new order | yes (read) |
| E7 | Recovery link | `POST /api/cart/recover` (`core/routes/api.php:106`) | `CartRecoveryController` → `OrderRecovery::payload` (`core/app/Domain/Orders/OrderRecovery.php:225`) | rebuilds a cart from a CANCELLED `paymob` order; writes nothing | yes (read) |
| E8 | Cancellation reconcile | `inventory:reconcile-cancellations` every minute (`core/routes/console.php:17`) | — | returns stock of orders cancelled anywhere | yes (read; not payment-specific) |
| E9 | Meta CAPI drain | `meta:drain` every minute (`core/routes/console.php:107`) | `MetaConversions::drain` (`core/app/Domain/Analytics/MetaConversions.php:181`) | retries card Purchase events; no-op while `META_CAPI_TOKEN` empty (`MetaConversions.php:52`) | yes (read) |
| E10 | Findings report | `payments:findings` (`core/app/Console/Commands/PaymentFindingsCommand.php:26`) | — | lists open reconciliation findings | manual (read) |
| E11 | Dashboard — contracts & methods | `/manage/storefronts/{s}/payments…` (`core/routes/web.php:425-439`, `can:manage-payments` + storefront scope) | `Manage/PaymentSettingsController` | add/edit/delete contracts (credentials) and methods (integration ids, order, limits) | yes (read) |
| E12 | Dashboard — orders | `orders/{order}/cancel`, `orders/{order}/status`, `orders/{order}/findings/{f}/resolve`, `orders/export/settlement` (`core/routes/web.php:336-354`) | `Manage/OrderController` | cancel (stock back), advance status, clear a finding, settlement CSV per attempt (`OrderController.php:474`) | yes (read) |
| E13 | Race proof | `payment:prove-callback-race` (+ `payment:race-worker`, `payment:race-lock-holder`) | `PaymentProveCallbackRaceCommand.php:51` | test harness; writes probe orders | manual, never on production (read) |

---

## 2. The spine — one card order, Watchizer, contract live

| Hop | What happens | Where | Tag |
|---|---|---|---|
| 1 | Checkout loads core's online methods; `retry: 1`, 60 s stale | `Frontend-next/src/Hooks/queries/usePaymentMethods.js:16,20` → E1 | read |
| 2 | Core returns only winners that can take money: `MethodList::forCustomer` winners, minus rows with an `unusable` reason, minus rows whose contract is not live (enabled AND `credentialsComplete`) | `CheckoutMethods.php:125-160`, `:163-171`; winners `MethodList.php:46`, candidates `:202` (ORDER BY `m.sort, p.provider, m.method, m.id`) | read |
| 3 | Shopper picks a row → `paymentMethod='card'`, `methodId=row.id` | `Frontend-next/src/PageViews/Checkout/Checkout.jsx:838-875` | read |
| 4 | Submit: `payment_method:'card'`, `payment_method_id`, client total, items, address / guest fields | `Checkout.jsx:478-505` | read |
| 5 | Buyer from the JWT, never the body | `CCC:126-132` | read |
| 6 | Validate (`payment_method` ∈ cash, card, paymob, whatsapp) | `CCC:163` | read |
| 7 | **Method check before any write** — the posted id must be one of THIS storefront's offered rows, agree with the legacy string, sit inside its limits | `CCC:192` → `CheckoutMethods::refusal` (`CheckoutMethods.php:76-111`) | read |
| 8 | Shopper's own older unpaid card orders cancelled (supersede) | `CCC:218` | read |
| 9 | `card` → stored as `paymob` | `CCC:220` | read |
| 10 | Address, lines, server pricing, client-total check (±0.01), promotions, discount clamp | `CCC:222-310` | read |
| 11 | Order row: `storefront_id` = the request's storefront, `payment_method='paymob'`, `status='pending'`, `order_number` = global MAX+1; stock reserved through the ledger | `CC:158-190` (`:180`, `:182`, `:185`) | read |
| 12 | Discount audit row; Meta browser signals stored for the later server Purchase; cart destroyed | `CCC:339`, `:344`, `:348-352` | read |
| 13 | **COMMIT** — the order exists before the provider is contacted | `CCC:356` | read |
| 14 | No e-mail for a card order at checkout | `CCC:380-382` | read |
| 15 | Amount = the STORED order total (discounted), billing from the order | `CCC:826-827`, `:898-930` | read |
| 16 | `PaymentInitiator::initiate` | `CCC:830` | read |
| 17 | Resolve the posted method to a row + contract | `D/P/PaymentInitiator.php:70` → `MethodList::resolve` (`MethodList.php:254`; legacy `card`/`paymob` → winning `card` row, `:272`) | read |
| 18 | Contract must be this storefront's, enabled, credentials complete; else **null** (→ wave-3, §4 F1) | `PaymentInitiator.php:75-86` | read |
| 19 | Implementation from the registry (`paymob`, `cod`, `whatsapp` only) | `PaymentInitiator.php:88`; `D/P/ProviderRegistry.php:31-33` | read |
| 20 | Integration id of the ENABLED method row with that key under that contract | `PaymentInitiator.php:94` | read |
| 21 | Callback URL = `contract.settings.callback_base` or the request's own scheme+host, https only, no IP/.local/.test; else initiation refused | `PaymentInitiator.php:114`; `D/P/CallbackDestination.php:96-101`, `:113-137` | read |
| 22 | Amount in minor units, rounded once | `PaymentInitiator.php:141` | read |
| 23 | `PaymobProvider::initiate`: needs `secret_key`, `public_key`, an integration id; POSTs the intention (30 s timeout) with `special_reference` = **order number**, both callback URLs, `expiration` only if configured | `D/P/PaymobProvider.php:87-187` (`:110`, `:140`, `:157-160`, `:164`) | read |
| 24 | Success → unified-checkout URL; failure → status-only reason. **The provider's reference (`id`) is returned and then dropped** — nothing stores it. *Decided 2026-10-12 (not built): store Paymob's ORDER id at initiation, not the intention id — see §14* | `PaymobProvider.php:178-186`; `CCC:842-860` uses only `checkoutUrl` / `failureReason` | read |
| 25 | Refused by provider → cancel + release, 422 `Payment session failed` + `paymob_error` | `CCC:851-861`, `cancelAndRelease` `CCC:891-895` | read |
| 26 | Browser: `window.location.href = redirect_url` | `Checkout.jsx:513-514` | read |
| 27 | Shopper pays on Paymob | external | — |
| 28 | Paymob calls back twice: POST (`notification_url`, server-to-server, nested `obj.*`) and GET (`redirection_url`, browser, flattened) — same URL | `CallbackDestination.php:58,76`; route `core/routes/api.php:270-283` | read |
| 29 | Callback checks 1–4 + idempotency + decision (detail in §7) | `PCC:154-242` | read |
| 30 | One transaction: insert `payment_statuses` attempt; finding if any; **PAY** → `status='processing'`, `paid_via_provider`, `paid_via_method`, queue customer+admin mail, queue Meta Purchase; **CANCEL** → `status='cancelled'`, release stock, **queue the recovery e-mail with `reason=declined` (2026-10-12, same gate and dedupe as the expiry)** | `PCC:292-343` (`:294`, `:316-337`, `:338-342`); the decline mail in the cancel branch | read |
| 31 | After commit: send mail, send Meta (failures retried by crons) | `PCC:401-403` | read |
| 32 | Answer: POST → JSON 200 (`ok` / `failed` / `Already processed` / `Recorded for reconciliation`); GET → **redirect to `<return base>/order-confirmation?order=<number>&status=paid\|failed\|pending`** on the storefront the callback was SCOPED to, the status from the policy's verdict (`Landing::of`), the replay from the recorded attempt (`Landing::ofRecorded`). Every non-POST exit, refusals included (no number on a refusal); the unknown-storefront 404 is the one exit that stays JSON for a browser (no host fallback). *(2026-10-12, `a0dda59`; before: the site root, `?payment_error=1` when not paid, and raw JSON on the replay and every refusal)* | `PaymentCallbackController::land()`; `Landing.php`; `StorefrontUrls::paymentLanding()` | read |
| 33 | Storefront: `/order-confirmation` reads `order` and `status` from the query when its store is empty and renders one of three states in both languages — a confirmation MESSAGE, not a receipt (fetches nothing, verifies nothing, fires no Purchase). *(2026-10-12, `932a2d1`; before: nothing read `payment_error`, a failed payment landed on the home page with no message)* | `Frontend-next/src/PageViews/Checkout/OrderConfirmation.jsx` (`CardLanding`); `app/(main)/order-confirmation/page.jsx` | read |

---

## 3. Credentials and secrets

| Secret / identifier | Where it lives | Protection | Written by | Read by | When absent |
|---|---|---|---|---|---|
| Paymob `secret_key`, `public_key`, `hmac_secret` (per storefront, per provider) | `storefront_payment_providers.credentials` (`core/database/migrations/2026_09_18_000000_payments_and_order_scope.php:92`) | Laravel `encrypted:array` (`core/app/Models/Storefront/StorefrontPaymentProvider.php:52`) = AES-256-CBC under `APP_KEY`; `$hidden` (`:45`); in memory only as `ProviderCredentials` with no dump/serialise (`D/P/ProviderCredentials.php:18-78`) | dashboard, write-only fields; blank keeps the stored value (`PaymentSettingsController.php:473-499`); the field list comes from the implementation (`PaymobProvider.php:77`) | initiation (`PaymentInitiator.php:84,148`), callback (`PCC:173-182`), live gates (`CheckoutMethods.php:163-171`, `PCC:135-152`) | contract not live → not offered (E1), card refused if posted with an id, null initiation → wave-3 (§4 F1); callback 404 `contract holds no credentials` (`PCC:176`) | read |
| Integration id (per method) | `storefront_payment_methods.integration_id` (migration `:109`) | none — not a secret (Paymob signs it into callbacks) | dashboard (`PaymentSettingsController.php:217-262`), digits-only check (`PaymobProvider.php:55-63`) | initiation (`PaymentInitiator.php:94`), method resolution at callback (`PCC:529-535`) | method `unusable`, never offered; enabling refused (`PaymentSettingsController.php:538-557`) | read |
| Callback base override | `storefront_payment_providers.settings.callback_base` (`CallbackDestination.php:96`) | none | **no dashboard field writes `settings`** — `storeProvider` writes `settings => null` (`PaymentSettingsController.php:118`); not found in `updateProvider` | `CallbackDestination.php:96` | request host used | read; "no writer" inferred from the two write sites |
| Global Paymob keys (wave 3) | `core/.env` `PAYMOB_SECRET_KEY/PUBLIC_KEY/HMAC_SECRET` → `config('services.paymob.*')` (`core/config/services.php:80-82`) | plain env | the developer on the host; **advertised blank in `core/.env.example:151-155`** | `CC:292` (HMAC), `CC:372-376` (intention) | intention throws (`CC:375`) → caught → cancel + 422 (`CCC:879-887`); HMAC → false → 403 (`CC:292-294`, `CCC:433-435`) | read; production empty = verified |
| Global integration ids (wave 3) | `PAYMOB_PAYMENT_METHODS`, **default `4988969,4627487,3961568` in code** (`core/config/services.php:83`) | — | — | `CC:378,385` only | default used — unreachable while keys are empty (§9 U1) | read |
| `APP_KEY` (+ `APP_PREVIOUS_KEYS`) | `core/.env`; previous keys honoured for decryption (`core/config/app.php:102-104`) | host secret | the developer | every decrypt of `credentials`; the recovery-link HMAC (`OrderRecovery.php:306-310`) | a changed key without the old one in `APP_PREVIOUS_KEYS` → credentials undecryptable → initiation and callbacks fail (inferred from the cast; not run) | read / inferred |
| Recovery-link key | derived from `APP_KEY` (`OrderRecovery.php:308`) | HMAC-SHA256, 32 hex | — | `OrderRecovery::verify` (`:102`) | — | read |
| Meta CAPI token | `core/.env` `META_CAPI_TOKEN` (`core/config/services.php:98`) | plain env | the developer | `MetaConversions.php:52,208` | CAPI off entirely (`MetaConversions.php:52,88`) | read; present in production = verified |
| Meta CAPI pixel id | `core/.env` `META_CAPI_PIXEL_ID`, **code default `1614877760150035`** (`core/config/services.php:99`); `core/.env.example:162` ships the same id | — | — | `MetaConversions.php:213` | Watchizer's pixel used — **this is what production runs on** | read; absent in production = verified |
| Storefront public pixel / GA4 | `Frontend-next` build env `NEXT_PUBLIC_META_PIXEL_ID`, `NEXT_PUBLIC_GA4_MEASUREMENT_ID` | public by nature | build env / hPanel panel | storefront analytics | silent | read (backlog GA4-PV) |
| Storefront card switch | `NEXT_PUBLIC_PAYMOB_ENABLED` (`Frontend-next/src/lib/env.js:9`, default **on** unless `'false'`) | — | build env | `Checkout.jsx:838,879-907` | — | read |

---

## 4. Branches and fallbacks

| # | Branch | Selected when | Path taken | Can it execute today? |
|---|---|---|---|---|
| F1 | **Null initiation → wave-3 builder** | no live contract for the method's storefront, or no method resolved (`PaymentInitiator.php:71,83-86,89`) | `CC::createPaymobIntention` with the GLOBAL keys (`CCC:863`) | **Reached, but throws**: keys empty → `RuntimeException` (`CC:375`) → `CCC:879-887` cancels + releases + 422 "Payment could not be initiated". **Cannot charge any account today** (inferred from read + §0). If `PAYMOB_*` are ever set (as `.env.example` invites), it would initiate on that account with **no callback URLs** (`CC:381-390`) — the portal decides where the callback goes. |
| F2 | `CheckoutMethods:82` — a card order with NO method id | skipped (not refused) unless that storefront's **Paymob** contract is live (`CheckoutMethods.php:82`, `paymobLive` `:114-120`) | for a storefront without a Paymob contract, the order is created and goes to F1 | yes — reaches F1's 422 (inferred) |
| F3 | Storefront fallback "Pay Online · via Paymob" | method list loading, or failed after one retry, and `NEXT_PUBLIC_PAYMOB_ENABLED` not `'false'` (`Checkout.jsx:838`, `:879-907`) | posts `card` with no id → F2 → (Watchizer) the live card row via `MethodList::resolve` (`MethodList.php:272`), (a shop without a contract) F1 | yes (read) |
| F4 | Alias → wave-3 callback handler | Watchizer's Paymob contract not live (`PCC:119-121`, `:135-152`) | `CCC::callbackPayment` — global HMAC | **Reached only if the contract is disabled; then every callback 403s** (`CC:292-294` with the secret empty) — inferred |
| F5 | Host → storefront | the request host matches no active `storefronts.domain` | `CompatStorefront::id()` falls back to `config('compat.storefront_id')` = **1 (Watchizer)** (`core/app/Compat/CompatStorefront.php:80`, `core/config/compat.php:37`) | yes, for any unmatched host (read). For a second shop on an unmatched host: its orders, methods and contract checks would all be **Watchizer's** (inferred). |
| F6 | Payment return URL | storefront has no `settings.payment_return` | default storefront → `COMPAT_PAYMENT_RETURN_URL` (default `https://watchizereg.com/`, `core/config/compat.php:140`); others → `https://<domain>/` (`StorefrontUrls.php:45-51`) | yes (read) |
| F7 | Callback ownership with NULL `orders.storefront_id` | order row has NULL and the route's storefront is 1 | accepted as Watchizer's (`PCC:204-213`) | yes — 9 production orders (verified count) |
| F8 | Recovery URL host | order's storefront has no domain | `watchizereg.com` (`OrderRecovery.php:83-87`) | yes for the 9 NULL orders (inferred) |
| F9 | Meta pixel | `META_CAPI_PIXEL_ID` unset | Watchizer's id (`core/config/services.php:99`) | yes — production (verified) |
| F10 | Callback base | no `settings.callback_base` | request host (`CallbackDestination.php:101`) | yes — the only path, since nothing writes the setting (inferred) |
| F11 | Method resolution at callback | precedence: (1) method recorded at initiation, (2) provider's `integration_id`, (3) NULL (`PCC:500-536`) | **(1) never matches** — no code writes a `payment_statuses` row at initiation (the only inserts: `PCC:294`, `CCC:540`, `D/P/CallbackPolicy.php:269`) | (2) is what runs (inferred from the three insert sites) |
| F12 | Legacy method string | `payment_method` `card`/`paymob` | `MethodList.php:272` → the winning `card` row; `CheckoutMethods.php:102` → any non-offline row | yes (read) |
| F13 | Intention expiry | `PAYMOB_INTENTION_EXPIRATION_SECONDS` set AND shorter than the unpaid window | sent (`PaymobProvider.php:137-141`, `:314-321`) | production has the key (verified); whether it is sent depends on its value vs 60 min (not known) |

---

## 5. Provider name as a literal

| Literal | Where | Means | Tag |
|---|---|---|---|
| `orders.payment_method = 'paymob'` | written `CCC:220`; read by `UnpaidOrders.php:116,155`, `OrderRecovery.php:156,228`, `CheckoutMethods.php:82,102`, `MethodList.php:272`, `CCC:384`, `OrderController.php:742` (filter), `OrderEmailData.php:387-388` (labels "Paid Online (Card / Wallet)") | **"online"**, whichever provider | read |
| Promotions `payment_method_in` | `PromotionRules::PAYMENT_METHODS = ['cash','paymob','whatsapp']` (`core/app/Domain/Promotions/PromotionRules.php:147`); validation `PromotionController.php:345`; **stored rules may hold `paymob`** (rows not inspected) | "online" | read |
| `payment_statuses.provider` default `'paymob'` | migration `:140` | the provider (default for legacy-written rows) | read |
| `'paymob'` in the wave-3 handler's findings | `CCC:548,612` | the provider | read |
| Alias → `(1, 'paymob')` | `PCC:123`, `:138-139` | the provider (Watchizer's) | read |
| `PaymobProvider::KEY` | `PaymobProvider.php:36`; registry `ProviderRegistry.php:31` | the provider | read |
| `CheckoutMethods::paymobLive` | `CheckoutMethods.php:82,114-120` | **the provider, used as "online is live"** | read |
| `CallbackPolicy::outcomeFromPaymob` called in the generic handler | `PCC:240` | the provider's payload shape | read |
| Dashboard copy: integration-id hint/refusals ("the number Paymob sent", "digits only") | `core/lang/en/manage.php:1188-1191,1250,1255` (+ `lang/ar`), `core/resources/js/pages/Manage/Payments/Index.tsx:659,684,1108` | the provider | read |
| Storefront money-reward warning "On card (Paymob)…" | `core/lang/en/manage.php:1820`; `Manage/Storefronts/Edit.tsx:193` | the provider | read |
| Storefront copy "Secure payment via Paymob · Visa / Mastercard" | `Checkout.jsx:907` | the provider | read |
| Storefront flag `NEXT_PUBLIC_PAYMOB_ENABLED` / `PAYMOB_ENABLED` | `env.js:9`; `Checkout.jsx:28,838,879-907` | "online on" | read |
| Trust pages (privacy, terms, payment) | `Frontend-next/src/content/trustPages.js:105,120,142,157,184,221` | the provider — Watchizer's legal text | read |
| Env names `PAYMOB_*` | `core/config/services.php:80-83`, `core/config/compat.php:136`, `core/.env.example:151-155` | the provider | read |

---

## 6. Data model (payment columns only)

| Table.column | Meaning | Written at (hop) | Tag |
|---|---|---|---|
| `storefront_payment_providers` (`storefront_id`, `provider`, `is_enabled`, `credentials`, `settings`; UNIQUE (`storefront_id`,`provider`)) | one CONTRACT | dashboard E11 | read (migration `:70-99`) |
| `storefront_payment_methods` (`storefront_payment_provider_id` FK cascade, `method`, `integration_id`, `icon`, `is_enabled`, `sort`, `settings` = min/max total; UNIQUE (provider,`method`)) | one pickable METHOD under a contract; the same key may exist under two contracts — lowest `sort` wins at display | dashboard E11 | read (migration `:101-123`; winner `MethodList.php:46-66`) |
| `storefront_payment_method_translations` | per-locale label | dashboard | read |
| `orders.storefront_id` | the shop the order belongs to (nullable, no FK) | hop 11 (`CC:180`); NULL on 9 old rows | read / verified |
| `orders.payment_method` | `cash` / `paymob` / `whatsapp` — legacy, "online" | hop 11 | read |
| `orders.status` | `pending` (card, WhatsApp) / `processing` (cash; card when paid) / `cancelled` / fulfilment states | hops 11, 30; E5, E6, E12 | read |
| `orders.paid_via_provider`, `paid_via_method` | who took the money — **written only by a successful scoped callback** (`PCC:323-324`); the wave-3 handler does not write them (`CCC:562-578`); COD orders get nothing | hop 30 | read |
| `payment_statuses` (`order_id`, `provider` NOT NULL DEFAULT 'paymob', `method`, `storefront_payment_method_id` FK nullOnDelete, `pay_order_id`, `pay_transaction_id`, `amount_cents`, `success`, `outcome`; UNIQUE (`provider`,`pay_transaction_id`)) | one row per CALLBACK received — never one at initiation | hop 30; lost-callback path | read (migration `:138-160`, `2026_09_20…:52-55`) |
| `payment_reconciliation_findings` (`order_id`, `payment_status_id`, `provider`, `kind`, `outcome`, `order_status`, `amount_cents`, `detail`, `resolved_*`) | needs a human | callback decision / lost callback (`CallbackPolicy.php:184-218`); resolved E12 | read (migration `2026_09_20…:59-79`) |
| `inventory_movements` | reservation at hop 11, release on cancel | `InventoryService` | read (via `UnpaidOrders.php:151-163`) |
| `integration_outbox` (`meta:purchase:<order>`) | the Meta Purchase, deduped per order | hop 30 (`MetaConversions.php:93-108`) | read |

---

## 7. The callback in detail (`PCC::process`, `PCC:154-411`)

| Step | Check | Failure answer | Writes on failure |
|---|---|---|---|
| 0 | `{storefront}` code exists AND the request host belongs to it (`PCC:80-89`; `StorefrontHost::serves`, `core/app/Storefront/StorefrontHost.php:105-114` — unknown host passes only in `local`/`testing`) | 404 | nothing |
| 1 | Contract (storefront, provider) exists and enabled (`PCC:160-165`); implementation registered (`:167-170`); credentials non-empty (`:172-177`) | 404 | nothing (log line) |
| 2 | HMAC-SHA512 over Paymob's 20 fields with THIS contract's `hmac_secret` (`PaymobProvider.php:197-221`) | 403 | nothing |
| 3 | Order: by the SIGNED `order` field via a previous attempt's `pay_order_id`, else the unsigned reference as `order_number`, else as id (`PCC:434`) | 404 "names no order" | nothing |
| 4 | Ownership: order's storefront = route's, or NULL and route = 1 (`PCC:196-213`) | 403 | nothing |
| 5 | Idempotency: (provider, `pay_transaction_id`) already recorded (`PCC:219-223`); the UNIQUE index backs it under a race | POST: 200 "Already processed"; GET: the landing, with what the RECORD says (2026-10-12) | nothing |
| 6 | Amount: provider minor units == `round(order total × 100)` exactly (`PCC:225-227`) | attempt recorded `success=false`, finding `amount_mismatch`, order untouched (`CallbackPolicy.php:138-140`) | attempt + finding |
| 7 | Outcome from Paymob flags: pending > refunded > voided > success/failed (`CallbackPolicy.php:110-127`) | — | — |
| 8 | Decision table (`CallbackPolicy.php:134-172`) | see below | attempt always |

| Scenario | Result | Tag |
|---|---|---|
| Duplicate delivery (POST + GET of one payment, or a retry) | step 5 → 200 "Already processed"; a simultaneous pair is caught by the UNIQUE index | read; inferred for the race (proven by `payment:prove-callback-race` per its docblock, not run here) |
| Late success after expiry/supersede cancelled the order | `cancelled` is TERMINAL → attempt recorded, finding `callback_on_terminal_order`, order stays cancelled, **money taken** → human refund or re-open (`CallbackPolicy.php:147-150`; `UnpaidOrders.php:33-40`) | read |
| Out of order: decline then success | decline cancels a `pending` order and releases stock (`CallbackPolicy.php:167-169`); the success then hits TERMINAL → finding | read |
| Out of order: success then decline | `processing` + failed → finding `decline_after_payment`, order untouched (`CallbackPolicy.php:171`) | read |
| Refund / void on a live order | finding `refund_or_void`, order untouched (`CallbackPolicy.php:152-160`) | read |
| Order no longer exists | step 3 → 404, nothing written. (Orders are never deleted — cancelled instead: `CCC:869` comment, read) | read |
| Deadlock past retries | attempt + finding `callback_lost_to_deadlock` written outside the transaction, 200 to stop retries (`PCC:345-389`; `CallbackPolicy.php:255-298`) | read |
| `pending` callback (3-D Secure) | attempt recorded, no finding, order untouched (`CallbackPolicy.php:142-145`) | read |

---

## 8. Failure modes

| Mode | Shopper sees | Order becomes | Cleaned up by | Tag |
|---|---|---|---|---|
| Provider down / timeout at initiation | 422; banner = `data.message` = **"Payment session failed"**, English even on the Arabic page (`CCC:855-861`; `Checkout.jsx:570-571`) | `cancelled`, stock released (`CCC:853`) | immediate | read / inferred (banner language) |
| Timeout where Paymob DID create the intention | same as above; the shopper never got the URL, so cannot pay it | `cancelled` | — | inferred |
| No live contract (or a shop without one) posting card | Watchizer: method not offered; posted with id → 422 "not available, nothing charged" before any write (`CheckoutMethods.php:110`). Without id (F3) → order created then 422 "Payment could not be initiated" (`CCC:885`) | with id: none; without id: `cancelled` | immediate | read |
| Shopper abandons Paymob's page | lands nowhere (stays on Paymob) | `pending` | `orders:expire-unpaid` after 60 min → `cancelled`, stock back, recovery e-mail to customer + admin copy unless they re-ordered (`UnpaidOrders.php:109-147`) | read |
| Shopper pays, closes the tab, processed callback lost | nothing | `pending` → **cancelled by expiry after 60 min although paid**; recovery e-mail sent; **no finding** unless a callback arrives later. `resolveStatus()` exists but no code calls it (`PaymobProvider.php:270`; `D/P/PaymentProvider.php:73-75` "not called by any wave-4C path") | nobody — a paid, cancelled order with no record. *Decided 2026-10-12, not built (§14): record the provider's order id at initiation, reconcile by ORDER inquiry before expiry with a per-run cap, settle automatically on Paid* | inferred from read |
| Declined card | `/order-confirmation?order=…&status=failed` — "payment not completed, the order is not confirmed; if your card was charged, contact us" — and the recovery e-mail with `reason=declined` (customer + admin copy), unless the weekly gate or a later order silences it (2026-10-12; before: the shop root with an unread `?payment_error=1`, no e-mail) | `cancelled`, stock released | callback | read |
| Two tabs, both submitted | second `add_order` supersedes (cancels) the first tab's unpaid order (`CCC:218`); paying in the first tab afterwards → TERMINAL finding, money taken | first `cancelled`, second `pending` | human, via the finding | inferred |
| Double-click submit | client guard `submitting.current` (`Checkout.jsx:461-462`); server throttle `add-order` | one order (client) | — | read |
| Amount mismatch | `/order-confirmation?order=…&status=pending` — "being confirmed", because the policy's finding means a human looks (2026-10-12; before: `payment_error=1`) | untouched (`pending`, stock reserved) — then **expiry cancels it** after 60 min unless a success attempt exists (`UnpaidOrders.php:162-166` excludes only successful attempts) | finding `amount_mismatch` + expiry | inferred |
| Callback for another shop's order | POST: 403, nothing written; GET: redirect to the refusing storefront's `status=failed` landing with no order number (2026-10-12) | untouched | — | read |
| Refused callback, any check (bad signature, no contract, unknown order, foreign order) | as above; **and one line in `storage/logs/payments-YYYY-MM-DD.log`** naming the check, storefront, provider, HTTP method and the claimed integration id and merchant order id — a channel whose level is never read from `LOG_LEVEL` (2026-10-12, `41e5a58`; before: a `warning` on the default channel that production's `LOG_LEVEL=error` dropped) | untouched | the operator, from the log | read |

---

## 9. Unreachable today

| # | Code | Why it cannot run | Tag |
|---|---|---|---|
| U1 | The wave-3 intention (`CC:370-404`) past `CC:375` | production keys empty → throws first | inferred (§0 verified) |
| U2 | The wave-3 callback handler past the signature (`CCC:436-660`) | `isValidPaymobHmac` returns false on an empty secret (`CC:292-294`) → 403 at `CCC:433-435` | inferred |
| U3 | `PCC::alias()` → wave-3 (`PCC:119-121`) | Watchizer's contract is live (inferred from §0), so the alias runs `process()`. **Whether anything still CALLS `/api/callback_payment` at all** depends on the URL in Paymob's merchant portal — core sends its own URLs on every intention (`CallbackDestination.php`), so only the portal could. Not visible from here. | inferred |
| U4 | `config('services.paymob.payment_methods')` default ids (`core/config/services.php:83`) | only read by U1 | read |
| U5 | `resolveMethodId` precedence 1 (`PCC:502-528`) | nothing records a method at initiation (F11) | inferred |
| U6 | `CallbackDestination` `settings.callback_base` arm (`:96-99`) | nothing writes `settings` (§3) | inferred |
| U7 | `PaymobProvider::resolveStatus` (`:270-293`) | no caller | read (grep: no call site outside the class) |
| U8 | `StorefrontHost::serves` local/testing pass (`StorefrontHost.php:113`) | production env | read |

---

## 10. Contradictions with what is recorded

| Record | Says | Code / measurement says | Tag |
|---|---|---|---|
| `core/docs/deploy/RUNBOOK_PHASE2_STOREFRONT.md:858-859` (§3A.2 step 2) | "Disabling it is the rollback — it puts the alias straight back on the wave-3 path with no deploy" | with `PAYMOB_*` empty, disabling the contract makes initiation throw (F1) and every callback 403 (U2). **The rollback cannot work** | inferred + verified (§0) |
| `RUNBOOK_PHASE2_STOREFRONT.md:823` (§3A.1) | the alias fall-through is "correct as long as the legacy app is the one taking payments" | the legacy app is deleted; the fall-through can only 403 | inferred |
| `core/routes/api.php:289-292` | "Until the runbook's `.env`-to-table step is performed it falls through…, so today's live callback behaves exactly as it does today" | the step is done; the sentence describes a past state | inferred |
| `new branding/AGENTS.md:39` (§19) | "Credentials never live in `.env` beyond the legacy Watchizer path that already does" | production has no Paymob credentials in `.env` at all; the legacy path holds none | verified (§0) |
| `new branding/docs/CLEAN_CORE_STUDY.md` §3.9.4 (`:2183-2187`) and `D/P/PaymentProvider.php:52-53` | the method the customer chose is "recorded at INITIATION… the value all reporting reads" | nothing writes at initiation (F11) | read |
| `CLEAN_CORE_STUDY.md` §3.9.6 (`:2276`) | `paid_via_provider` / `paid_via_method`: "COD orders get `cod` when the order is placed" | only the successful scoped callback writes them (`PCC:323`) | read |
| `core/lang/en/manage.php:1820` | on card, "the frontend asks the provider for the full amount" so discounted card orders fail the amount check | core initiates with the STORED (discounted) total (`CCC:826`, `CC:180-185` via `placeOrder`), so the amounts would agree; the COD half of the warning (`:1821`) still stands | inferred from read |
| `Frontend-next/src/PageViews/Checkout/Checkout.jsx:507` | "cart cleared by the callback" | the server cart is destroyed in `add_order`, before payment (`CCC:346-353`) | read |
| `core/docs/deploy/POST_CUTOVER_BACKLOG.md` PAY-GENERIC (2026-10-11, mine) | the fallback "reads the GLOBAL `PAYMOB_*` keys — Watchizer's account if they are still in production's `core/.env`" | they are not; today it throws and cancels (F1). The risk is re-arming, not a live leak | verified (§0) |
| `CLEAN_CORE_STUDY.md` §3.9.1 (`:2059-2060`) | `APP_KEY` rotation "needs a re-encrypt command written before it is ever attempted" | `APP_PREVIOUS_KEYS` is wired (`core/config/app.php:102-104`), which lets old ciphertext still decrypt after a rotation — no re-encrypt needed to keep working | read (framework behaviour inferred, not run) |
| `core/.env.example:151-152` | "Unset means the card branch answers the legacy 'payment could not be initiated' 422" | true for a shop with no live contract; but the file still lists the three keys as things to set | read |

**Status 2026-10-12 — corrected in the documentation pass, by file:** runbook `:858-859` (§3A.2 step 2 rewritten:
no no-deploy rollback; the off switch is the method rows) and `:823` (§3A.1 note); `core/routes/api.php:289-292`
(comment); `AGENTS.md:39` (credentials; the wave-3 state retired); study §3.9.4 (marked NOT BUILT, with the
order-id decision), §3.9.6 (as built), §3.9.1 (`APP_PREVIOUS_KEYS`); `manage.php:1820` (card line rewritten —
core charges the stored discounted total, so the amounts agree and the customer is charged less than the
figure shown, like COD); `Checkout.jsx:507` (corrected in `932a2d1`: the cart is destroyed at order placement);
backlog PAY-GENERIC (the clause); `.env.example` (the three `PAYMOB_*` keys and the pixel default removed).

---

## 11. My earlier statements today — confirmed or corrected

| Statement (2026-10-11) | Now |
|---|---|
| "A Brand Fashion card order would go through the global `PAYMOB_*` keys, Watchizer's if still set" | **Corrected.** Conditional was right, the live risk was not: keys are empty, so it throws and cancels (F1). It re-arms if anyone sets them. |
| "`CheckoutMethods:82` lets a no-contract shop's card order through" | Confirmed (F2) — to F1's 422, not to a charge. |
| "The storefront fallback option posts exactly that request" | Confirmed (F3). |
| "Orders record the provider only on success; nothing records where an order was SENT" | Confirmed, and wider: the study and the interface both promise an initiation record (§10), and `resolveMethodId` was built to read it (F11). |
| "The schema already has the provider dimension; the winner is enabled + lowest sort" | Confirmed (`MethodList.php:46-66,202-215`). |
| "The HMAC secret is a credential field of the contract, entered on the dashboard" | Confirmed (`PaymobProvider.php:77`, `PaymentSettingsController.php:473-499`). |
| "The allow-list line covers every provider's callback" | Confirmed for the path shape (`core/routes/api.php:283`; runbook `:1016`). |
| "The rollback works only if (a) keys set and (b) portal URL points at core" | (a) is false → **the rollback is dead today**, independent of (b). And (b) never mattered for core's own intentions: **observed 2026-10-12** — the test payment on integration 4988969 reached the api host while the portal still named the dead `dash` host (runbook §4A.3). |
| "The NULL-counts-as-Watchizer rule on orders may cover an impossible case" | **Refuted** by the measurement: 9 orders. |
| "Remove the `META_CAPI_PIXEL_ID` default; empty = send nothing" | **Unsafe as stated** — production relies on the default (§0). Set the key first, verify, then remove the default. |
| Not said before, found now | F5 (unmatched host → Watchizer), §2 hop 33 (`payment_error` unread), §8 paid-then-expired with no finding, §2 hop 24 (provider reference discarded). |

---

## 12. The two questions

### 12.1 Does `core/.env.example` still advertise the Paymob keys?

**Yes** (read): `core/.env.example:151-155` lists `PAYMOB_SECRET_KEY=`, `PAYMOB_PUBLIC_KEY=`, `PAYMOB_HMAC_SECRET=` under a
comment that reads as setup ("developer-handled, never committed"). `:162` ships `META_CAPI_PIXEL_ID=1614877760150035`.
A new install that fills them re-arms F1 for **every** storefront on that install without a live contract —
Brand Fashion's included — and points its CAPI at Watchizer's pixel. Per the developer's framing this is
an **A2** item.

### 12.2 Why are payment credentials in the database while A2 plans mail credentials in `.env`?

**What is recorded (read):** only the payment side is decided. `new branding/AGENTS.md:39` (§19, developer
decisions 2026-09-09/10) puts payment credentials in `storefront_payment_providers`, encrypted, and forbids
`.env` for them; `CLEAN_CORE_STUDY.md` §3.9 (`:1957-2072`) gives the reasons. No record decides where mail
credentials live: `BRAND_FASHION_HANDOVER.md:142-143,443,474` say "per-storefront mailer" without a store,
and `AGENTS.md:125` says only that mail credentials are developer-handled. **So it is not a deliberate
split — it is undecided.**

**The reasons §3.9 gives, and whether each applies to mail (inferred):**

| Reason for payments in the DB | Applies to mail? |
|---|---|
| Several contracts per storefront at once, added and switched without a deploy (§19) | No — one mailbox per storefront. |
| The set grows with storefronts; an env key per storefront means a host edit + `config:cache` per new shop | **Yes** — the same argument the developer accepted for `settings.api_host`. |
| A provider object must never find its own secrets; a global config is what made one-account-for-all possible (`D/P/PaymentProvider.php:19-21`) | **Yes** — a global `MAIL_*` set is exactly how Brand Fashion would send from Watchizer's mailbox when its own is missing (the no-fallback requirement). |
| Secrets entered on the dashboard, never through the repo or an agent (`RUNBOOK_PHASE2_STOREFRONT.md` §3A.2) | Yes, equally. |
| Cost: `APP_KEY` becomes critical; ciphertext unsearchable | Same cost, already paid. |

**Reading:** the payment pattern fits mail better than `.env` does — per storefront, entered on the dashboard,
absent = off, never borrowed. The same question applies to **`META_CAPI_TOKEN`** (W5), a secret now in `.env`.
The trade-off: Laravel's mailer is env-shaped, so a DB-held mailbox means building the transport per storefront
at send time (`config/mail.php` has one `smtp` mailer, `:40`) plus a dashboard screen. That is a decision for
the developer before W3 is built, not a code finding.

---

## 13. What this changes about the four open questions (not answered here)

- **Measurement method / rollback:** the no-deploy rollback is already gone (§10 row 1). The clean "off switch"
  that exists today is **disable the card METHOD rows, keep the contract enabled**: the list goes empty
  (`CheckoutMethods.php:125-160`), a posted id is refused before any write (`:110`), a no-id card order is
  refused because the Paymob contract is live (`:82-91`). Disabling the CONTRACT instead creates and cancels an
  order per attempt (F1/F2). Inferred from read, not run — **the exercise is on the backlog (PAY-OFF-SWITCH); the
  runbook's §3A.2 step 2 was rewritten to this on 2026-10-12.**
- **Storefront fallback option:** F3's only good path is Watchizer's; on any shop without a contract it produces
  a cancelled order and a 422 per click.
- **C10:** unchanged by this map; the harness inserter (backlog C10-SHAPE) still stands.
- **The nine orders:** F7 and F8 both act on them; they are the only reason the NULL rule exists.

---

## 14. After this map — 2026-10-12 (developer's measurements; built, awaiting one deploy)

| What | Fact | Tag |
|---|---|---|
| **The outage** | Card payments never completed on the new storefront from the 2026-09-23 flip: `payment_statuses` had **zero rows, ever**; 17 card orders, all cancelled. A live payment proved why: Paymob approved it, delivered the callback to the scoped route, **core answered 403** — the stored `hmac_secret` was not the one Paymob signs with (recomputed over the 20 fields: a different digest). Fixed by the developer 2026-10-12 12:48; verified on order `000027`: `processing`, `provider=paymob`, `method=card`, attempts 1, findings 0 — hop 30 exactly. | verified (developer) |
| **Why unnoticed** | `fail()` always wrote a `warning`; production has run `LOG_LEVEL=error` since 2026-09-28. A level filter plus two missing fields — not an absent trace. The 2026-09-26/27/28 log grep returned 0, which is a NULL result (only the 26th's file exists, last written before that day's orders). | verified (developer) + read |
| **Portal vs intention** | The test payment on integration 4988969 reached `api.watchizereg.com` while the portal still said `dash.watchizereg.com`: the per-intention URL wins — §4A.3's first row, now observed. Portal values corrected as hygiene (five of six; valU left). | verified (developer) |
| **Built: trace** (`41e5a58`) | `payments` daily log channel, level a literal, refusal line with check / storefront / provider / method / claimed ids; test pins independence from `LOG_LEVEL`. | verified (tests) |
| **Built: landing** (`a0dda59`, `932a2d1`) | Every non-POST exit → `/order-confirmation?order=…&status=paid\|failed\|pending`, status from the policy; the storefront renders three states in both languages. Option A: a message, not a receipt. Unknown storefront stays 404. | verified (tests, lint) |
| **Built: decline e-mail** (`a1d6b97`) | The cancel branch sends the recovery e-mail with `reason=declined`; admin copy says declined. Queue-time decision, accepted and pinned. | verified (tests) |
| **Decided, not built** | Store Paymob's ORDER id at initiation (not the intention id — `resolveStatus()` and `initiate()` are different identifier spaces); reconcile by order inquiry before expiry, per-run cap; settle automatically on Paid via a shared settlement service; failed bookkeeping insert → log and continue. C10 → `NOT NULL DEFAULT 1` with a test on the one production inserter. Mail: option A (database mailbox rows, Watchizer's too, recipients per storefront), 800/200 per mailbox, alert at ~200. | decisions (developer) |
| **Open** | Provider-pending outcome lands `status=pending` with no finding (backlog PAY-PENDING-NOFINDING). Browser Purchase on the card landing is its own decision (PAY-PURCHASE-BROWSER). The off switch is not yet exercised (PAY-OFF-SWITCH). | — |
