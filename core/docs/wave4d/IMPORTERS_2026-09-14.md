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

---

## 6. Five defects the real runs found

1. **The family was wrong on every product.** `familyFor()` asks the primary category, and a product
   being created has none — so a Tommy Hilfiger watch arrived as `fashion`. Categories are now
   resolved before creation and the primary travels in the payload.
2. **`str_contains('women', 'men')` is TRUE**, so every women's product was titled رجالي. Gender
   words are matched as whole words now, women first.
3. **TLS.** The first real run stored 79 products and ZERO images: `curl.cainfo` is unset on a stock
   Windows PHP. Added `MEDIA_CA_BUNDLE` (documented in `.env.example`; verification never disabled)
   and the report now groups image failures BY REASON.
4. **A source that knows its brand.** Joyroom titles are descriptions, so the title-derived brand was
   "20W Magnetic" and "Screen Protector" for all 86. `SourceRow::$brand` lets a reader assert it.
5. **A half-imported row was skipped for ever.** A failure after `import_ref` left a product with
   translations, an image and no placement. Fixes: `DeadlockRetry` around each row (six parallel
   workers deadlock), `discard()` to remove a partial row (refusing any product with a ledger
   movement or an order line), and the row's work reordered so everything undoable happens before
   the one thing that is not.

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

## 8. One consequence worth stating

**An import run and a compat-harness run are mutually exclusive until the write-switch.** The
harness compares core's catalogue byte-for-byte with legacy's, and an imported product exists only
in core — 86 Joyroom products are on Watchizer's storefront, so `all_product` now carries rows
legacy has never heard of. Running the harness needs the rebuild that destroys the import, which is
exactly the trade the rehearsal accepted.
