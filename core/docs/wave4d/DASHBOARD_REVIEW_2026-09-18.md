# The sixteen-item dashboard review — working log

The developer reviewed the dashboard against the real catalogue (7,713 products) on 2026-09-17 and
produced sixteen items in six groups. This file is the running record: what landed, what it cost,
and every judgement call made without being able to ask.

Phases, as agreed: **A** = 1a, 1b, 13, 11, 12 · **B** = 5, 6, 7 · **C** = 3, 2, 4 · **D** = 8, 9 ·
**E** = 10, 15, 14, 16.

Nothing in this run is committed — git is the developer's.

---

## Item 1a — the writing direction follows the chosen locale

**Landed 2026-09-17.** `Preferences::TEXT_DIR` (pinned `rtl`) became `Preferences::directionFor()`,
and `TEXT_LOCALE` was deleted beside it.

Both constants carried a docblock naming the condition for their own removal — *"step 1 of the i18n
backlog translates the shell and DELETES this constant"*. English coverage reached 99.8%, so the
condition arrived and the pin became the defect it was written to prevent: English words in a
right-to-left shell. `LocaleSeamTest` now asserts the flip in both directions, and the test that
used to list `dir` among the props that must be identical across locales now lists it among the
two that must differ.

---

## Item 1b — the operator reads product names in their own language

**Landed 2026-09-17.** One decision, one mark, four consumers.

| File | What it is |
|---|---|
| `resources/js/lib/title.ts` | `localisedTitle()` / `titleOrCode()` — pure, no React |
| `resources/js/components/manage/ProductName.tsx` | draws the name and the fallback mark |
| `resources/js/components/ui/bidi.tsx` | a third helper, `Name` — `<bdi dir="auto">` |
| `resources/js/lib/i18n.ts` | `useLocale()`, for data that carries its own two languages |

Wired into `Products/Index.tsx` (title cell, brand cell, row aria-label), `Placement/Index.tsx`
(cell, four aria-labels, the hide dialog) and `Products/Form.tsx` (heading, breadcrumb).

`tests/Feature/Manage/ProductNameSeamTest.php` holds both halves: the server sends BOTH names, and
no screen reads one language directly. The second is a grep and starts at zero offenders — the
regression it catches is one that looks completely correct to whoever writes it, because in their
own locale it *is* correct.

---

## Item 13 — Promotions parked in the navigation

**Landed 2026-09-18.** The nav item renders disabled with a "later" badge, the way Blogs does.

The **routes stay registered**. The promotions engine is finished and tested — rules, conditions,
rewards, the skip ledger, the preview, and the discount audit trail that records which rule
discounted which order and by how much — and `promotion_rules` holds **0 rows**, so nothing is
discounting anything. Deleting a tested engine to hide a link would be the expensive way to do a
cheap thing, and `PromotionScreenTest` still proves the server refuses a data-entry session, which
is the half that is actually the control. An admin who knows the URL still reaches the screen.

To reopen it: give the nav item its route back and drop `later: true`. One line, one place.

### A defect found while doing it

The sidebar printed the server's raw token. `wave: 'later'` rendered a badge reading the English
word **later** on an Arabic screen, with a tooltip reading **"قادم في later"** — "coming in later".
Doing item 13 "the way Articles is" would have reproduced it on a second item.

`wave` was a string holding the wave a stub was promised for (`4B`, `4C`, `4D`). Every one of those
promises has been kept, so the only value the field could still carry was the literal `'later'` — a
string field with one possible value is a boolean wearing a costume, and the costume was leaking
onto the screen. It is now `later: bool`, the badge and tooltip go through the translation seam, and
neither promises a date, because none of these has one.

---

## Item 11 — "brands are all inactive"

**Landed 2026-09-18.** They were not. All **78** rows of `catalog_brands` carry `is_active = 1`,
and they still do — no row was written to make a display match.

### The diagnosis

`LookupWriter::rows()` read every extra column through `is_scalar($value) ? $value : null`. MariaDB
returns a TINYINT as the integer `1`, so the flag reached the browser as the JSON number `1`. The
lookup screen's switch renders `checked={value === true}`, and in JavaScript `1 === true` is false.
Every brand showed as switched off while every brand in the database was switched on.

The storefront read layer had it right all along — `Storefront\Lookups::load()` uses
`Row::bool($row, 'is_active')` — so the customer-facing brand list and the sitemap were never
affected. Two readers of one column; the one written with the type in mind was correct.

### The half nobody had hit yet

The same screen sends the row back on save, and its `extraPayload()` maps a declared boolean to
`value === true`. For the incoming `1` that is **false**. Renaming a brand, replacing its logo or
correcting its slug would have written `is_active = 0` on the way out. The display bug was one edit
away from being a data bug across 78 rows.

Fixed at the source: `config/catalog.php` declares the type, the server holds that config, so the
server owes the client the right shape. A cast in the React file would have left the wrong value on
the wire for the CSV export and for every screen added later. The export now writes the word rather
than the digit, because a stringified `false` is `''` and an empty cell means "nobody filled this
in", not "switched off".

### The rule

There was no state to fix, so the whole of item 11 is the rule, written where it can hold.
`LookupWriter::assertMayDeactivate()` refuses to switch off a lookup row that products still use,
naming the count and what it would cost.

That cost is the reason it earns a guard. `is_active` on a brand does **not** hide its products —
they keep selling. It drops the brand from `Storefront\Meta`'s brand list and stops
`Storefront\Sitemaps` emitting its page. Switching Rolex off removes a browse path and a URL Google
has indexed while 125 products carry on as if nothing happened, and nothing on the screen said so.

The guard refuses only the **transition**, so a row that is already off stays editable, and it fires
on any list declaring a boolean `is_active`, not on brands by name. A brand nothing uses can still
be retired — 51 of the 78 have no products, and refusing there would be the screen inventing a
policy nobody asked for. Both halves are tested.

---

## Item 12 — the order line tells the packer what to pick

**Landed 2026-09-18.** Two defects on one screen.

### The colour was a hex code

`order_items.color_band` and `color_dial` hold what the legacy cart wrote — `#1F3A5F` — and the
screen printed it. Seven of the nine lines in this database carry one, so a team member packing any
of them read **"#1F3A5F / #1F3A5F"** where they needed to read **"أزرق"**.

`catalog_colors` already maps hex to a name in both languages, and it is the same table the product
form picks from, so the name shown on an order is the name the catalogue uses. Resolved in one
grouped query after the lines are built — an order of six watches would otherwise ask the colour
table twelve times for four distinct answers.

The hex **stays on screen**, demoted to a small monospace run beside the name. It draws the swatch,
and it is what the row actually stores, so when a name and a swatch disagree with the watch in
somebody's hand the value that caused it is right there rather than one query away.

A hex the catalogue has never seen keeps its hex and gets **no** name, with a word saying so.
`#1E3A5E` is not "أزرق" just because `#1F3A5F` is, and a screen that rounds colours is a screen
that mis-picks orders. That is its own test.

### The product row confirmed nothing

A line said a title and a code. Forty products share "طقم ساعة", so checking a pick meant opening
the product screen in another tab and losing the order.

The line is now a button opening a panel: the photograph first — that is what a person matches
against the thing in their hand — then both names, the supplier's code, the model number, the
brand, then the LINE's own facts (variant, variant SKU, colours, quantity, unit price), then the
specifications the product actually has.

| Piece | Where |
|---|---|
| `app/Domain/Orders/ProductPeek.php` | the product side, four grouped queries |
| `resources/js/components/manage/ColourChip.tsx` | swatch + name + hex |
| `resources/js/components/manage/ProductPeekDialog.tsx` | the panel |

Three things worth naming:

- **Built with the page, not fetched when the panel opens.** An order has a handful of lines, so
  the cost is bounded and there is no route to authorise, no loading state, no error state, and no
  panel that can be empty for a reason the operator cannot see. `OrderProductPeekTest` asserts every
  line's product is on the page, which is what makes that safe.
- **Empty specification fields are dropped**, on the server. A list of twenty rows where fourteen
  say nothing buries the six that identify the product. The panel says where they are added.
- **The family is a word.** `watch` is a column value; the panel shows "ساعات", through the same
  keys the products list uses, so the two cannot drift.

The link to the product screen is offered only to somebody the product route would admit — a link
that 403s teaches the team to distrust the ones that work.

---

## Phase A battery — 2026-09-18

Run once at the end of the phase, not after each item.

| Check | Result |
|---|---|
| `vendor/bin/pest` (whole suite) | **1,263 passed, 38 skipped, 0 failed** |
| PHPStan (larastan, level 10) | **No errors** |
| Pint | **passed** |
| `tsc --noEmit` | **clean** |
| `npm run build` | **clean** |

The 38 skips are the accepted gap recorded in `HANDOVER_NOTES_2026-09-17.md` §7: tests that need a
rebuilt catalogue, which is not being run. Nothing was committed.

Data after the phase, checked against the figures before it: **7,713** products, **78/78** brands
active, **9** orders, **9** order lines, **0** promotion rules, **5,795** inventory movements. No
drift, and no leftover probe scripts in the tree.

---

## Item 5 — every pre-switch gate opened, every notice kept

**Landed 2026-09-18.** `config('transform.pre_switch_gates')` is a new switch, defaulting to
**`warn`**. The refusals became caveats: the same sentence, beside a control that works.

### What was NOT done, and why it matters

Flipping `CORE_WRITE_SWITCH_COMPLETED=true` would have opened these gates in one line. It would also
have been wrong. That flag means *"legacy has stopped writing and core is the only writer"*, and its
own config comment says it must not be flipped while any legacy path can still write a stock column.
The legacy storefront is live. Two other things read it:

- **`ConversionGuard`** — converting a live product to sell through variants. Legacy writes
  `products.quantity` directly; a converted product's stock lives in the ledger. Opening that before
  legacy stops writing corrupts stock. **Still refuses**, and there is a test that says so.
- **`syncsSecondaryTrees()`** — the flag flip *is* the moment the mirror stops, permanently. Saying
  it had happened would silently end the sync a month early.

The two questions are now separate switches. The difference between them is the difference between
"you may lose an afternoon" and "you may lose the stock figures".

### The shape on screen

`PreSwitchState` gained a `caveat` field. `blocked` and `caveat` are mutually exclusive by
construction, and both are null once the switch has happened — then there is nothing to say, and
the sentence disappears rather than becoming furniture nobody reads. Asserted, not assumed.

One sentence had to change rather than move: the placement screen's notice said *"(changing links is
already paused)"*, which stopped being true the moment the field opened.

### The tests did not shrink

Every refusal test still exists and still passes — each now opts into `enforce` through one
`enforcing()` call. A refusal nobody tests is a refusal nobody can safely turn back on, and
`CORE_PRE_SWITCH_GATES=enforce` is exactly what somebody reaches for the first time a rebuild eats
an afternoon. Six new tests prove the shipped default actually opens the doors: a product created,
a slug changed, the caveat present on three screens, the secondary tree open while still mirrored,
and the conversion guard still shut.

---

## Item 6 — slugs and the tree's shape are admin-only, and the screen says why

**Landed 2026-09-18.** Two new abilities in `Role::ABILITIES`, both absent from data-entry's list:
`edit-product-slug` and `edit-category-tree`.

### Where the line is

| Admin only | Still data-entry |
|---|---|
| a product's slug | visibility, order, featured, the category set |
| a category's slug | renaming a category |
| create / move / reorder / delete a node | "show in the menu" |
| switching a category **off** | everything else they do all day |

The reason is blast radius, not seniority. A rename changes a word on a page. Moving a node moves
every product under it, the breadcrumb, the menu and the derived family; a slug moves a page
customers have bookmarked and Google has indexed, and the 301 written alongside is the only thing
between that and a 404. After the switch the archived Brand Fashion URLs depend on these exact
strings (item 7).

### Two things that were riding along, found while doing it

- **`placement.update` writes five fields at once.** The obvious implementation —
  `can:edit-product-slug` on the route — would have taken visibility, ordering, featured and the
  category set away from the people whose job they are. The check is on the FIELD, and fires only
  on a CHANGE, because the screen echoes the stored slug back on every ordinary save. There is a
  test for the refusal and a test for the neighbouring work still going through.
- **The category rename route writes the node's slug and its active flag.** Two of its four fields
  are not renames. Left alone, an operator who may only rename could have changed a category's
  public URL, or taken every product under it off the storefront, through the one route
  deliberately left open to them.

### On screen

Both screens carry a separate `slug_role` / `tree_role` prop rather than folding the rule into the
existing pre-switch state. They are different rules with different answers: one is about the
**calendar** and is the same for everybody, the other is about the **person** and is the same on any
day. Every message names the role reason **first** — telling a data-entry operator to wait for
switch night when the real answer is "ask an administrator" sends them to wait for the wrong thing.

The move-up and move-down buttons had carried **no disabled state at all**: on a mirrored tree they
looked live and the save was refused after the click.

---

## Item 7 — the archived Brand Fashion URLs, wired

**Landed 2026-09-18, applied to the real catalogue.** `php artisan core:archive-slugs`.

### The join

The importer does **not** carry each product's permalink — the WooCommerce export has no such
column. What it carries is the post **id**, as `catalog_products.import_ref` = `woo:<n>`. The
archived pages carry the same number, as WordPress's own `postid-<n>` on the `<body>` tag.

That is the only safe key. The titles were machine-translated on the way in, and the slugs are
exactly what is being corrected — matching on either would be matching on the thing that is wrong.

### The numbers

| | |
|---|---:|
| product URLs the live site published (sitemap) | **7,742** |
| URLs the crawl actually captured | 4,981 |
| captured pages that yielded a post id | **4,969** |
| imported Brand Fashion products | **7,087** |
| **now serving their original URL** | **4,713** |

Of the 7,087:

| outcome | products |
|---|---:|
| restored to the original URL | **2,975** |
| already correct — the derived slug happened to match | 1,738 |
| no archived URL for this product | 2,349 |
| archived slug too long for the column | 10 |
| archived URL had no usable slug | 8 |
| original slug taken by another product | 7 |

### The 2,349 with no archived URL — stated plainly

**Our generated slug IS their URL.** These products have no URL we know of: the crawl never captured
one, so there is nothing they could be "restored" to and nothing that breaks by leaving them. They
are not a gap in the work; they are the shape of the archive.

### The honest ceiling

The crawl captured **4,981** of the **7,742** product URLs the sitemap lists — **64%**. No amount of
matching improves on that, because the remaining 2,761 URLs were never fetched. Recovering them
would mean crawling the live site again, which is a decision about brandfashionegy.com and not a
thing this codebase can do to itself.

Within what the archive holds, the match is essentially complete: 4,969 of 4,981 captured product
pages yielded a post id (99.8%), and every one of those that belongs to an imported product was
used.

### The three small refusals, and why each is a refusal

- **10 slugs too long.** The column is `varchar(191)` and these are up to 197 characters; the live
  site ran on a schema that allowed it. **Not truncated** — a truncated slug is not the URL Google
  indexed, so cutting it to fit buys nothing and would let the report say "restored" while the old
  link still 404s.
- **8 URLs with no usable slug.** Underscores (`mf0334l_stainless-strap`) and percent-encoded Arabic
  (`casio%d9%84%d8%a7-...`). The command accepts an ASCII slug or nothing, because a slug it cannot
  vouch for is worse than the derived one already in place.
- **7 real collisions.** Four are genuine duplicate products — two rows with the same title, one
  archived URL between them. Three collide with a Watchizer product already placed on Brand Fashion.
  Skipped and listed, never suffixed: a product quietly given `/ysl-perfume-2/` is a product whose
  indexed URL still 404s, with a row claiming it was fixed.

### Two bugs the run found in my own command

Both failed loudly on the real data rather than quietly on a subset, and the transaction held both
times — verified by diffing all 7,713 slugs against the backup after each failure. Not one row had
moved.

1. **A one-pass assignment reported 14 collisions, of which 7 were the loop's own order.** `$taken`
   started as every current slug, so a product was refused a slug that another product was about to
   vacate later in the same run. Now two passes: everyone vacates, then everyone claims. A report
   that calls an ordering artefact a data conflict sends somebody to audit the catalogue for a bug
   in the script.
2. **`sp_storefront_slug_unique` is checked per statement, not at commit.** The final state was
   conflict-free; the states in between were not, and this data contains cycles — A wants B's slug
   while B wants A's — so no ordering fixes it. Every affected row is now parked on
   `item7-parking-<product id>` first, then given its real slug, both inside one transaction.

### No redirects were written

A 301 would point **from** the derived slug, which has never been served to anybody — the new Brand
Fashion storefront is not live. A redirect from a URL that has never existed is a row that can only
ever be wrong later. `storefront_redirects` for this storefront is still empty, deliberately.

### Reversible

`storage/app/backups/bf-slugs-before-item7-20260918.tsv` holds all 7,713 slugs as they were.
`storage/app/backups/bf-slugs-item7-report.csv` has one row per product with its before, its
archived slug and its outcome. Both are gitignored.

---

## Phase B battery — 2026-09-18

| Check | Result |
|---|---|
| `vendor/bin/pest` (whole suite) | **1,281 passed, 38 skipped, 0 failed** |
| PHPStan (larastan, level 10) | **No errors** |
| Pint | **passed** |
| `tsc --noEmit` | **clean** |
| `npm run build` | **clean** |

### Eleven tests went red, and every one was the policy change doing its job

Item 5 turned the pre-switch refusals into caveats and item 6 made slugs and the tree shape
admin-only. Eleven tests across five files existed to prove the old behaviour. None was deleted:

- **Nine** now call `Gates::enforcePreSwitch()` — one line, one shared helper, so the config key is
  named in one place rather than five. They still prove the refusals, in the mode that refuses.
- **Six** changed their ACTOR from data-entry to admin. Each was written when only the calendar rule
  guarded the path; the role rule now answers first, so a data-entry request is refused earlier and
  for a different reason. Run unchanged they would still have been red — and would have been proving
  the wrong rule, with `assertSessionHasErrors()` failing on a 403 that never reached a session.

One real gap surfaced while fixing them: the **product form** writes a slug per storefront through
the same writer, so it was refusing data-entry on save with nothing on screen to say why. It now
carries the same `slug_role` prop as the placement screen.

Data after the phase: **7,713** products, **78/78** brands active, **7,713** Brand Fashion rows with
**7,713** distinct slugs, **0** redirects, **0** parking slugs left behind, **9** orders.

---

## Item 3 — "the up/down reorder buttons don't work"

**Landed 2026-09-18.** They worked. The screen never read the result.

Every click wrote `sort_order` correctly, the activity log recorded it, and the **storefront**
honoured it — `Storefront\CategoryTree` has always ordered by `depth, sort_order, id`, so the
customer-facing menu moved every time. The dashboard did not, because it ordered by `path`, and
`path` is an **id** path: `/1/10/`, `/1/11/`, `/1/12/`. Sorted as a string that is ascending by id
and cannot be changed by any button.

So the operator clicked, the page came back identical, and the only evidence anything had happened
was on the live site.

A single `ORDER BY` cannot fix it: depth-first ordering of a materialised id path needs the sort
position of every ancestor, which the id path does not carry. `CategoryController::inTreeOrder()`
arranges the rows instead — the trees are 37 and 61 nodes, not a query worth contorting. An orphan
whose parent is missing is **appended** rather than dropped, because a screen that silently omits
rows hides the problem.

### Why the old tests missed it

They asserted the COLUMN. `reorder()` returned the number of rows moved and `sort_order` held the
new value, so everything passed while the screen was wrong. `CategoryOrderTest` asserts what the
operator sees: the order of the `nodes` prop, after a reorder, through the real endpoint.

**Checked rather than assumed:** the fix was reverted and all three tests were re-run against the
old ordering. Only the first went red. The other two pass either way on this catalogue, because each
level's id order happens to match its sort order — recorded in the file so nobody mistakes them for
the ones that caught it. The first earns its place by CREATING the condition it checks.

---

## Item 2 — the category tree reads as a tree

**Landed 2026-09-18.** The screen already indented by `depth`. The developer's verdict was still
*"it reads as a flat list and it's confusing"* — 1.5rem of margin is nothing beside a full-width
bordered card, so sixty-one rows looked like sixty-one rows. Depth you have to measure is depth
nobody reads.

### What changed

- **`components/manage/TreeRail.tsx`** — parentage is now DRAWN: one vertical line per ancestor
  level, an elbow into the row, and a chevron where a node has children. Built from logical
  properties so it mirrors with the page, and the chevron points the way the reader's language runs.
- **Collapse and expand**, per node, plus fold-all / open-all in the card header. State lives in
  `sessionStorage` per storefront, **not** in component state: every mutation on this screen is a
  full Inertia visit, so component state is rebuilt from nothing and a tree that re-opens all
  sixty-one nodes each time somebody nudges one row is worse than one that never folded.
- **A branch count.** `products_subtree` / `products_any_subtree`, computed in one backwards pass
  over the depth-first list.

### The contradiction the branch count fixes

The counts were per NODE. On this tree almost everything hangs off leaves, so a section showed
**0 products** while thousands sat underneath it — and worse, the "empty — hidden automatically"
badge read the node's own count while "in the menu" reads the §3.3 rule, which looks at the whole
branch. **One node on Brand Fashion displayed both at once today**, and folding a branch would have
left exactly that row on screen claiming to be empty.

Both badges now read the branch. `CategoryOrderTest` asserts the totals against an independently
recomputed walk, and asserts directly that no node ever claims to be both in the menu and empty.

---

## ⚠ One thing I could not do: log into the dashboard in a browser

The developer asked for a screen-by-screen inspection, driven as admin and as data-entry. **I cannot
type a password into a login form** — that is a standing prohibition I hold to even when asked
directly, and it is not something to work around.

What I checked instead, and it is not nothing: every screen is driven through the test HTTP layer,
which authenticates without a password. That covers whether a screen loads, what every prop
contains, which controls are offered to which role, what each message says, and both locales. What
it cannot cover is pure **visual** layout — whether the tree rail draws cleanly, whether anything
overflows at a narrow width, whether the right-to-left mirroring looks right.

Item 2 is the one item where that gap matters most, because it is a visual rebuild. The structure is
asserted and the build is clean; what it looks like needs one pair of eyes.

**What I would ask for:** log in at `http://127.0.0.1:8123/manage` (the dev server is running) and
open the categories screen for Brand Fashion — 61 nodes, three levels deep, the one that exercises
the rail properly. If it reads as a tree, item 2 is done. Public screens such as the login page I
can and do inspect visually myself.

---

## Item 4 — the primary-category rule, in writing and on the form

**Landed 2026-09-18.** Confirmed, verified, and then said on screen.

### The confirmation the developer asked for

**A product can sit in several categories on a storefront, and exactly one of them is primary.**
Both halves are true of this catalogue, not merely permitted by it:

| | storefront 1 | Brand Fashion |
|---|---:|---:|
| products placed | 626 | 7,585 |
| in more than one category | 589 | 3,968 |
| with exactly one primary | 589 | 7,548 |
| with **more than one** primary | **0** | **0** |
| with none | 37 | 37 |

The primary decides three things, each traced to the code rather than repeated from memory:

- **the family** — `FamilyForCategory`, the same resolver the transform uses;
- **the specification fields** — the family selects the block, `SpecBlocks::for()`;
- **the breadcrumb** — `Storefront\ProductDetail` builds it from the primary node and nothing else,
  so a product with no primary has **no breadcrumb at all**. That is the 37 per storefront.

`PlacementWriter` clears any existing primary before setting a new one, which is what makes "exactly
one" hold rather than hope.

### On the form

The screen already had a sentence in the right place — and re-reading it showed why the developer
still had to ask. It said one primary per storefront and that it decides the family. It never said a
product may be in SEVERAL categories, which was the first half of the question, and it never said
what the primary is FOR from the customer's side, or what happens without one.

Completed rather than duplicated: the same paragraph, in the same place, now carries the whole rule.
Two new tests hold each clause — a sentence on a screen is a claim, and a claim nobody checks is how
a screen ends up lying politely.

---

## Phase C battery — 2026-09-18

| Check | Result |
|---|---|
| `vendor/bin/pest` (whole suite) | **1,288 passed, 38 skipped, 0 failed** |
| PHPStan (larastan, level 10) | **No errors** |
| Pint | **passed** |
| `tsc --noEmit` | **clean** |
| `npm run build` | **clean** |

No test needed retargeting this phase — items 2, 3 and 4 changed presentation and reads, not rules.

---

## Item 9 — a colour picker beside the hex box

**Landed 2026-09-18.** The swatch on the lookup screen was a read-only `<span>`: it showed the
colour and could not set it, so the only way in was to type six hexadecimal digits from memory.
Nobody knows that ذهبي وردي is `#B76E79`.

Both controls now write, and either fills the other. They are two different jobs that happen to
share a value — the picker is for choosing, the box is for pasting a code a supplier sent — which is
why the answer is both and not one.

The box stays free text, character by character, **invalid included**. Normalising as somebody types
turns `#B7` into a fight with the cursor, and the server already refuses a bad hex with a sentence of
its own. Only the picker writes a normalised value, because a picker cannot produce an invalid one.
An empty field shows a dashed ring rather than black, because `<input type="color">` always has a
value and black would imply somebody chose it.

Two tests: the lower-case `#b76e79` a picker produces round-trips and is stored upper-cased like any
other, and the server still refuses a hex no picker could have made.

---

## Item 8 — the SEO fields, written in one click

**Landed 2026-09-18.** `resources/js/lib/seo.ts`, with a button on the product form's SEO card.

The developer's argument, and it is the whole argument: *"It exists to prevent human error — the
team will not write 7,700 meta descriptions."* A field nobody fills is empty; a field 7,700 people
fill in a hurry is worse than empty, because it is wrong in ways nobody audits.

### What it writes

| field | from |
|---|---|
| SEO title (ar/en) | the product's title, plus the brand **only when the title does not already contain it** |
| SEO description (ar/en) | title, brand, primary category (or family), model number — one sentence of facts |
| search keywords | every one of those terms in both languages, deduplicated, most specific first |

Clipped at 60 and 160 characters **at a word boundary**, never mid-word and never with an ellipsis —
an ellipsis spends three of the characters that were the problem.

### Three decisions inside it

- **It runs in the browser.** It writes from what is ON SCREEN: the brand just picked, the title
  just corrected, on a product that may not exist yet. A server round trip would need a route, an
  authorisation rule, a loading state and an error state to compose four strings the page holds.
- **The brand is skipped when the title already carries it.** "Michael Kors Watch for Women MK6268"
  does not become "… | Michael Kors". Most of this catalogue's titles already carry the brand, which
  is exactly the case a naive template gets wrong seven thousand times.
- **No price, no availability, no superlative.** A meta description is served for months: a price in
  it goes stale the first time somebody runs a sale, and "original" or "best" is a claim a generator
  is not in a position to make.

### Nothing is overwritten by surprise

With the fields empty the button fills them immediately. With anything already written it asks first,
naming what will be replaced — somebody may have written those two sentences by hand. Neither shape
SAVES: the fields go dirty and the operator reads them, edits them and presses Save like any other
change. That is what "editable afterwards" has to mean to be worth anything.

### What is tested, and what is not

The composition is TypeScript and this tree has no JavaScript test runner, so the sentences are not
asserted. `SeoGeneratorTest` asserts the thing that would break it silently: the generator writes in
two languages, so it needs every brand and every category name in **both**, and the pickers hand it
an id. A picker offering an id the name map does not carry would produce a sentence with a hole in
it, and nobody would notice until a customer read it. A fifth test keeps the form's hard-coded family
words in step with `Product::FAMILIES` — the map has to be hard-coded, because it needs both locales
at once and `t()` answers only in the active one, so it is a second copy and it gets a test.

---

## Phase D battery — 2026-09-18

| Check | Result |
|---|---|
| `vendor/bin/pest` (whole suite) | **1,295 passed, 38 skipped, 0 failed** |
| PHPStan (larastan, level 10) | **No errors** |
| Pint | **passed** |
| `tsc --noEmit` | **clean** |
| `npm run build` | **clean** |

One test needed a decision rather than a fix: **item 1b's own grep** flagged `lib/seo.ts` for reading
`title.ar` and `title.en` directly. It is the one legitimate exemption — the generator does not pick
a language for a reader, it writes an Arabic description AND an English one in the same click.
Routing it through `localisedTitle()` would give an English operator an English sentence in the
Arabic field: the exact defect item 1b removed, in the other direction. Listed by name in the test
with that reasoning, rather than loosening the pattern.

---

## Item 15 — payments on both storefronts, and the security confirmation

**Landed 2026-09-18.**

### Why it "only showed Watchizer"

The screen received one storefront and no list. Every other per-storefront screen — products,
categories, placement, banners — carries a switcher; this one did not, so the sidebar sent you to
whichever storefront you were last on and nothing on the page said another existed. Brand Fashion's
payment settings were reachable only by typing an id into the address bar.

It now has one, and it is **scoped to the acting grant** rather than listing every storefront.
Payments answers 404 for a storefront outside the grant (§3.11.14 — out of scope is 404, never 403,
so an id cannot be confirmed by probing), and a switcher offering an option that 404s reads as a
broken dashboard rather than as a permission somebody does not have. There is a test that walks every
option the switcher offers and requires the route to answer 200.

### The written confirmation — and it is a test, not a paragraph

*"Confirm in writing that this screen is the safest thing in the system: keys write-only, never
rendered back, never logged, never exported."*

**Confirmed.** Each clause, with the evidence, and each one is now a test in
`tests/Feature/Manage/PaymentSecrecyTest.php`. Every test writes a secret that cannot occur by
accident and then goes looking for it where it must not be — serialising the WHOLE payload rather
than checking a named field, because the guarantee is about the secret and not about a field name.

| Clause | Evidence | Test |
|---|---|---|
| **Write-only** | `mergeCredentials()` — a blank field keeps the stored value, so the form never needs to show one | a save with blank fields keeps the key and still applies the other changes |
| **Never rendered back** | `providerRows()` sends booleans and key NAMES only; the model also sets `$hidden = ['credentials']` | the secret appears nowhere in the serialised page payload |
| **Never logged** | `ActivityLog::REDACTED_FIELDS` covers `credentials` and eight more | the secret appears in no activity-log row, on create or on rotate |
| **Never exported** | there is no export on this controller at all | no route whose URI contains both `payments` and `export` |
| **Encrypted at rest** | `'credentials' => 'encrypted:array'`, AES-256-CBC under `APP_KEY` | the raw column does not contain the secret, and the model still round-trips it |
| **Not leaked by `toArray()`** | `$hidden` | `json_encode($model->toArray())` does not contain the secret |

One thing worth knowing about that test file: it uses **paymob**, because it is the only provider in
the registry that declares credential fields. `cod` and `whatsapp` take none, and `mergeCredentials()`
keeps only declared keys — so a secrecy test written against those two would store no secret and
pass for the emptiest possible reason. It was written against `cod` first, and that is exactly what
happened.

---

## Item 10 — the developer-speak sweep

**Landed 2026-09-18.** The developer's example was the brands screen printing
`عمود «الاستخدام» يحسب الإشارات من catalog_product_watch_specs.case_size_unit_id` at a data-entry
operator, with the instruction to sweep the whole class.

### How it was surveyed

One sub-agent, read-only, cataloguing every rendered raw token across `pages/Manage/**`,
`components/manage/**` and the Manage controllers. It returned **55 findings** plus a section of
what it had checked and ruled out. Every row was verified against the file before being acted on —
three were spot-checked first and all three were exact, and each subsequent fix opened the file it
named.

### The fix is one vocabulary, not fifty patches

`resources/js/lib/labels.ts`. Almost all 55 were one of six small vocabularies — a payment provider,
a payment method, a stock bucket, a product family, a media type, an ability — and **each already
had a translation somewhere**. `methodLabel()` lived in `Payments/Index.tsx`, `familyLabels` in
`Products/Index.tsx`, `BUCKET_LABEL` in two ledger files. The screens showing raw tokens were simply
the ones never given a copy, which is what copying a map to each screen guarantees: three copies of
the bucket words existed and a fourth screen still said `express`.

Every function falls through to the raw token on purpose. A value these maps have not heard of is
real data — a new payment method, a family added to config — and showing it verbatim is how somebody
finds out it exists.

### What changed, by concern

| Concern | Was | Now |
|---|---|---|
| **The developer's own example** | `usage_tables` prop: `catalog_product_watch_specs.case_size_unit_id` | a `usage_counts` token; the screen says how many **products** use the item and what to do about it |
| Payment provider / method | `paymob · card` on the order queue, the order detail, the customer's orders, six places on the payments screen, and four filters | the company and the method, in words |
| Stock bucket | `Express` / `Market` as table headers and placeholders, `express` in two filters and a promotions preview | إكسبريس / ماركت, the words the ledger always used |
| Product family | `watch` on every category row and under the spec block | ساعات |
| Activity log | `storefront_payment_providers` per row, and raw column names in "what changed" | the same words its own filter had always shown |
| Ledger source | `orders`, `inventory_movements`, and `order #41` | طلب, تسوية جرد, طلب #41 |
| Profile abilities | `manage-order-fulfilment` | "تحريك حالة الطلب" — a thing a person can do |
| Media types | `product_gallery` | معرض منتج |
| Credential names | "Secret key", "Public key", "HMAC secret" in English | Arabic, on the screen an administrator uses under pressure |
| Source-file paths | `config/catalog.php` under the spec block; `InventoryService` in the variants panel | a sentence saying what the operator can actually do |
| English literals | `Name (English)`, `Governorate`, `Alt text (English)`, `Integration id`, `sort`, `updated`, `integration`, `email or name`, `product id`, `command`, `legacy:` | all through the seam |
| Units CSV export | `case_size_unit_id: 231 \| band_width_unit_id: 12` | the same eight words the screen has always shown |

47 new English entries. Both coverage tests pass.

### The one it found in reverse

The Units screen already mapped those eight columns to Arabic — its **CSV export** did not. The
developer's example, one file away, in the direction nobody looks.

### A test that was pinning the defect

`AuthTest` asserted the throttle message contained "Too many login attempts". It passed only because
`lang/ar/auth.php` did not exist and Laravel fell through to its own English copy — so a test was
quietly holding the login screen's Arabic operator to an English refusal. It now asserts a fragment
of the translated message.

### Left deliberately

`php artisan manage:role`, `media:prune`, `mail:drain` and `inventory:verify` appear in operator
copy, each documented in place as intentional. They name real things a person may need to ask an
administrator about, and `inventory:verify`'s whole screen exists to agree with the terminal. They
are listed in the working notes rather than changed, because changing them is a decision about who
those sentences are for — and that is the developer's call, not a sweep's.

---

## Item 14 — articles

**Landed 2026-09-18.** Scoped exactly as asked: list, create, edit, publish/unpublish, Arabic and
English, the SEO fields, one cover image, no gallery.

### Core-owned, and that was the first real decision

The legacy schema is `blogs (id, image, created_at, updated_at)` plus
`blog_translations (locale, blog_id, title, text)`. That is the whole of it: **no slug, no published
flag, no SEO fields** — the three things named in the request. All three legacy blog tables are
**empty** on this dump, so there was nothing to preserve and nobody to break.

Core may not write legacy tables regardless, and the `legacy` connection runs `tx_read_only = 1`, so
an `ALTER` would be refused by the server rather than by a policy. So articles live in `core_blogs`
and `core_blog_translations`, joined `CoreChecksumCommand::DASHBOARD_TABLES`, and therefore survive
switch night's rebuild. `BlogScreenTest` asserts that, and asserts the two lists are disjoint as sets
so the guarantee covers every table rather than these two.

### Three rules, each a refusal rather than a caveat

- **An Arabic title is required.** Arabic is the storefront's default and translation fallback is
  off, so a missing Arabic title publishes a blank heading.
- **Publishing needs a body.** A published article with nothing in it is a live page that says
  nothing, and it is the state nobody discovers from inside the dashboard. Saving a **draft** with
  an empty body is fine — that is what a draft is.
- **The publish date is stamped once.** Re-stamping on every save would turn `published_at` into
  "last edited", so the screen saying "published on the 3rd" would start saying "published today"
  after a typo fix.

### What the screen says that it cannot do

An article published here **does not appear on the storefront**: nothing in `App\Storefront` reads
`core_blogs`, because the read endpoint and the page are a separate piece of work that was not in
scope. That is stated on the screen in an alert rather than left for somebody to discover by
publishing one and going to look for it.

### The test that caught a real bug

`MediaReferenceCoverageTest` failed within minutes of the table existing: `core_blogs.cover_path` was
not in `MediaPruneCommand::REFERENCE_COLUMNS`, so **`media:prune --delete` would have treated every
article cover as an orphan and deleted it**. That is the second time that list has been forgotten and
the second time this test caught it.

---

## Phase E battery and the end-to-end sweep — 2026-09-18

| Check | Result |
|---|---|
| `vendor/bin/pest` (whole suite) | **1,319 passed, 38 skipped, 0 failed** |
| PHPStan (larastan, level 10) | **No errors** |
| Pint | **passed** |
| `tsc --noEmit` | **clean** |
| `npm run build` | **clean** |

### The 38 skips, named

Thirty-seven of them skip for **one** reason, and it is the accepted gap from
`HANDOVER_NOTES_2026-09-17.md` §7: `inventory_movements` holds **4,543 non-transform rows** (4,503
from the import, 40 manual), and `core:transform` refuses to re-baseline over real stock movement.
That is the wave-3 guard doing its job, not a defect — they need a rebuilt catalogue, and the
catalogue is not being rebuilt. The thirty-eighth needs `CAPTURE_SCREENS=1`.

### The sweep

`tests/Feature/Manage/DashboardSweepTest.php` walks the **route table** rather than a list somebody
typed, so a screen added after it was written is covered by existing. It opens all **32** dashboard
GET screens **as an administrator and as data-entry, in Arabic and in English** — 128 page loads —
then opens seven with a nonsense id, posts four blank forms, and asks six table screens for a search
that matches nothing.

It found two things on its first run, and both were the fixture's fault rather than a defect: an
invented customer key (the customers screen groups guests by phone, so it 404s), and `media:prune`
answering 403 to an administrator — which is **correct**, because `MANAGE_MEDIA_PRUNE` is in
`Role::RESTRICTED`, an ability no role holds implicitly. That exception is named in the test rather
than filtered out, so the day somebody makes it ordinary the line asks whether they meant to.

### Data after everything

**7,713** products · **78/78** brands active · **7,713** Brand Fashion rows with **7,713** distinct
slugs · **626** Watchizer rows · **9** orders · **5,795** inventory movements · **0** parking slugs ·
**0** rows in the legacy blog tables. Nothing drifted, and no probe script is left in the tree.

---

## Screen-by-screen verdict

All 32 screens opened as **administrator** and as **data-entry**, in **Arabic** and in **English**.
"Clean" below means: it loaded, its props are complete, its controls are offered to the right role,
its messages are in the reader's language, and it survives an empty result set and a bad id.

The column that is NOT covered is pure visual layout — see the note under the table.

| Screen | Verdict |
|---|---|
| Home | clean |
| Products — list | clean · **changed**: English titles with a marked fallback (1b), brand name localised, bucket tooltips in words (10) |
| Products — create / edit | clean · **changed**: SEO generator (8), primary-category rule stated (4), slug locked for data-entry with the reason (6), family words (10) |
| Categories | clean · **changed**: real tree with rails and collapse (2), reorder now visible (3), branch counts, shape edits admin-only (6) |
| Placement | clean · **changed**: localised titles (1b), slug admin-only with the reason (6), slug open with a caveat (5) |
| Brands and reference lists | clean · **changed**: the boolean defect fixed (11), colour picker (9), usage note rewritten (10) |
| Units | clean · **changed**: the CSV export now uses the same words as the screen (10) |
| Orders — queue | clean · **changed**: payment provider and method in words, filters too (10) |
| Orders — detail | clean · **changed**: colour name + swatch, product confirmation panel (12), payment words (10) |
| Customers — list / detail | clean · **changed**: payment words (10) |
| Inventory — stock | clean |
| Inventory — ledger | clean · **changed**: source in words, bucket filter, order link (10) |
| Inventory — reconciliation | clean · English command output by design, flagged below |
| Shipping | clean · **changed**: English column head removed (10) |
| Banners | clean |
| **Articles** | **new** (14) — list, create, edit, publish, drafts first |
| Promotions | clean · **parked in the sidebar** (13); routes live, admin-only, 0 rules |
| Payments | clean · **changed**: storefront switcher (15), provider/method/credential words (10) |
| Storefronts — list / edit | clean |
| Users and permissions | clean · **changed**: legacy flag labelled, English placeholder removed (10) |
| Activity log | clean · **changed**: subject type and changed-field names in words (10) |
| Profile | clean · **changed**: abilities read as things a person can do (10) |
| Media cleanup | clean · 403 for everyone by design (`Role::RESTRICTED`); image types in words (10) |
| Login | **redesigned** (16) — and the error was in English on an Arabic page; `lang/*/auth.php` was never published |

### The one gap, stated plainly

**Visual layout is unverified on 31 of the 32 screens.** Driving a browser needs a password typed
into a login form, which is a line I hold to. Everything above was driven through the test HTTP
layer, which authenticates without one — that covers behaviour, props, roles, wording and both
locales, and it is most of the inspection. What it cannot see is whether the tree rail draws
cleanly, whether anything overflows at a narrow width, and whether the right-to-left mirroring looks
right.

The **login screen** is the exception: it is public, so it was opened in a real browser, looked at,
redesigned, looked at again, and its error state triggered and read. That is where the English
`auth.failed` was found — by looking, not by reading source. Two full i18n passes had walked past
it, because it only appears once something has already gone wrong.

**Item 2 is where that gap matters most**, being a visual rebuild. Its structure is asserted and the
build is clean. It wants one pair of eyes: `/manage/storefronts/2/categories` — Brand Fashion, 61
nodes, three levels, the tree that exercises the rail properly.

---

## What I decided and why

Entries here are calls made without being able to ask. Each gives the question, the choice, the
alternative, and why the choice is better.

### 1. The brand cell gets the locale rule, but NOT the fallback badge (item 1b)

*Would have asked:* should a brand name shown in the wrong language carry the same mark a product
title does?

*Chose:* no mark on the brand cell — the name follows the operator's language and falls back
silently.

*Alternative:* mark it identically, for consistency.

*Why:* a badge on a product title points at one row somebody is meant to go and fill in. A brand
name is shared by hundreds of rows, so the identical badge would repeat down the entire page and
carry no information — a mark that appears everywhere stops being a mark. The brands screen is where
a missing brand name is actionable, and that is where it should be shown.

### 2. `TitlePair`'s two sides are optional (item 1b)

*Would have asked:* nothing — this is an internal typing call, recorded because it weakens a type.

*Chose:* `{ ar?: string | null; en?: string | null }`.

*Alternative:* require both, and cast at the one call site that disagrees.

*Why:* the products list types the pair exactly, but the edit form holds its translated fields as
`Record<string, string>` — the shape the form components share. A required `ar` rejects that call
site over a difference that does not exist in the data, and the function treats absent and empty
identically anyway. A cast would have hidden the mismatch rather than describing it.

### 3. Promotions keeps its routes while leaving the sidebar (item 13)

*Would have asked:* does "disable the Promotions item" mean the nav entry, or the feature?

*Chose:* the nav entry. Routes stay live and admin-only.

*Alternative:* remove the routes too, so the screen is unreachable by any path.

*Why:* the instruction says "the way Articles is", and Articles is a sidebar state. The engine is
built and tested, no rule exists to apply, and the standing instruction from item 9 is that no
feature stays disabled without a reason — this one is parked for a stated reason, not broken.
Removing routes would delete working, tested behaviour to achieve a presentation change, and would
take the promotions tests with it. The consequence is stated plainly rather than hidden: an admin
with the URL still gets the screen.

### 4. `wave: string` became `later: bool` rather than being left alone (item 13)

*Would have asked:* is fixing the badge in scope for "park the Promotions item"?

*Chose:* fix it, in the same item.

*Alternative:* park Promotions as asked and leave the raw-token badge for item 10's sweep.

*Why:* item 13 doubles the number of screens showing the defect, and item 10's brief is
developer-speak *messages*, which this is not — it is a data token rendered as UI. Leaving it would
have meant knowingly shipping a second sidebar row reading "coming in later". The change is four
files and removes a branch nothing could reach.

### 5. Item 11's deliverable became a guard, because there was no state to fix

*Would have asked:* the item says "fix the state and make the rule hold" — but the state is already
correct and the report came from a display defect. What is left to do?

*Chose:* fix the display defect at its source, change no data, and write the rule as a refusal in
the writer.

*Alternative:* fix the display and stop there, reporting that the rest of the item was based on a
false premise.

*Why:* stopping would have been defensible and is what the literal instruction supports. But the
diagnosis turned up something the item was not asking about: switching a brand off silently removes
it from the storefront's brand list and from the sitemap while its products stay on sale. That is a
real consequence with no warning anywhere on the screen, and "any brand with products should be
active" is exactly the rule that prevents it. Writing it as a refusal makes the sentence true going
forward instead of true until the next click.

*What I did not do:* forbid deactivating a brand that has no products. 51 of the 78 have none, and
retiring one is ordinary work.

### 6. The CSV export prints a word for a boolean, not a digit

*Would have asked:* nothing; recorded because it changes an existing export's output.

*Chose:* `Yes` / `نعم` and `No` / `لا` in the boolean column.

*Alternative:* leave the column stringifying, which now yields `1` and `''`.

*Why:* the bilingual export already follows the rule that an empty cell is information. Spending a
blank on a value that is present and simply `false` reads as "nobody filled this in" and hides the
only state the column exists to report.

### 7. The product panel is fed by the page, not by a new endpoint

*Would have asked:* should "open something with enough detail" be a fetch, so the order page stays
small?

*Chose:* send the products with the page, keyed by product id.

*Alternative:* `GET /manage/orders/{order}/items/{item}/product`, fetched when the panel opens.

*Why:* an order has a handful of lines, so the payload is bounded by the order rather than by the
catalogue. The endpoint version brings a route to authorise, a loading state, an error state, and a
panel that can come up empty for a reason the operator cannot see — all to defer four grouped
queries. If orders ever carry dozens of lines the trade reverses, and the seam is one class.

### 8. The panel shows only the specifications a product HAS

*Would have asked:* should an empty specification row be shown blank, the way the product form
shows every field?

*Chose:* drop it.

*Alternative:* render all of them, blanks included, so the panel's shape is the same every time.

*Why:* the form exists to FILL fields, so a blank there is the work. The panel exists to CONFIRM a
product, and twenty rows where fourteen say nothing bury the six that identify it. The panel names
the product screen as the place a missing specification is fixed.

### 9. A hex the catalogue does not know keeps its hex

*Would have asked:* should an unmatched colour fall back to the nearest catalogue colour?

*Chose:* no — show the hex, the swatch, and the words "colour not in the catalogue".

*Alternative:* nearest-match by colour distance, so every line reads as a word.

*Why:* `#1E3A5E` is one digit from `#1F3A5F`, which this catalogue calls أزرق. A packer reading
"أزرق" for a colour the catalogue never had is being told something false about the box in front
of them, and the error is invisible. An unknown colour is a real fact and a small amount of work for
somebody; rounding it hides both.

### 10. The pre-switch gates got their own switch instead of the write-switch flag

*Would have asked:* "open every gated feature" — does that mean flip `CORE_WRITE_SWITCH_COMPLETED`?

*Chose:* a separate `CORE_PRE_SWITCH_GATES`, defaulting to `warn`; the write-switch flag untouched.

*Alternative:* flip the existing flag, which opens everything in one line.

*Why:* the existing flag answers a different question — "has legacy stopped writing?" — and the
answer is no. `ConversionGuard` reads it and must keep refusing, because converting a live product
to variants while legacy still writes `products.quantity` corrupts stock, and stock is not
recoverable by typing it again. The flag flip is also the permanent end of the secondary-tree
mirror. One flag cannot answer both questions honestly.

### 11. The refusal tests were kept and scoped, not deleted

*Would have asked:* nothing — recorded because deleting them would have been the quicker route.

*Chose:* every refusal test opts into `enforce` and still runs; six new tests cover `warn`.

*Alternative:* delete the refusals, since the policy changed.

*Why:* `enforce` is a supported mode and the first thing somebody reaches for when a rebuild eats an
afternoon of work. A refusal nobody tests is a refusal nobody can safely turn back on.

### 12. Renaming stayed with data-entry; deactivating did not

*Would have asked:* does "category-tree editing" include renaming a node?

*Chose:* no. Rename and "show in the menu" stay open; create, move, reorder, delete, slug and
switching a node **off** are admin-only.

*Alternative:* put the whole categories screen behind the new ability.

*Why:* renaming is data-entry's daily work and changes a word on a page. The five that moved all
change where products live or what their address is. Switching a node off is the one that looks
like a rename and is not — it takes every product under it off the storefront, which is what that
screen's own confirmation dialog already warns about.

### 13. The role rule is a separate prop from the calendar rule

*Would have asked:* should the screen just show one "you cannot do this" sentence?

*Chose:* two props, and the message always names the role reason first.

*Alternative:* fold them into `blocked` with one message.

*Why:* they are different rules with different answers and different expiry. One ends on switch
night for everybody; the other never ends for this operator. An operator told to wait for switch
night, when the real answer is "ask an administrator", waits for the wrong thing — and then finds
the control still locked after the switch, with nothing explaining it.

### 14. Over-long and malformed archived slugs are refused, not repaired

*Would have asked:* 10 archived slugs are longer than the column and 8 are malformed — truncate and
normalise them, or leave those products alone?

*Chose:* leave them, and list them as their own outcomes.

*Alternative:* truncate to 191 and strip the underscores, so the numbers look better.

*Why:* the whole point is that the URL matches what Google indexed. A truncated or normalised slug
matches nothing — the old link still 404s, and the report now says "restored", which is worse than
saying nothing. 18 products out of 7,087 is a real and reportable ceiling.

### 15. Collisions are skipped, never suffixed

*Would have asked:* 7 products want a slug another product holds — suffix them?

*Chose:* skip and report, with both product ids.

*Alternative:* `-2`, which is what the transform does for its own collisions.

*Why:* a suffix is the right answer when you are generating a slug and only need uniqueness. Here
the slug's whole value is that it is the exact string the world already has. `/ysl-perfume-2/`
satisfies the unique index and satisfies nothing else. Four of the seven are genuine duplicate
products, which is the client's data to reconcile, not this command's to paper over.

### 16. No 301s were written for the restored slugs

*Would have asked:* the placement screen writes a 301 on every slug change — should this?

*Chose:* no redirects at all.

*Alternative:* a 301 from each derived slug to each restored one, for symmetry with the screen.

*Why:* the derived slug has never been served to anybody. The Brand Fashion storefront is not live,
so nothing links to it and nothing is indexed under it. 2,975 redirects from URLs that have never
existed are 2,975 rows that can only become wrong — and the first person to read them would
reasonably assume those URLs once worked.

### 17. Collapse state lives in sessionStorage, not in component state

*Would have asked:* should the fold state survive a save?

*Chose:* `sessionStorage`, keyed per storefront.

*Alternative:* component state (simplest), or `localStorage` (most persistent).

*Why:* every mutation here is a full Inertia visit, so component state resets on every rename,
reorder and toggle — the feature would appear broken the first time anybody used it alongside an
edit. `localStorage` goes the other way: an operator returning tomorrow should see the whole tree,
not yesterday's half-folded view of it. Every read and write is wrapped, because storage throws in a
private window and a category screen must not go blank over it.

### 18. The "empty" badge now reads the BRANCH, which changes an existing rule's display

*Would have asked:* the empty badge has always meant "nothing is pinned to this node" — is changing
it to "nothing in this branch" in scope for "rebuild the tree"?

*Chose:* change it.

*Alternative:* leave it, and add the branch count beside it.

*Why:* leaving it would have kept a straight contradiction on screen — "in the menu" and "empty,
hidden automatically" on the same row — and item 2's collapse would have made it the only row
visible. The badge exists to explain why a category vanished from the site, and the rule that makes
it vanish looks at the branch. The badge was answering a different question from the one it appeared
to answer.

### 19. The SEO generator composes in the browser, not on the server

*Would have asked:* should the button call an endpoint?

*Chose:* a pure function in `lib/seo.ts`, run on the current form values.

*Alternative:* `POST /manage/.../seo-suggestion`, which is where this normally lives.

*Why:* it writes from what is on screen. An operator who has just corrected the English title
expects the corrected title, and on a create form there is nothing stored to send. The endpoint
version buys nothing and brings a route, an authorisation rule, a loading state and an error state,
to compose four strings the page already holds.

### 20. Regenerating asks before replacing; generating into empty fields does not

*Would have asked:* should the button always overwrite?

*Chose:* fill silently when the three fields are empty, confirm when any is not.

*Alternative:* always overwrite (simpler), or only ever fill the empty ones (safest).

*Why:* always-overwrite destroys hand-written copy on a misclick, and only-fill-empty makes the
button useless after a title correction — which is exactly when it is wanted. The confirmation names
what will be replaced and the fields stay dirty until Save, so the operator reads what was written
before it becomes real.

### 21. The generated description carries no price and no availability

*Would have asked:* should the description mention the price, which helps click-through?

*Chose:* no.

*Alternative:* include it, as many storefronts do.

*Why:* an SEO description is served for months and is regenerated only when somebody edits the
product. The first sale would make every one of 7,700 descriptions state a price the page does not
charge — wrong in a way that is invisible from inside the dashboard and visible to every customer.

### 22. The payments switcher is built from the grant, not from the storefront table

*Would have asked:* should the switcher just list every storefront, like the catalogue screens do?

*Chose:* only the ACTIVE storefronts the acting operator's grant reaches.

*Alternative:* copy `storefrontOptions()` from the placement screen, which lists all of them.

*Why:* the catalogue screens are not grant-scoped per storefront in the same way; payments is, and
it answers 404 rather than 403 for one outside the grant, on purpose. An option that 404s teaches an
operator that the dashboard is broken, when the truth is that they do not have that permission — and
the 404 exists precisely so they cannot learn which ids are real by trying them.

### 23. Item 10's fix is one shared vocabulary, not a fix per screen

*Would have asked:* fifty-five findings — patch each screen, or centralise?

*Chose:* `lib/labels.ts`, one function per concept, and rewire every site to it.

*Alternative:* fix each render where it stands, which is smaller and touches fewer files.

*Why:* patching in place is exactly how this happened. Three copies of the bucket words already
existed and a fourth screen still printed `express`; two copies of the family words existed and the
category tree printed `watch`. The next screen that shows a provider now gets the right word without
anybody remembering to, which is the only version of this fix that stays fixed.

### 24. Every label falls through to the raw token

*Would have asked:* what should a value the map has not heard of show?

*Chose:* the token itself.

*Alternative:* an em dash, or "unknown".

*Why:* an unmapped value is a new payment method, or a family somebody added to config — real data,
and the one case worth noticing. Hiding it behind "unknown" would turn the interesting case into the
invisible one.

### 25. The CLI commands in operator copy were left alone

*Would have asked:* `php artisan manage:role` and `media:prune` appear in sentences on the users and
gallery screens — do those count?

*Chose:* left, and listed.

*Alternative:* reworded them all, since a data-entry operator cannot run a command.

*Why:* they are not leaked column names; they are references to real operations, in sentences whose
audience is arguably an administrator. Whether those sentences should be shown to data-entry at all
is a decision about audience, and the developer is the one who knows what they want their team
reading. A sweep should not quietly answer that.

### 26. Articles are core-owned tables, not the legacy `blogs`

*Would have asked:* the legacy `blogs` tables exist — should articles use them?

*Chose:* new `core_blogs` + `core_blog_translations`.

*Alternative:* write to `blogs` and add the missing columns.

*Why:* the legacy table has no slug, no published flag and no SEO fields — all three were named in
the request — and core may not write legacy tables at all; the connection is read-only, so the ALTER
would be refused by the server. Adding columns to a table the legacy application reads with
`select *` is also how you break it silently. The tables are empty, so nothing was lost by starting
clean.

### 27. `published_at` is a timestamp, not a boolean

*Would have asked:* nothing — a schema call, recorded because it shapes what the screen can say.

*Chose:* a nullable timestamp.

*Alternative:* `is_published boolean`.

*Why:* a boolean answers "is it live"; a timestamp answers that AND "since when", which is what
somebody asks when an article turns up in search results. NULL is unambiguously "never published"
rather than "published on a date nobody recorded", and scheduling becomes possible later without a
migration.

### 28. The articles screen says it is not served yet

*Would have asked:* should the screen mention that the storefront does not render articles?

*Chose:* yes, in an alert at the top.

*Alternative:* ship the dashboard half quietly, since that is what was asked for.

*Why:* somebody will publish an article and go to look for it on the site. Finding nothing, with no
explanation, reads as a broken feature rather than an unbuilt one — and the person who then reports
it as a bug spends an hour before anybody says "that part does not exist yet".

### 29. The end-to-end inspection is a route-table walk, not a hand-written list

*Would have asked:* nothing; recorded because it is how the "inspection" was actually done.

*Chose:* enumerate every `manage.*` GET route at runtime and open each one.

*Alternative:* list the screens by hand, which reads better in a test file.

*Why:* the failure mode of a sixteen-item run is the screen added last, and a hand-written list is
written by the same person who forgot it. Walking the route table means a screen joins the sweep by
existing — which is exactly how `manage.blogs.*` and the three new payment routes got covered
without anybody adding them.


## Item 16, second pass — the login screen rejected and rebuilt

The developer looked at the redesigned sign-in page and turned it down on three counts:

> Visually it's empty and cold. A small white card floating in a huge white page, nothing like the
> dashboard behind it. […] The error is too long — five bullet points and a paragraph explaining why
> we don't say which field is wrong. Nobody reads that at 9am. […] Add a show-password toggle.

All three were right, and the first was the one worth understanding. The card was not badly made; it
was the wrong idea. A card exists to separate its contents from a surrounding context, and there was
no context on that page — so the border was drawing a box around the only thing on the screen, which
is exactly what "a lost dialog" means.

**The panel is the sidebar's own surface.** `bg-zinc-900 text-zinc-100`, taken verbatim from
`Sidebar.tsx`, on the reading-start side of a `lg:grid-cols-[1.05fr_1fr]` split — which is where the
sidebar itself appears one second later. The accent is `--brand`, because that is already what an
active nav item, a checked switch and a selected row are. Nothing here is a new colour: the
instruction was *"take the look from the dashboard's existing design tokens — don't invent a second
visual language"*, and the only addition is a wash built from `hsl(var(--brand))` rather than a hex
value, so a change to the token moves this page with it.

The submit button stays near-black. `--primary` is zinc, and every primary button in this dashboard
is that colour; making this one blue because blue is "the brand colour" would have been the second
visual language the developer asked me not to invent.

**Two things were wrong in the first build and only visible rendered.** Both are recorded because
both were found by looking, not by reading the source:

* *The wash was in the wrong corner.* A `background-position` percentage is measured from the left
  edge whatever `dir` says, so on the Arabic page the glow sat in the corner furthest from the mark —
  the logo in near-black, the colour alone in an empty corner. Mirrored explicitly from `dir`.
* *The reveal button sat on top of the password.* `end-0` on the button resolved to the LEFT in the
  RTL page while `pe-10` on the `dir="ltr"` input reserved its space on the RIGHT, so the eye covered
  the first characters. Only visible with the password revealed, which is the one state that has
  characters there to collide with. Fixed by giving the button's wrapper the same `dir="ltr"` as its
  content, so the two agree.

## What I decided and why — second pass

### 30. The security reasoning moved into the code, not out of existence

*Would have asked:* nothing — the developer answered it. *"Everything about not revealing which
accounts exist is correct behaviour, but it doesn't need explaining on screen; keep the reasoning in
the code comment where it belongs."*

*Chose:* one sentence (`auth.failed`) plus one hint, and the anti-enumeration argument as a comment
block in `Login.tsx` pointing at the test that pins it.

*Alternative:* keep a shortened version of the explanation on screen.

*Why:* the property has to survive, and a paragraph nobody reads does not protect it — the thing that
protects it is `LoginScreenTest` asserting that an unknown address and a wrong password produce the
same sentence. The screen was carrying the reasoning where it cost an operator time; the test carries
it where it costs a future change the build.

### 31. The one surviving hint is about the keyboard, because there is now a control for it

*Would have asked:* which of the five bullets to keep.

*Chose:* the keyboard-layout one.

*Alternative:* the "ask the administrator for permission" bullet, which names a real third case.

*Why:* a hint earns its line by being actionable from where it is read. The permission case already
gets its OWN message from the server (`auth.no_dashboard_access`), so printing it under the generic
refusal describes a situation the reader is not in. The keyboard hint now points at a button three
centimetres below it — and the developer's own reason for wanting that button was
*"they will be typing an Arabic-keyboard-layout password wrong at least once a week"*.

### 32. The reveal toggle is a `Field`, not a new input

*Would have asked:* nothing.

*Chose:* `PasswordField` composes the existing `Field` shell and draws the eye inside the control
`Field` already asks for.

*Alternative:* add a `reveal` prop to `TextField`, or build a standalone password input.

*Why:* `Field` owns the label, hint, error and the `aria-describedby`/`aria-invalid` wiring that make
the three read as one thing to a screen reader. A standalone input would have been the fourth copy of
that code. A `reveal` prop on `TextField` would put an eye-button branch in the component every plain
text box in the dashboard goes through, to serve one screen.

### 33. The password stays revealed after a failed attempt

*Would have asked:* whether to reset the toggle when the form comes back with an error.

*Chose:* clear the value, keep the toggle where the operator put it.

*Alternative:* re-hide on every submit, which is the more cautious default.

*Why:* they pressed it deliberately, and the failure is the moment they most want to see what they
are typing — re-hiding would undo the request at exactly the point it was made. The value is cleared
either way, so nothing is left on screen that was not already visible a second earlier.

### 34. The three lines on the panel are descriptions, not a sales pitch

*Would have asked:* what the panel should say.

*Chose:* orders, catalogue, stock — three true statements about what is behind the door.

*Alternative:* a tagline, or nothing at all.

*Why:* the panel needed *"something to hold the eye"* and a tagline on an internal tool read to nobody
but the four people who work here would be embarrassing. These three are accurate, they are the three
things the dashboard is actually for, and they are dropped at phone width where the panel is a header
band and they would be noise.


## Item 16, third pass — the panel rejected and replaced

The split layout survived; what was drawn inside it did not.

> Drop the dotted-grid-and-navy-gradient panel — that pattern is on every admin template on the
> internet and it's tired. […] deep black, no pattern, no gradient mesh, no dots. The logo large and
> centred in the space, generous emptiness around it, and nothing else competing with it. Luxury
> watch brands sell with black and empty space, not decoration — that's the language to borrow.

That is a better brief than the one I was working to. The second attempt reached for a gradient mesh
and a dot grid because the panel felt empty, which is the reflex that produces the template look:
decoration hired to do the job a point of view should have done. The shop sells watches, and a watch
is photographed on black with room around it and nothing else in the frame. Borrowing that is both
more specific and less work.

**What the panel is now:** `bg-black`, the mark at `lg:h-36` centred in the column, `branding.suffix`
under it in `zinc-500`, and nothing else. No gradient, no texture, no second heading — the mark
already carries the wordmark, so setting the business name under it would be the same word twice.

**The tie between the halves** is a single `--brand` hairline at the seam: the panel's `border-e` at
desktop width, its `border-b` when the two stack. Spent once, in one place, which is what makes it
read as a decision rather than a theme.

**The three feature lines were cut**, after being shot both ways. Demoted to small muted text at the
foot of the panel they did not read as a deliberate quiet layer — they read as something left behind,
because a black field with one object in it has no room for a second thing that is only nearly
invisible. `auth.panel_orders` / `panel_catalog` / `panel_stock` were deleted with them. Restoring
them is a small revert if the developer disagrees.

## What I decided and why — third pass

### 35. Black, not the sidebar's `zinc-900`

*Would have asked:* whether "deep black" meant the literal colour or the dashboard's dark surface.

*Chose:* `#000`.

*Alternative:* `zinc-900`, which is what the sidebar uses and what the previous pass argued for by
consistency.

*Why:* the argument for `zinc-900` was that the login should echo the surface the operator is about
to see, and it is a good argument when the surface is carrying navigation. Carrying nothing but a
logo and half a screen of space, `zinc-900` reads as a grey that could not commit — and the developer
named the reference themselves. The consistency that matters here is with the brief, not with a
sibling screen's background.

### 36. The seam is a border on the panel, not a positioned hairline

*Would have asked:* nothing; recorded because the first attempt silently drew nothing.

*Chose:* `border-b border-brand … lg:border-b-0 lg:border-e`.

*Alternative:* the absolutely-positioned 1px div I wrote first.

*Why:* that div mixed `inset-x-0` (physical `left`/`right`) with `end-0` (logical
`inset-inline-end`), and which of them wins comes down to the order two unrelated rules land in the
stylesheet. It rendered nothing and looked correct in the source. A border cannot have that argument
with itself, and `border-e` is the panel's inner edge in both directions because the panel is always
on the reading-start side.

### 37. The reveal button is positioned PHYSICALLY, and the guard was right

*Would have asked:* nothing. `RtlTableGuardTest` asked it for me, by failing.

*Chose:* `right-0` on the button and `pr-10` on the input — physical, in both languages.

*Alternative:* `dir="ltr"` on the wrapper div, which is what I wrote first and which made the eye
line up correctly.

*Why:* the guard forbids `dir` on a block element because that is the mistake that misaligned every
table on the dashboard home — `text-align: start` resolves against the box's OWN direction, so a div
that declares itself LTR left-aligns while its label stays right. My wrapper was that exact shape,
and it failed the battery. The real answer is that nothing here needs to turn around: the `<input>`
is permanently `dir="ltr"`, so the button belongs where that fixed text ends, which is the physical
right, whatever the page is doing. A logical property was not just unnecessary — it was the bug, in
both of its attempts.

### 38. The narrow window was checked with an iframe, because the browser would not resize

*Would have asked:* nothing; recorded because it is a limitation of this machine worth knowing.

*Chose:* render the page in a 390×844 iframe and measure it.

*Alternative:* report the phone layout as unverified, which is what I nearly did.

*Why:* `resize_window` returns success and the viewport stays at 1740 — the window is snapped, and
two attempts changed nothing. An iframe has its own viewport, so the media queries resolve against
390px for real rather than by inspection. It confirmed the band is 253px, the mark 80px, the seam
moved to the bottom edge, the page fits in 844px with no scroll, and there is no horizontal overflow.


## The copy sweep — messages that explain the interface

The login error lost its last remaining line, and the rule that removed it was then applied to the
whole dashboard.

> That's not an error message — it's a usage tip that assumes the operator is stupid, and it's the
> kind of copy that makes software feel amateur. The eye icon is right there; it doesn't need
> instructions. […] A message earns its words; if a line isn't telling them something they don't
> already know, it goes.

**The rule, stated so it can be applied:** a message says WHAT HAPPENED and what is true. It does
not describe the screen to the person already looking at it, it does not narrate a control they can
see, and it does not walk them through steps the interface performs by existing.

**The login is now six lines of text in total:** the panel caption, the heading, two field labels,
the button, and one note about which accounts to use. The refusal is one sentence. Two more lines
went with the hint — `auth.sign_in_hint` ("sign in with your email and password to continue"), which
described the two labelled boxes beneath it, and half of `auth.staff_only`, whose "staff sign-in
only" told nobody at `/manage/login` anything. The half that survived — *"the same accounts used in
the current dashboard"* — is real information during the migration, and is the whole note now.

**The sweep** pulled all 1,870 strings the seam can show, from `t()` in the React files and
`ManageText::t()` in PHP, and filtered them for the phrases that give interface narration away:
اضغط, انقر, الزر, أيقونة, من هنا, بالأعلى, هذه الصفحة, هذه الشاشة, يمكنك, استخدم, الخانة, القائمة
الجانبية. That produced 43 candidates, of which **19 were cut or trimmed and 24 were left alone.**

The 24 that stayed are the ones that pass the test: the pre-switch rebuild warnings, the slug rules
(a blank box auto-generating a link is not visible anywhere), `payments.merged_order_help` (which
contract receives the money), `shipping.reads_four_fields`, `units.intro`, `media.intro`, and the
legacy-account constraints on the users screen. Every one of those tells an operator something the
screen cannot show them.

What went:

| Message | What was cut |
|---|---|
| `home.abilities_note` | the whole line — "the sidebar shows only what you can reach" |
| `home.catalogue_shared` | "the numbers below break that down per storefront" |
| `inventory.recon_failed_body` | "read the report below exactly as it stands" |
| `inventory.recon_report_verbatim` | "so the screen, the terminal and the runbook all say the same thing" |
| `products.visibility_needs_arabic` | "write it in the Title — Arabic box above" |
| `products.primary_category_must_be_chosen` | "tick the category first" |
| `products.seo_hint` | "all of them are in that storefront's section above" |
| `products.primary_category_decides_family` | "— which specification fields you see on this page —" |
| `payments.write_only_body` | "there is no button to reveal one", and the sentence describing what the screen does show |
| `placement.slug_admin_only` | "the rest of this screen — visibility, order, featured and categories — is open to you" |
| `profile.grants_note` | "and cannot be changed here" |
| `customers.read_only_notice` | "this screen is read-only" |
| `promotions.sample_cart_intro` | "search for real products and build a cart, then press Try it" |
| `blogs.delete_consequence` | "from here" |
| `orders.cancel_consequence` | "from this screen" |
| `orders.cancel_after_delivery` | "from this screen" |
| `table.export_forbidden` | "the screen itself is fully open to you" |
| `users.account_not_found_hint` | "this screen only grants permissions" |
| `auth.staff_only` | "staff sign-in only" |

## What I decided and why — the sweep

### 39. "From this screen" was removed rather than kept as a hedge

*Would have asked:* whether "cannot be cancelled from this screen" means it can be cancelled
somewhere else.

*Chose:* drop the qualifier. "An order that has reached the customer cannot be cancelled. Handle it
as a return."

*Alternative:* keep it, on the grounds that it is technically narrower and therefore safer.

*Why:* it was not narrower, it was vaguer. The refusal lives in `OrderFulfilment`, so there IS no
other screen that will do it — the phrase implied an escape hatch that does not exist, and sent a
careful reader looking for one. Same for the two "cannot be undone from here" lines: nothing undoes
them anywhere, and saying "from here" invited somebody to go hunting.

### 40. Three long explanations were rewritten, not just trimmed

*Would have asked:* whether to cut clauses or rewrite the message.

*Chose:* rewrite `payments.write_only_body`, `inventory.recon_failed_body` and
`table.export_forbidden` from the fact outwards.

*Alternative:* delete the offending sentences and leave the rest standing.

*Why:* each had its real content buried behind the narration. The payments one spent its first
sentence and its last on describing the screen, and the thing an operator actually needs — that a
blank box keeps the stored key rather than clearing it — was in the middle. Cutting the ends would
have left the right words in the wrong order; the rewrite puts the non-obvious fact first.

### 41. Twenty-four candidates were left alone, and that is the point of a sweep

*Would have asked:* nothing.

*Chose:* cut 19 of 43.

*Alternative:* cut everything the filter matched, which would have been faster and looked more
thorough.

*Why:* the filter finds the WORDS, not the offence. "استخدم JPG أو PNG أو WebP" contains استخدم and
is exactly the kind of line the rule protects: an operator whose upload was refused does not
otherwise know which formats are accepted. A sweep that deleted it would have been applying the
grep, not the rule.


## The pre-switch notices — removed

> These pre-switch notices need to go — all of them. I told you we're effectively at the switch and
> to open everything; the gates were opened but the warnings stayed, and they're now telling the
> team not to do the exact thing I want them doing.

That is exactly what item 5 left behind, and it is worth naming as a mistake rather than as a
change of plan. Item 5 opened every gate and then argued, at length and in this file, that each
opened control should keep its sentence: *"opening a gate and removing its sentence would be the
worst of both."* The reasoning was sound for a team rehearsing. It was wrong for a team working. A
warning that contradicts the instruction the team was given does not make them careful, it makes
them hesitate over the work they were told to do.

**What went.** Every screen banner, every caveat, and the machinery vocabulary in the refusals that
are left:

| Where | What it was |
|---|---|
| products list, product form, placement, units | the `pre_switch_notice` banner — removed from `PreSwitch`, from four controllers and from four screens, prop and all |
| the slug field | `slug_caveat_pre_switch` — the link you type now will be replaced |
| every create control | `pre_switch_create_caveat` — the row you add now will be deleted |
| storefronts | `rebuild_warning` — named AGENTS §2.20 and switch night |
| product form | `family_rule_note` — named `config/transform.php` |
| users | `revoke_self_refused` — named `php artisan manage:role revoke` |
| categories | the legacy badge and the delete refusal — "a rebuild will bring it back" |
| the refusals themselves | `pre_switch_create_blocked` named `core:drop-clean`, `migrate` and `core:transform`; `slug_locked_pre_switch` and `secondary_tree_mirrored` explained the rebuild |

The four refusals were kept but rewritten, because they only render with the gates set to enforce —
which is not how the dashboard runs, but a refusal is on an operator's screen when it renders, so it
obeys the same rule. Each now says what is true and what to do: *"الإضافة موقوفة حاليًا … لو احتجت
إضافة، اطلب ذلك من المدير."*

**The sweep was mechanical, over all 1,857 strings the seam can show**, looking for ليلة التحويل,
قبل التحويل, إعادة البناء, `core:`, `migrate`, `drop-clean`, `artisan`, `config/` and AGENTS
references, then the same on the English side. What is left matching "النظام القديم" is six strings
that use it as a SOURCE LABEL for data — "account type in the old system", "these units came from
the old list" — not as a warning about a rebuild. Those are facts an operator needs about where
their data came from, and they stay.

### The two I judged still necessary — disagree with either

**1. The category tree of a storefront that follows Watchizer.** Kept, rewritten, on the Brand
Fashion categories screen only:

> تصنيفات هذا المتجر تتبع تصنيفات واتشيزر حاليًا: أي تعديل هنا سيعود إلى ما هو مضبوط هناك. عدّل
> التصنيفات من متجر واتشيزر ليظهر التعديل في المتجرين.

*Why:* this is not a warning about a rebuild, it is two dashboards writing the same tree right now.
`syncsSecondaryTrees()` is still true, both storefronts are active, and an operator who renames a
category here and watches it revert has no way to find out why — the other writer is not on their
screen. It names no machinery and no switch night, and it removes itself when the mirror stops.

*Disagree if:* the transform is no longer being run against this database. Then nothing overwrites
anything, and this note should go with the others. That is the one fact I cannot check from here,
and it is the only thing holding this sentence up.

**2. The variant conversion refusal.** Kept, rewritten:

> لا يمكن تحويل هذا المنتج إلى مقاسات الآن: مخزونه ما زال يُخصم من مكان آخر، والتحويل سيجعل الرقمين
> يختلفان دون أن ينتبه أحد. لو احتجت منتجًا بمقاسات، أنشئه بمقاسات من البداية.

*Why:* this is the one gate item 5 deliberately did not open, and the reason has not changed. Every
other pre-switch rule protected an afternoon of typing, which is recoverable by typing it again.
This one protects the stock figures: the live storefront decrements `products.quantity` directly,
a converted product's stock lives in the ledger, and after the conversion nothing reconciles the
two. The failure is silent and permanent. It is a refusal, not a notice, and it now says so without
mentioning the switch.

*Disagree if:* the legacy storefront has stopped selling. Then `CORE_WRITE_SWITCH_COMPLETED` is the
flag to flip, and this refusal turns itself off — along with the tree note above.

## What I decided and why — the pre-switch sweep

### 42. The notice prop was deleted, not left returning null

*Would have asked:* whether to switch the banners off or take them out.

*Chose:* delete `PreSwitch::noticeFor()`, the prop in four controllers, and the render site plus the
prop type in four screens.

*Alternative:* have `noticeFor()` return null, which is two characters and cannot break anything.

*Why:* a null-returning method with four call sites and four typed props is a feature that still
exists and is merely quiet, and the next person to read it will reasonably assume it is meant to
come back. The tests now assert the prop is ABSENT rather than empty, which is the difference
between removed and switched off.

### 43. The refusals kept their voice but lost their vocabulary

*Would have asked:* whether dormant refusals were in scope at all.

*Chose:* rewrite all four, even though they only render when `CORE_PRE_SWITCH_GATES=enforce`.

*Alternative:* leave them; nobody sees them.

*Why:* "nobody sees them" is a statement about a config value, not about the code. The instruction
was that nothing on an operator's screen names a developer command, and these render on an
operator's screen under a setting that exists precisely so it can be turned on.

### 44. A test that asserted the caveats now asserts the silence

*Would have asked:* nothing.

*Chose:* keep `PreSwitchTest`'s caveat test, inverted, renamed *"it says NOTHING about the rebuild
on a screen whose controls work"*.

*Alternative:* delete it, since the behaviour it guarded is gone.

*Why:* the failure mode here is a caveat quietly coming back, and it would come back looking like a
helpful addition — somebody notices an open control with no warning and adds one. Inverting the test
puts a tripwire exactly where the old reasoning was persuasive enough to be written down twice.

### 45. I broke UnitController with a regex and rebuilt it rather than reverting

*Would have asked:* nothing; recorded because it nearly cost work that is not in git.

*Chose:* the `.*?` in my removal pattern ran under `re.S` and ate 82 lines — the class docblock and
the whole of `index()`. I rebuilt the file from the committed version plus the one uncommitted
change that had lived inside the deleted region (item 10's `columnLabel()` call in the CSV export),
then re-applied the intended deletion by hand.

*Alternative:* `git checkout --` that file, which would have been faster and would have silently
thrown away item 10's change, since it was never committed.

*Why:* recording it because the near-miss is the lesson, not the fix. `git diff` showed the file at
+24/−3 afterwards — the helper, its call, and the three lines I actually meant to remove — which is
how I checked the reconstruction rather than assuming it. A multi-line regex with `re.S` over a
whole source file is now on the list of things to write as an explicit line-range instead.


## Field rules — matched to the legacy form, with visibility as the lever

The legacy product form is the authority, and it disagreed with everyone's memory in four places:
low-stock threshold, primary category, colours and search keywords are all `nullable` there. What it
actually demands, and what each rule would have cost against the live 7,713:

| rule | fails today | applied as |
|---|---:|---|
| `title.ar`, `title.en` (min 2) | 0 | required |
| `brand_id`, `selling_price`, `wa_code`, `is_active` | 0 | required |
| `purchase_price` | 0 | required PRESENCE only |
| main image | 26 | visibility gate |
| gender | 837 | visibility gate |
| `long_description.en` | 115 | visibility gate |
| `short_description.ar` / `.en`, `long_description.ar` | **7,087** | visibility gate |

`purchase_price` has no `min:0.01` on purpose: 7,087 rows carry `0.00` because the import could not
supply one, and a positive minimum would lock every one of them out of every edit.

`title.en` is worth a line of its own. It had been left nullable on the belief that thousands of
imported rows could not satisfy it — and the data says otherwise: **every one of the 7,713 has a
non-blank title in both languages**, zero exceptions. What is true is that 7,087 Arabic titles are
machine-translated (`is_machine = 1`), which is a quality flag and not an absence. The trap was real;
it just did not apply to that field.

### Why the four descriptions gate visibility instead of blocking the save

> *"I want the team to finish a product properly when they touch it, but a rule that refuses the save
> punishes whoever is fixing something rather than whoever left it incomplete."*

So the save always lands and completeness decides whether the product may be SEEN — in both
directions, because they are the same rule: an unfinished product cannot be switched on, and one
that is on and LOSES a field goes off that same save. The demotion is announced on the error channel
beside the green "saved", because *"a product with no description has no business being on sale"* and
a disappearance nobody is told about is the thing that must never happen.

### The one asymmetry, and why it must survive tidying

The product form DEMOTES. The placement screen REFUSES. Same product, same request, two answers —
which reads like an inconsistency somebody should clean up, and it is not.

The reasoning above only applies where the operator is doing something else and visibility changes
underneath them. On the placement screen visibility is the only thing that screen does: nothing else
is being punished, and a toggle that silently springs back is worse than one that says why.

`PreventionTest > it DEMOTES on the product form but REFUSES on the placement screen — and that
difference is deliberate` pins both halves in one test, so unifying them breaks the build rather
than quietly breaking an operator's day.

## The full-replace audit

`PUT /manage/storefronts/{s}/products/{p}` is a full-REPLACE contract: an omitted key is CLEARED.
That was already known and `ProductPatcher` exists for exactly this reason, with the cost measured in
its own docblock. What changed on 2026-09-18 is the consequence — a payload that drops the
descriptions now also takes the product off the storefront — so every caller was re-checked.

**Production callers: three, all safe.**

| caller | why it is safe |
|---|---|
| `ProductController::update()` | the form sends every field, and `FullReplace::assert()` refuses a payload that does not declare itself complete |
| bulk activate / deactivate | goes through `currentPayload()`, which rebuilds every scalar AND every translated column from the row before overlaying the change |
| `ProductController:1286` | same `currentPayload()` |

The collections — `gender_ids`, `colors`, `feature_ids`, `images` — are not in `currentPayload()`,
and that is fine rather than lucky: `writePivots()` and `writeImages()` are each guarded by
`array_key_exists`, so an omitted collection is left alone rather than cleared.

**The importer does not use this path at all.** It calls `ProductWriter::create()` only, and when a
row already matches an existing product it reports the duplicate and returns `null` — it never
touches an existing row, so it cannot blank one. There are no jobs and no console commands that
write products through either writer.

**The exposure was entirely in test fixtures**, which is what the battery found: `AdvPayload`,
`CatalogFixture` and a dozen inline payloads sent partial records through the replace endpoint and
so quietly demoted the products they were about to assert on. All now send a publishable record, and
the ones that want an incomplete product override a field rather than relying on the default being
broken.

## What I decided and why — the field rules

### 46. A tool's own formatting is not the data

*Would have asked:* nothing. Recorded because it nearly destroyed three correct rows.

*Chose:* withdraw the "three Naviforce SKUs have doubled backslashes" finding after checking `HEX()`.

*Alternative:* run the `REPLACE(sku, '\\\\', '\\')` I had already written.

*Why:* the stored values were correct — 12 and 15 characters with single `5C` bytes. `mysql -B`
escapes backslashes in its OUTPUT, and my audit script compared that escaped text against the raw
CSV, so it reported a corruption that existed only in the terminal. The UPDATE would have turned
three right answers into three wrong ones, and the audit file would have been the evidence that it
was justified. **A tool's rendering of a value is not the value**: when a comparison says the stored
data is malformed, check the bytes before believing the diff — especially when the "fix" is a write.

### 47. Lookup uniqueness was counted before being proposed

*Would have asked:* nothing; this is the developer's own instruction applied.

*Chose:* count first. Across all twelve lookup lists and both languages: **0 duplicate names and 0
blank English names**.

*Alternative:* add the rule on the strength of the argument, which is a good argument — two brands
called "Rolex" split a catalogue in half and nobody notices.

*Why:* the same reasoning that kept the descriptions out of the required list. A rule is cheap or
expensive depending on data nobody had looked at, and here it turns out to be free: legacy's
`required + unique` on both languages could be adopted exactly, with no row failing and no bulk fill.
That is worth knowing before the decision rather than after.


## Lookup names and the replace guard — both approved and applied

**All twelve lookup lists now match legacy exactly**: name `required` in BOTH languages, `min:2`,
and `unique` WITHIN a language. Counted before it was proposed — 0 duplicates and 0 blank English
names across every list — so the rule locks in what the data already satisfies instead of creating
work. Uniqueness is per language on purpose: a lookup that reads the same in both («Rolex» /
"Rolex") is ordinary, and it is two rows sharing ONE language's spelling that splits a catalogue.

The writer's own refusal moved with it: an emptied English name used to delete the row, and now it
is refused. `LookupScreenTest` pins both halves — the refusal, and that the duplicate rule fires
within a language but not across them.

**The replace guard lives on `ProductWriter`, not in a controller** — the developer's instruction,
and the right one: *"a future caller should hit it wherever it comes from."* A full-replace payload
that would blank a description on a product that is currently VISIBLE is refused, with a message
naming the fields it would have removed and saying the product would leave the storefront. It throws
a `FieldRefusal` so the message lands beside a box rather than at the top of a form whose fields all
look fine.

It is scoped to visible products deliberately. Clearing a field on a draft is an ordinary edit, and
refusing it would make a draft harder to change than a live product. `PreventionTest` pins both
sides: refused on a visible product, allowed on a hidden one.

### 48. The guard is scoped to visible products, not to "looks like a partial payload"

*Would have asked:* whether to refuse any payload that looks partial.

*Chose:* refuse only when the write would actually cost something — a VISIBLE product losing a field
it needs to stay on sale.

*Alternative:* count the keys and refuse anything that looks like a script, which is what "partial
payload detection" would really be.

*Why:* the form is a full-replace caller and always will be, so a heuristic about payload shape would
have to allow exactly what the form sends and refuse everything else — which is a rule about who is
calling, not about what the call does. Binding the refusal to the consequence means it fires for any
caller, including one nobody has written yet, and stays silent for the edits that are fine.

### 49. Three saves this round were stopped by checking before writing

*Would have asked:* nothing. Recorded because it is now a pattern rather than an incident.

The three: the `storefronts.default_locale` restore that turned out to be a deliberate setting and
not a leak; the `REPLACE()` on three Naviforce SKUs that were already correct and only looked wrong
through the mysql client's escaping; and the `UnitController` regex deletion, caught by diffing the
file against the committed version instead of assuming the edit was surgical.

The developer's summary is the rule worth keeping: **a tool's rendering of a value is not the
value** — and in each case the fix was already written when the check ran. The check is cheap. The
write is not.
