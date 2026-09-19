# The SEO generator, rebuilt — item 5, second browser pass

**2026-09-19** · `resources/js/lib/seo.ts` · three real products, before and after

The brief: *"It fills the fields but produces nothing that would help these sites rank. Rebuild it
properly: meta titles within the length search engines actually display, descriptions that read as
a sentence a person would click rather than a list of attributes, keywords drawn from what the
product IS (brand, family, material, colour, gender, category) and not just its title, and both
languages generated independently rather than one translated from the other. Look at what the
legacy dashboard's generator did before you start."*

---

## 1. What the legacy generator did — read first, as asked

`backend/resources/views/Dashboard/product/create.blade.php`, `window.autoSEO`. It is a narrower
baseline than the team remembers, and that is worth stating plainly before anything else:

| | legacy `autoSEO` |
|---|---|
| Languages | **English only.** `titleEn` was its only input. |
| Meta description | **None. It generated no description at all** — that field was a plain `<textarea maxlength="160">`, filled by hand or left empty. |
| Meta title | `titleEn + ' — ' + brand + ' | ' + category`, then `substring(0, 57) + '...'`. |
| Keywords | `[titleEn, brand, category]`, lowercased, comma-joined — the whole title as one keyword. |
| Where | The **create** screen only. The edit screen had the three fields and no button. |

So "restore what the legacy one did" was not available as an instruction: half of what the brief
asks for never existed there. The one idea worth keeping is the idea itself — one button, every
field still editable afterwards — and that was already kept.

### What that generator actually left in the database

Measured on the live catalogue today (read-only queries):

| | |
|---|---|
| Products | **7,713** |
| With any meta title | **625** (8%) — all of them English |
| Arabic meta title or description, any product | **0** |
| Of those 625 titles, ending in `\| Select…` | **317** (51%) |
| Meta descriptions at or past 155 characters | **120** |

`Select…` is the **category dropdown's own placeholder**. On half the products that have a meta
title, the legacy generator read an un-chosen `<select>` and wrote its placeholder into the title
that Google indexes:

```
Hugo Boss Watch For Men 1513755 | Select…
Hugo Boss Watch For Men 1513814 | Select…
Emporio Armani Watch For Men AR1648 | Select…
```

This is not a finding about the rebuild; it is 317 rows of live SEO copy that need replacing, and
the new generator is what replaces them. It is **not** fixed by this pass — nothing here writes to
the database — and it is the one follow-up worth scheduling: open each of those products and press
the button, or accept a one-off backfill as a separate, reviewed change.

---

## 2. Three real products, before and after

"Before" is **the previous version of this generator**, run against the same live rows, so the
comparison is one product through two algorithms rather than two different products. Where a stored
value exists it is quoted too.

---

### Product 47 — `AR1648`, Emporio Armani chronograph (family: watch)

Everything recorded: brand, audience, case material, glass material, band material, dial colour,
band colour, primary category.

**Meta title (ar)**

```
before   ساعة إمبوريو أرماني للرجال AR1648
after    ساعة إمبوريو أرماني للرجال AR1648 | كرونوغراف
```

**Meta title (en)**

```
stored   Emporio Armani Watch For Men AR1648 | Select…      ← the legacy placeholder bug
before   Emporio Armani Watch For Men AR1648
after    Emporio Armani Watch For Men AR1648 | Chronograph   (23.9em of 30 — fits)
```

**Meta description (ar)**

```
before   ساعة إمبوريو أرماني للرجال AR1648، من إمبوريو أرماني، ضمن كرونوغراف، موديل AR1648
after    ساعة رجالي من إمبوريو أرماني، علبة ستانلس ستيل ومينا بلون أزرق. موديل AR1648. المواصفات الكاملة والصور داخل صفحة المنتج.
```

The "before" is the product's name, then its brand again, then its category, then its model — four
facts and three commas, and the brand appears twice. The "after" says what the object is and what
the page holds.

**Meta description (en)**

```
stored   Original Emporio Armani Watch For Men Gianni AR1648\r\nwith blue dial\r\nstainless steel
         belt with silver color\r\nwar…                    ← hand-written, carriage returns and all,
                                                             cut mid-word at 110 characters
before   Emporio Armani Watch For Men AR1648, by Emporio Armani, in Chronograph, model AR1648
after    Emporio Armani men's watch with a stainless steel case and a blue dial. Model AR1648.
         Full specification and photos on the product page.          (59.9em of 72 — fits)
```

**Keywords**

```
before   AR1648، إمبوريو أرماني، Emporio Armani، كرونوغراف، Chronograph، ساعات، Watches،
         ساعة إمبوريو أرماني للرجال AR1648، Emporio Armani Watch For Men AR1648

after    ساعة رجالي إمبوريو أرماني، Emporio Armani men's watch، ساعات إمبوريو أرماني،
         Emporio Armani watches، ساعة رجالي، men's watch، إمبوريو أرماني، Emporio Armani،
         كرونوغراف، Chronograph، ساعة ستانلس ستيل، stainless steel watch، ساعة بلون أزرق،
         blue watch، AR1648، Emporio Armani AR1648
```

The last two entries of "before" are the product's own 35-character titles. Nobody types those. The
"after" is what people type: *audience + noun*, *brand + noun*, *material + noun*, *colour + noun*,
in both languages.

---

### Product 1118 — `BF-10861`, Calvin Klein wallet (family: wallet)

A different family, to show the grammar is not watch-shaped: one material in the `main` role from
the JSON `specs`, no dial, no strap.

```
title  ar   before  محفظة Calvin Klein | كالفن كلاين
             after  محفظة Calvin Klein | كالفن كلاين            (unchanged — already right)

title  en   before  Calvin Klein wallet
             after  Calvin Klein wallet | Wallets

descr  ar   before  محفظة Calvin Klein، من كالفن كلاين، ضمن محافظ، موديل ck2
             after  محفظة رجالي من كالفن كلاين، خامة جلد. موديل ck2. المواصفات الكاملة والصور داخل صفحة المنتج.

descr  en   before  Calvin Klein wallet, by Calvin Klein, in Wallets, model ck2
             after  Calvin Klein men's wallet with a leather body. Model ck2. Full specification
                    and photos on the product page.
```

```
keywords after  محفظة رجالي كالفن كلاين، Calvin Klein men's wallet، محافظ كالفن كلاين،
                Calvin Klein wallets، محفظة رجالي، men's wallet، كالفن كلاين، Calvin Klein،
                محافظ، Wallets، محفظة جلد، leather wallet، ck2، Calvin Klein ck2
```

---

### Product 7010 — `BF-58437`, Joyroom power bank (family: electronics)

The awkward case, chosen on purpose: one of the 72 Joyroom products restored in item 1(b), with no
audience, no material and no colour recorded. This is where a template shows what it is worth.

```
title  en   before  Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank
             after  Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank   (27.9em of 30 — fits)

descr  en   before  Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank with LCD display,
                    by Joyroom, in Power Banks
             after  Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank with LCD display.
                    Full specification and photos on the product page.

descr  ar   after  باور بانك شحن سريع JR-QP192. المواصفات الكاملة والصور داخل صفحة المنتج.
```

**The first draft of the rebuild got this wrong, and the row is why it is right now.** Composing
from the product's attributes produced *"Joyroom device. Full specification and photos on the
product page."* — grammatical, and worse than the raw title, because the only two facts recorded
were the brand and the family. The rule is now: describe the product from its **attributes** when
it has any, and from its **name** when it has none. The name is always the most specific true thing
on the screen.

---

## 3. What changed in the generator, and why

1. **Length is measured in width, not characters.** A search engine cuts by pixels.
   `MMMMMMMMMMMMMMMMMMMM` and `iiiiiiiiiiiiiiiiiiii` are the same twenty characters and nowhere near
   the same width, and the familiar 60/160 is that width divided by an average English lowercase
   letter. `estimateEm()` approximates the rendered width and the budgets are in ems — 30em for a
   title, 72em for a description. For ordinary English lowercase they land within a character or
   two of 60 and 160, so nothing familiar moved; they simply now hold for Arabic and for capitals.

2. **Nothing is cut mid-phrase.** The title is *composed* to fit: one optional segment is appended
   only if it fits whole, and only a name with no seams left is clipped. A clip also drops a
   trailing joining word — a real row cut to `…fast charging powerbank with`, which reads as a
   sentence somebody abandoned.

3. **One appended segment, never two.** The brand first, the category only when the brand is
   already in the name. `محفظة Calvin Klein | كالفن كلاين | محافظ` was the three-segment version,
   and the third segment is where a title stops being a name and starts being a breadcrumb.

4. **The description is two sentences.** What the thing is, and what the page holds. The second is
   the honest form of a call to action available to a generator forbidden from mentioning price,
   stock or how good the product is — a rule kept from the previous version and worth keeping: a
   meta description is served for months.

5. **Keywords are combinations, not fields.** Audience + noun, brand + noun, brand + plural,
   material + noun, colour + noun, category, model, brand + model. Interleaved Arabic and English,
   deduplicated, capped at sixteen so a truncation downstream loses the vaguest term.

6. **The two languages are written independently.** Arabic leads with the noun and hangs the brand
   off `من`; English leads with the brand and puts the noun last. Each picks its own attribute
   phrasing — `علبة ستانلس ستيل` versus `a stainless steel case` — and neither is a translation of
   the other. They are generated from the same *facts*, which is a different thing.

### Two grammar decisions worth knowing about

**Arabic colour agreement, solved by construction.** An Arabic colour adjective agrees with its
noun and the lookup stores one form, so `مينا أزرق` is wrong (a dial is feminine: `زرقاء`) and
nothing in the code can know that. Hung off `لون`, which is masculine and never changes, the stored
form is always correct: `مينا بلون أزرق`, `سوار بلون فضي`, `ساعة بلون أزرق`. No table of
exceptions, and it stays right for any colour the team adds.

**The audience word.** Arabic uses the lookup's own name untouched — `ساعة` + `رجالي` — which means
the wording lives in the team's data: rename the row on the lookups screen to `نسائية` and every
description generated afterwards says so, with no deploy. English cannot do that, because English
needs a possessive and `Women watch` is not English, so `lib/seo.ts` carries a four-entry grammar
table (men → men's, women → women's, kids → kids', unisex → unisex) covering the four rows that
exist in `catalog_genders`. A row added later that is not in it is used as a plain modifier.

**When more than one audience is ticked, the generator says nothing about audience.** A watch
listed for both men and women is not a men's watch, and "unisex" would be an inference — the team
has a row for that and can tick it.

---

## 4. What else moved

- `ProductController` now sends `lookup_names` — bilingual names for **genders, colours and
  materials**, the three lookups that appear in a sentence somebody would click. Brands and
  category nodes already travelled this way.
- The form reads its materials out of the **block definition** (`config/catalog.php`), not from a
  list of spec keys written into the component: a family that gains a material field reaches the
  generator with no code change. `SeoGeneratorTest` asserts exactly that.
- `FAMILY_WORDS` moved from `Products/Form.tsx` into `lib/seo.ts` and gained a **singular** for each
  family — a sentence says "a watch", a keyword says "watches". The test that kept the old map in
  step with `Product::FAMILIES` moved with it and now also refuses a family with no singular noun.
- The SEO card shows a line when **what is in the boxes** no longer fits a search result. The
  generator's own output always fits; what an operator types afterwards may not, and nothing on the
  screen used to say so.
- `SeoGeneratorTest` asserted `model_number` on the edit payload — the column merged away in item 4.
  It now asserts `sku`. That assertion had been failing since the merge.

## 5. Not done, deliberately

- **No backfill.** 7,088 products have no meta title and 317 have a broken one. Writing them is a
  data change across the whole catalogue, and the brief asked for the generator, not for the pass.
  It should be its own reviewed change with a digest either side of it.
- **No JavaScript test runner.** The generator's sentences are still not asserted by CI, because
  this tree has no JS runner and adding one is a bigger decision than this item. The examples above
  were produced by running the real module under `node --experimental-strip-types`; what CI holds is
  the payload it is fed, which is the half that fails silently.
