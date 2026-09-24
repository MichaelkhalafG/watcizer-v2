# Overnight run — 2026-09-17

The review fix list, a fresh production database, the full switch-night sequence, and the complete
Brand Fashion import. Nothing is committed; the branch is `wave-4d` and the tree is as this run left
it.

---

## 1. The fix list — all eleven landed

| # | finding | what was done |
|---|---|---|
| 🟠-1 | settlement export ignored the grant | `applyScope()` on `settlement()`, plus `abort_if` on a `storefront_id` outside the grant. Scoped-admin probe is a permanent test. |
| 🟠-2 | raw transport text stored and rendered | `MailFailure` classifies (`auth`/`connect`/`refused`) and masks; the screen shows the classification. A test asserts no SMTP username or `host:port` can reach the props. |
| 🟠-3 | no Arabic validation attributes | `lang/ar/validation.php` and `lang/en/validation.php`, 84 attributes each, generated with a guard that aborts if the two locales name different fields. A test fails if a locale has no validation file. |
| 🟠-4 | the ratchets never scanned `config/` | `config/catalog.php`'s spec-block labels, list names and extra-column labels are on the seam; `ServerTranslationCoverageTest` now scans `config_path()`. |
| 🟠-5 | harness mail one command from real addresses | The harness and the WriteTarget tools write outbox rows as `parked`; `mail:drain` refuses any row belonging to an order with a parked sibling. The 26 parked rows are left as they were. |
| 🟡-1 | discount clamped per reward | `discountFor()` is unclamped; `clampToCart()` runs once, on the sum, before recording. |
| 🟡-2 | activity log unscoped | `storefront_id IN scope OR NULL` — the NULLs stay visible on purpose, because a shop-wide action by a scoped operator must not vanish from their own log. |
| 🟡-3 | `products` moves during a harness run | Documented in the runbook, with the table of what is EXPECTED to have moved. |
| 🟡-4 | importer swallowed the row after a bad one | Width checked per row, refused **by line number**, naming "unclosed quote" as the usual cause; all file refusals are `ImportFileError` and print as a sentence, not a stack trace. |
| 🟡-5 | English suffix on an Arabic refusal; English CSV headers | Suffix removed; all ten settlement headings on the seam. |
| 🔵 | double cancel; unnamed FK | The UPDATE is now the claim (`where status != 'cancelled'` → `$claimed === 0` means somebody else got there first, reported as a status not an error). `core_user_preferences`'s key is named `cup_user_fk` per §2.14 — and this run actually applied the rename to the live database, see §3. |

**Verification before the data work:** PHPStan (Larastan level 10) clean, Pint clean, `tsc --noEmit`
clean, `npm run build` clean, and the full Pest battery **1 201 passed / 38 skipped / 0 failed**.

**Read that skip count properly — I did not, at first.** 38 is the *wrong-order* number the runbook
warns about: the battery ran against the catalogue as the previous session's harness had left it, so
the ledger held order movements and **31 transform tests skipped themselves** rather than
re-baselining over real stock. Nothing failed and nothing was broken, but those 31 were not exercised
in that run. The transform was proved instead by §4, which drives the same code end to end on a
rebuilt catalogue and reconciles 83 checks — stronger evidence for that path than the unit tests, but
it is not the same claim, so it is written separately. See §6.7 for why the battery was not re-run
afterwards.

Seven of those tests were stale rather than wrong, and were fixed rather than skipped: 🟠-2 removed
`last_error` from `OrderMailer::forOrder()` on purpose, and seven cases still asserted on it. They now
assert the classification. That change surfaced a second thing worth having — see §2.

---

## 2. Two classifications that 🟠-2 would otherwise have made worse

`OrderMailer` writes two `last_error` values that never came from a transport: *"ORDER_ADMIN_EMAILS
is empty"* and *"the order carries no customer e-mail address"*. We compose both, they carry nothing
secret, and they are the most actionable messages in the whole outbox.

Passed through the new classifier unrecognised, both would have rendered as **"failed for an
unrecognised reason — needs a developer"**, which is false of each: the reason is known, and neither
needs a developer.

So they get classifications of their own — `MailFailure::CONFIG` and `MailFailure::NO_ADDRESS` — with
their own sentences, rather than an exemption. `MailFailure::ours()` is the single door for any
`last_error` we write ourselves, stamping the same `[kind]:` marker `masked()` writes, and `kindOf()`
now matches `[kind]: ` with the colon so a message that merely *contains* a bracketed word (e.g.
`unknown mail kind [woo]`) cannot be mistaken for a classification.

The `CONFIG` sentence names `ORDER_ADMIN_EMAILS` deliberately. It is a variable **name**, never a
value, and it is the difference between a message an administrator can act on and one that sends them
to a developer.

---

## 3. The production database was replaced

Import of `u591083448_watchizer 9-17.sql` (1.9 MB, generated 2026-09-16 22:19). A full `mysqldump`
of the previous local database was taken first and is beside it, outside the repository.

`mysql < export.sql` is wrong three ways, and the third fails almost silently — so this is now
`scripts/refresh-legacy-from-export.ps1`, and the trap is written up in the harness runbook:

1. The export carries **no `DROP TABLE`**, so a straight import dies on *table already exists* for all
   65 legacy tables.
2. phpMyAdmin writes `CREATE TABLE` **without the primary key** and adds it in a later `ALTER TABLE`.
   Six core-owned foreign keys point at `users.id`, so recreating `users` makes InnoDB validate those
   orphaned child constraints against a table with no index on `id` yet, and it refuses the statement
   with `errno: 150`. **`SET FOREIGN_KEY_CHECKS = 0` does not prevent this** — it suppresses row
   checks, not the structural validation of a pending constraint's parent. The other 64 tables import,
   `users` does not, and the error is four lines deep in thousands of output.
3. Dropping those six keys is therefore required, and re-adding them is the half that gets forgotten.

The script drops the six, replaces the 65, restores the six, and proves both halves: 57 core-owned
tables still present, 65 exported tables present, and a before/after count per table. It refuses to
import if its own backup failed. Because it drops and re-adds the key anyway, it took the one free
moment to rename `core_user_preferences_user_id_foreign` to **`cup_user_fk`** — closing the "honest
limit" the 🔵 migration documented for databases built before today.

### The delta

**The catalogue grew**, exactly as a week of data entry would:

| table | before | after | delta |
|---|---:|---:|---:|
| `products` | 530 | 626 | **+96** |
| `product_translations` | 1 060 | 1 252 | +192 (2× the new products) |
| `product_images` | 2 058 | 2 330 | +272 |
| `feature_product` | 1 010 | 1 126 | +116 |
| `gender_product` | 530 | 626 | +96 |
| `color_band_product` | 551 | 653 | +102 |
| `color_dial_product` | 307 | 410 | +103 |

94 of the 96 new products were created on or after 2026-09-13.

**The orders shrank, and that is not data loss.** `orders` 90 → 9, `order_items` 94 → 9, `addresses`
72 → 9, `carts` 71 → 20. The 9 that remain are the real production orders (July 20 – September 1,
real customer names). The 81 extra were **harness-placed orders accumulated locally** — the compat
harness places real orders through the compat endpoints, which is the same fact 🟡-3 documents. The
local database had been collecting them; the production export has none.

**No new taxonomy.** `sub_types` is still 28 rows and `category_types` still 2, so the pin map needed
no change. Measured directly rather than assumed: every product's `sub_type_id` resolves, 0 dangling,
37 NULL, and **A-26 = 0** — every product-less sub type is pinned. Sub types that *have* products
derive their parent from the real `(category_type_id, sub_type_id)` pairs, so nothing in the legacy
taxonomy is guessed at any point.

---

## 4. The switch-night sequence — everything reconciles

```
core:transform --audit   35 codes, 12 non-zero, NO BLOCKING FINDING
core:drop-clean          41 dropped, 15 dashboard-authored preserved and verified present
migrate                  20 migrations, all re-run against the preserved tables
core:transform           inserted 15 848, updated 0 — reconciliation: ALL COUNTS RECONCILE (83 checks)
core:transform (again)   inserted 0, updated 0, unchanged 15 148 — ZERO NET CHANGES (idempotent)
inventory:verify         1 252 checks over 626 products — ledger, columns, aggregate and in_stock agree
```

`legacy checksum identical before/after: 2fb07ea2…` on every run — the transform read the legacy
tables and wrote none of them.

Audit codes that are non-zero and why none of them blocks: A-10 (25) family/column mismatches, A-15
(15) product-less sub types (all pinned), A-17 (7) slug collisions, A-18 (37) products with a NULL
type or sub type, A-22 (6) stored discount ≠ derived, A-28 (7) pins now redundant because their sub
type gained products. A-11 (54) and A-12 (145) are missing image files, and the command says so
itself: this workstation holds a **partial** copy of the image tree, so those two are not production
findings.

---

## 5. What is in the real file that the samples had not shown

Measured over all 8 614 rows rather than a 500-row slice.

**Everything in the map, nothing outside it.** 60 distinct category leaves, **all 60 mapped**, and no
leaf in the map the file does not use. Both the old refusal rule and the new flag rule fire zero
times on this file.

**Their `Parent` column is written two ways** — `id:61368` for 111 variations and `GUS026` (the
parent's SKU) for 142. The reader already accepts both by design, and all 253 resolve. Worth stating
because a naive check reports 23 "orphans" and there are none.

**Their test data never reaches us.** Three rows are named `test`, `Test Product For Nabil` and
`Testing Variable Product`; all three are unpublished or hidden, so the published-and-visible gate
already excludes them. Evidence the gate earns its place.

Things worth a decision:

| finding | count | note |
|---|---:|---|
| in-scope rows carrying **more than one image** | 7 279 of 7 282 | median **5**, up to 17. We import the **cover only**, so roughly **33 000 gallery images** are being left behind. |
| in-scope rows with stock ≤ 0 | 1 857 | plus 776 with no `Stock` value at all — about **36%** arrive unsellable |
| in-scope rows with no SKU | 744 | imported as NULL and marked |
| duplicate SKUs | 19 SKUs over 38 rows | first keeps it, the rest import without one |
| in-scope rows with no usable price | 26 | imported, marked, left hidden |
| titles over 120 characters | 92 | longest is **211** characters |
| titles with non-ASCII characters | 478 | |
| titles carrying HTML entities | 28 | the decoder handles these |
| **31 products priced at exactly 214 380.00 EGP** | 31 | every other price in the file is a round tier (196 distinct prices over 7 282 products; 1 500 × 1 218, 5 500 × 1 208). 214 380 is not round and is shared by 31 different Seiko models. It looks like a data-entry or conversion artefact — **not changed, because guessing at catalogue prices is exactly the thing not to do.** |

---

## 6. What I decided and why

Each of these is a judgement I would otherwise have asked about. Reverse any of them.

### 6.1 An unknown category now FLAGS the row instead of refusing it

*The alternative:* keep the 2026-09-15 rule, which refused any row whose category leaf was not in
`CategoryMap`.

*What I chose:* the row imports, **unplaced, hidden and marked `needs_category`**, with the offending
leaf named in `report.csv`.

*Why:* your instruction was explicit — flagged and unplaced, not stopped — and the reasoning holds up
on its own. Refusing the PLACEMENT is right, because a guessed placement is invisible afterwards. But
refusing the PRODUCT throws away its title, price, stock, images and brand over a single unfamiliar
word in one column, and on a 7 000-row overnight run that is the difference between a catalogue with
a to-do list and no catalogue at all. `unmappedLeaves` contributes no path, so the guarantee that
mattered is untouched: nothing is guessed, nothing falls through to a default node.

Two sub-decisions inside it:

- **A row with one known leaf and one unknown leaf is placed by the known one, and still flagged.**
  The known leaf is a fact, not a default; dropping a real placement to punish an unrelated unknown
  word loses information for nothing.
- **`needs_category` is a separate marker from `category`.** `category` means the source said nothing
  usable and somebody must decide what the product is; `needs_category` means the source said a real
  word we have not mapped, and the fix is one dashboard assignment or one `CategoryMap::LEAVES` row.
  Folding them together would hide the second inside the first, and the second is the one that means
  *the map is out of date*.

It fired **zero times** on this file: all 60 leaves are mapped. `UnmappedCategoryTest` fabricates the
row instead, and its load-bearing case is *"it places it NOWHERE"*.

### 6.2 I stopped the first import run 400 products in, and started again

*The alternative:* let it finish and report low image coverage in the morning.

*What I chose:* stop, fix the cause, rebuild, restart from zero.

*Why:* `MEDIA_CA_BUNDLE` was missing from `.env`, so every HTTPS fetch failed TLS verification and
every product was arriving with no image. The run would have completed, exited zero, and produced
seven thousand imageless products — and because the importer is idempotent on `import_ref`, a later
re-run would have **skipped** every one of them rather than fetching their images. Finishing the run
would have made the damage harder to undo, not easier. Restarting cost twelve minutes.

Coverage read 26% when I caught it, which is itself worth knowing: 26% was not partial success, it
was total failure plus the share of urls an earlier run had already cached to disk.

### 6.3 The TLS failure is now a guard, not a documentation line

*The alternative:* set the variable, note it, move on. It is already documented in `.env.example`.

*What I chose:* `import:catalogue` runs a TLS pre-flight before the first row and refuses with a
sentence naming `MEDIA_CA_BUNDLE`.

*Why:* this is the **second** time it has happened — the first cost 79 products and zero images on
2026-09-14. A documented variable that nobody copies into `.env` is not a guard, and the failure mode
is silent: every counter looks plausible and the command exits zero. It is deliberately narrow, and
only a **certificate** error stops a run: a 404 or a timeout says nothing about the machine, supplier
files are full of dead links, and aborting 7 000 rows over one would be a worse failure than the one
prevented.

Its own test caught a bug in it immediately: the first draft named `CURLE_PEER_FAILED_VERIFICATION`,
which does not exist on this PHP build, so the guard against a silent failure would itself have died
with a fatal error. The codes are now numbers with the names in a comment.

### 6.4 Two new mail-failure classifications rather than an exemption

*The alternative:* let `ORDER_ADMIN_EMAILS is empty` and `no customer e-mail address` fall through to
`unknown`, or exempt them from classification.

*What I chose:* `MailFailure::CONFIG` and `MailFailure::NO_ADDRESS`, each with its own sentence.

*Why:* 🟠-2 was about never storing transport text. These two strings are not transport text — we
write them ourselves a few lines away — and they are the most actionable messages in the outbox.
Unclassified they would have rendered as "failed for an unrecognised reason, needs a developer",
which is false of both. A security fix that makes two good messages worse has overshot.

### 6.5 The 81 missing orders are not investigated further

*The alternative:* treat `orders` 90 → 9 as data loss and dig.

*What I chose:* record it as expected, with the evidence, and move on.

*Why:* the 9 remaining orders are real ones with real customer names spanning July–September; the 81
that vanished existed only in the local copy, which is exactly what a harness that places real orders
through the compat endpoints produces. That is the same fact 🟡-3 documents. Nothing was lost that
production ever had.

### 6.6 I renamed the `core_user_preferences` foreign key on the live local database

*The alternative:* leave it as Laravel's generated `core_user_preferences_user_id_foreign`, as the
migration's own "honest limit" note says happens on databases built before today.

*What I chose:* re-add it as `cup_user_fk` while the refresh script had it dropped anyway.

*Why:* the script has to drop and restore that key regardless, so it is the one moment the §2.14 name
costs nothing. It closes the limit for this database instead of leaving it to the next migration.

### 6.7 The catalogue is left in place, so the full battery is not re-run

*The alternative:* rebuild after the import and run the whole suite again, which would prove a clean
green — and delete every imported product.

*What I chose:* leave the imported catalogue standing.

*Why:* you want to assign the flagged products from the dashboard in the morning, and a rebuild would
destroy them.

What is actually proved, stated exactly:

- The battery ran **1 201 passed / 38 skipped / 0 failed** — but in the wrong order, so 31 transform
  tests skipped themselves (§1).
- The transform itself is proved by §4 end to end: audit, rebuild, 83 reconciliation checks, a
  zero-change idempotent re-run, and `inventory:verify`.
- Every change made after that battery is covered by a suite that was run and passed: Notifications
  (40), Import (79 — 6 rewritten, 5 new), plus PHPStan level 10, Pint and `tsc` clean after the last
  edit.
- **The full battery has not been run against the current tree**, and cannot be honestly: the import
  has written `reason=import` rows into the ledger, so the same 31 tests would skip again. A clean
  green needs the rebuild that throws the night's data away.

That trade is yours, not mine. When you want it:
`core:drop-clean --force && migrate --force && core:transform --force && vendor/bin/pest` — expect
~7 skipped, and re-run the import afterwards if you still want the catalogue.

### 6.8 Gallery images are still not imported, and I did not change that

*The alternative:* switch the run to fetch all images.

*What I chose:* cover only, as the command's default and the existing decision.

*Why:* it is a much larger change than it looks — 7 279 of 7 282 rows carry more than one image, a
median of 5 and up to 17, so roughly 33 000 more files, a run measured in days rather than hours, and
several gigabytes. That is a decision about time and disk that belongs to you, not a default I should
quietly flip inside an overnight run. It is in §5 as a number so you can decide with it.

### 6.9 A broken file no longer takes the report with it

*The alternative:* leave it; `ImportFileError` already prints a readable sentence and exits non-zero.

*What I chose:* write `report.md` and `report.csv` on the failure path too, then return the non-zero
exit.

*Why:* found while checking whether tonight's four-hour run could survive an interruption. The
command returned on the error **before** writing the report, so a malformed row a thousand products
in would have left a database full of half-finished products and no list of which ones need a person
— precisely what `report.csv` exists to prevent. The rows already written are committed either way;
only the order of the last two steps changed. The verdict did not: the exit code is still non-zero,
and the run now says out loud that the report covers the part that landed.

It does not help tonight's run — PHP had already loaded the old class — but it is the kind of thing
that is only ever noticed while something long is running.

---

## 7. Two small things found while watching the run

**Zero-byte renditions — 8 of 59 835 files (0.013%).** Seven are dated 2026-09-14 and one is from
tonight, so this is a rare pre-existing hiccup in rendition encoding, not something this run
introduced. Seven are AVIF and one is WebP, spread across the 320/480/640/960 sizes with no pattern.
An empty AVIF is probably invisible to a customer — the `<picture>` element falls through to the next
source — but the empty WebP is not, and nothing currently notices that a rendition came out empty.
**Not fixed**: it wants a size check in the pipeline plus a test, and the middle of a four-hour import
is the wrong moment to change the pipeline. Measured and left for a decision.

**A false alarm worth writing down.** `media.root` is `public/Uploads_Images` and `MediaStore` resolves
it through `base_path()`, so imported images land in **`core/public/Uploads_Images`**. The *legacy*
tree at `backend/public/Uploads_Images` is the read-only source the transform's A-11/A-12 audit reads,
and it does not grow during an import. Checking the wrong one of the two makes a perfectly healthy run
look like it is recording images it never wrote — which is exactly what the TLS bug looked like an
hour earlier, so it is worth knowing which tree answers which question.

---

## 8. The machine rebooted at 06:32, and what that cost

**Cause: a Windows restart, not anything in this work.** `LastBootUpTime` is 2026-09-17 06:32:02.
It took `mysqld` and the running `php` with it. Disk was not full (C: 19.8 GB, D: 391 GB free) and
memory was not exhausted — the import had been running cleanly for nearly four hours at that point.

**Nothing was corrupted.** InnoDB replayed 16 pages from the redo log on restart and came up clean.
Checked explicitly rather than assumed, because a crash mid-import is exactly where a half-written
product would hide:

| check | result |
|---|---:|
| imported products missing an `ar` translation | 0 |
| imported products missing an `en` translation | 0 |
| imported products with no `storefront_product` row | 0 |
| imported products with no search row | 0 |
| imported products with no ledger movement **and non-zero stock** | 0 |
| products whose ledger disagrees with their stock column | 0 |

The one number that looks alarming and is not: **1 957 imported products have no ledger movement at
all.** Every one of them has zero stock, and an opening balance of zero writes no row. That is the
importer behaving correctly, and it matches the file — 1 857 in-scope rows carry stock ≤ 0 and 776
carry no `Stock` value.

**Where it stopped:** 6 066 of ~7 308 products imported (83%), 6 040 of them with an image (99.6%).

**Resumed, not restarted.** `catalog_products.import_ref` is UNIQUE and the importer skips a row it
has already seen, so the second run continues rather than duplicating. The resumed run also picks up
the TLS pre-flight written earlier in the night, which it passes.

### 8.1 The resume path was theory until 06:32. It is not any more.

`IMPORTERS_2026-09-14.md` §5 has said *"The run is idempotent (`catalog_products.import_ref` UNIQUE),
so it resumes where it stopped"* since the day the importer was written. **Nothing had ever tested
that claim against a real crash.** `ImportRunTest` covers the same-file-twice case on a handful of
rows in a transaction that rolls back; a machine losing power 6 066 products into a 7 308-row run is a
different thing, and until this morning nobody knew whether the sentence was true.

It is true, and here is what was measured rather than assumed.

**The skip phase behaved exactly as the claim requires.** The resumed run re-read the file from row 1
and reported `imported 0` all the way through the region the first run had already covered:

```
…  4500 rows | imported     0 | flagged   127
…  5000 rows | imported     0 | flagged   142
…  5500 rows | imported     0 | flagged   149
…  6000 rows | imported     0 | flagged   156
…  6250 rows | imported    18 | flagged   184     ← picks up where the crash left it
```

Six thousand rows recognised as already present, not one of them written twice, and the first new
insert lands within ~100 rows of where the power went.

**The decisive check is the one that would fail if any of that were wrong** — a row imported twice
would mean two `catalog_products` rows sharing an `import_ref`:

| check | result |
|---|---:|
| imported rows | 6 097 |
| distinct `import_ref` values | **6 097** |
| `import_ref` values used by more than one product | **0** |
| `wa_code` values used by more than one product | **0** |

Equal counts and two zeroes. The UNIQUE key did its job, and so did the check in front of it.

**What this costs on a real resume: one full re-read of the file.** The importer does not remember
where it stopped; it re-derives it, row by row, from what the catalogue already contains. That is the
slower design and the right one — a stored cursor would be a second source of truth that could point
at the wrong row after any partial write, and the file is cheap to read compared to what happens per
product. The skip phase took a couple of minutes over 6 000 rows.

So: documented 2026-09-14, **proven under a real crash 2026-09-17**, with the numbers above. Nobody
needs to treat it as theory again.

---

## 9. Developer decisions taken on the morning of 2026-09-17

### 9.1 Gallery images: NOT fetched, and not scheduled

The team has their own product photography and will upload it from the dashboard, so the supplier
file's gallery URLs are not worth fetching. The cover we already have is enough to start from. The
~33 000 gallery URLs measured in §5 stay a measurement, not a backlog item — **nothing is scheduled**.

### 9.2 The 31 odd prices: the team corrects them, and they are findable

Not changed — guessing at catalogue prices is the thing not to do, and they are the team's to fix.
What they needed was to be findable, and that is now true by identifier: every one is listed with its
`wa_code` in this run's `report.csv`, and the products list searches on that code.

**Being straight about one thing:** no dashboard filter *isolates* these 31 specifically. The
missing-data filter answers six derived questions plus `image_problem`, and "this price looks odd" is
not one of them — it cannot be, without a rule. Every price in this file is a round tier (196 distinct
prices over 7 282 products) and 214 380 is not, which is a good smell for a human and a bad one for
code: a threshold that caught it would also flag every genuinely expensive watch the shop ever adds.
So the answer is the list, not a filter, and I did not invent a price-outlier detector to look
thorough.

### 9.3 No further rebuild

The catalogue stays as it is. The full battery waits for a natural moment rather than trading real
data for a green suite — see §6.7 for exactly what is and is not proved in the meantime.

### 9.4 Zero-byte renditions: fixed, guarded, and surfaced (M1s)

Not left as a backlog line. Four parts:

**1 — The pipeline cannot record one again.** `imageavif()` and `imagewebp()` can return `true` and
leave a zero-byte file. `ImagePipeline` now checks the bytes on disk, **removes** the empty file and
records it as a failure instead of listing it in `renditions` — so nothing broken is ever served. The
master gets the same check and **throws**: a missing rendition degrades to another `<picture>` source,
a zero-byte master has nothing to fall through to.

**2 — A failure is not a skip.** `skipped` already carried deliberate omissions like *"320w: source is
only 300px wide"*, which is the pipeline correctly refusing to upscale and is true of a large share of
any supplier catalogue. `ImagePipeline::FAILED_PREFIX` separates the two. Conflating them would have
put half the shop on a review list and taught the team to ignore the marker.

**3 — The team sees it.** `catalog_product_images.renditions_failed` (M1s migration, a guarded
`Schema::table` so it applies without a rebuild) is the one **stored** image marker — it has to be,
because "does a file on disk have zero bytes" is not a question a list query can ask per row. It
renders as a seventh missing-data chip reading *"صورة تالفة — تحتاج رفعًا جديدًا"* / *"Damaged image —
needs re-uploading"*, has its own `flag=image_problem` filter, and joins the `missing_data` composite
so the §4 law still holds: **the filter and the badge select the same set.** A re-upload clears it, the
same lifecycle `is_machine` has.

Deliberately a separate token from `image`: that one means *find a photo*, this one means *the photo
we have did not process*. Two different actions.

**4 — The existing files are found.** `media:verify --empty-renditions` walks the tree for files that
exist and hold zero bytes — the check in step 1 of that command walks straight past them, because
`is_file()` is true for an empty file, which is exactly how 8 of them sat there unnoticed since
2026-09-14. `--mark` records them on the product rows.

**What it found this morning:** 7 empty files, all dated 2026-09-14, and one of them a **`-480.webp`**
— the customer-visible case, not just AVIF. `--mark` marked **0 rows**, and that is the right answer:
the rebuild had already dropped the `catalog_product_images` rows that referenced them, so they are
unreferenced files in the tree. Checked the other place they could still do harm — the importer's
image cache references **63 950 rendition files, 0 of them empty** — so nothing anywhere currently
serves a broken rendition. `media:prune` is what removes the 7 orphans.

Tests: `MediaTest` pins that every recorded rendition has bytes on disk (`file_exists()` is true for an
empty file, so the pre-existing test walked past this) and that a deliberate skip is not a failure;
`ImportRunTest` pins the whole path from the column to the chip — the marker appears, the filter
selects **only** the affected product, the composite filter includes it, and a re-upload clears it.

---

## 10. The thirteen that did not land — and the lesson under them

The first complete pass imported 7 074 of 7 308. Thirteen rows failed, **and not one was the
supplier's fault in a way we could not have handled**:

| cause | rows | what it was |
|---|---:|---|
| slug too long | 10 | `storefront_product.slug` is varchar(191); their titles run to **211 characters** |
| SKU too long | 1 | **180 characters of Arabic marketing copy** pasted into the SKU column |
| invalid UTF-8 | 1 | a lone `0x8E` byte in a Calvin Klein title — MariaDB refused the whole INSERT |
| `Product 7001 does not exist` | 1 | a product created and gone before its placement |

All four are fixed and the thirteen re-imported in **14 seconds**: `imported 13`, **0 failed, 0
refused**. Final tally **7 087 imported + 221 duplicates = 7 308** — the in-scope total, reconciling
exactly.

### 10.1 A measurement without a threshold test is only half a finding

Three of the four were **already measured** the night before, in §5, and written up as curiosities:
*"92 titles over 120 characters, longest 211"*, *"478 titles with non-ASCII"*. The numbers were
right. The conclusion was missing.

A count tells you the data is unusual. Only comparing it against **what the schema actually accepts**
tells you it is about to fail — and nothing in §5 did that, so ten products were lost to a number
already sitting in the report. `HostileFieldsTest` is that comparison, kept: the fixtures are the real
211-character title, the real `0x8E` byte and the real 180-character SKU.

The same reading applies to any measurement in this document: 214 380 is a price anomaly that has not
been checked against a rule, and 2 994 generic-brand products is a count that has not been checked
against what the storefront does with an unbranded product.

### 10.2 What each fix does, and why it is not a truncation everywhere

- **Slug** — shortened, cutting on a hyphen so the URL ends on a whole word, with room reserved for
  the `-{id}-{n}` collision suffix (truncating to exactly 191 and *then* appending a suffix would
  reproduce the same `1406` one step later — that is its own test). The transform keeps its BLOCKING
  X-05, and rightly: a legacy product already has a live URL to preserve. An imported product has
  none, so shortening costs nothing. The importer now REPORTS each shortening (`slug_shortened`, 10
  rows) — the X-05 equivalent, visible rather than silent.
- **Typed slugs are refused, not shortened.** Nobody chose the generated one; somebody chose that one,
  and handing them back a different URL is how a person links to a page that does not exist.
- **Invalid bytes** are dropped in `SourceTitle::scrub()`, which now runs **first** — everything else
  in that class is `preg_*` with `/u`, which returns null on bad UTF-8, so one byte had been silently
  disabling the entity decode, the un-glue and the whitespace collapse through their `?? $text`
  fallbacks. Dropped rather than substituted: a visible `�` in a customer-facing title is worse than a
  gap. Invisible bidi and zero-width marks go too — legal UTF-8 that makes two identical-looking
  titles compare unequal.
- **The SKU is NOT truncated**, and this is a deliberate departure from the instruction. Cutting 180
  characters of Arabic prose to 64 would store a sentence fragment and present it to the team as this
  product's supplier code — a wrong answer that looks like a right one, and the kind nobody
  re-checks. It is treated as absent instead, which is the path an empty SKU already takes: the
  product lands, carries the `sku` marker, and the report says what was really in the column.
  **Say the word and I will switch it to a plain truncation.**
- **`Product 7001`** — cause not determined, and not invented. The product was created (its id is
  missing from the sequence) and gone by the time its placement ran; `discard()` then cleaned up,
  which is the error path working. It imported cleanly on the re-run, so it was transient — most
  likely my own test suite writing to the same database while the import ran. Recorded as unexplained
  rather than given a tidy story.

### 10.3 Which row count is authoritative: **8 617**, from the reader

`WooExport` counts **8 617** source rows (8 315 simple + 253 variation + 49 variable). My own
`fgetcsv` probe the night before counted **8 614**, and §5 quotes that number.

**The reader is authoritative.** It is the code that actually consumes the file: it handles embedded
newlines inside quoted fields, refuses a malformed row by line number (🟡-4) rather than silently
resyncing, and is the thing whose count has to reconcile with the catalogue. A quick `fgetcsv` loop
in a scratch script is not — the three-row gap is in `simple` rows, and it is my probe's parsing, not
the importer's.

**So: derive the file's size from `import:catalogue --dry-run`, never from an ad-hoc script.** A
throwaway probe is fine for exploring shapes and wrong for any number that goes in a report — which
is exactly how §5 came to quote 8 614.
