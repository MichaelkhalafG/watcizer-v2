# Second browser pass — what I decided and why

**2026-09-19** · the eleven findings from the developer's own walkthrough · branch `wave-4d`, nothing committed

The brief said to make the judgement calls rather than stop, and to collect them here: what I would
have asked, what I chose, the alternative, and why mine is better. Reverse anything you disagree
with — every one of these is a small, local change.

---

## 1 · The storefront switch shows one shop's state, not both (item 1a)

**Would have asked:** two side-by-side columns, or one shop at a time with a switch?

**Chose:** a segmented switch, one shop visible at a time, with a state badge on *each* segment so
the other shop's answer is legible without switching, and a coloured rail down the active panel.

**Alternative:** both storefronts' visibility blocks stacked on the page at once.

**Why:** the two shops share every field except five, so a two-column layout would duplicate the
frame around a small difference and halve the width of the controls on a 1366px screen. The badge
on the inactive segment carries the one thing stacking was for — *is it live over there?* — at a
fraction of the space.

---

## 2 · The Electronics restore placed 72 products **visible** on Watchizer (item 1b)

**Would have asked:** visible, like they are on Brand Fashion today, or hidden until somebody
completes them?

**Chose:** visible, matching the shop that already sells them.

**Alternative:** place them hidden, since all 72 are missing both short descriptions, the Arabic
long description and a gender.

**Why:** the visibility gate is enforced on WRITE and was never applied retroactively, so these 72
are live on Brand Fashion today with exactly this data. Placing them hidden on one shop and visible
on the other, from identical rows, is an inconsistency with nothing behind it.

**The trap this leaves, and it is written into the migration and into
`docs/wave4d/joyroom-incomplete.tsv`:** the first time one of these 72 is saved through the product
form, the gate will demote it and it will disappear from **both** shops. Filling in the four fields
is the fix, and it is a data job.

---

## 3 · Deleting a category is now blocked before the write switch (item 1b, found by a test)

**Would have asked:** nothing — this is a hole, not a preference.

**Chose:** `PreSwitch::assertMayCreate('category')` guards `destroy()` as well as `store()`.

**Why:** creation was blocked and deletion was not, and nothing noticed because the block on
creation meant no deletable node could exist — every node was legacy-sourced, or held children, or
held products. The invariant was a property of the data, not a rule. The seven nodes this pass
created are none of those, and `StorefrontIsolationTest` found the hole the same hour. A gate that
permits destruction while forbidding restoration is worse than a gate in either direction: an
operator could delete this node and then be refused when they tried to put it back.

---

## 4 · `sku` survived the merge, and `model_number` was **not** cleared (item 4)

**Would have asked:** which column survives, and what happens to the 15 rows where the two disagree?

**Chose:** `sku` survives (6,799 values against 295). The 59 products whose code lived only in
`model_number` had it moved across. The **column stays and its remaining values are left alone**.

**Alternative:** null the column, or drop it.

**Why:** fifteen products carry a `model_number` that disagrees with their `sku`, and in fourteen of
them it is the truer manufacturer code. Clearing it destroys the only remaining copy of something
nobody has reconciled. They are listed in `docs/wave4d/sku-model-conflicts.tsv`; the column is no
longer written, read or shown, and the importer's matcher still consults it deliberately — those 15
values are exactly what an incoming supplier row may carry.

**One product could not be merged:** 413 and 414 both carry `Ap0012`, and `catalog_products.sku` has
a UNIQUE index. Which brings up a contradiction worth your decision, because I could not resolve it
from the brief: you defined the SKU as *"the manufacturer's code… two products can share one"*, and
the database forbids that. The merge is row-by-row and skips collisions rather than dying, so
nothing is lost — but if two products really may share a supplier code, that index has to go, and
dropping it is a schema change I did not make on my own.

---

## 5 · The audience word: Arabic from your data, English from a four-entry table (item 5)

**Would have asked:** is `ساعة نسائي` acceptable, or should the generator inflect?

**Chose:** Arabic uses the lookup row's own name untouched (`ساعة رجالي`, `ساعة نسائي`); English
carries a four-entry grammar table (men → men's, women → women's, kids → kids', unisex → unisex).

**Alternative:** an inflection table for Arabic too.

**Why:** using the stored name means the wording lives in **your** data. If `نسائية` reads better
than `نسائي`, rename the row on the lookups screen and every description generated afterwards says
so, with no deploy and no ticket. English cannot do that — `Women watch` is not English — so it gets
the smallest possible table, covering the four rows that exist, and anything added later degrades to
a plain modifier.

Arabic **colour** agreement is solved differently, by construction rather than by a table: colours
hang off `لون`, which never inflects, so the stored form is always correct — `مينا بلون أزرق`,
`سوار بلون فضي`. `مينا أزرق` would have been wrong.

---

## 6 · More than one audience ticked → the description says nothing about audience (item 5)

**Chose:** silence.

**Alternative:** use the first, or infer "unisex".

**Why:** a watch listed for both men and women is not a men's watch, and "unisex" is an inference —
you have a row for that and the team can tick it. A generator that guesses here is wrong on the
products where it matters most.

---

## 7 · The description falls back to the product's own title when nothing distinguishes it (item 5)

**Would have asked:** nothing — the first draft was wrong and a real row proved it.

**Chose:** describe from the attributes when there are any, from the name when there are none.

**Why:** on product 7010, a Joyroom power bank with no audience, material or colour recorded, the
attribute-first rule produced *"Joyroom device. Full specification and photos on the product page."*
while the title read `Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank with LCD display`.
Grammatical, and worse than the raw title. Full write-up and three before/after examples in
`docs/wave4d/SEO_REBUILD_2026-09-19.md`.

---

## 8 · Length is measured in width, and one segment is appended, never two (item 5)

**Chose:** an em budget (30 for a title, 72 for a description) instead of a character count, and at
most one `| …` segment on a title — the brand, or the category when the brand is already in the
name.

**Why:** search engines cut by pixels. `MMMM…` and `iiii…` are the same twenty characters and
nowhere near the same width, and the 60/160 rule is that width divided by an average English
lowercase letter — which is why it is wrong for Arabic and for capitals. As for the second segment:
`محفظة Calvin Klein | كالفن كلاين | محافظ` was the real output, and the third segment is where a
title stops being a name and starts being a breadcrumb.

---

## 9 · «فارغ — مخفي تلقائيًا» became a sentence that ends in the remedy (items 6 and 7)

**Would have asked:** keep the badge and add a tooltip, or replace it?

**Chose:** replace the badge with one sentence that names the cause and the fix, e.g. *"مخفي من
القائمة لأنه لا يحتوي منتجات — أضف منتجًا أو أزل العلامة."*

**Alternative:** a badge plus a hover explanation.

**Why:** the complaint was that an enabled toggle sat beside a chip saying the thing was hidden, and
a tooltip does not resolve a contradiction — it hides the resolution behind a hover that nobody on a
touch screen will find. A sentence that ends in what to do next resolves it in place.

---

## 10 · The out-of-stock banner lost four fifths of its words (item 8)

**Chose:** one line plus an inline link, on both inventory banners, and the same treatment swept
through the screens that had picked up the same voice.

**Alternative:** keep the explanation and collapse it behind "read more".

**Why:** a banner is read once, at a glance, by somebody who already knows what the screen is for.
An essay in that position is skipped, and a "read more" is an essay plus a click.

---

## 11 · Tables scroll with a visible affordance rather than being made narrower (item 9)

**Would have asked:** hide columns below a breakpoint, or make the table scroll?

**Chose:** both, in that order — a `hideBelow` prop on the genuinely secondary columns, and a
scroll region on the `Table` primitive itself, with gradient edges that appear only when there is
something past the edge, a keyboard-focusable region, and a stable scrollbar gutter.

**Alternative:** shrink type and padding until everything fits at 1024px.

**Why:** ten dashboard tables share one primitive, so the affordance was one change, not ten. And
shrinking to fit 1024 punishes the 1920 screens the team actually works on. Nothing was removed —
a hidden column is hidden at a width where it was already unreadable, and returns above it.

---

## 12 · The shipping table's row was rebuilt rather than patched (item 10)

**Chose:** the linked-address count became a plain number with its explanation beneath it, actions
moved into their own fixed-width end column, `dir` attributes on block elements were replaced with
the `<Ltr>` component, and the delete refusal was shortened to one word in the cell.

**Why:** the malformation had three causes at once — a badge wide enough to push the row, `align`
props the table primitive does not honour, and a `dir` on a `<td>` that `RtlTableGuardTest` forbids
for good reason. Patching one leaves a row that breaks again at the next width. **The guard test was
extended** to cover `TableCell`, `TableHead`, `TableRow`, `CardContent`, `CardHeader` and
`DialogContent`, so this specific mistake cannot come back through a component.

---

## 13 · The sidebar got design tokens, and dark `--destructive` was brightened (item 11)

**Would have asked:** just fix the sidebar, or sweep light mode?

**Chose:** five new `--sidebar-*` tokens registered in Tailwind, every zinc literal in the sidebar
replaced, and — separately — the dark `--destructive` changed from `0 62.8% 30.6%` to the light
value.

**Why:** the sidebar was black in light mode because it was written in literal zinc rather than in
tokens; tokens make it follow the theme by construction. The destructive change is the larger one
and worth a second look from you: 37 places use `text-destructive`, and at 30.6% lightness on a dark
background it was a dark red on near-black — legible as a colour, not as text. One token change
fixed all 37.

---

## 14 · The save bar moved to the **top** and says what Save saves (item 3)

**Chose:** sticky at the top rather than the bottom, with a line stating that Save saves the whole
product, not the open tab.

**Alternative:** leave it at the bottom and add the line.

**Why:** with the form now in tabs, a bottom bar sits below whichever tab happens to be longest and
the operator's eye has no fixed home for it. At the top it is in the same place on every tab, beside
the error count — which is the thing that tells somebody on the Images tab that the Price tab is
refusing.

---

## 15 · The variants panel is a tab, rendered **outside** the `<form>` (item 2)

**Chose:** the eighth tab, rendered after `</form>`.

**Why:** it looks like a tab and behaves like one, but it must not be inside the form element:
the panel posts each row on its own, because a quantity is a ledger event and must never ride on a
title validation. Inside the product form, pressing Enter in a quantity box would submit the
**product** — a surprise that writes.

---

## 16 · A mistake of mine, and what it costs you at review time

I ran `prettier --write` on `resources/js/lib/seo.ts` and
`resources/js/pages/Manage/Products/Form.tsx`. **This repo is not prettier-formatted** — I checked
afterwards, and prettier rewrites about 1,300 lines of the committed `Form.tsx` on its own. The
result is that `git diff` on that file shows ~1,575 changed lines where roughly 525 are mine.

I tried to undo it with a three-way merge against a prettier-formatted copy of `HEAD`; it produced a
file that did not compile (it duplicated part of the SEO card), so I reverted to the working
version rather than risk losing work to a merge I could not fully verify.

**To review just the real change:**

```
git diff -w core/resources/js/pages/Manage/Products/Form.tsx     # 525 insertions, 270 deletions
```

`git diff -w` removes the whitespace-only noise. `seo.ts` is a full rewrite either way, so its
formatting does not cost you anything.

If you would rather the file came back in its original formatting, the clean way is to reset it and
re-apply my changes deliberately — say the word and I will do that rather than leave you with a
noisy diff.

---

## Verification

| | |
|---|---|
| Pest | full battery green — 1,359 passed, 38 skipped, 32,011 assertions |
| PHPStan | level 10, no errors |
| Pint | passed |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| Catalogue digest, opening | `d5b63f1a…` |
| Catalogue digest, closing | `b7008874f4eff35a7ea3a8723e263a87d0c719d6` |

The two digests differ, and they are **supposed** to: this pass ran two migrations you approved in
the brief — the `model_number` → `sku` merge (item 4) and the Electronics/Joyroom restoration
(item 1b). Those touch `catalog_products`, `catalog_brands`, `catalog_brand_translations`,
`storefront_categories`, `storefront_category_translations`, `storefront_product` and
`storefront_category_product`. Nothing else in this pass wrote to the database; `inventory:verify`
ran 15,670 checks after the restoration and all agreed.

The per-table digest as it stands now is saved outside the repo; the opening baseline for this pass
was recorded as a combined hash only, so a per-table comparison against it is not available — the
next pass should open by saving the per-table output, not just the combined one.

---

# Third pass — 2026-10-05

Four more findings from the developer's own walkthrough, plus the sticky-bar report that arrived
while they were being fixed.

## 17 · The explanatory sentence was right and its PLACE was wrong

**The root cause, and it was mine.** Items 6 and 7 of the second pass replaced a contradictory badge
with a whole explanatory sentence ending in the remedy. That was the right fix for the
contradiction. Printing the sentence on **every row** is what broke three screens: the category
tree repeated the same paragraph on sixty nodes until the names truncated to `ساعات سـ…`, and the
brands table wrapped Arabic to roughly one word per line inside a narrow column, making rows ~300px
tall — three brands to a screen.

**The rule now applied everywhere:** a row carries a short state **chip** and nothing more; the
sentence appears **once**, where the operator is dealing with that row.

| screen | on the row | the sentence |
|---|---|---|
| Categories | `مخفي — لا منتج ظاهر` (server-side `in_menu_reason_short`) | the chip's tooltip, and an alert inside the node's own edit dialog |
| Brands / lookups | `غير مستخدمة` | once above the table, and only when a row on the page is unused |

**Swept the rest.** A script walked every `.tsx` for a translated string of 55+ characters rendered
inside a `.map(` or a `cell:` — 18 candidates, and every one of the other 16 was already a
`title=` tooltip or a confirmation dialog's consequence text. Categories and brands were the only
two screens with the defect.

**Also on the category row:** the name no longer gives up width (`shrink-0`, with the English name
and the slug shortening first and the slug dropping below 2xl), and the destructive `مفعّل` toggle
moved into the overflow menu with its confirmation intact. One inline toggle, two chevrons, one
menu — down from five controls per row on sixty rows.

## 18 · The products list fits 1366px, and the name is the row

**Measured on the live catalogue first**, because the answer depends on it: of 7,713 Arabic titles,
82% are ≤45 characters, 15% are 46–70, 2% are 71–110 and 0.7% are longer — the longest 208.

**Six columns earn the row at 1366:** the picture, the name, the price, the stock, where it is live,
and the actions. Four dropped to `2xl` (1536) — the internal code and the model number, because
codes are *searched* not scanned; the brand, because it is inside the title on essentially every
row; the family, because it is a filter nobody scans by. `DataTable` gained a `2xl` breakpoint for
this: `xl` is 1280, and a column hidden at `xl` is still on the row at 1366, which is the width that
was reported.

**`مفعّل` lost its column outright.** It said `نعم` on 7,600 of 7,713 rows. The exception is now a
chip on the name. **`غير مضاف لهذا المتجر` lost its chip** — the visibility column already says so,
and the duplicate was what wrapped onto a second line and set the height of every row.

**The name:** no max width, at most two lines, `break-words`. One line holds the 82%, two lines hold
97%, and a row only grows for the title that needs it.

**Measured after, in the browser:** table overflow **0px**, every row **65px**, 3 of 25 names
clipped at two lines (all of them 110-character English imports). Before: the name cut mid-word at
`حقيبة كروس Tory Burch نسائي جل…`.

The first attempt at this was wrong and the browser caught it: giving the name `min-w-0` inside a
flex row let the chips take the width and the name collapsed to **one character per line**. The name
now carries the minimum and the chips wrap.

## 19 · The three unlabelled numbers, and what they were

**They were `price_delta`, `stock_express` and `stock_market`** — the price difference, the Express
quantity and the Market quantity. The developer was right that one of them is money.

**Why they had no labels:** every control in that form was labelled with a **placeholder**. A
placeholder is the text a box shows *while it is empty*, and those three start at `0`, so their
placeholders had never once been visible to anybody. The four above them start empty, which is the
only reason they looked labelled.

All seven controls now carry a real `<label>` through the same `Field` wrapper the product form
uses, plus a line saying what the number means — including `فرق السعر`: *"يُضاف إلى سعر المنتج لهذا
الصف وحده — واكتب رقمًا سالبًا ليُخصم. اتركه صفرًا ليُباع بسعر المنتج نفسه."* (verified against
`ProductDetail::variants()`, which prices a row as `product price + delta`).

**Swept for the same defect.** One more instance: the gallery's per-image alt-text boxes, labelled
only by placeholder, so every image that already had alt text showed two unlabelled boxes. Fixed.
Everything else that looked like a candidate was inside a `TextField`/`SelectField` and already
labelled.

## 20 · The sticky save bar sat on top of the app header

Two causes, both needed fixing:

* The bar was `top-0 z-30` and the dashboard header is **also** `top-0 z-30`. Same offset, same
  layer, and the one later in the document wins — so the bar painted over the avatar, the
  breadcrumb and the product's own name. It is now `top-header` (the same `--header-height` token
  the header's own height is built from) and `z-20`, strictly below the header.
* It also **stopped sticking** on the variants tab, and that is a separate bug the first fix would
  have hidden: a `position: sticky` box only sticks inside its own parent, the bar was the first
  child of `<form>`, and the variants panel is deliberately rendered after `</form>`. The bar has
  moved outside the form and submits it through HTML's own `form="product-form"` association —
  same validation, Enter still saves.

Measured in the browser at scroll positions 0 / 400 / 900 / 1600 on the variants tab: bar top **64**,
header bottom **64**, overlap **0** at every one.

## 21 · One more, found on the way

The brands table was 211px per row and 335px wider than a 1366px window, because `ImageField` — the
product form's 80px upload control with its dashed frame, two labelled buttons and the stored file
path — was being rendered once per brand inside a table cell. It now has a `compact` mode (40px
preview, icon buttons, no frame, no path line) and `Field` a `labelHidden` mode for a control whose
column header is already its label. Result: **65px rows, 0px overflow.**

## Verification, third pass

| | |
|---|---|
| Pest | 1,367 passed, 38 skipped, 0 failed |
| PHPStan | level 10, no errors |
| Pint | passed |
| `tsc --noEmit` | clean |
| `npm run build` | built |
| Browser | products / categories / brands / product form / variants panel, screenshots taken |

**One limitation, stated plainly:** the Chrome window could not be resized — it is maximised on a
1920 display at 110% OS scaling, so the real viewport is 1740 CSS px and `resize_window`,
`window.resizeTo` and the CDP resize all left it there. The 1366 case was therefore verified by
clamping the content column to what a 1366px viewport leaves it and forcing the `2xl` columns into
their hidden state, then measuring overflow, row heights and clipped names against the live data.
The wide case (all four columns back, rows still one line) was verified on the real viewport, which
is what a 1920 screen shows.
