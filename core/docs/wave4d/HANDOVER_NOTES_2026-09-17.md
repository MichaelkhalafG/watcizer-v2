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

---

## 7. The battery, and the gap that turned out not to be one

Run on the live tree — the imported catalogue standing, **no rebuild** — on 2026-09-17.

### The result

| | |
|---|---:|
| passed | **1 249** |
| failed | 0 |
| skipped | 38 |
| assertions | 26 663 |

Two failures surfaced on the first pass and **both were the tests, not the code**:

- **`MailParkTest`** counted outbox rows by `aggregate_id` and `status` alone, with no
  `aggregate_type`. That was correct only while the outbox held nothing but mail. The Brand Fashion
  import put **4 543 `morabaa` rows keyed by PRODUCT id** in the same table, and order ids and
  product ids are different sequences in the same integer space — order 913 and product 913 both
  exist. The test counted a product's pending row as the order's and reported the park guard "too
  wide" while the guard was doing exactly the right thing. Proved by dumping the rows mid-test: the
  order's row was `sent`. The queries now say `channel` and `aggregate_type`, which is what they
  always meant.
- **`BackupCommandTest`** ran `backup:verify` with its default 36-hour limit against whatever dump
  was on the machine. That asserts a property of the MACHINE — did a cron run here recently — not of
  the code. The newest dump was 49 hours old, `backup:verify` correctly said *"the schedule has
  stopped running"*, and the suite reported a defect that was the command telling the truth. It now
  computes the limit from the dump actually present, so it pins the contract worth pinning: **given a
  dump inside the limit and above the floor, say OK**. The stale and stub refusals keep their own
  explicit thresholds.

One further failure appeared only in the full run and is **environmental**:
`MediaPruneScreenTest` died on `SQLSTATE[HY000] [2002] Only one usage of each socket address` —
Windows ephemeral-port exhaustion after ~1 287 tests of TIME_WAIT sockets. It passes in isolation
(120 s; it is a slow media-tree scan). Not a defect, and not something the code can fix.

### The 38 skips, named

**7 are opt-in evidence captures** (`CAPTURE_SCREENS=1`). They skip on every run, by design, and
regenerate the wave-4C screen captures when asked. Nothing about the system is untested because of
them.

**31 are the wave-3 ledger guard.** `core:transform` refuses to re-baseline once
`inventory_movements` holds a row it did not write — legacy `products.stock` has stopped being the
truth at that point. The Brand Fashion import wrote **4 503 `import` movements** (plus 40 `manual`
from variant opening stock), so every transform-touching test skips with that sentence. They cover,
in eight files:

| file | what it covers |
|---|---|
| `TransformCommandTest` | the audit, a rolled-back dry run, the full transform reconciling and converging |
| `VariantTransformTest` | variant baselines, the product-as-sum invariant, idempotency, corrections, `inventory:verify` |
| `CategoryVisibilityTest` | a node lights up only for a visible, placed, non-deleted product on THIS storefront (4 break cases + a bypass attempt) |
| `MultiStorefrontTransformTest` | mirrored-tree sync on/off, insert-only behaviour for a second storefront |
| `OnePrimaryPlacementTest` | the database-level one-primary guard, and the primary moving with the legacy sub type |
| `LedgerGuardTest` | the guard itself: naming foreign reasons, running clean, re-baseline appends |
| `CacheBumpTest` | the storefront cache version bumping on a real run and never on a dry run |
| `DashboardTablesTest` | storefronts surviving a transform with their dashboard-authored columns intact |

### They were RUN — on a disposable copy, and they all pass

The gap is closable without touching the catalogue, and it was closed:

1. `CREATE DATABASE watchizer_scratch`
2. copy the **65 legacy tables only** into it (`mysqldump` of the live copy — the clean tables are
   deliberately NOT copied, because building them is the thing under test)
3. `migrate` then `core:transform --force` against it — reconciliation passed 83 checks, ledger came
   out **transform-only, 1 252 rows**
4. run the eight files with `DB_DATABASE=watchizer_scratch`
5. `DROP DATABASE watchizer_scratch`

**Result: 56 passed, 485 assertions, 0 skipped.** The live catalogue was verified untouched
afterwards — 7 713 products, 7 087 imported, ledger unchanged at import × 4 503, manual × 40,
transform × 1 252.

This works because `phpunit.xml` deliberately does not pin `DB_*` (its own comment says so), so the
whole suite can be aimed at another schema, and the `legacy` connection follows `DB_DATABASE` when
`LEGACY_DB_DATABASE` is unset.

**So the honest status is not "31 tests untested".** It is: *31 tests cannot run against the live
database while the import's movements are in the ledger, and they pass against a disposable copy
built from the same legacy data.* The command to reproduce is above; it takes about three minutes.

### What would make them runnable in place

A rebuild — `core:drop-clean --force && migrate --force && core:transform --force` — which clears the
ledger to transform-only and destroys the imported catalogue. That is the trade, and it is taken
**after the team is working and the catalogue is theirs rather than ours**: at that point the Brand
Fashion products will have been reviewed, categorised and corrected in the dashboard, and re-importing
is a known, repeatable operation rather than a loss.

---

## 8. PATTERN — an id without its type is not an identifier

`integration_outbox` is shared by every channel. A row is addressed by
`(channel, aggregate_type, aggregate_id)`; the id alone is just a number.

`MailParkTest` counted an order's rows with `where('aggregate_id', $orderId)` and nothing else. That
was not a shortcut anybody noticed, because for months it gave the right answer: the outbox held
order mail and little else, so no other row could share the number. Then the Brand Fashion import
wrote **4 543 `morabaa` rows keyed by PRODUCT id**, order ids and product ids being different
sequences in the same integer space — and **order 913 met product 913**. The test counted a product's
pending row as the order's and reported the park guard as "too wide" while the guard was behaving
perfectly. The order's row was `sent`; the row being counted was never mail at all.

**The general shape:** whenever a table is keyed by a polymorphic pair, a query that names only the
id is not narrower than the table — it is a query over every type at once that happens to be correct
while the other types are empty or their sequences have not overlapped yet. It is not a latent bug in
the sense of something that might go wrong; it is **already wrong and not yet visible**, and the
thing that makes it visible is ordinary growth in an unrelated part of the system.

Cheap to find: grep for a `where` on `aggregate_id`, `subject_id`, `reference_id`, `product_id` or
any other polymorphic key that is not accompanied by its type column in the same query. Cheap to fix:
say the type. The queries in `MailParkTest` now name `channel` and `aggregate_type`, which is what
they meant on the day they were written.

This sits next to §5 and §6 as a third way an artefact can look like evidence and not be one: §5 is a
guard whose trigger never fires, §6 is a contract with no caller, and this is **a query whose
correctness was on loan from an empty table**.
