# Wave 4D — supplier importers and the cleanup tools (2026-09-14)

What was built, what was measured, and what the real runs found. Every number here came from the
two files the client sent, not from an example.

---

## 1. The two files

| file | what it really is | rows |
|---|---|---|
| `Untitled spreadsheet - wc-product-export-27-7-2026-….csv` | a WooCommerce export, 52 columns | 8 614 (8 312 simple + 49 variable + 253 variation) |
| ``T.JOYPrice List`2;4.pdf`` | **a PDF**, Excel-printed, with a real table and 238 embedded photos | 18 pages → 138 extracted rows |

Neither carries a brand column, a `wa_code`, a cost price (except the PDF), or a word of Arabic.

---

## 2. The shape

```
WooExport ──┐
            ├──► SourceRow ──► ProductImporter ──► ProductWriter / PlacementWriter
JoyroomSheet┘                                      VariantWriter / InventoryService / MediaStore
```

`ProductImporter` writes no table directly. Everything goes through the doors the dashboard uses,
so the import inherits the family derivation, the Arabic requirement, the effective-price mirror,
the FULLTEXT reindex, the cache bump, the stock ledger — and every refusal.

**The PreSwitch exemption** is `--allow-preswitch` on the command plus `PreSwitch::allowing([…])`,
a scope restored in a `finally`. No config key, no `.env` entry, no permanent `blocked => false`.
The command prints what the flag means before the first write: everything created is deleted by the
next `core:drop-clean` → `migrate` → `core:transform`.

> ## ⛔ THAT IS NO LONGER TRUE — 2026-09-18
>
> **The rebuilds are finished.** The developer's words: *"Rebuilds are finished, so this import is
> permanent."* The last one ran at `2026-09-17 02:34:55` (every row of `core_transform_id_map`
> carries that one timestamp), and it is the last one there will be.
>
> **What that changes.** An import is no longer a rehearsal that a rebuild will tidy away. Every row
> it writes is production data from the moment it is written, and the only thing that removes it now
> is somebody deleting it on purpose.
>
> **The standing rule that follows.** Anything that would erase imported catalogue data —
> `core:drop-clean`, a transform run, a bulk delete, a truncate, a "just re-import it cleanly" —
> needs the developer's explicit say-so, per occasion. Not inferred from an earlier approval, not
> bundled into a larger task, not assumed because a command exists to do it.
>
> This note sits here because the paragraph above it is the one that taught everybody the opposite,
> and the previous sentence is still true of the runs it describes — it is the world that changed,
> not the history.

---

## 3. The category decision (approved shape, 60 leaves)

Their one `Categories` field holds four different kinds of fact, so each leaf maps to a KIND:

| kind | leaves | destination |
|---|---|---|
| `node` | 48 | our tree, by slug path |
| `gender` | 3 (7 537 placements) | `catalog_product_gender` |
| `material` | 2 (`Leather` 215, `Satin` 138) | the material lookup |
| `none` | 2 (`Uncategorized` 75, `Toys` 1) | no category — and the product is MARKED |

21 nodes are declared as new: `quartz` + `luxury` under Watches; `shoes`, `slippers`, `bundles`
under Fashion; eight bag SHAPES as depth-3 children of Bags; and a new **Electronics** root with six
children on BOTH storefronts — where the Joyroom list lands.

Measured on the real file: **31 distinct node paths are actually used**, and the merge created **20
nodes** on Brand Fashion (the rest already existed).

---

## 4. The six things that can be missing

| missing | what happens | count in the file |
|---|---|---|
| brand | read from the TITLE against our 77; no match → `Generic` + marked | `Brands` column is 0% filled |
| `wa_code` | minted `BF-<woo id>` / `JO-<model>` | not in either file |
| SKU | imported as NULL + marked | 1 140 rows |
| duplicate SKU | first keeps it, rest import without one and are reported | 73 SKUs over 148 rows |
| Arabic | machine-translated, `is_machine` on the translation row | the file is English |
| category / image / price | imported, MARKED, and left HIDDEN | 270 / 544 / 110 |

The marker is DERIVED on every render, so it cannot go stale while somebody fixes the data. The one
stored fact is `catalog_product_translations.is_machine`, cleared by `ProductWriter` on any human
edit. Both are filterable in the products list (`flag=missing_data`, `flag=machine_ar`).

---

## 5. What landed

### Joyroom — COMPLETE

```
imported            86 / 86      (86 distinct models out of 138 rows)
variants            74           (colour rows folded into their model)
images_fetched      62           (of 92 extracted photos)
categories_created   6           (the Electronics tree on Watchizer)
flagged             24           mostly: no stock column in the price list
```

Prices: `RDP` → `purchase_price`, `RRP` → `selling_price`. It is the only source with a cost price.

### Brand Fashion — RUNNING

7 308 of the 8 614 rows are in scope (published **and** visible). Skipped and counted, never
dropped: **631 unpublished, 416 hidden, 6 with no name**.

The run is idempotent (`catalog_products.import_ref` UNIQUE), so it resumes where it stopped.

**Proven under a real crash, 2026-09-17.** That sentence was written the day the importer was and
went untested against anything worse than a rolled-back test transaction. A Windows reboot then killed
`mysqld` and PHP 6 066 products into a 7 308-row run. The resumed run re-read the file, reported
`imported 0` across all six thousand already-present rows, picked up within ~100 rows of the crash
point, and finished with **6 097 imported rows over 6 097 distinct `import_ref` values — 0 duplicated
refs, 0 duplicated `wa_code`s**. Full numbers and the integrity checks on the crashed database:
`OVERNIGHT_2026-09-17.md` §8.

The cost of a resume is one full re-read of the file: the importer does not remember where it stopped,
it re-derives it from what the catalogue already holds. A stored cursor would be a second source of
truth that could point at the wrong row after a partial write; the re-read is cheap next to the
per-product work.

---

## 6. Five defects the real runs found

1. **The family was wrong on every product.** `familyFor()` asks the primary category, and a product
   being created has none — so a Tommy Hilfiger watch arrived as `fashion`. Categories are now
   resolved before creation and the primary travels in the payload.
2. **`str_contains('women', 'men')` is TRUE**, so every women's product was titled رجالي. Gender
   words are matched as whole words now, women first.
3. **TLS.** The first real run stored 79 products and ZERO images: `curl.cainfo` is unset on a stock
   Windows PHP. Added `MEDIA_CA_BUNDLE` (documented in `.env.example`; verification never disabled)
   and the report now groups image failures BY REASON. **It happened again on 2026-09-17** — see §6a.
4. **A source that knows its brand.** Joyroom titles are descriptions, so the title-derived brand was
   "20W Magnetic" and "Screen Protector" for all 86. `SourceRow::$brand` lets a reader assert it.
5. **A half-imported row was skipped for ever.** A failure after `import_ref` left a product with
   translations, an image and no placement. Fixes: `DeadlockRetry` around each row (six parallel
   workers deadlock), `discard()` to remove a partial row (refusing any product with a ledger
   movement or an order line), and the row's work reordered so everything undoable happens before
   the one thing that is not.

---

## 6a. The TLS defect happened a SECOND time — so it is now a guard (2026-09-17)

The overnight run started, imported 400 products, and every one of them was marked "no image".
`MEDIA_CA_BUNDLE` is documented in `.env.example` and was simply **not present in `.env`**, so every
HTTPS fetch died with *"unable to get local issuer certificate"*.

**Nothing about the run looked wrong.** Products imported, counters incremented, the command was on
course to exit zero. It was caught only because image coverage read **26%** — and even that figure
was an artefact: 26% is precisely the share of urls an earlier run had already cached to disk, so
what looked like partial success was in fact a total failure plus a cache.

A documented variable is not a guard. `import:catalogue` now runs a **TLS pre-flight** before the
first row: it takes the first image url the file offers and asks `CoverImages::tlsProblem()`, which
refuses the run with a sentence naming `MEDIA_CA_BUNDLE`, whether it is unset, missing or wrong, and
offering `--images=none` for a deliberate imageless import. Verification is never disabled.

It is deliberately narrow — **only a certificate error stops a run**. A 404, a timeout or a refused
connection says nothing about the machine, supplier files are full of dead links, and aborting 7 000
rows over one of them would be a worse failure than the one being prevented. A certificate error is a
property of the HOST: identical for every url on the internet, fixed by one line in `.env`.

`ImageTlsPreflightTest` pins both halves — it fires on an unusable bundle, and stays silent on a
working machine, on a dead link and on a plain-HTTP url. The working-machine case doubles as a live
check that `.env` still carries a usable bundle, which is the assertion that would have caught this
before the run instead of 400 products into it.

One more thing the guard's own test caught: the first draft named `CURLE_PEER_FAILED_VERIFICATION`,
which **does not exist** on this PHP build (libcurl renamed 60 and PHP exposes whichever name it was
compiled against). The guard against a silent failure would itself have died with a fatal error. The
error numbers are now listed as numbers, with the names in a comment.

---

## 7. The other three tasks

**C2 — payment initiation** now reads the storefront's own contract (`PaymentInitiator`), asking the
same live-or-not question the callback asks. Until this, a customer could pay into one Paymob
account while the callback was verified against another. Prerequisite (d) moves from HALF-OPEN to
READY.

**C3 — units cleanup.** 37 units, **31 of them clothing or shoe sizes**, and `M` is the recorded
unit of **231 watch measurements**. Merge (repoint + retire, one transaction, columns read from the
schema) then Retire (refused while in use). Nothing deletes.

**C4 — media cleanup.** `media:prune`'s scan, refusals and deletion moved into `MediaAudit`; the
command prints them and the new admin-only screen renders them, and a test asserts they agree. The
screen adds a typed confirmation checked against a FRESH scan.

---

## 8. An unknown category FLAGS the row — it no longer refuses it (2026-09-17)

The rule of 2026-09-15 refused any row whose category leaf was not in `CategoryMap`. Its reasoning
was right about the thing it protected and wrong about what to do with it.

**What stays.** An unmapped leaf still contributes **no placement**. No guess, no fallthrough to a
default node, no teaching the reconciliation to look away. That guarantee is the point, because a
guessed placement is invisible afterwards: the product sits in a real section, looks deliberate, and
nothing records that a machine chose it.

**What changed.** The PRODUCT now arrives. Its title, price, stock, images and brand are all good
data, and refusing the whole row threw every one of them away over a single unfamiliar word in one
column. It lands **unplaced, hidden and marked `needs_category`**, with the offending leaf named in
`report.csv` — so it is one dashboard assignment rather than a re-run of a file of thousands.

`needs_category` is deliberately NOT the same marker as `category`:

| marker | what it means | what to do |
|---|---|---|
| `category` | the source said nothing usable (`Uncategorized`, or no category) | decide what the product is |
| `needs_category` | the source said a real word the map has not been asked about | assign it, or add a `CategoryMap::LEAVES` row |

Collapsing them would hide the second inside the first, and the second is the one that means *the map
is out of date* rather than *their data is thin*.

**What it costs on today's data: nothing.** Measured on the real export, 2026-09-17: 8 614 rows,
**60 distinct leaves, all 60 mapped**, and no leaf in the map the file does not use. Both the old rule
and the new one fire zero times on this file. `UnmappedCategoryTest` fabricates the row instead, and
its load-bearing case is *"it places it NOWHERE"*.

---

## 9. One consequence worth stating

**An import run and a compat-harness run are mutually exclusive until the write-switch.** The
harness compares core's catalogue byte-for-byte with legacy's, and an imported product exists only
in core — 86 Joyroom products are on Watchizer's storefront, so `all_product` now carries rows
legacy has never heard of. Running the harness needs the rebuild that destroys the import, which is
exactly the trade the rehearsal accepted.
