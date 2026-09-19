# The watch specifications block, checked against both schemas

**2026-10-05 · report only, nothing changed**

Every column of `catalog_product_watch_specs`, lined up against the legacy column it came from, the
legacy dashboard's own label for it, and what our form calls it today.

---

## 1. Column by column

`✓` = the label names the column correctly. `⚠` = the label is ambiguous or says something the
column does not.

| clean column | legacy column | legacy label (ar / en) | our label (ar / en) | |
|---|---|---|---|---|
| `case_size` + `case_size_unit_id` | `case_size` + `case_size_type_id` | حجم العلبة / Case Size | قياس العلبة / Case size | ⚠ |
| `case_thickness` + unit | `case_thickness` + type | سمك العلبة / Case Thickness | سماكة العلبة / Case thickness | ✓ |
| `case_shape_id` | `case_shape_id` | شكل العلبة / Case Shape | شكل العلبة / Case shape | ✓ |
| `case_material_id` | `dial_case_material_id` | مادة علبة القرص / Dial Case Material | مادة العلبة / Case material | ✓ (ours is clearer) |
| `glass_material_id` | `dial_glass_material_id` | مادة زجاج القرص / Dial Glass Material | مادة الزجاج / Glass material | ✓ |
| `band_material_id` | `band_material_id` | **الخامة / Material** | مادة السوار / Band material | ⚠ **see §2.1** |
| `band_closure_id` | `band_closure_id` | نوع إغلاق حزام الساعة / Watch Band Closure Type | نوع الإغلاق / Closure type | ⚠ |
| `band_length` + unit | `band_length` + `band_size_type_id` | طول حزام الساعة / Watch Band Length | طول السوار / Band length | ✓ |
| `band_width` + unit | `band_width` + type | عرض حزام الساعة / Watch Band Width | عرض السوار / Band width | ✓ |
| `dial_display_type_id` | `dial_display_type_id` | نوع عرض القرص / Dial Display Type | **نوع العرض / Display type** | ⚠ **see §2.2** |
| `movement_type_id` | `watch_movement_id` | نوع الحركة / Movement Type | نوع الحركة / Movement type | ✓ |
| `water_resistance` + unit | `water_resistance` + type | مقاومة الماء / Water Resistance | مقاومة الماء / Water resistance | ✓ |
| `height` + unit | `watch_height` + type | **ارتفاع الساعة / Watch Height** | **الارتفاع / Height** | ⚠ **see §2.3** |
| `width` + unit | `watch_width` + type | **عرض الساعة / Watch Width** | **العرض / Width** | ⚠ **see §2.3** |
| `length` + unit | `watch_length` + type | **طول الساعة / Watch Length** | **الطول / Length** | ⚠ **see §2.3** |
| `interchangeable_dial` | same | هل القرص قابل للتبديل؟ | إمكانية تغيير القرص | ✓ |
| `interchangeable_strap` | same | هل الحزام قابل للتبديل؟ | إمكانية تغيير السوار | ✓ |
| `watch_box` | same | **هل يوجد صندوق للساعة؟ / Is there a watch box?** | **علبة أصلية / Original box** | ⚠ **see §2.4** |

**Nothing was lost in the transform.** Every legacy watch column has a home in the clean schema,
including all eight unit columns (`*_size_type_id` → `*_unit_id`). The block is complete with
respect to legacy; the problems below are all about *wording*, plus one about the data underneath.

---

## 2. The five that would make an operator enter the wrong thing

### 2.1 `band_material_id` — the label is right NOW, and the data behind it may not be

The legacy dashboard labelled this column **«الخامة» / "Material"** — with no part named. An
operator filling in a steel watch with a leather strap had one box called "Material" and no way to
know which it meant. Our label («مادة السوار» / "Band material") is the correction, and it is
correct — but it is applied to **315 rows that were filled under the old, ambiguous label**.

There is a second reason to suspect that column: the Brand Fashion importer writes a product's
material into `band_material_id` for every watch, by design —

> *"On a watch it is the BAND: a leather watch in this catalogue means a leather strap."*
> (`ProductImporter`, line 344)

which is a reasonable default for a leather watch and wrong for a steel one, where the source's
"Stainless Steel" usually describes the case.

**This is the one worth acting on.** The label is not the bug; the label change *revealed* the bug.

> **Corrected by the addendum at the end of this document.** The importer half of this suspicion is
> wrong: it has written **zero** rows, because the Brand Fashion export has no material field at all
> and none of its 4,261 watches sits on a material node. The risk is latent, not realised. The
> legacy-entry half stands, and the addendum sizes it: 291 rows, of which 202 record the same
> material for case and band.

### 2.2 «نوع العرض» collides with «العرض» — in the same block

We shortened «نوع عرض القرص» (Dial Display Type) to **«نوع العرض»**, and the same block contains
**«العرض»** for `width`. In Arabic العرض means both *width* and *display*, so the form now reads:

```
العرض            ← width, in mm
نوع العرض        ← analogue / digital
```

Two adjacent fields whose Arabic labels differ by one word and mean unrelated things. The legacy
label said «نوع عرض القرص» and had no such collision. This is the clearest of the five.

### 2.3 Height, width and length no longer say *of what*

Legacy: «ارتفاع الساعة» / "Watch Height", «عرض الساعة», «طول الساعة». Ours dropped the noun, so the
block now offers:

```
طول السوار   ← band length
الطول        ← length of the whole watch
عرض السوار   ← band width
العرض        ← width of the whole watch
```

Exactly the developer's case: *"a length that could be the band or the whole product."* An operator
who types the strap length into «الطول» is not being careless — the label does not distinguish.

**The data suggests this already happened, or that nobody dared:** `band_length` is filled on **4**
rows while `band_width` is filled on **299**; `height` on **2**, `width` on **8**, `length` on **3**.
Three whole-watch dimensions are effectively unused, and the one band dimension that shares a word
with a whole-watch dimension is the one that is empty.

### 2.4 «علبة أصلية» claims something the column does not record

Legacy asked **«هل يوجد صندوق للساعة؟»** — *is there a box?* We label the same column **«علبة
أصلية» / "Original box"**, which asserts the box is *genuine*. Those are different questions, and on
a catalogue that also sells grades, the second is a claim with commercial weight. 269 rows carry a
value entered against the first question.

### 2.5 «قياس العلبة» does not say *diameter*

Neither we nor legacy say which measurement this is. For a watch, case size conventionally means
the case **diameter** in mm, but the field will take a circumference, a case-plus-crown width or a
lug-to-lug from anybody who reads it differently. 337 rows are filled. Lower risk than the four
above, and a one-line hint would close it.

---

## 3. What a watch listing normally carries that this block does not

| attribute | in legacy? | where it is now | verdict |
|---|---|---|---|
| **Gender** | yes (`gender_product`) | ✅ `catalog_product_gender`, on the form's features tab | present, not a gap |
| **Warranty** | yes (`products.warranty_years`) | ✅ `catalog_products.warranty_years`, on the shared form — **265 watches filled** | present; arguably belongs *in* the watch block where the buyer's question is |
| **Country of origin** | yes, via `products.extra_attributes` conventions | ⚠️ `catalog_product_translations.country` exists and is **filled on 0 watches** | the field exists and nothing writes it |
| **Model name** | yes | ✅ `translations.model_name`, 385 watches | present |
| **Stone** | yes | ✅ `translations.stone`, 66 watches | present |
| **Complications** (chronograph, date, day-date, alarm, stopwatch, luminous hands, GPS, heart rate, sleep tracking, Bluetooth) | yes (`feature_product`) | ✅ 15 features, 1,126 links | present as features, not spec columns — correct place |
| **Calibre / movement reference** | **no** | nowhere | new |
| **Jewel count** | **no** | nowhere | new |
| **Power reserve** | **no** | nowhere | new |
| **Lug width** | **no** | — | new, and mostly redundant: on a watch, lug width *is* `band_width` |
| **Bezel material / type** | **no** | nowhere | new |
| **Case back** (solid / exhibition) | **no** | nowhere | new |
| **Crown type** (screw-down / push) | **no** | nowhere | new |
| **Battery life** | **no** | nowhere | new |
| **Dial colour** | n/a | ✅ colours with roles (`dial`, `band`) | present |
| **Strap colour** | n/a | ✅ same | present |

**Brand Fashion's own watch attributes add nothing:** the importer maps only *material* and *gender*
into watch specs (`ProductImporter`, lines 335–360). The BF source carries no calibre, jewels or
power reserve to import, so none of the "new" rows above is a gap the transform created — they were
never in this business's data.

**Nothing in this block was dropped by the transform.** Every missing attribute above is either
present elsewhere (gender, warranty, complications, colours) or was never recorded anywhere.

---

## 4. What I would do, in order

**Worth doing:**

1. **Fix the four labels** — §2.2 «نوع العرض» → «نوع عرض القرص»; §2.3 restore "of the watch" to the
   three whole-product dimensions; §2.4 «علبة أصلية» → the question legacy asked; §2.5 add a hint
   saying case size is the diameter. Free, and each one removes a way to enter the wrong number.
2. **Audit `band_material_id` (§2.1).** 315 rows entered under a label that did not name a part,
   plus an importer that assigns every watch's material to the band. Probably a report first —
   watches whose band material is `Stainless Steel` while the title says leather, and vice versa —
   before anything is rewritten.
3. **Move warranty into the watch block**, or show it beside these fields. It is filled on 265
   watches and it is the question a buyer asks in the same breath as water resistance.

**Worth deciding, not obviously worth building:**

4. **Country of origin** — the column exists on every translation and is empty on all 4,645
   watches. Either put it on the form and fill it, or stop carrying it.
5. **Calibre, jewels, power reserve, case back, bezel, crown** — these matter for mechanical
   watches and this catalogue is overwhelmingly quartz fashion watches. The block is only ~7% filled
   as it is (337 of 4,669 rows have a case size), so **adding fields is not the constraint** — the
   existing ones are not being filled. I would not add any of them until somebody is filling the
   fields that already exist.

**Not worth adding:** lug width (it is `band_width`), battery life (it belongs in the description),
and complications (already features).

---

## 5. Fill rates, for context

Of 4,669 rows in `catalog_product_watch_specs` (4,645 live watch products):

| field | filled | | field | filled |
|---|---|---|---|---|
| case material | 378 | | movement type | 378 |
| display type | 360 | | case size | 337 |
| glass material | 318 | | band material | 315 |
| band width | 299 | | case shape | 293 |
| closure | 292 | | water resistance | 290 |
| watch box | 269 | | case thickness | 237 |
| **width** | **8** | | **band length** | **4** |
| **length** | **3** | | **height** | **2** |

The four at the bottom are the four whose labels do not say what they measure (§2.3).

---

# Addendum — sizing the `band_material_id` finding (asked 2026-10-05)

**The headline reverses my earlier suspicion: the importer has written nothing.**

## How many imported watches carry an importer-supplied band material?

**Zero.**

| | |
|---|---|
| Live watch products | 4,645 |
| …imported from Brand Fashion (`import_ref` set) | **4,261** |
| …entered in the legacy dashboard | 384 |
| Imported watches with a `catalog_product_watch_specs` row | **4,261** (all of them) |
| Imported watches with **any** value in that row | **0** |
| Imported watches placed on a material node (Leather / Satin / Silicone / Rubber / Stainless Steel) | **0** |

Every material value in the watch block, by origin:

| field | entered by a person (legacy) | written by the importer |
|---|---|---|
| `case_material_id` | 378 | **0** |
| `band_material_id` | 315 | **0** |
| any watch spec at all | 383 | **0** |

The importer's rule — *"on a watch it is the BAND: a leather watch in this catalogue means a
leather strap"* — is real and is still in the code, but **it has never fired.** The Brand Fashion
watch rows carry no material, because the material is not a column in that export at all (see
below), and none of those 4,261 products sits on a material category.

So the risk is **latent, not realised**. Nothing needs correcting from the importer. What needs
doing is stopping it before the next import, which is free right now and will not be free once it
has written four thousand rows.

## Does the source file distinguish case material from band material?

**No — it has no material field at all.**

The Brand Fashion source is a WooCommerce export, and `WooExport::taxonomy()` derives the material
from the **`Categories`** column: a leaf such as `Leather` is simultaneously a section customers
shop and a material tag, and the importer records both. That gives **one material per product, with
no part attached** — there is nothing in the file that could say "case" or "band", and no second
value to put anywhere.

This matters for the recommendation: the importer is not mis-mapping two columns onto one. It is
inventing a part for a value that never had one.

## The 291 rows a person did enter

All 291 band materials on live watches were typed in the legacy dashboard, where the label read
**«الخامة» / "Material"** with no part named.

| | |
|---|---|
| Watches with a band material | 291 |
| …with a case material as well | 288 |
| …where **case and band are the same material** | **202** |
| Stainless Steel / Leather / Rubber / Silicone / Resin | 206 / 49 / 32 / 3 / 1 |

Cross-checking against the English title is the only independent signal available, and it barely
exists: only **32 of the 291** titles name a material at all (18 say stainless, 14 say leather). Of
those 32, exactly **two** contradict the recorded band material — one steel band on a watch whose
title says leather, one leather band on a watch whose title says bracelet.

**So the honest reading is:** there is no evidence of widespread corruption, and no way to prove
correctness either. 202 rows recording the same material for case and band are equally consistent
with an all-steel watch (common) and with somebody answering one ambiguous question twice.

## What correcting or marking them would take

**Automatic correction is not available.** There is no trustworthy signal: the source has no
material field, and the titles name one on 11% of the rows.

Three things are worth doing, in this order:

1. **Stop the importer inventing the part — free, and the only urgent one.** Change the rule so a
   material derived from a shop-by-material *category* does not land in `band_material_id` for a
   watch. Either leave the watch's material unset, or record it where it does not claim a part.
   Costs one condition and a line in the import report; prevents up to 4,261 wrong rows on the next
   run. **Zero rows exist today, so this can be changed with no migration and no backfill.**
2. **Mark the 204 doubtful rows for a human — one derived filter, no schema change.** A quick-filter
   flag on the products list (same mechanism as `missing_data` and `shared_sku`) selecting watches
   where a band material is set AND either case and band match (202) or the title contradicts it
   (2). Nothing stored, nothing to go stale, and the list is worked through like any other.
3. **Leave the other 87 alone.** They record a band material that differs from the case material,
   which is what somebody would only enter deliberately.

**What I would not do:** bulk-null the 202. They may well be correct, and blanking a field that was
filled by a person — to remove doubt that a filter can express instead — destroys information to
tidy a report.

## One loose end found on the way

**24 rows in `catalog_product_watch_specs` belong to products that are no longer `family = 'watch'`**
(315 band materials across the table against 291 on live watches). Their specs are unreachable from
the form, which shows the block for the current family only. Harmless, invisible, and worth knowing
about before somebody counts watch specs and gets a different number from the screen.
