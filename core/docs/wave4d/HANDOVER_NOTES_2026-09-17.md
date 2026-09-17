# Handover notes — 2026-09-17

Things the team will meet on day one that are **not defects**, and two decisions taken so they are
not re-raised as findings later. Kept next to the UX review rather than inside it, because the review
is evidence and this is rulings.

---

## 1. Several order filters will be EMPTY, and that is correct

**What they will see.** Open the customers screen or the orders queue, use the **storefront**,
**payment provider** or **payment method** filter, and nothing comes back — on a database holding
nine real orders.

**Why.** All three columns are written by CORE, when core handles the event:

| filter | column | written when |
|---|---|---|
| storefront | `orders.storefront_id` | core places the order through the compat checkout |
| provider | `orders.paid_via_provider` | a payment SUCCEEDS through core |
| method | `orders.paid_via_method` | a payment SUCCEEDS through core |

Measured 2026-09-17, after the fresh production import: **9 orders, 0 with a `storefront_id`, 0 with
either payment column.** Every one of those orders was taken by the LEGACY application, which has
never heard of these columns — they were added by core's own migrations. A legacy order cannot
retroactively acquire the storefront it was placed on, because at the time there was only one shop.

**When they start working.** From the write-switch. Every order placed after it goes through core, so
it carries all three, and the filters fill up from that day forward. Until then they answer honestly:
no order matches, because no order has the value.

**What would be wrong.** Back-filling `storefront_id = 1` on the nine historical orders to make the
filter "work". That would be inventing a fact — the column would then say core placed an order it
did not — and it is exactly the shape of thing an audit cannot later distinguish from the truth.

`CustomerScreenTest` asserts the honest version: the filter is well formed, does not crash, and
returns zero while there is nothing to match; it asserts the "finds rows" half only when an order
carrying the column exists.

**This is the same family as B-BUG-3** in the UX review, which found the provider/method option lists
are built FROM those columns and are therefore empty too. That one is a real defect and is on the
first-week list — the lists should be built from the configured providers and methods, so an operator
can at least select a value. The emptiness of the RESULTS is not a defect; the emptiness of the
OPTIONS is.

---

## 2. DECISION — a storefront-scoped grant may edit shared columns (A-BUG-5)

**Ruling: leave it exactly as it is.** No refusal in the writer, no change to the model.

**The finding.** A data-entry user scoped to storefront 1 can post a bulk `deactivate` through the
storefront-1 URL for a product that lives only on storefront 2, and it succeeds. `is_active`, price,
titles and specs are SHARED columns on `catalog_products`, so scoping the URL scopes which pages open
— not which columns move. Reads are correctly scoped (404 on the other storefront); writes to shared
columns are not.

**Why it stays.** This is **one team running two shops**, not two companies sharing a platform. The
people with a scoped grant are colleagues of the people with an unscoped one; the scope exists to keep
a busy screen relevant, not to defend one shop from the other. A refusal in the writer would buy
nothing real and cost something real: every shared-column edit would need to know which shops a
product touches, and the first legitimate cross-shop price change would be blocked by a rule nobody
could explain.

**What this means, stated plainly so it is not rediscovered as a surprise:** a storefront scope is a
VIEW, not a permission boundary on shared data. If Watchizer ever hands a scoped login to someone
outside the team — a franchisee, an agency, a supplier — this ruling has to be revisited first, and
the answer then is probably "the scope means placement only, and the writer enforces it".

Recorded so A-BUG-5 is not re-raised as a defect. It is a decision with a condition attached.

---

## 3. DECISION — low stock is PER PRODUCT, and the default is 2

**Ruling.** Each product already carries its own `low_stock_threshold`, and that value is what every
screen must use. Where a product has no value, the default is **2, not 5**.

Two changes follow, and neither is in the seven pre-handover fixes — they belong to **D-1 and D-2**
on the first-week list:

1. **The default drops 5 → 2.** D-2 measured the current threshold firing on **97.5% of the
   catalogue**, which makes the badge noise rather than signal.
2. **Every screen computes "low stock" against the product's own threshold**, never against a blanket
   number.
3. **"Out of stock" and "running low" are separated** on both the home tile and the stock screen. D-1
   found the same words giving two different figures one screen apart, which is the version of this
   bug that costs trust fastest.

Not implemented yet — flagged here so the ruling is on record before the work starts, and so nobody
re-derives the threshold from the config default.

---

## 4. The storefront's Arabic search still has the prefix gap — its own item

A-UX-1 and A-UX-2 are both fixed **for the dashboard**. The customer-facing storefront
(`ProductListing`) got the A-UX-1 half — it had to, because index and query must agree or matching
breaks — but NOT the A-UX-2 substring fallback.

**Why the split is safe.** Normalisation is a correctness requirement: the index holds one spelling
and every reader must ask for that spelling. The substring fallback is extra RECALL, so the two
readers can differ without anything being wrong — the storefront simply keeps the word-prefix
limitation it has always had.

**Why it should still be done.** Customers type the bare word exactly as operators do, so `اسود`
misses the products spelled `الأسود` on the shop as well as it used to in the dashboard.

**What it needs before it lands:** a measurement against the **§5.5 storefront budget**, which the
dashboard does not share. The dashboard change costs a flat ~19 ms full scan over 15,426 index rows;
the storefront has a count cache, index-ordered plans and a payload ceiling, so the same change has to
be priced against those rather than assumed to be equally cheap.

---

## 5. PATTERN — a fallback triggered by "no results" cannot fix "wrong results"

Worth keeping because it nearly shipped as written.

The UX review proposed fixing the Arabic prefix gap with *"a LIKE fallback when FULLTEXT returns
nothing"*. The mechanism was right and the **trigger was wrong**: the failing search `اسود` returns
**429** rows, not zero, so a fallback conditional on an empty result would never have fired and the 79
products spelled `الأسود` would have stayed invisible. The fix had to be an always-ON `OR`.

The general shape: **"no results" and "wrong results" are different failures, and a guard that waits
for emptiness only ever catches the first.** Any fallback written as *"if we found nothing, try
harder"* is worth re-reading with the question *"what if it finds something, and the something is
incomplete?"* — which is the more common and far quieter failure.

---

## 6. PATTERN — a declared contract with no caller looks exactly like a working one

`StorefrontCache::INVALIDATION_MAP` has listed `StorefrontSettingsChanged` against `meta` since the
class was written. It is a real contract, in the right place, naming the right event. **Nothing ever
fired it.** A storefront saved in the dashboard did not reach the shop for ten minutes (C-BUG-1), and
the map said otherwise the whole time.

There is even a unit test over that map — `CacheInvalidationMapTest` — and it passed throughout. It
scans every `remember()` call and asserts the key it writes is LISTED. That is a real check of a real
property, and it is blind to this defect by construction: it verifies the map covers the keys, never
that anything dispatches the events the map names.

**The shape to watch for:** a declaration (a map, a registry, an interface, a docblock contract) that
describes behaviour nobody exercises. It reads as evidence, it survives code review, and it is
indistinguishable from working code until something tests the BEHAVIOUR rather than the declaration.

This is the same family as the checks that could not fail — a test asserting prose instead of a
security property, an audit code with no non-zero case, a guard whose condition is never true. In
each, the artefact that should have caught the bug is the artefact that hid it, because its existence
was mistaken for its working.

The practical test: for every declared invalidation, refusal or event contract, ask **"what would
fail if I deleted the caller?"** If the answer is "nothing, the declaration still passes", the
contract is decoration. C-BUG-1's fix is now covered by a test that primes the shop, changes the
setting and asks the shop again — which fails if the caller is deleted.
