# Free shipping over a threshold — runbook (2026-10-29)

The client (via Michael, 2026-10-29): free shipping on orders over 10,000 EGP, both storefronts. Checkout did not do it.
Built in core where shipping is calculated, so the cart, the checkout, the stored order and the Paymob amount agree.

## 1. The rule

    delivery is free when  goods  ≥  storefronts.settings.shipping.free_over

- **goods** = the merchandise subtotal at the prices charged (a valid sale price already applied), **before** any
  cart-level promotion. It is the subtotal the storefront shows, so the page can show "Free" and send the exact total.
- **free_over** per storefront, on the dashboard's storefront screen ("Free shipping"): a positive amount = the
  threshold; 0 or empty = off; key absent = the default 10,000.
- At the threshold is free (≥, compared in cents).
- Code: `App\Domain\Shipping\FreeShipping` (the rule), `App\Storefront\StorefrontCheckout` (v2 checkout, before the total
  guard), `CheckoutCompatController` (compat `add_order`, the same rule — no storefront posts there today),
  `App\Storefront\StorefrontCart` (the cart answer carries `totals.shipping = {free_over, free, remaining}`),
  `App\Domain\Orders\OrderTotals` (the dashboard and both e-mails: "free", not an unexplained gap).
- Storefronts (both): `src/lib/freeShipping.js` + `src/Hooks/useFreeShipping.js` (twins); the cart shows "Free" /
  «مجاني» and "Add X more for free shipping" / "This order ships free"; the checkout shows "Free" and sends
  goods + the delivery core will charge (read from the cart answer at the click).

## 2. DECISION FOR MICHAEL — before or after discounts?

| | **A. Before promotions (built)** | B. After promotions |
|---|---|---|
| threshold checked on | goods at the prices charged | goods minus a promotion's money discount |
| storefront can show it exactly | yes — the subtotal it already shows | no — the page does not know the promotion before the order exists; needs a preview endpoint |
| total guard / Paymob amount | exact | needs the guard moved after promotions |
| cost of the rule | a money promotion can bring the paid goods below 10,000 while delivery stays free (one delivery fee, ~50–150 EGP) | none |
| today | core has **no coupons**; promotions exist but **money rewards are switched off on both storefronts** and no rule is active — A and B give the same answer until a money promotion runs | |

**Recommendation: A**, until a money promotion is planned; switching to B is a change in `StorefrontCheckout` (move the
waiver after `evaluatePromotions()`) plus a preview of the promotion for the storefront. Michael decides.

## 3. Migration (forward-only in production)

`database/migrations/2026_10_29_000000_free_shipping_threshold.php` — guarded, additive:

1. `orders.free_shipping_over DECIMAL(12,2) NULL` after `total_price_for_order` — the threshold that waived the order's
   delivery (NULL for every other order).
2. `storefronts.settings.shipping.free_over = 10000` on every storefront that names none (an operator's figure, or 0,
   is never overwritten).

Tested on scratch copies (2026-10-29): `up()` on `scratch_cart_proof_20261029` → the column and `{"free_over": 10000}`
on both storefronts; `down()` → the column and the `shipping` key gone, every other settings key intact; `up()` twice →
the same state (idempotent). (`migrate:rollback` is refused in this environment by design — the methods were run
directly.) Note: on the dev database `2026_10_23_000000_orders_storefront_backfill` is still pending; this migration
does not depend on it.

### Hand-written rollback SQL (production, only if the release is withdrawn)

Run in this order, after the storefront and core code are rolled back (the old code never reads either):

```sql
-- 1. the threshold out of each storefront's settings (keeps every other key)
UPDATE storefronts
   SET settings = JSON_REMOVE(settings, '$.shipping.free_over')
 WHERE JSON_EXISTS(settings, '$.shipping.free_over');
UPDATE storefronts
   SET settings = JSON_REMOVE(settings, '$.shipping')
 WHERE JSON_EXISTS(settings, '$.shipping') AND JSON_LENGTH(JSON_EXTRACT(settings, '$.shipping')) = 0;

-- 2. the order column (orders placed with free delivery keep their totals; only the reason is dropped)
ALTER TABLE orders DROP COLUMN free_shipping_over;

-- 3. the migration's record, so a later deploy runs it again
DELETE FROM migrations WHERE migration = '2026_10_29_000000_free_shipping_threshold';
```

## 4. Tests

- core `tests/Feature/V2/FreeShippingTest.php` (9 tests): just under (charged), exactly at (free, recorded, the old
  total refused with the right one), above (free), off (0), the default when absent and the cents comparison, the cart
  answer (free / remaining), a promotion's fixed discount (rule A: free delivery and the discount), no double waiver
  with a free-shipping promotion, a CARD order — the intention's `amount` sent to Paymob = the stored order total × 100 —
  and the same total by cash.
- storefronts `tests/freeShipping.test.mjs` (both, 4 tests): the same rule in the page.
- The full core suite: see §5.

## 5. What the full suite says

(filled in from the run on `scratch_suite_20261029` — a fresh copy of the dev DB with only this migration applied)

Gate `tests/baseline/suite-baseline.sh` against `tests/baseline/suite-baseline.txt` (61 entries, demo variants on):

| run | failed | in the baseline | NEW | notes |
|---|---|---|---|---|
| 2026-10-29, free shipping only | 64 | 61 | 3 | `OrderRecoveryTest` (sent goods + delivery over 10,000), `CartQueryBudgetTest` (13 > 12 queries), `StorefrontTwinsTest` (lockfile) — all three fixed, each rerun green |
| 2026-10-30 06:5x | — | — | — | VOID: the promotions work edited the checkout during the run |
| 2026-10-30 08:00, free shipping + promotions (both migrations on the copy) | 63 | 61 | 2 | `ProductNameSeamTest` (a pick-list label chose a language by ternary) and `ApiAllowListTest` (a cart URL built with an inserted expression) — both from the promotions work, both fixed, rerun green with every promotions test |

Tables changed by the suite: 0. `mysql.db` CHECK TABLE: OK before and after. Baseline entries that now pass: 0.

## 6. Deploy order — STOREFRONTS FIRST, then core (revised 2026-10-29)

The 422 risk, measured from the code (the live checkout at HEAD, `Checkout.jsx` lines 608–614):

- **Core first is unsafe.** New core waives delivery at 10,000; the OLD page still sends goods + delivery, so core
  answers `422 total_mismatch`. The old page re-reads the cart and shows "The total changed to EGP X", but it
  computes the same goods + delivery again, so every retry is refused: **no order of 10,000 EGP or more can be placed
  until the storefront is deployed.** Nothing is charged (the guard runs before the order and before Paymob).
- **Storefronts first is safe.** The new page takes the threshold from the cart answer (`totals.shipping.free_over`);
  the OLD core sends no such key, so the page treats free shipping as off and sends goods + delivery, which old core
  accepts. Nothing changes for the shopper until core is deployed.
- **Then core** (code + `php artisan migrate --force`). From the first request the cart answer carries the threshold
  and the checkout sends the waived total. The resolved-storefront cache cannot disagree: a row cached before the
  migration has no `shipping` key, which reads as the default 10,000 — the migration's own value.
- The compat `add_order` endpoint (no storefront posts there; the tests do) follows the same rule; a caller that
  sends the old total over 10,000 gets the same 422 with the right total.

**RECOMMENDATION: deploy order, no transition code.** 1. Watchizer and Brand Fashion storefronts, 2. core +
migration, at least an hour later. The one gap left: a tab opened BEFORE the storefront deploy and submitted AFTER
core's, over 10,000 EGP, gets the 422 loop until the shopper reloads (the reload serves the new page). The hour
makes that rare; nothing is charged in that case.

The alternative, not recommended: core accepts, for a dated window, a client total equal to goods + the waived
fee, and then CHARGES that fee, so the order and Paymob match what the shopper saw. It costs code and a test in both
checkouts, a date to remove it, and orders over 10,000 that pay a delivery the rule says is free.

### Paymob — the amount is the stored order total in every case (measured from the code)

- v2 checkout (`StorefrontCheckout::startPayment`) passes `body.total`, which is the order's stored
  `total_price_for_order` (the server's total after the waiver and any promotion), never the client's figure.
- compat (`CheckoutCompatController::paymob`) reads `total_price_for_order` from the stored order.
- `PaymentInitiator` converts once: `amountMinor = round(amount × 100)`.
- A 422 happens before the order exists, so no payment is started. `FreeShippingTest` proves the CARD case: the
  intention's `amount` = the stored total × 100 for a free-shipping order.

Order: 1. Watchizer and Brand Fashion storefronts, 2. core + migration. If core must go first for another reason,
set the threshold to 0 on both storefront screens right after the migration and back to 10,000 once the storefronts
are live.
