# Promotions: coupon codes, targeted discounts, Black1000 — runbook (2026-10-30)

Michael's brief (2026-10-30, item 1): the client's team sets up promotions themselves, per storefront, working on both
storefronts' cart and checkout; coupon codes and "buy from X, get a discount on Y"; the Black Friday coupon Black1000.

## 1. What existed (measured before building)

| Piece | Before this round |
|---|---|
| core engine | `App\Domain\Promotions\PromotionEngine` (wave 4D): rules per storefront, a window, AND-conditions (subtotal, product/variant/category/brand quantity, distinct lines, payment method), rewards (free product/variant, `percent_discount`, `fixed_discount`, `free_shipping`), ONE rule per cart by priority, skip counters |
| money rewards | gated per storefront by `storefronts.settings.promotions.money_rewards` — **off on both storefronts** (dev + scratch copies of production) |
| order record | `promotion_order_discounts` (one row per order: rule + amount), `order_items.promotion_rule_id` for free items |
| dashboard | Promotions screen (`Manage/Promotions/Index` + `Form`): authoring, sample-cart preview, skip counts |
| v2 checkout | `StorefrontCheckout`: total guard on goods + delivery, THEN the promotion taken off; Paymob gets the stored total |
| coupons, usage limits, per-line discounts, item scopes | **none** — deliberately out of scope in wave 4D (§3.16.7) |
| storefront "OFFERS" menu | legacy product BUNDLES (`offers`), a different thing; v2 refuses an offer line at checkout. Not touched. |
| both storefronts | no coupon field, no discount line |

Nothing parallel was built: every addition below extends those tables and classes.

## 2. What was added

- **Migration `2026_10_30_000000_promotion_coupons`** (forward-only, guarded): `promotion_rules` gains `code` (unique,
  case-insensitive by collation), `label_en`/`label_ar`, `apply_per` (`order`|`item`), `max_discount`,
  `min_order_amount`, `usage_limit`, `usage_limit_per_customer`, `include_sale_items` (default yes), `stackable`
  (default no), `unit_floor` (default 0), `qualify_min_quantity`, `qualify_min_amount`; `starts_at`/`ends_at` become
  nullable (a DRAFT may wait for dates; activation still requires both). New tables `promotion_rule_scopes` (target /
  qualify items by brand, category, sub-type, grade, family), `promotion_redemptions` (one per rule per order — what
  limits count), `promotion_order_discount_lines` (the discount per order line, with the `order_item_id`).
  `promotion_order_discounts` gains `code`; UNIQUE(order_id) → UNIQUE(order_id, promotion_rule_id). The three new tables
  are in `CoreChecksumCommand::DASHBOARD_TABLES`.
- **Engine** — `PromotionScopes` (which lines), `PromotionMoney` (the money per line, in integer cents, largest-remainder
  split so lines always sum to the rule's discount), `CouponStatus` (the shopper's sentence, EN + AR).
- **v2 cart** — every cart answer carries `totals.promotion` = `{discount, applied[], lines[{item_id, amount}], coupon}`
  for the code in `?coupon=` (a PREVIEW; asked only where money rewards are on or a code was typed, so the cart's query
  budget is unchanged otherwise).
- **v2 checkout** — body field `coupon`; the guard still compares goods + delivery (the page never sends a discount);
  the discount is taken off after it; a typed code that does not apply STOPS the order with `422 {error: coupon,
  status, messages{en,ar}}` and nothing is written. The order records the rule, the code, each line's amount and the
  redemption (customer key: account, else e-mail, else phone).
- **Dashboard** — the Promotions form gains a "Code and included products" card (`components/manage/promotions/
  CouponFields.tsx`): code, AR/EN label, once per order / per item, cap, minimum order, total and per-customer limits,
  sale items, stacking, the floor, the items the discount comes off and the "buy from" items, each picked from a
  searchable list. English strings in `lang/en/manage.php`.
- **Storefronts (both, twins)** — `src/lib/coupon.js`, `src/Hooks/usePromotion.js`, `src/Components/Cart/CouponBox.jsx`
  (+ `.css`): the coupon field in the cart and the checkout summary, the discount line, the total shown as goods −
  discount + delivery; the code rides on every cart request and on the order. GA4 `begin_checkout` and `purchase` carry
  `coupon` and each item's `discount` (GA4's own fields), and the value AFTER the discount; Meta/TikTok get the same
  value. No new event names. Pixel ids stay blank in local builds.
- **`php artisan promotions:black-friday`** — Black1000 as data (§6).

## 3. The rules, as built

- Order total = goods − discount + delivery, never below zero. A money-off discount comes off the GOODS only; delivery
  is waived only by a `free_shipping` reward or the free-shipping threshold.
- Free-shipping threshold: on the goods BEFORE any promotion (option A, approved 2026-10-29) — a coupon never takes
  free delivery away.
- `item` + fixed: the value off EACH eligible unit (Black1000 on two watches = 2,000). `order` + fixed: once, split
  over the eligible lines. Percent: the rate of the eligible goods. `max_discount` caps the rule. No unit below
  `unit_floor`.
- Eligible items: the target scopes (AND across kinds, OR within one; none = every item), minus items on sale when the
  rule says so. "Buy from X": the qualify scopes must hold at least `qualify_min_quantity` (default 1) and
  `qualify_min_amount`.
- Which promotion wins: priority first (as wave 4D), then the bigger discount, then the lower id — so at the default
  priority the BEST SINGLE discount wins. Stackable money rules combine (each on what the previous ones left) when their
  sum beats the best single rule.
- Usage limits count redemptions of orders that are not cancelled (an expired unpaid card order gives its use back).
- Paymob is always sent the stored order total (the discounted one).

## 4. Decisions taken overnight — RECOMMENDATIONS, each reversible

| # | Decision | Why | Alternative |
|---|---|---|---|
| D-P1 | A fixed discount larger than the goods no longer eats the delivery (two wave-4D tests updated: 130 → 100, 340 → 300) | the brief: total = goods − discount + delivery | restore the old clamp in `PromotionEngine::moneyOf` |
| D-P2 | Winner = priority, then discount, then id | keeps wave 4D's "higher priority wins" for an operator who wants it, gives "best single wins" at the default 0 | discount first, priority as tie-break |
| D-P3 | A code typed in the cart that no longer applies at checkout → 422, not a silent order at the higher price | the shopper saw the discount; charging more without asking is worse than one more click | place the order without it and say so on the confirmation |
| D-P4 | Payment-method min/max limits still read the total BEFORE the discount (core's existing `CheckoutMethods::refusal`) | unchanged core behaviour; the page uses the same figure | move the limit check after the promotion (a core change + test) |
| D-P5 | The cart preview runs only where money rewards are on, or when a code is typed | the cart is the hottest read; `CartQueryBudgetTest` holds its query count | always run it (one more query on every cart read) |
| D-P6 | Per-customer limit key: account, else e-mail, else the phone's last 10 digits | the strongest identity the order carries | account only (guests unlimited) |
| D-P7 | Black1000's floor defaults to EGP 0 (`--floor=` to change) | no figure was given; 0 is "never below zero" | Michael names a floor, e.g. 500 |
| D-P8 | The money-rewards switch stays OFF in the migration; Michael turns it on on the dashboard after the storefronts are live | it is what lets the dashboard write money promotions for a storefront; turning it on before the storefronts can show a discount repeats the wave-4D disclosure problem | turn it on in the migration |

## 5. Deploy order — RECOMMENDATION

1. **Both storefronts** (they send `coupon` and `?coupon=`; an old core ignores both — Laravel's validator drops the
   unknown key — so nothing changes until core is deployed). Free shipping already needs the storefronts first.
2. **core + `php artisan migrate --force`** (this migration and `2026_10_29_000000_free_shipping_threshold`).
3. Dashboard → Storefronts → Watchizer → **Money rewards: on**. (Brand Fashion: only when it should run money
   promotions.)
4. `php artisan promotions:black-friday` (dry run: it lists the brands it found) → `php artisan promotions:black-friday --apply`.
5. Dashboard → Promotions → «Black Friday — Black1000»: set the start and end (Cairo time), switch it on, save.

**Decided 2026-10-30 (Michael):** no floor concern; it goes live right after the deploy — start now, NO end date —
and the client switches it off when the offer ends. Money rewards on Watchizer follows (step 3 is required). The
post-deploy steps and the test order: `DEPLOY_20261030.md` §6.1.

## 6. Black1000

Data, not code: `PromotionsBlackFridayCommand` (idempotent by code; dry run without `--apply`; refuses where money
rewards are off; refuses when a brand is missing unless `--allow-missing`). EGP 1,000 off EACH eligible watch;
eligible = family `watch` AND brand ∈ the six; on sale included; no stacking; floor 0; Watchizer only; INACTIVE, no
dates.

Brands matched by their records (slug), measured on the scratch copy of production (2026-10-30):

| id | slug | name (en / ar) | watches |
|---|---|---|---|
| 1 | rolex | Rolex / رولكس | 124 |
| 2 | patek-philippe | Patek Philippe / باتيك فيليب | 28 |
| 3 | audemars-piguet | Audemars Piguet / أوديمار بيغيه | 20 |
| 5 | omega | Omega / أوميغا | **0** |
| 64 | tissot | Tissot / تيسو | 1 |
| 9 | hublot | Hublot / هوبلو | 2 |

All six found; none guessed. Omega has no watch today, so it is eligible but cannot discount anything until watches
are added. Run on the scratch copy: dry run listed the six and wrote nothing; `--apply` with money rewards off was
refused with the dashboard's own sentence; with them on it created the rule (inactive, no dates, `apply_per=item`,
seven scope rows, one `fixed_discount 1000`); a second `--apply` reported "Already there" and wrote nothing.

### By hand on the dashboard (the same rule)

Promotions → New: name «Black Friday — Black1000»; storefront Watchizer; leave Active off and the dates empty;
remove the empty condition row; Rewards: «خصم بمبلغ ثابت» 1000. Card "Code and included products": code
`Black1000`; customer labels; «يُطبَّق الخصم» = «على كل قطعة مشمولة»; «يشمل المنتجات المخفّضة بالفعل» ticked;
«يتجمّع…» unticked; floor empty (0). «يُخصم من»: brand Rolex, Patek Philippe, Audemars Piguet, Omega, Tissot,
Hublot, and «نوع المنتج» = ساعات. Save. Later: set the dates and switch it on.

## 7. Migration — tested on a scratch copy (forward-only in production)

`scratch_promo_20261030` (a fresh copy of the dev database, 139 tables, 8,041 products, 29 orders, 0 rules): `up()` →
the 13 columns, both dates nullable, UNIQUE(order_id, promotion_rule_id), the three tables; `down()` → exactly the
before state (10 columns, dates NOT NULL, UNIQUE(order_id)); `up()` → the after state again; `up()` once more → no
change (idempotent). `migrate:rollback` is refused in this environment, so the methods were run directly.

### Hand-written rollback SQL (production, only if the release is withdrawn; after the code is rolled back)

```sql
-- 0. nothing may be running on the new fields
UPDATE promotion_rules SET is_active = 0 WHERE code IS NOT NULL;
-- 1. the new tables (the per-line record and the redemptions are lost; the order totals are not touched)
DROP TABLE IF EXISTS promotion_order_discount_lines;
DROP TABLE IF EXISTS promotion_redemptions;
DROP TABLE IF EXISTS promotion_rule_scopes;
-- 2. one discount row per order again — first check that no order holds two (a stacked order); resolve any by hand
SELECT order_id, COUNT(*) AS n FROM promotion_order_discounts GROUP BY order_id HAVING n > 1;
ALTER TABLE promotion_order_discounts ADD UNIQUE KEY promo_discount_order_unique (order_id);
ALTER TABLE promotion_order_discounts DROP INDEX promo_discount_order_rule_unique, DROP COLUMN code;
-- 3. the window NOT NULL again: a draft without dates is dated in the past and left inactive
UPDATE promotion_rules SET starts_at = '2000-01-01 00:00:00', is_active = 0 WHERE starts_at IS NULL;
UPDATE promotion_rules SET ends_at = '2000-01-01 00:00:00', is_active = 0 WHERE ends_at IS NULL;
ALTER TABLE promotion_rules MODIFY starts_at DATETIME NOT NULL, MODIFY ends_at DATETIME NOT NULL;
ALTER TABLE promotion_rules DROP INDEX promo_code_unique,
  DROP COLUMN qualify_min_amount, DROP COLUMN qualify_min_quantity, DROP COLUMN unit_floor, DROP COLUMN stackable,
  DROP COLUMN include_sale_items, DROP COLUMN usage_limit_per_customer, DROP COLUMN usage_limit,
  DROP COLUMN min_order_amount, DROP COLUMN max_discount, DROP COLUMN apply_per, DROP COLUMN label_ar,
  DROP COLUMN label_en, DROP COLUMN code;
-- 4. the migration's record
DELETE FROM migrations WHERE migration = '2026_10_30_000000_promotion_coupons';
```

## 8. Tests

- core `tests/Feature/Promotions/CouponTest.php` — 23 tests: 2 watches = 2,000; mixed cart; no eligible items; on-sale
  included/excluded; floor and zero; cap split to the piastre; percent once per order; unknown / switched-off /
  expired / not-started / other-storefront codes; minimum with the amount in EN and AR; total and per-customer limits
  with a cancelled order giving its use back; best single vs a code that lost; stacking on/off; "buy from X"; v2 COD
  order at goods − discount + delivery with the code, each line (and its `order_item_id`) and the redemption recorded
  and `OrderTotals` explaining the total; free shipping on the goods before the coupon; a CARD order whose Paymob
  `amount` = the stored discounted total × 100; a code that no longer applies stops the order and writes nothing; a
  tampered total is still refused; the dashboard writer (dateless draft, activation refused without dates, code taken
  in any case, bad code, unknown scope, a bare automatic rule refused); the command (dry run writes nothing, `--apply`
  creates exactly Black1000, idempotent, refused where money rewards are off).
- `PromotionMoneyRewardTest`: two expectations moved to D-P1.
- storefronts `tests/coupon.test.mjs` (both, 7 tests): the kept code, the cart answer read defensively, the words, the
  amount shown, GA4 `coupon` and item `discount` on `begin_checkout`/`purchase`, no `coupon` field when there is none.
- Full core suite against the baseline: see the record `SEO_CONTENT_20261029.md` (handoff) for the gate result.
