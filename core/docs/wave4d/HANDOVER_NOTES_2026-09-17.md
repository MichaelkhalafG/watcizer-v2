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

---

## 9. THE ONE TO TELL THE TEAM BEFORE THEY FIND IT — saving a Joyroom product hides it from both shops

*Added 2026-10-05. The developer's instruction: "the trap goes in the handover notes, not just a tsv.
The team must be told before they discover it."*

**What will happen.** Open any of the **72 Joyroom products**, change one thing — a price, a photo,
a title — press Save, and the product **disappears from Watchizer AND from Brand Fashion**. Nothing
is deleted; it becomes hidden on both shops at once. The save itself succeeds.

**Why.** The visibility gate (2026-09-18) refuses to publish a product that is missing any of:

| | |
|---|---|
| Arabic **and** English short description | all 72 are missing **both** |
| Arabic **and** English long description | all 72 are missing the **Arabic** one |
| a gender | all 72 have **none** |
| at least one image | present on these |

The gate is enforced **on write, and was never applied retroactively**. So these 72 are live today
carrying data the gate would refuse — they were imported before it existed, and the Electronics
restoration on 2026-09-19 placed them on Watchizer in the same state they already had on Brand
Fashion, deliberately (see `SECOND_PASS_DECISIONS_2026-09-19.md` §2). The first save is the first
time the gate ever sees them, and it demotes them the way it would demote a new product with the
same gaps.

**It is not a bug and there is nothing to fix in the code.** A gate that let a save publish an
incomplete product would be worse. What is missing is the data.

**What to do — the whole remedy, in order:**

1. Before touching a Joyroom product, fill the four fields **in the same edit**: the short
   description in Arabic and English, the long description in Arabic, and the gender.
2. Save. The product stays visible, on both shops.
3. If one has already been saved and vanished: it is not lost. On the products list set the
   **visibility** filter to **hidden** (`الظهور` → `مخفي`), open it, fill the four fields, save
   again, and switch it back to visible.

**Which products.** All 72 are listed with their codes in `docs/wave4d/joyroom-incomplete.tsv`. On
screen, find them by setting the **brand** filter to **Joyroom / جوي روم** — that is the whole set
and nothing else, because the brand was created for them on 2026-09-19.

**The quick filter `بيانات ناقصة` does NOT find them,** and that is worth knowing rather than
discovering. It selects what the row BADGES mark — no supplier code, no price, no Arabic title, no
image, no category, an unassigned brand, a broken image — and the four fields the visibility gate
demands are not among those seven. The badge set and the gate set overlap but are not the same
list; the gate is checked by `PlacementWriter::REQUIRED_TRANSLATED`, the badges by
`ProductController::missingFor()`. Aligning them is a real improvement and a deliberate change,
not something to assume has already happened.

**Verified on the live catalogue, 2026-10-05** — 72 products, and the gaps are exactly:

| | of 72 |
|---|---|
| Arabic short description missing | 72 |
| English short description missing | 72 |
| Arabic long description missing | 72 |
| No gender | 72 |
| English long description missing | 0 |
| No image | 0 |

**Tell the team this before the first one of them opens a Joyroom product**, not after. The failure
is silent from the operator's side — the save works, the confirmation appears, and the consequence
is on a shop they are not looking at.

---

## 10. RULE — a clean table that differs from its legacy source is NOT damaged

*Added 2026-10-05, after this session got it wrong and had to be stopped.*

**What happened.** `catalog_units` held 16 rows where legacy `size_types` holds 37. A test that
named specific unit codes failed. The reasoning went: legacy has 37, clean has 16, 21 rows are
missing, the legacy table is read-only so it must be the truth — and the 21 rows were restored from
it, id by id.

**Every one of those 21 rows had been deleted deliberately**, by the developer, using the Units
screen, which exists for exactly that purpose: the garment and shoe sizes (XS, S, L, XL, 26–47)
were never units of measurement, and removing them is the job that screen was built to do. The
restore undid a completed piece of work and put the mess back.

**The rule, stated so nobody repeats it:**

> A difference between a clean table and its legacy source is not evidence of damage. The clean
> tables are **supposed** to diverge — every retirement, merge, archive and cleanup the dashboard
> performs makes them diverge on purpose, and that divergence is the product of the work, not a
> fault in it.

**Before restoring anything from legacy, in this order:**

1. **Ask whether a dashboard action created the difference.**

   **Correction (2026-10-05): the activity log will usually NOT tell you.** An earlier version of
   this note said it "records exactly this". It does not: nothing in `UnitController`,
   `UnitCleanup`, `LookupController` or `LookupWriter` calls `ActivityLog::record()` at all, so a
   unit merged, retired or deleted through the dashboard leaves **no trace**. The log is evidence
   when it has a row; its silence is evidence of nothing, and steps 2 and 3 carry the whole check
   for the screens it does not cover.

   **§11 below lists exactly which screens those are** — eight of them, nineteen write actions —
   and what closing the gap would cost. Read it before trusting the log about any table.
2. **Check the screen that owns the table.** Units, Brands & lists, Categories and Products all have
   verbs that legitimately remove rows. If the table has such a screen, removal is a feature.
3. **Ask the developer** if it is not obvious. A restore is not a safe default: it is a write that
   reverses somebody's decision, and it is far harder to notice than the gap it fills.

**A gap is only damage when nobody chose it** — a failed migration, a half-finished import, a
transform that stopped. Those leave other traces: a run directory under `storage/transform`, a
partial count in a report, an exception in the log. Look for the trace before reaching for the
source.

**The test that started it has been fixed too**, because it was the other half of the mistake: it
asserted a CENSUS (`['xs', 'xl', 'xxxl', '42', 'free-size']` are present) rather than the rule it
was meant to cover. A test that fails when an operator does the intended thing is a test that
teaches the team to distrust the suite. It now asserts that size-shaped units are flagged as such,
on whatever rows still exist, and says plainly when the cleanup is finished and the test should be
retired with it.

---

## 11. "WHO CHANGED THIS?" has no answer for eight screens

*2026-10-05. Found while correcting §10, whose first version wrongly told people to check the
activity log first. Recorded here as a finding in its own right.*

### What is actually covered

The log CAN record nine subject types. Only three have rows on this copy, because the others have
not been exercised here — **absence of rows is not absence of coverage**, and confusing the two is
how §10 went wrong:

| subject type | written by | rows today |
|---|---|---|
| `catalog_products` | `ProductWriter` | 7,611 |
| `core_user_roles` | `Domain\Access\Roles` | 20 |
| `storefront_categories` | `CategoryController` | 5 |
| `catalog_product_variants` | `InventoryService` (stock only) | 0 |
| `orders` | `OrderFulfilment` | 0 |
| `core_blogs` | `BlogController` | 0 |
| `storefront_payment_providers` | `PaymentSettingsController` | 0 |
| `storefront_product` | `PlacementController` | 0 |
| `promotion_rules` | `PromotionController` | 0 |

### What is NOT covered — 19 write actions across 8 screens

Nothing in these paths calls `ActivityLog::record()`. A change made through them leaves **no trace
of any kind**: no row, no actor, no before/after, nothing to ask.

| screen | write actions | what changes untraceably |
|---|---|---|
| **Units of measurement** | 3 | merge, retire, restore — *this is the one that started it: 21 units were removed and nothing recorded it* |
| **Brands & lists** (12 lists through one controller) | 3 | create, rename, delete a brand, colour, material, shape, gender, feature, size, movement, closure, display type, grade |
| **Shipping prices** | 3 | a governorate's name or its delivery cost |
| **Banners** | 3 | what the shop's home page shows |
| **Variants** | 4 | creating, editing, deleting or reordering a product's buyable rows (their STOCK is logged; their existence is not) |
| **Storefront settings** | 1 | a shop's name, slug and settings JSON |
| **Profile** | 1 | the operator's own language — the row we could not attribute on 2026-10-05 |
| **Media prune** | 1 | permanent deletion of image files |

**Two of those are the worst kind:** media prune *deletes files permanently*, and the lookups screen
*deletes rows that products reference by id*. Both are irreversible and neither leaves a record.

### One thing that is actively misleading

`ActivityController::typeLabel()` already offers **`shipping_cities`** and
**`storefront_payment_methods`** in the activity screen's filter dropdown. Nothing writes either.
So the filter promises two record types the log can never contain, and an operator who filters by
"Shipping prices" and sees nothing will read it as *nothing was changed* rather than *this is not
recorded*. That is worse than the gap itself and is the cheapest thing on this page to fix.

### The honest cost of closing it

Measured against the screen that already does it properly. `BlogController` spends **15 lines** on
logging across 4 write actions: a 14-line `logFields()` helper that snapshots the columns worth
diffing, and one `ActivityLog::record(...)` call per action passing `$before`, `$after` and a label.

| | |
|---|---|
| Write actions to cover | **19**, across 8 controllers |
| Per controller | one `logFields()` helper (~10–15 lines) + a `label()` (~5) |
| Per action | 4–8 lines (snapshot before, record after) |
| New `typeLabel()` entries | **8** subject types, one line each, plus their English in `lang/en` |
| Tests | 8 — one per screen, asserting the row, the actor and the before/after. The repo already has a test file per screen, so these are additions, not new files |
| **Estimate** | **≈ 350–450 lines across 17 files, and about a day** including the tests |

**Two decisions have to be made first, and they are not code:**

1. **The lookups screen is 12 lists behind one controller.** Either it logs one subject type
   (`catalog_lookups`) with the list name in the label — cheap, one entry in the filter, and the
   filter cannot narrow to "brands" — or twelve subject types, which is twelve filter entries and
   twelve English strings for a screen most people touch rarely. My recommendation: **one type**,
   because the question people will ask is "who deleted this brand", and the label answers it.
2. **Media prune deletes FILES, not rows.** A log row about a deleted file is the only record that
   would exist, so it should carry the paths — which makes it the one place where the log's own
   `changes` column is doing real work rather than recording a diff nobody will read.

**What I would do in what order**, if it is worth doing at all:

1. **The filter lie** — remove `shipping_cities` and `storefront_payment_methods` from
   `typeLabel()`, or add them to the list below. Minutes, and it stops the screen implying coverage
   it does not have.
2. **Media prune and lookups deletes** — the two irreversible ones. ~80 lines, half a day with
   tests.
3. **Units, shipping, banners, storefront, variants, profile** — the rest, in whatever order the
   team's questions actually arrive.

**And one honest caveat about all of it:** `ActivityLog::record()` swallows its own exceptions by
design, so adding these calls cannot break a write — but it also means a logging gap can never
announce itself. The only thing that proves a screen is covered is a test that asserts the row.
That is why the estimate has eight tests in it and why they are not optional.

---

## 12. §11 IS BUILT — and two things in §11 were wrong

*2026-10-05, later the same day. §11 above is left exactly as written, because it is the record of
what was known when the decision was taken. This section is what happened next, and what §11 got
wrong.*

All **19 write actions across the 8 screens** now write to `core_activity_log`, with **one test per
screen asserting the row** — the actor by the name the log captured, the subject, the action, and a
real before/after. The screens: units, the twelve reference lists, shipping prices, banners,
storefront settings, variants, profile and media prune.

### The two corrections

**1. `storefront_payment_methods` was never a false filter entry — and this is the worked example
§11's own rule deserves, so it stays on the page rather than being quietly fixed.**

§11 says, in the paragraph above its coverage table:

> **absence of rows is not absence of coverage**, and confusing the two is how §10 went wrong

Two paragraphs later, under *"One thing that is actively misleading"*, it names
`storefront_payment_methods` as a filter entry nothing writes and recommends removing it. That is
the same confusion, committed in the same section, while writing the warning against it.
`PaymentSettingsController` has written that subject type from **three** actions — add, edit and
delete a payment method — since the payments screen was built. The entry was removed on that
finding, and has been put back.

**How the error was actually made**, because the shape of it is the lesson: the grep ran over the
controllers *being changed that day*, found nothing, and the absence was read as absence everywhere.
A `git grep` across the whole tree would have taken the same few seconds and returned the three call
sites. The first version of this rule in `AGENTS.md` had the same defect in the other direction —
it claimed the log covered everything — so the rule has now been wrong twice, once too generous and
once too harsh, and **both times because it was read from the rows or from a partial grep instead of
from the writers.**

`shipping_cities` was genuinely false, and is now true: the shipping screen logs.

> **The general shape of it:** a filter entry with nothing behind it and one whose rows nobody has
> produced yet look *identical* from the screen. Only the writers can tell you which it is, and
> only `git grep -n 'ActivityLog::record' -- app` across the whole tree counts as having looked.
> An audit that cannot survive that command being run twice is not an audit.

**2. A `deleted` row recorded nothing about what was deleted.** Not this round's code — the whole
table, since it was built. `ActivityLog::diff()` walked `$after` only, and a delete has no after, so
the before-snapshot that `BlogController`, `CategoryController`, `PaymentSettingsController` and
`ProductWriter` all carefully capture was diffed against nothing and the column went in `NULL`. The
log knew a category had been deleted and by whom, and could not say what the category had been.

It was not found by reading the code. It was found in the data: ten `revoked` rows on this database,
all ten with a null `changes`, against zero of the ten `granted` rows beside them — the same screen,
the same writer, one passing `before` and one `after`.

`diff()` now walks the union of both sides, which is a three-line change in one place instead of a
patch at fourteen call sites. A field present in `before` and absent from `after` is exactly what
`from: value, to: null` means. Creations are unaffected (nothing in `before`); updates post both
sides, so no existing row changes shape.

### The two decisions in §11, as taken

1. **Lookups: ONE subject type**, `catalog_lookups`, with the list in the label — `الماركات: Rolex`.
   The list key also travels in `changes`, so a history for one list is reachable without matching
   on a label text.
2. **Media prune carries the paths.** It is the only screen whose row describes something that no
   longer exists anywhere, and the row *nearly failed to do it twice over*: the payload went in as
   `$before`, where `diff()` could not see it, and the path list went in as an array, which
   `ActivityLog::readable()` json-encodes and clips at 300 characters — about eight filenames out of
   five hundred, with nothing saying the rest were dropped. It is now one newline-joined string
   passed as `$after`, capped at 500 paths with the remainder counted in the row, and
   `LIKE '%name.webp%'` finds a single file inside it.

   A prune cannot be driven end to end by a test — the `no_coverage` guard refuses on any machine
   whose media tree is not the live one, which is what stops a workstation deleting its own files —
   so the payload assembly is a public static (`MediaPruneController::auditPayload()`) and the test
   asserts it directly, then pushes the result through `ActivityLog::record()` and reads the row
   back. That is every step except `unlink`.

### What is still NOT recorded, deliberately

- **A bare image upload.** The file arrives with no row of its own; it enters the log when a
  product, banner or brand comes to reference it. The activity screen's coverage sentence now says
  this — it previously claimed banners, articles and the reference lists were unrecorded, which had
  stopped being true.
- **Ten field names render as raw column names** on the activity screen: `list`, `hex`, `retired`,
  `merged_into`, `specifications_moved`, `rows_reordered`, `money_rewards`, `default_locale`,
  `link_url`, and the five media-prune counters. `changedFieldLabel()` gained arms only for the
  fields that could reuse an EXISTING translation key — sixteen of them — because a new key means a
  new English string, and one key may not carry two different Arabic strings, so inventing ten of
  those unseen is how a wrong label gets into a table that is never rewritten. The raw name is the
  documented fallback for a newly audited field and is how anybody finds out one exists. **This is
  work, not a bug** — an hour, and it wants somebody looking at the screen.

### The caveat from §11, restated because it is the reason the tests exist

`ActivityLog::record()` swallows its own exceptions by design. A logging call that has stopped
working cannot announce that. The media-prune row is the proof: it lost its contents twice, and both
losses were silent — they would have been discovered by somebody asking which file had gone, and
finding that the row written for exactly that question could not answer it.
