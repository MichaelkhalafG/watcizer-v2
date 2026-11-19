# One offer builder for the offers page and the wheel — plan for approval (19 November 2026)

Plan only. Nothing here is built until Michael approves. Before screenshots of both pages (EN and AR, top, middle,
lower): `checkpoints/2026-11-19_offers-plan/before/` — `promotions-list-*`, `promotion-form-*` (the WR10 rule),
`wheel-*` (Watchizer's wheel).

## 1. Recommendation in one paragraph

Build ONE step-by-step builder (What → On what → Condition → Limits → Where it shows) that writes the promotions engine's
existing rule shape, used by both the Promotions page and the wheel. Keep the engine (one rule per cart, checked on the
server at order time) and add four small capabilities it lacks: a qualifier that differs from the target ("a wallet
WITH a watch"), pairing ("one wallet per watch, the most expensive first"), a fixed number of discounted items per
order, and "first order only". One additive migration (nullable columns only), no data step required, nothing for the
team to re-enter, every existing offer and wheel prize evaluating exactly as today (proved by a before/after test over
a production copy). "30% off a wallet with any watch" is the first offer built with it.

## 2. What exists today

**Promotions page** (`/manage/promotions`, MANAGE_PROMOTIONS, all shops in one list). A rule has: name, start and end
(end required), priority (highest wins, ties by id), active switch, shops, conditions (all must hold) from seven types
(cart subtotal minimum; product, variant, category or brand quantity minimum; distinct lines minimum; payment method),
rewards from five types (free product, free variant, free shipping, percent off, amount off), a coupon code (optional),
customer-facing labels EN/AR, and the money knobs: apply per order / per item / to the cheapest unit, a cap in EGP
(`max_discount`), minimum order, total uses and uses per customer, sale items in or out, a unit floor, a qualifying
minimum count or amount, and scopes with three roles (target, qualify, exclude) over category / brand / product. A
**preview** already evaluates an unsaved draft against a test cart inside a rolled-back transaction. The writer refuses:
no end date, an unbounded gift, a gift not sellable on the shop, an empty condition or reward, two ACTIVE rules at the
SAME priority whose windows overlap. The list shows state (running, scheduled, ended, off), period, priority, gift stock.

**Wheel page** (`/manage/storefronts/{shop}/wheel`, per shop). 4–12 segments, each: labels EN/AR, a type (percent off,
amount off, free shipping, better luck, invite a friend, free gift from a list), value, odds (whole %, must sum to 100
— refused otherwise), colour, a cap, and for percent prizes a category with an optional minimum count and a mode (every
eligible item / the cheapest one). A won prize becomes a coded promotion rule (`issuer = wheel`) through the same
writer; a spin snapshots its terms, so editing a segment changes only later spins.

**Timed campaigns** (Hot Deals, Flash Sale) are a third screen and stay separate: they set PRICES for a window, not
rules. The builder links to them ("show this as a campaign page") but does not replace them.

**What the engine cannot do today** (needed by the request): a qualifier different from the target in the wheel (the
engine supports a separate qualify scope; the wheel and the form do not expose it); pairing one discounted unit per
qualifying unit, most expensive first; a fixed number of discounted items per order; first order only; telling the
shopper why a code does not apply yet ("add a watch").

## 3. The builder, step by step

Every field has a short label and a one-line hint in both languages; no internal terms ("priority", "scope", "issuer"
never appear). A live sentence under the steps says the offer in Arabic and English, built by the server's own copy
(`OfferCopy`, so the storefront and the dashboard say the same thing), e.g. EN "30% off one wallet for each watch in the
cart, the most expensive wallet first, up to 1,500 EGP. Until 31 December." / AR «خصم 30% على محفظة واحدة مع كل ساعة في
السلة، الأغلى أولًا، حتى 1500 جنيه. حتى 31 ديسمبر.» (Latin digits in both languages, as the shops print them).

| Step | Choices | Engine today | New |
|---|---|---|---|
| What | percent off; amount off; free item; buy X get Y; free shipping | rewards percent / fixed / free_product / free_variant / free_shipping; buy X get Y = free item + a quantity condition | — |
| On what | a category, a brand, chosen products, the whole cart | target scopes (category / brand / product), or none | — |
| Condition (optional) | another item in the cart; a minimum cart total; first order only | qualify scope + `qualify_min_quantity`; `cart_subtotal_min` | **first order only**: condition type `first_order` (a plain string, no DDL); "another item" exposed in the UI and in the wheel |
| Limits | cap in EGP; items per order (a number, or "one per qualifying item"); start and end; total uses | `max_discount`; dates; `usage_limit` | **pairing** (`apply_per = paired`: one target unit per qualifying unit, most expensive first; fits the existing varchar(8)); **max items** (`max_units`, new nullable column) |
| Where it shows | the wheel (with its chance and colour); an offer banner or page; a code | wheel prizes; automatic rule shown by the product page's offer note and the cart; coupon code | the wheel prize points at the builder's offer instead of its own fields (§6) |

**Templates** (a list at the top, each fills the steps, every field still editable): watch plus wallet discount; buy 2
get the 3rd free (percent 100, cheapest unit, qualifying minimum 3 — an existing shape, pinned by a test); X% off a
brand; amount off above a cart total; free shipping above a total.

**Safety checks before saving** (shown on the page; refusals stay in the writer, the one door):
- the wheel's odds add up to 100, shown as percentages per segment (exists, kept; shown live);
- a warning when a discount has no cap or no end date (a wheel code has a validity; a rule has an end date already —
  the cap warning is new);
- a warning when two active offers overlap on the same products (new; the same-priority overlap stays a REFUSAL — with
  one rule per cart it would make the winner an accident of insertion order);
- a test cart: pick products and quantities, see each line, the discount and what the customer pays (the existing
  preview, given a product picker and a clear result).

**The list**: status filters active / scheduled / ended / off, a shop filter, search — the pattern of the new Reviews
page (and its wide-table scrolling). One-click **pause** (switch off), **duplicate** (a copy, switched off, " (copy)"),
**end now** (end date = now). Every action logged, as today.

## 4. The first offer: "30% off a wallet with any watch"

The wheel's row 4 today is "30% off wallets" (30%, category Wallets, cap 1,500, every eligible item). Built with the
builder it becomes:

- What: 30% off. On what: the Wallets category. Condition: another item — the Watches category, at least one.
  Limits: one per qualifying item (pairing), the most expensive wallet first; cap 1,500 EGP. Where: the wheel, its
  chance and colour unchanged; wheel text "30% off a wallet with any watch" / «خصم 30% على محفظة مع أي ساعة».
- With two watches and three wallets: the two most expensive wallets get 30% off, together at most 1,500.
- A cart with the wallet and no watch: no discount, and the cart and checkout say "Add a watch to use your 30% off a
  wallet" / «أضف ساعة لتستخدم خصم 30% على محفظة» (a new coupon status, `needs_qualifier`, beside today's
  `no_eligible_items` and `minimum_not_reached`). The code is not used up and stays valid until its own expiry.
- Removing the watch removes the discount at once (the cart is re-evaluated on every read, as today) and the order is
  checked again on the server when it is placed: a client cannot keep a discount the cart no longer earns.
- Tests: discount only with a watch; one wallet per watch, most expensive first; the 1,500 cap across wallets; no watch
  → no discount, the message, the code still valid; watch removed → discount gone in the cart and refused at the order;
  a held total from before the removal refused (422, as the campaign prices do); the wheel text in both languages.

## 5. The Hot Deals bug, folded in (fixed 19 Nov in 1fc9ebc)

The builder keeps the rules that fix made: a confirmation asks only about what is NEW or CHANGED in this save; a save
writes a difference (added, removed, changed — never the whole list rewritten); a refusal or warning that names
products gives the count, the first five names and "show all" in a box of its own height — never a wall of names; every
dialog stays within the screen (the shared dialog is capped since 1fc9ebc).

## 6. Data: migration, data step, what the team re-enters

**Migration, one, additive, every column NULL-able** (`2026_11_2x_offer_builder`):
- `promotion_rules.max_units` int unsigned NULL — the fixed number of discounted items per order;
- `storefront_wheel_prizes.offer_rule_id` bigint unsigned NULL — a segment that uses a builder offer (an inactive rule
  of `issuer = wheel_template` holding the offer); NULL = the segment's own fields, exactly as today;
- `storefront_wheel_spins.prize_offer_rule_id` bigint unsigned NULL — the spin's snapshot of it.
`apply_per = 'paired'` and the condition type `first_order` are values in existing string columns (no DDL). Run as the
release scripts run migrations: the four-pass dry run on a production copy (schema diff, row counts, the before/after
evaluation below, a second migrate that changes nothing), apply, a second run that reports nothing to do, and the undo
(`down()` drops the three columns; tested on the copy, then re-applied).

**Data step: none required.** Existing rules and prizes keep NULL in the new columns and evaluate exactly as today.
**Proof**: a before/after test on the 18 Nov production export — every active rule and every wheel prize evaluated
against a fixed set of carts (each rule's own target, a mixed cart, an empty cart, a sale item, a capped total) before
the migration and after it, results byte-identical.

**Row 4 (the wallet prize)**: recommended — the team edits it in the new builder after the deploy (one save, logged).
Alternative if Michael prefers it switched at the deploy: a guarded data step keyed by stable keys (shop code + wheel
position 3 + label_en "30% off wallets", never ids), with its dry run, apply, second run (nothing to do) and undo
(restore the snapshot row). Spins already won keep their snapshot and their old terms either way.

**What the team must re-enter: nothing.** Existing offers and segments open in the builder with their fields mapped
into the steps; a segment keeps its own fields until someone chooses "use a builder offer" for it.

## 7. Order of work

1. Core: the four capabilities (pairing, max units, first order, needs-qualifier status) with tests; the migration with
   its four-pass dry run and undo; the before/after evaluation test.
2. Core: the builder component (steps, templates, live sentence, checks, test cart) used by the Promotions page and the
   wheel page; the list's filters and one-click actions.
3. Storefronts: the "add a watch" message in the cart and the checkout, both shops, EN and AR (the code panel already
   shows coupon statuses).
4. The wallet offer built through the builder on a scratch copy; screenshots after (both pages, EN and AR); the
   deploy (core first, then the storefronts), its script in today's style with undo and sha256.

## 8. Questions for Michael (answers change the build)

1. Items per order: both "a number" and "one per qualifying item" in the Limits step, or only "one per qualifying item"
   for now?
2. First order only: first order by the account, or by the phone number too (a guest with a previous order by phone)?
3. Row 4: the team edits it after the deploy (recommended), or a data step switches it at the deploy?
4. "Offer banner or page": the product page's offer note and the cart's strip (both exist) — or also a page per offer?
