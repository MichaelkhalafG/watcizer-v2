# The browser walkthrough's findings, fixed — 2026-09-19

Every item of `BROWSER_WALKTHROUGH_2026-09-18.md` that was handed over for repair: **19 straight
fixes (Part 1) and 19 decided changes (Part 2)**, 38 in all.

**State at the end of the pass**

| Check | Result |
|---|---|
| Pest | **1,357 passed, 38 skipped, 0 failed** (31,211 assertions) |
| PHPStan (Larastan, level 10) | clean |
| Pint | clean |
| `tsc --noEmit` | clean |
| `npm run build` | clean, 7.3 s |
| `core:checksum --set=all` | `84cb4861882e32ac9254971f935cd2f448bc9b88` — **identical** to the walkthrough's §0.1 baseline |

Nothing was committed. No database row was changed: the digest above is the same string §0.1
recorded before the walkthrough began.

New regression file: `tests/Feature/Manage/WalkthroughFixesTest.php` — 30 tests, each naming the
finding it holds. It is one file on purpose: these defects have nothing in common by screen or by
layer, and everything in common by how they were found. **Every one of them survived a green test
suite.** That file is the list of things the automated suite could not see until somebody looked.

---

## What I decided and why

The brief said to make the calls and collect them. These are the ones where I chose something other
than the literal instruction, or where the instruction left a real fork.

### 1. J-6 — family-scoped colour roles shipped; "REQUIRED" did not

*Would have asked:* J-6 says watches get `لون القرص` and `لون السوار` as **required**, and every
other family gets `اللون الأساسي` **required**. Do you mean marked on the form, or refused by the
server?

*Chose:* the family scoping in full, driven from `config/catalog.php`. **Not** the requirement.

*Alternative:* make them required, as written.

*Why:* measured against the live catalogue before deciding, as every field rule on this project is —

```
main colour missing:  7,713 of 7,713   (no product has ever had one)
watch dial missing:   4,264 of 4,645
watch band missing:   4,263 of 4,645
```

A required colour refuses a save on essentially every product in the shop. That is the exact
failure the 2026-09-18 field-rules decision was written against — *"a rule that refuses the save
punishes whoever is fixing something rather than whoever left it incomplete"* — and legacy has these
`nullable` too. The half that closes the trap is the scoping: a handbag is no longer asked for a
strap colour, so it can no longer write into the column the storefront renders as a watch band. The
requirement would have added nothing to that and locked the catalogue.

### 2. §2.1 — only the ACTIVE tab is mounted

*Would have asked:* nothing; this is an implementation choice.

*Chose:* render one panel at a time; unmount the rest.

*Alternative:* keep every panel in the DOM and hide the inactive ones, which is the usual way.

*Why:* it breaks the fix from the same brief. A hidden `<input required>` cannot be focused, so the
browser refuses the submit with *"An invalid form control is not focusable"* — silently, in the
console — and the operator presses Save and watches nothing happen. That is D-20 again from a new
direction. Form state lives in `useForm`, not the DOM, so unmounting loses nothing. The cost is that
the browser cannot guard a field on another tab, which is why **every tab carries the count of what
the server refused in it** and a failed save switches to the first tab holding one.

### 3. W-2 — `set_threshold` is one statement; the other bulk actions stay row-by-row

*Would have asked:* is it acceptable for one bulk action to bypass `ProductWriter`?

*Chose:* yes, for this one. `UPDATE … WHERE id IN (…)` plus **one** audit row.

*Alternative:* run all 7,578 products through the writer like `activate` does.

*Why:* the four original actions each have a per-product consequence the writer has to compute — a
visibility gate, a slug, a search row, an audit entry. A reorder threshold has none: one unsigned
integer, no translation, nothing derived from it, no effect on what the storefront shows. The full
writer would be ~220,000 queries and several minutes holding row locks to achieve what one statement
achieves, and **7,578 is the number W-2 exists to fix**. One audit row for the batch follows the call
`CategoryController::reorder()` already makes for the same reason: "who set thresholds to 0?" is a
question about the batch, and 7,578 identical rows would bury the answer rather than record it.

The per-product actions keep their 500 cap and now **refuse** an over-large matching set with the
real count and an instruction, rather than attempting it.

### 4. W-2 — the 7,578 existing rows were NOT rewritten

*Would have asked:* should the new default of 0 be back-filled?

*Chose:* no. The default applies to new products; the bulk action is how the existing ones change.

*Alternative:* a migration setting every untouched `5` to `0`.

*Why:* which products deserve a reorder point is the team's decision, and 7,578 rows is exactly the
size of decision that should not be made by a migration on their behalf. The tool to do it in one
pass now exists, which is what makes leaving it alone reasonable rather than lazy.

### 5. W-1 — bulk category assign is ADDITIVE, and clearing the last one is refused

*Would have asked:* should `set_category` replace the product's categories or add to them?

*Chose:* add. And `clear_category` refuses to take the last category off a **visible** product.

*Alternative:* replace, which is what "set" usually means.

*Why:* twenty selected products have twenty different existing sets and none of them is on screen. A
"set" that replaced would silently strip categories the operator never saw — the same class of
silent loss as the full-replace hazard recorded on 2026-09-18. Correcting a wrong category is
therefore two deliberate passes, which is still two actions instead of twenty seven-screen form
edits. The refusal exists because taking the last category off a visible product leaves it on sale
and reachable from no listing — the `بلا تصنيف` state, created in bulk, silently. Skipped products
are named, which is the posture this screen already takes.

### 6. W-3 — "select all matching" is a SCOPE, not a list of ids

*Would have asked:* nothing.

*Chose:* post the screen's query string; re-resolve it server-side through the same declaration the
list renders from (`ProductController::listTable()`, extracted for this).

*Alternative:* send the ids.

*Why:* 7,578 ids do not fit a request, would blow every id cap, and would be stale by the time they
arrived. The safety argument is the shared declaration: an invented filter key is dropped by the
whitelist, an out-of-range value is dropped the same way, an unknown sort falls back — so a
hand-written request cannot reach a product the screen would not have listed. Two copies of those
lists would be two things to keep in step, and the day they drifted the bulk action would act on a
different set from the one selected. There is a test for exactly that.

### 7. D-13 — `branding.suffix` was deleted, not patched

*Would have asked:* do you want the sidebar's product name to stay per-deployment?

*Chose:* remove the config key entirely; the word is `shell.dashboard` through the seam.

*Alternative:* keep the key and add a translated fallback.

*Why:* the finding was larger than reported. The key carried the exemption *"an ENV DEFAULT for the
browser-title suffix"* and the browser title **never used it** — the title comes from
`config('app.name')` in the Blade. It was visible copy in three places wearing a marker that said it
was not, and that marker is what stopped anybody noticing for as long as they did. A deployment that
wants to rename the product sets `BRANDING_NAME`, which the sidebar already shows.

### 8. D-17 — a refusal's OWN sentence wins over the new error page

*Would have asked:* nothing — I caught this with a test.

*Chose:* `ManageError` prefers the exception's message; the generic body is a fallback.

*Alternative:* one sentence per status, which is what the item asked for.

*Why:* the first version broke `ExportPermissionTest`, and the test was right. Several refusals in
this application already say exactly why — `abort(403, 'تنزيل ملف التصدير يحتاج صلاحية مدير.')` is
the clearest — and a page that replaced every 403 with one general sentence would be a step
backwards: a reason the operator can act on, swapped for one they cannot. The framework's own
defaults (`This action is unauthorized.`, `Not Found`) are excluded, because those are the strings
the page exists to replace. 500 is left alone entirely: hiding a stack trace from a developer is the
worse trade.

### 9. D-21 — the login label was never missing; the ID was unaddressable

*Would have asked:* nothing.

*Chose:* `f-${useId().replace(/:/g, '')}` in `Field`.

*Alternative:* add an explicit `id`/`htmlFor` pair to the login form.

*Why:* the markup already carried `htmlFor` and a matching `id`. React's `useId()` returns `:r1:` —
legal as an HTML id, and an invalid CSS selector, so anything resolving a label by querying rather
than by `getElementById` finds nothing. That is exactly what "accessible name is the placeholder"
looks like from outside. Fixing the id fixes it for **every** field on every form rather than for one
box, and it is what makes `ErrorSummary`'s jump-to-field work at all.

### 10. D-16 — the reported resolution is the PICTURE's, not the file's

*Would have asked:* item 32 called this "a design decision". Should the number describe the file on
disk or the photograph?

*Chose:* the photograph. An 800×800 upload reports 800×800; the file is still a 1200 square.

*Alternative:* keep reporting the canvas and only fix the rendition guard.

*Why:* the number is on screen so the operator can judge whether the picture is good enough. The
canvas size is an implementation detail of the preset and tells them nothing actionable — and it was
actively misleading, because 44% of that master is white. The same number is now the right one for
the guard, so `960w: source is only 800px wide` can finally fire, where before it was unreachable
for every product image. `MediaTest` changed with it: a 1400×900 source reports 1200×771, which is
the picture, where it used to assert 1200×1200, which is the padding.

### 11. D-23 — both figures, rather than a looser query

*Would have asked:* item 34 offered "qualify it or show both".

*Chose:* both — `إجمالي ما طلبه` (everything not cancelled) and `إجمالي ما استلمه` (delivered and
completed).

*Alternative:* qualify the existing label and leave one number.

*Why:* loosening `spent()` to make the screen look right would make the number that means "money
actually taken" stop meaning that, and that is the number somebody quotes at a customer before
refunding them. The docblock defending it is correct. What was wrong was showing one of two numbers
and giving it the name of the other. The gap between them is itself worth seeing: it is the shop's
open exposure.

### 12. D-22 — the totals block says what it could NOT account for

*Would have asked:* nothing.

*Chose:* `الأصناف · الشحن · فرق غير مفسَّر · الإجمالي`, with the last line rendered only when it is
non-zero.

*Alternative:* label the difference "shipping" and stop.

*Why:* `orders` carries one money column — no subtotal, no shipping, no discount, measured on the
live schema. So the block cannot report a stored breakdown; what it can do honestly is add the lines
up, look up what delivery costs today, and show whatever is left over as exactly that. Calling the
remainder "shipping" would be right today and wrong the first day a discount or a price change is
the real cause. The delivery price is today's because nothing stored the one charged, and the screen
says so rather than presenting a current number as a historical fact.

### 13. J-9 — the legacy tree is marked, not removed

*Would have asked:* nothing.

*Chose:* `selectable: false`, and the picker **re-enables** any node the product is already in.

*Alternative:* drop those nodes from the payload.

*Why:* removing them would hide an existing placement from the only screen that could take it off.
Measured first: 7 nodes per shop, **0 products** — so this costs nothing today and is purely a guard
against tomorrow. The reason renders as visible text beside the control rather than in a `title`,
which is J-8's rule applied before J-8's own turn came up.

### 14. D-18 — old activity-log rows keep their English strings

*Would have asked:* should the existing `previous order ← 15 node(s) reordered` rows be rewritten?

*Chose:* no. The fix is forward-only.

*Alternative:* an UPDATE over the affected rows.

*Why:* the log is forward-only by design, and rewriting history is the one thing this table must
never do. A row records what happened when it happened, including that it was recorded badly.

### 15. D-18 — the sort-name fix needed no screen edits

*Would have asked:* nothing.

*Chose:* strip the alias once in `TableQuery` and once in `DataTable`, by the same rule.

*Alternative:* change `key:` on every column of every screen.

*Why:* twelve screens is twelve chances to miss one, and a missed one is a column that silently
stops sorting. Old bookmarks (`?sort=p.wa_code`) keep working and come back normalised, so a link
shared today is clean — and a collision (`p.id` and `oi.id` both stripping to `id`) throws at
declaration time rather than quietly making one column unsortable.

### 16. D-6 — the `error` Alert tone was fixed too

*Would have asked:* nothing.

*Chose:* fix the Alert as well as the badge.

*Alternative:* the seven badge call sites the item named.

*Why:* identical defect, identical measurement — `text-destructive` on a near-black card, where
`--destructive` is a background-grade red in the dark palette. A half-fixed alarm colour is worse
than an unfixed one, because it teaches the reader that red is sometimes legible.

### 17. §2.2 — the three badges became a COUNT, not a dot

*Would have asked:* item 26 said "a single 'needs work' indicator with a count and tooltip", which is
what this is; the choice was what feeds it.

*Chose:* everything wrong with the product, counted — including `machine_ar` and `root_only` — but
**not** `absent`, which stays its own neutral badge.

*Alternative:* collapse everything, `absent` included.

*Why:* `absent` is not work. It is the D-3 distinction — a fact about where the product is sold — and
folding it into a "needs work" count would re-create the exact instruction D-3 exists to remove, in a
new shape. `تصنيف` also stopped being named twice on one row (once inside `بيانات ناقصة`, once as its
own badge).

---

## Two bugs the new tests caught in work from this same pass

Worth recording, because both were mine and both were invisible without the assertion.

**The select-all scope resolved to everything.** `ProductController::listQuery()` applies only the
VIRTUAL filters — `category` and `flag`. Every plain column filter, and the search, are applied by
`TableQuery::apply()`, which the list reaches through `paginate()` and my scope resolver did not
call. "All matching watches" silently meant all 7,713 products. The first version of the test could
not see it because it counted the intersection (`family = watch AND threshold = 7`), which is right
either way; it now counts the total as well, so **"and nothing outside the filter"** is asserted.

**A 500 on the low-stock filter.** A missing `use App\Support\Sql;` in `InventoryController`, on the
`?filters[view]=low` branch only. Earlier runs never took that branch.

---

## What was NOT changed, and why

* **`spent()`'s definition.** D-23 is a labelling defect; the query is right.
* **The public API's generic 404.** D-17's page is scoped to `/manage`. Outside it, an
  indistinguishable 404 is deliberate (wave-2 review 🟡-11) and a test now holds that boundary.
* **500 error pages.** Left as they are — see decision 8.
* **Colour requiredness.** See decision 1.
* **The 7,578 default thresholds.** See decision 4.
