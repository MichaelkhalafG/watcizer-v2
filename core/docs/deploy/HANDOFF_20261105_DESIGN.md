# Handoff — design pass of 2026-11-05 (after the night run; everything ships together)

**Branch:** `wave-5`, nothing committed by the session (git is the developer's). **Read with:** `HANDOFF_20261105.md`
(the night run this pass builds on) and `DEPLOY_PART2_20261105.md` (the deploy record, updated by this pass).
**The brief:** the developer's message of 2026-11-05 "DESIGN PASS + DECISIONS" and its correction to decision 2
(the page-text numbers are of two kinds: the brand's own catalogue from official sources, and ours).

**Deploy:** no split. Parts A, B, 1 to 7 and this pass ship in one deploy. valU stays off until then.

Working databases: `scratch_design_20261105` (browser pass; demo rows) and a fresh clean copy for the gate.
The dev database is not written. Checkpoint outside the repo: `D:\coding\watchizer website\checkpoints\2026-11-05_design\`.

## Status

| item | state | where |
|---|---|---|
| D1 campaign pages permanent + indexable | **DONE** (code + sitemap test); seen; final captures pending | §SF2 |
| D2 page-text numbers: ours + the brand's own | **DONE** — code, 3 tests, PHPStan clean; deploy script: 10c recount dry run, C1 recount --write on production, C2 seed | §D2 |
| D3 seeder guard kept, production report | **DONE** — guard kept and tested; the deploy script prints the production report (10b: pages edited since the seed) before any text is written | |
| D4 BF domain plan: the four decisions | **DONE** — §9 of BF_DOMAIN_SWITCH_PLAN.md: four decisions, each with options, cost, a marked recommendation and an answer line | |
| X1 toggles, Hot Deals and Flash Sale identity | built and SEEN in a browser on both shops (desktop + phone, EN + AR): switch (coal / lit / white-hot in mode), flash chip, phone bar in halves, fire and storm sliders, pages, idle state; second round of fixes applied, final captures pending | |
| X2 slider colour: section and image grounds only | SEEN — cards keep white surface, text, prices, button; section + photo boxes take the colour (look/wz-blend-1440.png); final captures pending | |
| X3 phone designs (360 / 390 / 430) | SEEN at 360 / 390 / 430; final captures pending | §SF2 |
| X4 brand banner | SEEN desktop + phone, EN + AR (look/bf-bb-1440, wz-bb-390, wz-bb-1440-ar); final captures pending | |
| X5 Campaign hero and Banners block together | **DONE** — core, dashboard, both storefronts, 3 new tests; seen in a browser; final captures pending | §X5 |
| X6 the rest reviewed against the references | not started | |
| G gates, measurements, screenshots, scripts | partly — PHPStan, Pint, guard tests, targeted tests green; deploy + COMMITS scripts updated; full suite, blocked builds, measurements, evidence screenshots and the deploy record still owed (§WAIT) | §WAIT |

## §X5 — the Campaign hero and the Banners block together

**The rule (as the developer wrote it):** hero on + Banners block on → the hero leads, the block is a home section at
the place set in the home ordering; no hero → the block is the first thing on the page automatically; 3D as before;
Brand Fashion the same without the Campaign option.

**How it is stored (core, done — PHPStan clean, typecheck clean; tests + storefronts still to do at this line):**
- `settings.home.hero` keeps its three values and now means "what LEADS the home": `campaign`, `three_d`, or `banner`
  (= nothing above the banners: the block is first, automatically).
- The block's place and on/off under a hero is a ROW of `storefront_home_rails`, kind `banners` (`HomeRails::BANNERS`):
  one per storefront, no target, no products; it moves with the same arrows and switch as a slider row.
- Migration `2026_11_05_000005_banners_block_row` gives every storefront that row, ON and FIRST (sliders renumbered
  20, 30…). Reason: until now a shop with a hero drew its active banners straight under it; with the row first and on,
  that shop keeps showing them (after the category tiles — the first place the ordering has); a shop with no banner
  shows nothing, as before. **SIX migrations now, not five** — the deploy script's gates must say six.
- Payload: compat `catalog/home` and v2 `/home` emit the row as a rail `{kind: "banners", products|cards: []}` at its
  place — only when the hero is not `banner`. The live (old) storefront builds drop a rail without products by their
  own rule (Watchizer: 4 minimum; Brand Fashion: 2), so core-first deploy order is safe.
- Dashboard: Home sliders screen shows the row ("Banners block", count of active banners, "leads the page now" when the
  hero is `banner`); the kind is offered in "new" only when the storefront has no row. Banners screen: the hero select
  says "The Banners block (nothing above it)", and a line says whether the block is on and at which place.

**RECOMMENDATION R-X5a:** with the **3D watch** as hero the block is also a positioned section when its row is on
(same rule as under the campaign), not forced to the top. Alternative: under 3D never show the block. Reversible by
switching the row off.
**RECOMMENDATION R-X5b:** "no hero" is the existing third value (`banner`), not a fourth "nothing at all" state: a
home with neither hero nor banners is not offered.

**X5 — tests and one defect found by them (13:55).** Three new tests in `HomeZoneTest` (the migration: one row per
storefront, on, first, sliders keep their order, idempotent · the block is drawn where its row is under `campaign` and
`three_d`, on both v2 and compat, and is NOT sent when the hero is `banner` or the row is off · one row per storefront,
the kind offered only when there is none, both screens say where it stands). `LocaleSitemapTest` asserts the two
campaign pages in both languages. Existing tests taught about the row: `HomeRailsReachTheShopTest` ×2 (they swapped /
switched "the first rail", which is now the block's row on a shop where the block leads) and `HomeAndRelatedTest` (every
rail had to have cards; the block's rail has none by design).
**Defect found by the existing hero test:** I had remembered the hero mode on the `CompatHome` object; that object
outlives a request in the suite (and would under a long-lived worker), so a hero switched on the dashboard was not seen
by Watchizer's compat home. Fixed: the hero is read once per build and handed to `rails($hero)`; nothing is kept.
Targeted run on the clean gate copy (`scratch_gate_20261105`, now with migration 000005): 65 tests, 62 passed, 3 failed
— all three are baseline entries (`CatalogHomeTest` ×2, `HomeRailScreenTest` reorder: production's rows). PHPStan clean.

## §SF2 — the storefront design pass (seen in a browser, 13:30–13:45)

Look captures (working shots, not the evidence set): `checkpoints\2026-11-05_design\look\`.
- **Hot Deals = FIRE, Flash Sale = STORM** — identities of their own in both shops (promo.css), not the shop palette.
  Switch: a coal with a burning rim when off, alight (gloss, sheen, glow) when on, white-hot on a burning header.
  Flash chip: storm-blue capsule, yellow bolt that strikes. Sliders, pages and Hot Deals mode wear the same two looks.
  All motion is CSS on transform/opacity and is off under `prefers-reduced-motion`.
- **Phones:** the header bar is two halves (switch | flash link), 48 px tall, name over time — not two squeezed pills;
  the Hot Deals slider is a banner + cards + a full-width button; the Flash slider puts "View all" as a bar under the
  cards; the campaign page stacks stats 2-up and the countdown as a full-width row; the brand banner is a poster
  (words, slanted seam, shallow fan, button bar); the mega menu is `MegaDrawer` (twin) inside each shop's drawer:
  picture rows → an entry opened in place with picture tiles, brand chips, featured card. Seen at 360, 390, 430.
- **X2:** a slider's colour paints the section and (blend on) the photo boxes; cards keep their surface, text, prices
  and buttons. The heading on the section takes ink-or-white from the colour (`--hl-ink`).
- **Idle campaign page:** the hero unlit (coal / dim storm), the page's name as H1, "No … is running right now", what
  the page is for, "Browse the shop", and the shop's real top categories as links. No countdown, no product.
- **Removed:** the campaign colours from each shop's `globals.css` (`--pm-flash-*`, `--pm-hot*`); the legacy
  "banners straight under the hero" block in `HomeClient.jsx` (now the row decides).
- **RECOMMENDATION R-SF2a:** both shops share the two campaign identities (Watchizer's Flash Sale is no longer its
  gold-on-champagne). Alternative: per-shop palettes again — the tokens `--pm-fire-*` / `--pm-volt-*` / `--pm-storm*`
  can be overridden in a shop's `:root`.

## §PREVIEW — servers left RUNNING for the developer's look (started 13:57–14:00)

**Running on purpose until the developer says he is done:** mysqld (:3307, standalone), the scratch core
(`php -S 127.0.0.1:8020`, database `scratch_night_20261105`), Watchizer (`next start` :3000), Brand Fashion (:3001).
Production builds, pixel ids blank. **While they run:** no storefront build (it needs the servers stopped), no core
source edit that changes a screen he is looking at (php -S serves the working tree), no full suite. When he is done:
continue the design pass, and at the end of the run stop everything
(`mysqladmin -u root -h 127.0.0.1 -P 3307 shutdown`) and prove it.
The scratch admin's login is in the scratchpad file `dash-cred.json` (never printed).

## §D2 — the page-text numbers: two kinds, fixed (state at 14:00)

- **Mechanism (core, written, PHPStan not yet re-run, tests not yet rewritten):** `SeoFacts` rebuilt. The `{facts}`
  token becomes an "in numbers" section built ONLY from two content files:
  `database/seeders/content/seo/facts.json` (OUR catalogue, per storefront and page; written by the new command
  `php artisan seo:count-facts [--write]`, with `counted_on`, `counted_from` and a `method` block) and
  `database/seeders/content/seo/brand_sources.json` (the BRAND'S OWN figures, each with `source` URL, `how`, and the
  brand's `checked_on`; `left_out` lists what was not printed and why). The seeder no longer reads the database for
  numbers; a page without numbers is reported ("no numbers for …").
- **Ours:** counted as every real catalogue product the page lists on that storefront, in stock or not (the page's own
  listing without the in-stock filter). Checked against independent SQL: Rolex 95 = 95 (92 in stock), Tommy Hilfiger
  442 = 442 (428), Joyroom 72 = 72 (0 in stock). A category's total is the sum of its brands' counts (watches: 664 =
  664 listed, 12 brands). 86 pages counted (43 per shop); recorded from `scratch_gate_20261105`, whose newest product
  is of 2026-10-15 — **so the deploy must recount on production before seeding** (post-deploy step).
- **The brands' own (researched 2026-11-05, official sources only):** 10 of 19 brands have sourced figures —
  Rolex (18 model families, rolex.com/watches, read in a browser), Patek Philippe (9 wristwatch collections), Audemars
  Piguet (7 collections; movements; size bands; materials), Hublot (site groups; Unico 2009 / 330 components; materials —
  hublot.com + lvmh.com), Tissot (since 1853, Le Locle — swatchgroup.com; lines and movements named on its site, no
  count), NAVIFORCE ("more than 1000 models"), Emporio Armani, Tory Burch, GUESS (names only, no counts), JOYROOM.
  **9 brands have NO brand figure printed:** Tommy Hilfiger, BOSS, Calvin Klein, Coach, Lacoste (sites answered HTTP
  403 to the reader), Michael Kors, Pinko, Just Cavalli (redirects not read), Armani Exchange (certificate error) —
  each is in the file with the URL to read by hand. No third-party number is used anywhere.
- **Still to do for D2:** rewrite the facts test in `PageTextsTest`, add the command's tests and a guard test over
  `brand_sources.json` (every printed figure has an https source and a date), PHPStan, update the `_about` notes of
  brands.json / categories.json, deploy-script step (recount on production, dry run then `--write`, before seeding).

## §WAIT — state while the developer looks at the preview (14:45)

**Done and tested while the preview runs (nothing a screen of his depends on was changed):**
D2 tests (PageTextsTest 9/9 on the gate copy), D4 (the four decisions in `BF_DOMAIN_SWITCH_PLAN.md` §9),
PHPStan `[OK] No errors`, Pint, guard tests (StorefrontTwins, ApiAllowList, ServerTranslationCoverage, RtlTableGuard:
17 passed), `TWINS.txt` (+ `MegaDrawer.jsx`, dated note), the deploy script
(`deploy-scripts\deploy-part2-20261105.sh`: SIX migrations, pre `[false×5,0]`, post `[true×6,6,"campaign",true]`,
10b edited-pages report, 10c `seo:count-facts` dry run, 10d seed dry run; apply steps A 000092 → B valU → C1 recount
`--write` → C2 seed; `bash -n` OK), the COMMITS generator and script (217 files, 10 commits — REGENERATE at the end).

**Still owed after he says he is done (in this order):**
1. his feedback, if any, on the design;
2. X6: review the pieces still in last night's version (desktop mega menu panel, payment badges, home category
   tiles, the large banner, the questions block of the page texts) and raise them;
3. the final builds with Google's font hosts blocked (`build.ps1 -App wz|bf -Block`), unit tests (vitest) and ESLint
   for both apps, `npm run typecheck` + `npm run build` in core;
4. the full suite through `tests/baseline/suite-baseline.sh` on a CLEAN copy (`scratch_gate_20261105` is clean and
   migrated to 000005; expect 31 failed, all baselined, 0 new) — servers stopped first;
5. measurements: memory at 128M on Brand Fashion's catalogue (home, menu, campaigns; `measure_part2.php`), LCP / CLS
   on both homes (`perf.mjs`) — the hero, sliders and menu changed;
6. the evidence screenshots, 1440x900 and 390x844, EN and AR, into
   `checkpoints\2026-11-05_design\screens\` with last night's set copied beside them as `screens-before\`
   (`gen_jobs.py 45 47`, plus jobs for: idle campaign page, phone bar, phone drawer, Banners block as a section,
   dashboard Home sliders with the Banners block row);
7. `DEPLOY_PART2_20261105.md` updated (six migrations + rollback SQL for 000005, the design pass, the numbers,
   the RECOMMENDATIONS R-X5a/b, R-SF2a), COMMITS regenerated, final checkpoint, everything stopped and proven.

# ROUND 2 — the developer's review of the running build (received 14:50)

**The brief, verbatim:** `D:\coding\watchizer website\checkpoints\2026-11-05_round2\brief-round2.txt` (23 items; also in the
scratchpad as `brief-round2.txt`). New checkpoint folder for this round: `checkpoints\2026-11-05_round2\`.
The preview servers may be stopped for rebuilds now; at the END the preview must be running again for him
(mysqld + scratch core :8020 + both shops), and the records, COMMITS and deploy scripts updated.

## Status — round 2

| item | state | where |
|---|---|---|
| R1 one menu (nav items open the mega panel) | **SEEN** in a browser (look/wz-menu-watches-1440.png: the WATCHES nav item opens the one panel on Watches; no narrow dropdown); final captures pending | |
| R2 menu hover corridor | code DONE (panel overlaps the row by 8 transparent px, 280 ms close delay, 110 ms switch delay, ArrowDown from a nav item enters the panel); browser check pending | |
| R3 OFFERS → SALE with its panel | **SEEN** (look/bf-menu-sale-1440-ar.png: Sale panel with both live campaigns, by category, by brand, real counts; Watchizer's nav says SALE); final captures pending | |
| R4 category picture fallback everywhere | **core DONE** — in stock first, else the newest product (a sold-out category still gets a picture); menu genders too; storefront check pending | |
| R5 Flash Sale toggle removed from the header | code DONE — no Flash Sale control in the header on any screen (CampaignNav is the Hot Deals switch only); browser check pending | |
| R6 toggle fixed width, CLS 0 with the countdown | code DONE — the countdown is HH:MM:SS (days carried into hours), one character per fixed cell; unit test; CLS proof with the countdown running pending | |
| R7 bar and stock label never contradict | **DONE in code** — core rule + storefront barOf/DealCard follow it; core 4 tests, storefront unit tests (139 pass); browser check pending | |
| R8 campaign sections full width | **SEEN** (look/wz-hot-1440.png: edge to edge, dark stage on Watchizer) | |
| R9 banner slider slides (autoplay, swipe, controls) | code DONE (twin BannerHero: autoplay by index with wrap, arrows wrap, touch holds 8 s instead of freezing, '2 / 4' counter). FINDING: the preview copy had ONE hero slide per shop — one slide is a still picture by design; needs 2+ demo slides on the scratch copy and a browser check | |
| R10 cards a separate surface on a coloured section | code DONE (cards in a coloured section: white ground, edge, shadow; with blend the tinted photo is framed inside the card); browser check pending | |
| R11 featured banner removed; every home section ordered from the dashboard | core + dashboard DONE (section kinds, managed_sections, featured not offered); storefront homes pending | §R2-A |
| R12 side banners: sizes and crop tool | code DONE — a side slot has a device now: desktop strip 960 × 500, phone tile 520 × 390 (4 : 3), each with the crop tool; the slot serves mobile_image when it has a Phone-only banner; storefront draws it (picture source ≤ 599 px); typecheck + PHPStan clean; test + browser check pending | |
| R13 brand banner full width | code DONE (bleed); browser check pending | |
| R14 trust strip and badges redesigned | code DONE (twin TrustSignals: drawn glyphs in chips, framed sheet, hairlines; cart + checkout lists in both shops restyled the same; wording untouched; --tr-* tokens per shop); browser check pending | |
| R15 new home (command: dry run, apply, restore) | command DONE (home:rebuild dry run / --apply / --restore, each shop its own order, PHPStan clean); storefront rendering of the new kinds + tests pending | §R2-A |
| R16 brand text: no dates, links, comparison, language mixing | **DONE in core** — section rewritten: heading '{name} at {shop}', no dates / links / sources / comparison; names per language (SeoTextController names{en,ar}); tests updated (PageTextsTest 9/9) | |
| R17 brand range presented as available at the shop | **DONE in core** — brand_sources.json  block (families + plain facts) printed as the range the shop carries + 'ask and we add it'; the record (figures, sources, dates, counts) is never printed; guard test | |
| R18 page-text layout | code DONE + first look (questions in two columns, each opens its answer; lead beside a facts sheet; cards); FacetIntro twin + sectionsFromMarkdown (3 unit tests); final captures pending | |
| R19 navigation delay measured and fixed | measured BEFORE; fixes written (NavProgress line, no menu fetch on a live campaign page, stale times); AFTER measurement pending | |
| R20 each shop its own design; divergences in TWINS.txt | tokens + TWINS.txt divergence note DONE; browser check pending | |
| R21 responsive pass 360/390/412/430 + landscape; brand banner links | PARTLY — brand banner: each card links its own product, only the button opens the brand page (no nested links), names two lines, phone fan kept inside the screen; flash View-all bar cannot overflow (box-sizing); the full 360/390/412/430/landscape pass pending | |
| R22 Hot Deals mode recolours the phone chrome | **SEEN** on Watchizer's phone header (look/wz-mode-390.png); BF's bar already burns | |
| R23 Hot Deals control on phone: options + recommended one | floating pill SEEN on BF (bottom corner, above the bottom bar). DEFECT found on Watchizer: the header's backdrop filter trapped the fixed pill under the header — fixed by drawing the pill on <body> (portal); re-check pending; option mock-ups pending | |
| RG gates, measurements, screenshots, scripts, preview left running | not started | |

## §R2-A — round 2, state at 15:40 (core + storefront code; nothing of round 2 seen in a browser yet)

**Core, done (PHPStan clean, typecheck clean):**
- R7 `StorefrontCampaigns::bar()` — the one rule (selling / sold_out / unavailable); `CampaignBarRuleTest` (4).
- R4 `CategoryImages` + menu genders: in stock first, else the newest product — a sold-out category still has a picture.
- R3 `StorefrontMenu::sale()` → `{count, max_discount, categories[], brands[]}` with real counts.
- R16/R17 `SeoFacts::section()` rewritten: heading "{name} at {shop}", the brand's range presented as carried by the
  shop (`brand_sources.json` → `page.families`, `page.facts`), "ask and we add it", our numbers; NO dates, links,
  sources or comparison; names per language (`SeoTextController::brands()/categories()` now return `names{en,ar}`).
  `PageTextsTest` updated (9 pass). The record (figures / sources / dates / the brands' own counts) stays in the file.
- R11/R15 `HomeRails`: new kinds — SECTIONS `banners, hot_deals, flash_sale, categories, brands` (one row each) and the
  product rails `best_value`, `price` (settings `max_price`); payloads carry `managed_sections` (compat + v2): a section
  WITH a row is drawn only where its row is; one WITHOUT a row keeps its old fixed place. Dashboard Home sliders screen
  knows them (labels, notes, the price field); `featured` is no longer offered.
- R15 `php artisan home:rebuild --storefront=<code> [--apply|--restore]` (HomeRebuildCommand): dry run prints the plan
  from real data; `--apply` switches every current row OFF (never deletes), lays out the new home (each shop its own
  order), records the before-state in `settings.home.rebuild`; `--restore` puts every row back. Dry run on the scratch
  copy: 14 rows per shop (Tommy Hilfiger + Rolex banners, largest category watches, under EGP 3,500, Chronograph + Bags).
  **Tests for the kinds and the command are NOT written yet.**
- R9 twin `BannerHero`: autoplay by index with wrap, arrows wrap, touch holds 8 s, "2 / 4" counter. FINDING: the preview
  copy had ONE hero slide per shop (a still by design) — add 2+ demo slides before judging.

**Storefronts, written (lint clean, vitest 139 pass on Watchizer; NOT built, NOT seen):**
- R1/R2/R3: `megaBus.js` (twin) + `megaItemProps` on every nav item in both `Nav.jsx`; the narrow dropdowns are out of
  the nav row; corridor = panel overlaps the row by 8 transparent px + 280 ms close + 110 ms switch delay; `SalePane.jsx`
  (twin); Watchizer's OFFERS → Sale. Menu panel colours are tokens now (`--mm-panel`, `--mm-muted`, `--mm-faint`, `--mm-pic`).
- R5/R6/R23: `CampaignNav` is the Hot Deals switch only — `top` (desktop) and `float` (phones: a pill fixed in the
  thumb's corner; BF lifts it 60 px over its bottom bar); the clock is HH:MM:SS in fixed cells.
- R7 card: `barOf` + `DealCard` follow core's state. R8/R13: `bleed` on CampaignRail and BrandBanner, both homes.
- R10: cards in a coloured section keep a white surface, edge and shadow; blend tints a photo framed inside the card.
- R21: BrandBanner is a section; each card is its own link to its product; only the button opens the brand page.
- R22: Watchizer's phone header takes its dark state painted with fire in Hot Deals mode.

**Still to do (in this order):** storefront homes draw the new kinds + `managed_sections` (R11/R15) → build → seed 2+
hero slides, apply `home:rebuild` on the scratch copy → browser pass (all of the above) → R12 side-banner crop tool →
R14 trust strip → R18 page-text layout → R20 per-shop looks (Watchizer dark tokens) + TWINS.txt → R19 navigation
measurements and fixes → R21 full responsive pass → tests (core kinds + command), full suite, blocked builds,
measurements, evidence screenshots → records, COMMITS, deploy script (home:rebuild as a post-deploy step) → preview
left running.

**A slip, found and undone (15:28).** While syncing twins I passed `app/(main)/HomeClient.jsx` to `twin.py` — a file that
is NOT a twin — which copied Watchizer's home over Brand Fashion's own (tracked, unmodified) `app/(main)/HomeClient.jsx`.
Restored from the committed blob (`git cat-file blob HEAD:…`, written back CRLF); `git status` shows the file clean
again. Rule for the rest of the run: `twin.py twin` only for paths listed in `TWINS.txt`.

## §R2-B — additions from the developer (received 15:50), and the state then

**NO SCREENSHOT DELIVERABLE any more** (this brief and the previous one): he watches the live preview. Keep the preview
running; tell him when a change is ready to look at. Own browser checks only to verify layout / overflow / CLS.
The six additions (24 to 29) are appended verbatim to `checkpoints\2026-11-05_round2\brief-round2.txt`.

| item | state | where |
|---|---|---|
| A24 remove the All categories button on desktop (both shops; phone keeps its drawer) | code DONE (MegaMenu trigger={false} in both Nav.jsx; Esc returns focus to the nav item that opened the panel); browser check pending | |
| A25 the round featured-photo frame: any photo whole, centred, clean; per shop | code DONE (a plate the width of the card, photo whole with object-fit contain; per shop --mm-plate / --mm-plate-radius); browser check pending | |
| A26 brand banner picks show the range (watch, bag, accessory; no socks) — picker default + home:rebuild | **DONE in core** — HomeRails::suggestBannerProducts (watch, bag, accessory; socks only when nothing else); the form fills the picker with it on choosing a brand + a Suggest button (GET home-rails/banner-picks); home:rebuild uses it. Checked: Tommy Hilfiger → watch, bag, belt/wallet; Hugo Boss → watch, bag, belt. Tests pending | |
| A27 Watchizer sliders: 5 cards per row on desktop (1280/1366/1440/1920) | code DONE (5 per row from 1200 px; card widths 224/241/256/269 at 1280/1366/1440/1920 by arithmetic); browser check pending | |
| A28 full width: BF every home section; Watchizer only coloured sliders as a band (+ campaigns, brand banner) | code DONE (BF: every home section uncapped; coloured rail = band. Watchizer: only a coloured slider is a full-width band, cards on the column); browser check pending | |
| A29 BF mega menu design pass (alignment, rail consistency, light featured card, no empty space, tile pictures) | code DONE (panel hangs from the bar, full width, content on --mm-max; rail one type size; light featured card + plate; few tiles → larger grid + real top picks from core menu.picks; tiles one picture treatment); browser check at 1280/1366/1440/1920 pending | |

**State of round 2 at this moment (code; see the round-2 table above for each item):** R1–R18, R20–R23 written and
most of them seen once in a browser; R19 measured BEFORE (`scratchpad\nav-before-wz.txt`, `nav-before-bf.txt`):
the local core (PHP built-in server) answers ONE request at a time and each API call costs 0.22–0.40 s there by
itself; findings that are OURS: the campaign pages fetch the whole `menu` only to name their filter chips (1.1 s
serialized beside the products request); no feedback at all between a click and the new page. Fixes being written:
`NavProgress` (twin, a 3 px line from click to arrival), CampaignPage reads the menu from cache only, longer
stale times for campaigns and badges. Pre-existing, not ours, reported: Watchizer's home RSC payload (3.2 s on a
client navigation to `/`, 304 KB of JS), the product page's two `all_product_rating` calls.

## §R2-C — third set of additions (received ~16:39, verbatim in the checkpoint's `brief-round2.txt`)

| # | Item | State | Where |
|---|---|---|---|
| A30 tiles too big | Watchizer menu tiles smaller, no inner scroll at 1366x768 and up; same check on BF | open | |
| A31 slider colours | a better default than beige; per-shop presets in the dashboard, custom still allowed | open | |
| A32 blend never tints | only the white studio background is replaced; product pixels untouched; measured | open | |
| A33 land at the top | every forward navigation starts at the top, header not over the hero; back/forward restores | open | |

## §R2-D — the additions of 16:39 → 17:55 (items 30–43), state at 18:00 (verbatim texts in the checkpoint's `brief-round2.txt`)

Preview: mysqld :3307, scratch core :8020 (`scratch_night_20261105`), Watchizer :3000, Brand Fashion :3001 — production builds (`next build` + `next start`), pixel ids blank. Last build 17:46.
Scratch probe harness: `probe.mjs` (measures, no pictures unless `shot`), jobs `jobs-probe-*.json` in the scratchpad.

| # | Item | State | Measured / where |
|---|---|---|---|
| A30 | menu tiles fit, no inner scroll | **DONE, verified** | 0 px inner scroll on every pane, both shops, 1280x720 → 1920x1080; no label cut; `megaMenu.css` (--mm-cols / --mm-cols-wide from the tile count, container query on the pane, --mm-pic-max), `MegaMenu.jsx` tileCols |
| A31 | slider colours: presets per shop | **DONE, tested** | `HomeRails::PRESETS` (Watchizer: midnight, racing green, oxblood, espresso, graphite, pearl; BF: sky, blush, mint, butter, lilac, peach), prop `background_presets`, swatches in `HomeRails/Index.tsx`; `home:rebuild` uses them (no beige); en keys `home_rails.preset_*` |
| A32 | blend never tints | **DONE, measured, tested** | `Domain/Media/CutoutMask` (flood from the border, 256 px PNG mask, ~1.3 KB), `Storefront/CutMasks` (lazy: ≤12 photos / 2.5 s per request, memory room 36 MB), migration `2026_11_05_000006_image_cut_mask` (catalog_product_images.cut_mask TEXT, cut_mask_at), `media:cut-masks`, rails carry `masks` (compat + v2), cards `hl-cut` + CSS mask; multiply removed (homeZone.css, promo.css, megaMenu.css). Chrome, 7 real photos x 4 grounds, 177,464 product px each: NEW 0.000/255 (max 0); OLD multiply 9.0–11.4/255 on light, 102/255 on dark. 124–170 ms per photo, peak 62 MB at a 128 MB limit. LIMITS (deliberate): white enclosed by the product stays white; a studio shadow stays. Preview only: 19 covers read from the public image host into the scratchpad to make masks on the scratch copy (the local tree is older than the catalogue) |
| A33 | land at the top | **DONE, verified** | cause: `main.focus()` scrolled to main's top (153/126 px desktop, 53 px phone on Watchizer; 104/56 on BF). `ScrollToTop.jsx` → `focus({preventScroll:true})`. 24 navigations x 2 shops: scrollY 0, 0 px covered; Back restores the exact scrollY. Banners now navigate through the client router (`BannerLink`) |
| A34 | Brands panel: no dark margins, equal cards | **DONE, verified** | Watchizer `--mm-max` removed (rail at x=1, pane to the right edge at 1366/1536/1920); `.mm .mm__panel li{display:block}` (Watchizer's `.nav-ul li{display:flex}` made a card as wide as its name); cards 210/244/226 px, one height; rows fill the pane. Logo list: scratchpad `logo-report.txt` |
| A35 | category row | coded, **built 17:46 except the last filter**; verified at 1366/1536/1920/820/390: 0 half items, 0 arrows over an item, one label baseline, 4 product-photo fallbacks; 2 letters left = categories with no picture AND no product → now left out of the row (needs the next build) | `CategoryTiles.jsx/.css` (twin), `Carousel.jsx` `snap` |
| A36 | menu never "Loading" | coded, **built 17:46**; verified: 60 ms after the hover the panel is drawn (rail 9, tiles 10), no loading state, both shops | cause: fetched on first hover. Now seeded in the (main) layout (`getServerV2Menu`, `slimMenu`: 95 KB raw / 10.1 KB gzip from core → slim). `menuData.js` NEW TWIN (in TWINS.txt). Warm-up of pictures made gentle (4 at a time; first section at idle, all on header entry) — needs the next build; BF showed 0/11 pictures loaded under the first, burst version |
| A37 | banner arrows | **DONE, verified** | pair in the top end corner, 40 px, solid dark + light ring, per-shop tokens `--bh-arrow-*`; hidden on touch phones; dots kept |
| A38 | Watchizer home data + order | **APPLIED on the scratch preview** | `home:rebuild` ORDER watchizer + `curated()` (custom rows, brands in turn, no product twice, THIN=6, merge rule); trust strip = existing TrustSignals (cart variant) closing the page (not a dashboard row). Dry run on the preview copy: categories; hot_deals; Men's watches 581 in stock (12 shown, 4 brands); banners block; Women's watches 51 (12, 5 brands); flash_sale; Bags & wallets 264 (12, 10 brands); brand banner Tommy Hilfiger; Men's accessories 11 (10, 3 brands); Women's bags & perfumes 185 (12, 8 brands); brand banner Rolex; brands strip; new arrivals. Electronics LEFT OUT: 0 pictured products in stock on this copy |
| A39 | BF home: list + proposal, do not apply | **open** | |
| A40 | what happens when a campaign ends / starts | **MEASURED** (BF, scratch): open home: the Flash section left at the end second, no reload, CLS 0; open /flash-sale: live hero + 7 cards → the idle state by itself (the page gets shorter: CLS 0.16 at that moment); fresh loads after the end: no section, idle page, HTML without the section; a campaign that STARTS appears on an open page at the next 60 s poll (seen 20 s after the start), in its place. From the code, not measured: a live campaign with no showable product hides its home slider but still shows the header switch and a page with no cards → proposal, not changed |
| A41 | campaign look on listing cards | **open** | |
| A42 | campaign theme on the product page | **open** | |
| A43 | page texts: A) stacked full-width layout; B) structured editing + migration | **open** | |

New/changed tests: `tests/Feature/Manage/HomeRoundTwoTest.php` (9 tests, 2,510 assertions, pass on `scratch_gate_20261105`). PHPStan `[OK] No errors` at 17:40. Typecheck clean. FULL SUITE NOT YET RUN since round 2 began. Deploy script, DEPLOY record and COMMITS generator NOT YET updated for: migration 000006 (SEVEN migrations), `home:rebuild`, `media:cut-masks`, new files.

## §R2-E — state at 18:12 (after the build of 18:08) + the FINAL VERIFICATION brief (verbatim in `brief-round2.txt`)

Michael is away: decide, record every decision with its undo, finish items 1–43, verify, measure old vs new, write the report, update the checkpoint, **stop the servers and mysqld at the end**.

| # | State | Measured (build 18:08, probe-r4.txt) |
|---|---|---|
| A35 | DONE | empty picture-less categories left out of the row; warm-up gentle |
| A36 | DONE | menu seeded in the page; panel drawn 60 ms after the hover, no loading state |
| A41 | DONE, verified | mixed grid (brand page, 24 cards: 14 normal, 5 flash, 5 hot): every card 264x475 / media 262x262 (Watchizer 1440), 178x372 (390); BF 216x398 and 175x364; 10 strips, all inside the photo box; 0 rows with unequal cards; timer ticks on ONE clock (campaign.js subscribeClock); no blend/filter on photos; CLS 0. Files: `OfferStrip.jsx` (NEW TWIN, in TWINS.txt), `campaign.js` (offerOf, the clock), `usePromo.js` (useProductOffer), `promo.css` (.pm-offer*), both cards + their CSS, core `StorefrontCampaigns` (`product_ids`) |
| A42 | DONE, verified | product page of a hot and a flash product, both shops, 1440 and 390: root `pm-offer pm-offer--hot|flash`, note 36 px (in the SERVER HTML), buttons in the offer's fill with white text, photos untouched, CLS 0. Files: ProductDetailClient.jsx + ProductDetail.css, BfProductPage.jsx + .css, Watchizer globals (.pm-offer--flash paper/gold) |
| A43 A | DONE, verified | stacked full-width blocks; facts 7 cells in 4 columns (1366, 1920), 2 on phone, equal heights; `FacetIntro.css` (twin) only — no wording touched |
| A43 B | OPEN | structured editing + migration |
| A39 | OPEN | BF small changes: decide and apply through home:rebuild |
| A40 | measured; FIX OPEN: a live campaign with no showable product still shows the switch and an empty page |
| gates | OPEN: full suite, pint, final builds with -Block, console/link crawl, dashboard open+save, old-vs-new performance, report, checkpoint, stop everything |

---

# FINAL REPORT — the design pass, round 2 and its additions (items 1–43), 2026-11-05

> **How to read the final report below:** §1 every item 1–43 · §2 the decisions I made, with their undo · §5 the lists for you · §3 what was run and what it found · §7 design, old against new (opinion) · §4 speed, old against new (measured) · §6 item 40's answers.
> **Left as it is at the end of the run:** nothing is running (the shops, the scratch core and mysqld are stopped — the proof is the last lines of this file); nothing is committed, nothing is deployed.


Written for Michael, who was away for the end of the run. Everything below was checked on **production builds** (`next build` + `next start`, pixel ids blank) of both storefronts against the local core on the scratch copy `scratch_night_20261105`; the core suite ran on the clean copy `scratch_gate_20261105`. Nothing is committed, nothing is deployed, no production system was written to. The verbatim briefs are in the checkpoint's `brief-round2.txt`.

## 1. Completeness — every item, 1 to 43

"Checked" names how: **M** = measured in headless Chrome on the production build (the probe files are in the checkpoint), **T** = a core test, **U** = a storefront unit test, **R** = read in the code.

| # | Item | State | What changed | Checked |
|---|---|---|---|---|
| 1 | one menu: the nav items open the mega panel | done | `megaBus.js` (new twin), `MegaMenu.jsx`, both `Nav.jsx` | M: every top-level entry opens the one panel on itself, both shops, EN and AR |
| 2 | hover corridor | done | panel overlaps the row by 8 px; close 280 ms, switch 110 ms; **aim**: a pointer heading for the panel (down from the row, across the rail) waits 320 ms before an entry it crosses takes the panel | M: 8-step pointer script per shop and language — straight down, diagonal to the featured card, diagonal across the rail: the panel stays on its entry; resting on another entry switches; leaving closes |
| 3 | OFFERS → SALE with its panel | done | `SalePane.jsx` (new twin), core `StorefrontMenu::sale()` | T (real counts), M (panel opens, 2 campaigns, by category, by brand) |
| 4 | category picture fallback everywhere | done | core `CategoryImages` (in stock first, else newest), menu genders; category row (item 35) now uses it too | T, M: 0 letter placeholders |
| 5 | Flash Sale toggle removed from the header | done | `CampaignNav.jsx` is the Hot Deals switch only | M |
| 6 | toggle fixed width, CLS 0 with the countdown running | done | fixed digit cells (`promo.css`, `Countdown.jsx`) | M: 12 pages x 13 clock values: one box size, **CLS 0** on every page, both shops, computer and phone |
| 7 | bar and stock label never contradict | done | core `StorefrontCampaigns::bar()` (selling / sold_out / unavailable), `DealCard.jsx` | T (4), U |
| 8 | Hot Deals / Flash Sale home sections full width | done | `CampaignRail` `bleed` | M: 0..viewport at 1280–1920 |
| 9 | the banner slider slides | done | `BannerHero.jsx` (autoplay by index with wrap, arrows wrap, touch hold) | M: autoplay 1→2→3; next/prev/dot, wrap both ways, EN and AR, both shops |
| 10 | cards stay a separate surface | done | `home.css`, `BfHome.css` | M (white card ground inside a coloured band) |
| 11 | black "featured" banner removed; every home section ordered from the dashboard | done | `HomeRails::SECTIONS`, `managed_sections`, both homes | T, M |
| 12 | side banners: sizes and crop tool (desktop and phone) | done | `BannerWriter` (device on a side slot), `Banners/Index.tsx` (`side_mobile` 520x390), `HeroZone.jsx` `<picture>` | R, typecheck; dashboard page opens (see §3) |
| 13 | brand banner full width | done | `BrandBanner.jsx` bleed | M |
| 14 | trust strip and badges redesigned, wording unchanged | done | `TrustSignals.jsx/.css` (twin), cart/checkout CSS | R (no string changed), M (pages clean) |
| 15 | rebuild the home: command, dry run, apply, restore; deactivate, never delete | done | `HomeRebuildCommand` | T: dry run writes nothing, apply deletes no row, second apply refused, restore puts the rows back exactly |
| 16 | brand text: no Arabic in English, no dates, no links, no "their catalogue vs ours" | done | `SeoFacts::section()` | T |
| 17 | the brand's range as available at the shop | done | `brand_sources.json` `page` block, `SeoFacts` | T |
| 18 | page-text layout uses the page width | done (and redone by 43 A) | `FacetIntro.jsx/.css` | M |
| 19 | navigation delay: measured, ours fixed, before/after | done | `NavProgress` (new twin), no menu fetch on a campaign page, stale times, menu seeded (36), banners through the client router | M: before/after table in §4 |
| 20 | each shop its own design, divergences recorded | done | tokens in each `globals.css`; `TWINS.txt` note | `StorefrontTwinsTest` (in the suite) |
| 21 | responsive pass 360/390/412/430 + landscape 844x390 | done | fixes: Watchizer's nav row overflowed 769–1066 px (now fits, may wrap); Flash "View all"; brand-banner cards | M: 70 page/width combinations, **0 overflow**, 0 cut cards, 0 buttons out |
| 22 | Hot Deals mode recolours the phone chrome | done | header + search (both shops), BF bottom bar, the browser's own bar (`theme-color`) | M: off → ON → off, both shops |
| 23 | new phone Hot Deals control | done (recommended option built: the floating pill) | `CampaignNav` float variant via a portal | M: one position, no layout shift, 14 px above the bottom (74 px on BF, over its bar) |
| 24 | "All categories" button removed on desktop | done | `MegaMenu trigger={false}` | M: 0 triggers |
| 25 | featured-photo frame: any photo whole and centred | done | `megaMenu.css` plate (`--mm-plate*`) | M: object-fit contain, plate per shop |
| 26 | brand banner picks show the range | done | `HomeRails::suggestBannerProducts` (+ unsorted products last), `banner-picks` endpoint, Suggest button | T (12 brands), M (Tommy Hilfiger: watch, bag, belt set on both shops) |
| 27 | Watchizer: 5 slider cards per row on desktop | done | `ProductSlider.css` | M: 5 whole cards at 1280 / 1366 / 1440 / 1920 |
| 28 | full width: BF every section; Watchizer only coloured sliders as a band | done | `BfHome.css`, `home.css`, `HomeClient.jsx` | M |
| 29 | BF mega menu design pass | done | `megaMenu.css`, BF tokens, core `picks` | M at 1280 / 1366 / 1440 / 1920, EN and AR |
| 30 | menu tiles smaller, no inner scrolling at 1366x768 and up | done | columns from the tile count, picture height capped, container query | M: **0 px** inner scroll on every pane, both shops, 1280x720 → 1920x1080 |
| 31 | slider colours: better default + presets per shop | done | `HomeRails::PRESETS`, swatches in the dashboard, `home:rebuild` | T |
| 32 | the blend never changes the product's colours | done | `CutoutMask`, `CutMasks`, migration `2026_11_05_000006_image_cut_mask`, `media:cut-masks`, CSS mask | T (3), M: **0.000 / 255** on 177,464 product pixels x 4 grounds (old multiply: 9–11 on light, 102 on dark) |
| 33 | every forward navigation lands at the top; Back restores | done | `ScrollToTop.jsx` (`preventScroll`) | M: 14 landings per shop (links, the Hot Deals switch, router pushes; computer and phone): scrollY 0, 0 px covered; Back = the same scrollY as at the click |
| 34 | Brands panel: no dark margins; equal brand cards; logo list | done | `megaMenu.css`, Watchizer `--mm-max` removed | M at 1366 / 1536 / 1920; list in §5 |
| 35 | category row: arrows, whole items, fallback, labels; picture list; BF row | done | `CategoryTiles.jsx/.css`, `Carousel.jsx` `snap` | M at 1366 / 1536 / 1920 / 820 / 390; list in §5 |
| 36 | the menu never shows "Loading" | done | menu seeded in the layout, `menuData.js` (new twin) | M: panel drawn 60 ms after the hover on a cold page, both shops |
| 37 | banner arrows: top corner, solid, consistent | done | `BannerHero.css`, `--bh-arrow-*` per shop | M at 1366 / 1920 / phone, every slide |
| 38 | Watchizer home data and order | done, **applied on the preview copy** | `HomeRebuildCommand::ORDER`, `curated()` | T; the list in §2 |
| 39 | BF home: small changes | done, **applied on the preview copy** (decision D2) | `ORDER['brandfashion']` | the two orders side by side in §2 |
| 40 | what happens when an offer ends / starts | measured; one gap fixed (decision D3) | `StorefrontCampaigns::payload()` | M (timelines), T |
| 41 | offer look on listing cards | done | `OfferStrip.jsx` (new twin), `campaign.js` clock, both cards | M: all cards one size on a mixed grid, CLS 0, one clock |
| 42 | offer theme on the product page | done | both product pages + CSS | M: note in the server HTML, CLS 0, both shops, computer and phone |
| 43 A | page texts as stacked full-width blocks | done | `FacetIntro.css` | M at 1366 / 1920 / phone |
| 43 B | page texts edited as fields | done — **no storage change, so no migration** (decision D4) | `SeoTextStructure`, `SeoTextController::structure()`, `StructuredFields.tsx`, `seo:structure-check` | T (3): round trip character for character; all 172 stored texts are shown as fields |

## 2. Decisions I made (Michael away) — each with its options, the reason, and exactly how to undo it

No decision touches wording that states facts about the products or the business: originality, warranty, payment and delivery sentences are as they were.

**D1 — Watchizer's home (item 38): applied on the preview copy through `home:rebuild`; on production it is the deploy script's step E2.**
The order, with what each section shows and how many products in stock it draws from (preview copy; the command prints production's own numbers in its dry run):

| # | Section | Shows | In stock |
|---|---|---|---|
| — | Hero | the Banners screen's setting (not touched) | — |
| 1 | Category row | the categories that have a picture or a product | — |
| 2 | Hot Deals | the live campaign (drawn only while one is live) | 6 |
| 3 | Men's watches | curated: 12 shown, 4 brands in turn | 581 |
| 4 | Banner | the Banners block (large banner + two side slots) | — |
| 5 | Women's watches | curated: 12 shown, 5 brands | 51 |
| 6 | Flash Sale | the live campaign | 7 |
| 7 | Bags & wallets | curated: 12 shown, 10 brands, on midnight navy | 264 |
| 8 | Brand banner | Tommy Hilfiger: a watch, a bag, a belt set | — |
| 9 | Men's accessories | curated: belts, wallets, bracelets — 10 shown, 3 brands | 11 |
| 10 | Women's bags & perfumes | curated: 12 shown, 8 brands, on racing green | 185 |
| 11 | Banner | a second BRAND banner (Rolex) | — |
| 12 | Brands strip | every brand with a logo | — |
| 13 | New arrivals | the newest in stock | all |
| — | Trust strip | the cart's trust strip, closing the page | — |

- *Thin rails:* **Electronics is left out** — on this copy it has no pictured product in stock (the command says so in its dry run and adds nothing unrelated). On production the command decides from production's stock: at 6 or more it takes its place between the brands strip and the new arrivals by itself. *Men's accessories* has 11 in stock: kept as asked (10 shown — one wallet is already on "Bags & wallets"; no product is on two of these sliders); below 6 the command would merge it into "Men's bags & accessories".
- *The second "Banner":* the home has ONE Banners block by design, so the second banner of the order is a second brand banner. Options: (a) a second brand banner — chosen, it exists and is editable; (b) allow two Banners blocks — a new behaviour, not built ("no new section type").
- *Trust strip:* the existing `TrustSignals` (the cart's variant) as the page's last line. It is not a row of the sliders screen; making it one would be a new section kind.
- *The six "who and what" sliders are curated rows* (kind "custom"): the command picks up to 12 products in stock, brands in turn. They do not refill by themselves — the Home sliders screen edits them, and running the command again refreshes them. Option not taken: a new "gender x category" slider kind that refills itself (a new section type).
- **Undo:** `php artisan home:rebuild --storefront=watchizer --restore` — every old row back on, in its place; the new rows stay, switched off. Tested (apply → restore leaves the old rows byte-identical; a second apply is refused until a restore).

**D2 — Brand Fashion's home (item 39): small changes, applied on the preview copy the same way.**

| # | Was (the order Michael saw) | Now | Why |
|---|---|---|---|
| 1 | Banners block | Banners block | — |
| 2 | Category tiles | Category tiles | — |
| 3 | Hot Deals | Hot Deals | — |
| 4 | **Flash Sale** | **Best value** (on sky) | Hot Deals and the Flash Sale were neighbours; the best value goes between them |
| 5 | Best value | Flash Sale | — |
| 6 | New arrivals | **Men's watches** (curated, 12 of 581 in stock, 4 brands) | men and watches visible early; replaces the generic "watches" slider further down |
| 7 | Brand banner (Tommy Hilfiger) | Brand banner (Tommy Hilfiger) | — |
| 8 | Brands strip | **Women's bags & perfumes** (curated, 12 of 185, 9 brands, on blush) | women and a second product kind by the middle of the page; replaces "Chronograph" (watches a third time) |
| 9 | Watches (largest category) | Brands strip | — |
| 10 | Brand banner (Rolex) | New arrivals | two all-catalogue sliders no longer neighbours |
| 11 | Under EGP 3,500 | Brand banner (Rolex) | a banner between two sliders |
| 12 | Chronograph (by type) | Under EGP 3,500 | — |
| 13 | Bags (by type) | **Men's accessories** (curated, 9 in stock, 3 brands) | replaces "Bags", now covered above |
| 14 | On sale | On sale | — |

Its design and identity are untouched. **Undo:** `php artisan home:rebuild --storefront=brandfashion --restore`.

**D3 — A live campaign with nothing to show is not shown (item 40).** Found in the code: a campaign inside its dates whose products are all hidden or deleted had no home slider (already so), but the header switch, the Sale panel's entry and the campaign page were drawn from "a campaign is live" alone — a switch leading to a countdown with no product. Options: (a) hide each surface in the storefronts; (b) core does not send such a campaign — chosen: one rule in one place, every surface (and any future one) follows, and it comes back by itself when a product is showable again. `StorefrontCampaigns::payload()`. **Undo:** remove the `$heavy['count'] === 0` condition there (one line); the test `does not send a campaign that is inside its dates but has no product…` documents it.

**D4 — Page texts are edited as fields WITHOUT changing how they are stored; no migration (item 43 B).** Measured first: a page text is one Markdown field per language (`core_seo_texts.description`), the catalogue's facts written into it at seed time; with the dashboard alone an admin could produce the new layout only by typing Markdown in exactly the right shape, numbers included. Options: (a) new columns / a JSON structure per text + a data migration of 172 texts — rewrites every stored text once and needs a restore path; (b) keep the stored text and let core read it into its parts and compile the parts back (`SeoTextStructure`) — chosen: nothing is rewritten until somebody saves a page, a page saved untouched keeps its words (tested), a text that is not in the shape simply keeps the Markdown editor, and there is nothing to restore. `seo:structure-check` is the dry-run report: **all 172 stored texts (86 per shop) are shown as fields; 0 stay Markdown**. The facts are read-only with a show/hide switch each; a fact's line is always the catalogue's (a label that is not a real fact is not written — tested). The seeder writes the same shape and now says when a prepared text is not in it. **Migration count: unchanged by this item.** **Undo:** the form's own "Markdown" switch gives the old editor back for any page; to remove the feature, drop the `structure` route and `StructuredFields.tsx` — no data is involved.

**D5 — The blend is a mask over the unchanged photo (item 32).** Options: (a) a background-removed copy of each photo (a second image file per product, re-encoded — its pixels would differ from the original's by the encoder's error, and it doubles the picture weight of a blended slider); (b) a CSS colour blend restricted somehow — none exists that knows where the product is; (c) the SAME photo file plus a small transparency mask of its studio background (~1.3 KB, generated once, kept on the photo's row, sent inside the page) — chosen: product pixels are untouched by construction (measured 0.000 / 255), no second image request, no cross-origin rule to satisfy, nothing on disk to keep in step. It needs **one migration: `2026_11_05_000006_image_cut_mask`** (two nullable columns on `catalog_product_images`). **The deploy script now expects SEVEN migrations.** Known limits, deliberate: white enclosed by the product (inside a bag's handle) stays white — telling it from a white dial is a guess that would punch holes in products; a soft studio shadow stays. **Undo:** switch "blend" off on the slider (the photo is then on its own white, as without the feature); rollback SQL is in the migration's header.

**D6 — Slider colours (item 31):** presets per shop — Watchizer: midnight navy `#111A2C`, racing green `#13261F`, oxblood `#3A1216`, espresso `#2B1F18`, graphite `#22262B`, pearl grey `#E4E7EB`; Brand Fashion: sky `#DCEBFA`, blush `#FBE1E4`, mint `#DDF2E6`, butter `#FFF0C4`, lilac `#E9E3F8`, peach `#FFE2D2`. Any colour can still be typed. The new homes use them (no beige); on Watchizer's dark bands the blend is off by default (a studio photo reads best on the card's own white there). **Undo:** pick another colour on the slider; the list is `HomeRails::PRESETS`.

**D7 — SUPERSEDED by the performance pass (P-D1, below): the menu's data is no longer inside every page; it is asked for once after load and kept for the session. As written at 19:29 —** *The menu's data is in the server-rendered page (item 36),* slimmed to what the panels draw, rather than fetched after load: it is the only choice that has no window in which a hover can meet an empty panel. Cost measured in §4. **Undo:** remove the menu block from `app/(main)/layout.jsx` — the browser then asks for it right after the page loads (the fallback that is already there).

**D8 — Old-vs-new baseline (part 4):** "old" is the last commit, `9a369cd` (2026-11-04) — the code before last night's run and this pass — exported with `git archive` (read-only) and built the same way against the same scratch core. The `2026-11-05_design` checkpoint holds changed files only and cannot be built by itself.

## 5. Lists for Michael

**Brand logos (item 34) — measured from the logo files as they draw on a white plate** (scratchpad `logo-report.txt`; nothing was redrawn):
- **No logo at all:** Joyroom (72 products; pending from you), Generic (2,934 products — not shown in the menu).
- **Too light — nothing in the file is darker than mid-grey:** Patek Philippe.
- **Faint when drawn small (thin, pale strokes):** Calvin Klein (0.9% of the file is dark), then, paler than the rest but readable: Armani Exchange, Emporio Armani, Rolex, Pinko (its mark also fills only a third of its file, so it draws small).
- Naviforce's logo file is not in this machine's copy of the image tree (it draws correctly from the live host).

**Category pictures (item 35 e) — Watchizer** (scratchpad `cat-pics-watchizer.txt`). The dashboard CAN change a category picture: Categories → the category → picture (upload, or hide it so the product-photo fallback shows).
- **A logo or a drawing instead of a photo:** Sport (9 products; 98% of the picture is 8 flat colours). You also named Casual and Dress — the same kind. With no products today but the same problem: Pilot, Field, Racing, Smart Watch.
- **No picture of their own:** Screen Protectors, Phone Holders, Earphones & Headphones, Power Banks, Socks, Accessories & Spare Parts, Charger Cables. The row shows a product photo for such a category when it has a pictured product a shopper can reach; when it has none it is left out of the row (on this copy: Power Banks and Charger Cables).
- **No picture and no product** (left out of the row): Luxury Watches, Sport Watches, Classic Watches, Fashion Accessories, Men Collection, Women Collection.

**Banner pictures needed:** the preview's hero slides and side slots are demo rows on the scratch copy. For production: at least 2 hero slides per shop (one slide is a still banner, not a slider), and for each side slot a 960x500 desktop picture and a 520x390 phone picture (the Banners screen has the crop tool for both).

**Still yours:** the BF FAQ payment wording (D3 of 2026-11-04, untouched); the four domain-plan answers; payment badge pictures; the 9 brands with no official figures.

## 3. No errors — what was run, and what it found

**Gates**
- Core suite, full, on the clean copy (`bash tests/baseline/suite-baseline.sh`): **2,282 passed, 35 failed — 31 of them the known baseline, 4 NEW at first; all 4 fixed and re-run green:** two tests listed a payload's keys exactly and did not know the new `managed_sections` key; one asserted the old side-banner rule that item 12 changed on purpose (a side slot now keeps its device); the twin guard caught two files changed on Watchizer only (`ProductDetailClient.jsx` — synced; `ProductSlider.css` — recorded as an intentional divergence, item 27). 0 tables changed outside a transaction; `mysql.db` OK before and after. The full gate was not run a second time after those four fixes: the four files and the storefront guard tests were re-run (77 passed; the 2 that still fail there are baseline entries).
- New tests: `HomeRoundTwoTest` (10), `PageTextStructureTest` (3), plus the campaign and page-text files: all pass.
- PHPStan level 10: `[OK] No errors`. Pint (changed files): passed. Dashboard `tsc --noEmit`: clean. Dashboard bundle: built.
- Storefront unit tests: Watchizer 142 passed, Brand Fashion 100 passed. ESLint: 0 errors on both (the warnings are the two kinds that were there before: `<img>` elements and hook dependency lists in older files).
- Final production builds of both shops with Google's font hosts blocked: exit 0, **0 lookups** of them. Build warnings on Watchizer: 49, all lint warnings of the four kinds the last commit's build already prints (50 there: hook dependency lists 20, `<img>` 18 → 17, set-state-in-effect 6, unused disable comments 6) — **no new warning**; Brand Fashion prints 35 of the same four kinds.

**Pages (production builds, a computer 1440x900 and a phone 390x844), both shops — 80 page loads:** home (EN, AR), category, sub-category, brand, listing, search, sale, Hot Deals, Flash Sale, product (normal, in Hot Deals, in the Flash Sale), cart, checkout (opened, nothing submitted), account, FAQ, about, contact, a 404.
- **Console errors: 0 on all 80.** Horizontal overflow: 0. Dead links (`#`, empty): 0.
- Failed requests: 0 on 76. On 4 product-page loads two requests answered **429** — the local core's own rate limit, tripped by this crawl loading 80 pages in a row; the same pages are clean on the other device.
- The menu on a computer: every top-level entry (Watches, Fashion, Electronics, Men, Women, Unisex, Brands, Sale) opens its panel with 15–34 links; **never "Loading"**. The phone drawer: 8 rows, ready.

**Links: every internal link those pages carry was followed (both shops).** At first 210 answered 4xx/5xx; re-checked one at a time, 201 of them were the same local rate limit. The 9 that were real:
- **Fixed — Brand Fashion's product page linked sub-categories to `/category/fashion/bags` (404).** A category's path is a slug path and the routes are one segment each; the breadcrumb and the two "see all" links now go to `/subtypes/{slug}` (or `/category/{slug}` for a top-level one). Not from this pass, found by the crawl. `BfProductPage.jsx`.
- **Preview data, not code — `/experience/hugo-boss-grand-prix-1513477` answers 404** on the scratch copy because that product is not in this copy of the catalogue (0 rows); the page asks core for it and has always answered 404 without it. Production has the product.
- **For Michael — the FAQ (`src/content/faq.js`, Watchizer) links the article `/blog/how-to-tell-if-a-watch-is-original`**, which the scratch copy does not have. If production does not have it either, the link is broken there too. The wording was not touched.
- 3 product pages that answered 500 answered 200 on a third try (the rate limit again — the server log names 429).
- Buttons: the ones this pass touched were operated by the probes (menu entries, banner arrows and dots, category-row arrows, the Hot Deals switch on computer and phone, the drawer). A page-by-page click of every other button was NOT done.

**Dashboard (scratch admin, the new bundle):** Home sliders, Banners, Campaigns, Payments, Page texts, Categories — for both shops, 12 screens: all open, 0 console errors. The page-text form opens as fields (7 fact switches per language, the Fields / Markdown switch). SAVING is covered by the feature tests that post to the same controllers (sliders with a colour and blend, a brand banner, the banners' placements and devices, the page texts as Markdown and as fields, campaigns); it was not clicked through by hand in a browser.

**The specific checks:** scroll-to-top (33): 14 landings per shop, all at 0. Menu ready (36): drawn 60 ms after the hover. No half or covered items (35, 37): 0 at five widths. Equal cards (34, 41): one size each. No product colour change (32): 0.000 / 255.

## 7. Design, old against new — my opinion, apart from the numbers

- **Home (Watchizer):** better. The old page was a hero, a black rotating banner and a run of look-alike sliders; the new one reads as a shop with departments — who it is for (men, women) and what it sells (watches, bags, accessories) are on the page by its middle, the two sales have their own, unmistakable looks, and the dark bands give the white cards something to stand on. Weak points: with both campaigns live plus two brand banners and two dark bands the page is heavy and long — I would drop one brand banner; the "Men's accessories" slider is thin (10 products) and will look tired until the catalogue grows; the hero is still the piece that decides the first impression and this pass did not touch it.
- **Home (Brand Fashion):** better, mostly through order: the best value between the two sales removes the "two countdowns in a row" feeling, and men/women arrive early. The pastel bands with the masked photos are the best-looking thing in the pass. Weak: the page is long (14 sections); the category tiles row still mixes drawings and photos (the pictures, not the layout).
- **Menu:** clearly better on both shops — one panel instead of narrow dropdowns that scrolled inside themselves, pictures, real counts, a Sale panel, instant. Weak: on Watchizer the panel is very dark against a white header; some category pictures and brand logos are poor files and the panel now shows that plainly (lists in §5); the Electronics pane is thin because the category is.
- **Category / brand pages:** the page text is better (stacked blocks, a facts grid instead of a narrow column). The offer cards add life to a grid without moving anything. Weak: on a grid where half the cards are in an offer, the strips become noise — the campaign size is the team's lever.
- **Product page:** the offer theme is deliberately restrained (a note by the price, the buttons and the discount in the offer's colours). It reads as "this is in the sale" without becoming another page. Weak: Watchizer's Flash Sale theme (coal and gold) is close to its normal look — it is less of an event than the fire.
- **Hot Deals / Flash Sale pages:** new; nothing to compare. The idle state is honest (no fake countdown) but plain.
- **Dashboard:** the page-text fields are much easier than Markdown; the facts switches are the part I would show a new admin first. Weak: the live preview is a sketch of the layout, not the shop's own styles.

## 4. Speed, old against new — measured, and the new version is SLOWER on a first load

> **SUPERSEDED the same night by the PERFORMANCE PASS at the end of this file** — these are its "new, before this pass" numbers. Kept as written.

**What was compared.** OLD = the last commit, `9a369cd` (2026-11-04: the code before last night's run and this pass), exported read-only and built; NEW = the working tree. Both `next build` + `next start` on this machine, the same env, the same scratch core and data, Watchizer (the live shop; Brand Fashion has no older version in service to compare with). Headless Chrome, a fresh browser profile per load, **three cold loads, the median**. Computer: 1440x900, no throttling. Phone: 390x844, CPU 4x slower, 1.6 Mbit/s down, 750 kbit/s up, 150 ms latency. TBT (long-task time over 50 ms in the first 8 s) stands in for INP, which needs a person's input and was not measured. On the phone the "weight" is what arrived in the 12-second window, not the whole page.

| Page | Device | | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | computer | old | 934 | 1380 | 1424 | 0.002 | 937 | 566 | 2586 | 115 |
| | | **new** | 1287 | 2084 | 2084 | 0 | **198** | 571 | 4127 | 184 |
| Home | phone | old | 922 | 2484 | 2524 | 0 | 1252 | 566 | 1404 | 121 |
| | | **new** | 1104 | 2828 | 2872 | 0 | 1258 | 288 | 1977 | 92 |
| Category | computer | old | 468 | 932 | 960 | 0 | 17 | 276 | 488 | 88 |
| | | **new** | 647 | 1460 | 1652 | 0 | 87 | 274 | 794 | 101 |
| Category | phone | old | 492 | 1832 | 2544 | 0 | 753 | 276 | 428 | 76 |
| | | **new** | 669 | 2500 | 3284 | 0 | 1762 | 274 | 1005 | 92 |
| Product | computer | old | 431 | 932 | 1052 | 0 | 77 | 298 | 413 | 68 |
| | | **new** | 591 | 1356 | 1472 | 0 | 32 | 296 | 718 | 81 |
| Product | phone | old | 441 | 1568 | 1644 | 0 | 437 | 298 | 416 | 66 |
| | | **new** | 684 | 2280 | 2372 | 0 | 1315 | 296 | 992 | 82 |
| Hot Deals | computer | old | — the page does not exist (404) | | | | | | | |
| | | **new** | 106 | 1484 | 1484 | 0 | 68 | 294 | 1191 | 73 |
| Hot Deals | phone | **new** | 153 | 1788 | 6232 | 0 | 674 | 294 | 1460 | 77 |

Menu, from the pointer reaching a nav entry to a drawn panel (computer, home): **old 104 ms → new 24 ms.**

**Plainly:**
- **Slower:** the first byte (+150 to +350 ms), the first paint and the largest paint (+400 to +700 ms on a computer, +350 to +750 ms on the throttled phone), and the main-thread time on the phone's category and product pages (TBT 753 → 1762 ms, 437 → 1315 ms).
- **Heavier:** +300 KB on a category or product page on a computer (and +580 KB in the phone's window), +1.5 MB on the home.
- **Faster / better:** the menu (104 → 24 ms, and never a loading state), the home's main-thread time on a computer (937 → 198 ms), layout shift (0 everywhere; the old home had 0.002), and the JavaScript is the same size (571 vs 566 KB on the home, 274 vs 276 on a category).

**Why — what I could attribute, and what I could not:**
- *The document is bigger:* +95 KB raw / +13 KB gzip on a category page (470 vs 375 KB raw), +112 KB raw / +16 KB gzip on the home — the menu's data and the live campaigns (with every product id) now travel inside every page (items 36, 41).
- *More render-blocking CSS:* 31 KB gzip in 10 files against 17 KB in 8 (35 vs 19 on the home) — the campaign, menu and home-zone styles load on every page, including pages that draw none of them.
- *More pictures on every page:* the menu's first section is warmed up when the page is idle (11 pictures; item 36: "images inside the panel load with it"), the offer strips and the campaign surfaces draw, and the fonts are now the shop's own files instead of Google's. That is most of the +300 KB.
- *The home has more on it:* 13 sections with their pictures against the old page — the +1.5 MB and the 184 requests are the new home's content, not overhead.
- *The server's own time is nearly the same when warm:* three plain requests for the category page took 0.33–0.36 s on the old build and 0.36–0.37 s on the new (≈ +25 ms). The +180 ms the browser runs show at the first byte is therefore NOT explained by render time alone. My best reading — not proven — is the layout's extra reads (campaigns every 30 s, menu every 5 min) landing on a cold run against a local core that answers one request at a time; a multi-worker core would shrink it. I did not isolate it.
- *The phone's Hot Deals LCP of 6.2 s* is the campaign hero's artwork arriving late on the throttled line; its first paint is at 1.8 s. Not optimised in this pass.

**What I would do next, in order of return** (none of it done — each changes behaviour Michael asked for or needs a measurement of its own): (1) load the campaign and menu styles only where they are drawn; (2) warm the menu's pictures on the pointer reaching the header instead of at idle — saves the pictures for every visitor who never opens the menu, at the cost of a possible first-open flicker (it trades against item 36's wording, so it is Michael's call); (3) send the campaigns' product ids to the page only on pages that draw cards; (4) preload the campaign hero's artwork on the campaign pages; (5) measure against a multi-worker core before judging the first byte.

**Client navigation (a click to the new page drawn; one run each, before and after this pass's fixes, the same production builds):**

| Route | Watchizer before → after | Brand Fashion before → after |
|---|---|---|
| Home (from a brand page) | 3712 → 1268 ms | 309 → 570 ms |
| Hot Deals | 272 → 132 ms | 85 → 142 ms |
| Flash Sale | 151 → 127 ms | 144 → 95 ms |
| Brand | 576 → 622 ms | 959 → 680 ms |
| Category | 1016 → 607 ms | 1054 → 724 ms |
| Product | 480 → 526 ms | 1585 → 941 ms |
| Cart | 122 → 116 ms | 74 → 113 ms |

Single runs, so differences under ~100 ms are noise. What is certain: a campaign page now makes one data request on arrival instead of two (the menu is no longer fetched there), every click is answered within a frame by the progress line, and Watchizer's home is no longer a 3.7 s navigation. What was measured as NOT ours in the first place: the core's own endpoints at 0.22–0.40 s each on a server that serialises them.

## 6. Item 40 — what happens when an offer ends or starts (measured on Brand Fashion's Flash Sale, scratch copy, production build)

| Question | Answer | How it was measured |
|---|---|---|
| The period ENDS while a page is open | The home section leaves at the end second, by itself — no reload, no rebuild. The campaign page changes to its designed "no offer running" state by itself. The header switch, the Sale panel's entry, an offer card's strip and colour and a product page's offer theme read the same live campaign and leave at the same second. | The campaign's end was set 75 s ahead; an open home and an open /flash-sale were sampled every second for 150 s: section present → gone at the end second; page "live hero + 7 cards" → "idle state". CLS on the home 0; on the campaign page 0.16 at that one moment (the page gets shorter when its cards leave). |
| A page LOADED after the end | Nothing of the offer: no section, the campaign page in its idle state, no strip on a card. | Fresh loads 10–20 s after the end: no section in the page, and none in the server's HTML either. |
| Can a CACHED page show an ended offer? | The server's copy of a page can carry an ended campaign for at most the page's cache time (home: up to 5 minutes; the layout's campaign read: 30 s; core's own cache of a campaign's cards: 60 s) — but the BROWSER never shows it: the countdown is checked against the end on every load and a campaign that is already over is dropped before the first second passes. So: in the HTML for up to 5 minutes, on the screen never. | Read in the code (`useLiveCampaign`: "a campaign that is ALREADY over when it arrives is dropped on the next tick") and confirmed by the fresh loads above. |
| A NEW period starts | The section comes back in its own place by itself on every open page within 60 seconds (the browsers ask once a minute, and on returning to the tab); a freshly loaded page has it at once. | The campaign was set to start 50 s ahead with a page open: the section appeared 20 s after the start (at the page's next 60 s poll), CLS 0. |
| The countdown at zero | It does not run past zero or show an expired time: the timer for the end is set on the server's clock (the browser's clock offset is measured on every answer), and at that moment the whole surface leaves. | As above. |
| A campaign inside its dates with NO showable product | **This was the one thing that did not adjust:** the home section was hidden, but the header switch and the campaign page were still drawn (a countdown with no product). **Fixed (decision D3):** core no longer sends such a campaign, so every surface treats it as not running; it returns by itself when a product is showable again. | Test: `does not send a campaign that is inside its dates but has no product this storefront shows`. |

## 8. End of the run — nothing is left running (2026-11-05 19:29:09)

Stopped: the old-version shop (:3010), Watchizer (:3000), Brand Fashion (:3001), the scratch core (php -S :8020), every headless browser, then mysqld (`mariadb-admin -u root -h 127.0.0.1 -P 3307 shutdown`). Measured after: ports 3307, 8020, 3000, 3001, 3010 **closed**; mysqld processes 0; php processes 0; `next` node processes 0; headless Chrome processes 0.

To look at the result again: start mysqld (`C:\mariadb-11.8.9\bin\mysqld.exe --defaults-file=C:\mariadb-11.8.9\data\my.ini --standalone`), then the scratch core and the two shops with the scripts in the checkpoint's `scratch-scripts` (`start-core-scratch.ps1`, `start.ps1 -App wz`, `start.ps1 -App bf`) — the builds of 19:06 / 19:08 are still in each app's `.next`. The preview copy `scratch_night_20261105` has both new homes applied, the photo masks of their coloured sliders, and the campaigns armed until 22:10 / 23:55 on 2026-11-05 (re-arm their dates to see them again).

Not committed, not deployed. Git: no state-changing command was run (read-only: status, diff, log, archive, ls-files). No SSH, no production write. The only reads from a production host were public product photos (55 cover pictures, for the masks on the scratch copy) and the public pages read earlier in the day.

Checkpoint: `D:\coding\watchizer website\checkpoints\2026-11-05_round2\` — the working tree as a patch + every untracked file (`tree_<time>_final`), the briefs verbatim (`brief-round2.txt`), the scratch scripts, the probe outputs, the crawl (`crawl-1.json`, `crawl-recheck.json`), the old-vs-new measurements (`cmp-old.json`, `cmp-new.json`), the logo and category-picture lists, and the old version's export (`old-build\`, which can be deleted).

---

# PERFORMANCE PASS — 2026-11-05 19:40 → 2026-11-06 00:30 (Michael away; after the FINAL REPORT above)

**This section replaces §4 of the FINAL REPORT ("Speed, old against new") as the current state. §4's numbers are the
column "new, before this pass" below. Decision D7 above is superseded (see P-D1).**

**Continued on 2026-11-06 by "FOLLOW-UP TO THE PERFORMANCE PASS" at the end of this file — Michael's decisions on P6
and P10 carried out. Where the two disagree (the home's window, the numbers, the open items), the follow-up is right.**

## P0. The short version

- **The goal — "at least as fast as the old version on every page and every metric, and clearly faster" — is met for
  some metrics and NOT for others. Plainly:**
  - **Faster than old, clearly:** the first byte (TTFB) on every page, **63 to 89 % sooner** (home 993 → 107 ms on a
    computer, category 445 → 90, product 415 → 52); the largest paint (LCP) on the throttled phone — category **−29 %**
    (2368 → 1688 ms), product **−12 %**, home **−5 %**; the home's weight on a phone **−46 %** (1815 → 976 KB); the menu
    (109 → 30 ms from pointer to panel, and never a loading state).
  - **Level with old** (inside the run-to-run spread of this harness): the first paint (FCP) and LCP on a computer;
    layout shift (0 everywhere); the JavaScript (equal to the kilobyte, ±1 %).
  - **Still behind old:** main-thread blocking after the first paint (TBT, the lab's stand-in for INP) on every page —
    category 101 → 141 ms on a computer and 634 → 867 ms on the throttled phone, product 353 → 518 ms on the phone, home
    1103 → 1733 ms; the weight and the number of requests of a category or product page (+185 KB and +14 requests
    measured here; **about +90 KB on a computer and +33 KB on a phone in production**, where the web server compresses
    the menu's data — P4 says what each byte is). Every one of these is the cost of something items 1–43 asked for;
    P6 lists them with their numbers and the ways to buy them back, for Michael to decide. Nothing was removed or
    weakened to win speed.
- **Against where this pass started** (the FINAL REPORT's numbers), the new build is far faster on everything:
  phone category LCP 3284 → 1688 ms and TBT 1762 → 867 ms; home on a computer TTFB 1287 → 107 ms and weight 4127 →
  2553 KB; Hot Deals on a phone LCP 6232 → 1628 ms.
- **Lighthouse** (lab): **Accessibility 100, Best Practices 100, SEO 100 on all 12 page types of both shops, phone and
  computer** (old Watchizer: 87–96, 93–96, 100; the two campaign pages and Brand Fashion's home were SEO 92 before this
  pass). **Performance is level with old** inside Lighthouse's own run-to-run spread — P8 has both old runs side by side.
- **Core Web Vitals, lab:** LCP on the throttled phone is 1.4–2.1 s on every page type measured (limit 2.5 s); CLS is 0
  (limit 0.1); INP cannot be measured without a person — its lab stand-in (TBT) is the metric still behind old.
  **These are lab results. Google's real-user data (Search Console → Core Web Vitals, and CrUX) only moves in the weeks
  after the deploy** — P9 says what to watch.

## P1. What was measured, and how

The same way as the FINAL REPORT, so the columns can be read against each other: OLD = commit `9a369cd` exported and built
(port 3010), NEW = the working tree (Watchizer 3000, Brand Fashion 3001), all `next build` + `next start` on this machine
against the same scratch core and data, pixel ids blank. `cmp.mjs`: a computer (1440×900, no throttling) and a phone
(390×844, CPU 4× slower, 1.6 Mbit/s, 150 ms latency), **three cold loads each (a fresh browser profile per load), the
median**. `MSYS_NO_PATHCONV=1` was set for every run and each output line names the URL it measured (they are in the
checkpoint's `evidence-perf\`). Old and new were measured back to back in the same session (22:04–22:25), then the new
build again after its last change (22:56–23:25).

Four things a reader should know about these numbers:
1. **On a computer this harness has a floor of about 0.7 s to the first paint** (a fresh browser starting up): the old
   build's 404 page, which is almost nothing, paints at 692 ms. And with no network delay the page's JavaScript arrives
   and runs *before* the first frame — so a computer's FCP here is mostly "browser start + hydration", and the 300–900 ms
   the new build saves at the first byte hardly shows in it. It shows on the phone profile, and it will show on a real
   connection.
2. **The scratch copy's two test campaigns had ended** (Flash Sale at 22:10) when the first round of this evening's
   numbers was taken; they were re-armed on the scratch copy for the final round, so "new, after" is measured **with a
   Hot Deals and a Flash Sale campaign live** — the heavier state.
3. **The product page's first final run was thrown** by the real asset host answering slowly (the product photo is
   fetched from `api.watchizereg.com` over the internet): LCP 9.7 s on the phone, 2.6 s on a computer. Run again at once:
   1424 ms and 1256 ms. The table has the re-run; the thrown one is kept in `evidence-perf\cmp-new-final-firstrun.json`.
4. **Lighthouse is one run per page and form**, and its Performance score moves by several points between runs of the
   same build (the old category page on a phone scored 75 and then 83). Old was run twice; both runs are in the table.
   "New, after" is the run of 23:00–23:15 on the final build; the three computer rows whose last accessibility fix came
   after it (Watchizer brand and product, Brand Fashion brand) are from the run on the build of 23:17.

## P2. Each cause, its measured cost, and what was done

| # | Cause (measured) | Cost | What was changed | Gain measured |
|---|---|---|---|---|
| 1 | The layout read the live campaigns from core before every page's first byte | +150 to +350 ms TTFB | the server answers from its held copy and refreshes it behind the request (`serverV2.js` `memo`) | TTFB back to old's |
| 2 | **Every page asked core again for every visitor and waited** — old and new alike: the home's sliders (640 KB) and its campaign banner's product, a category's cards, a product | 0.7–0.9 s (home), 0.35–0.45 s (category), 0.3–0.4 s (product) before the first byte | **held reads** (`coreRead.js`, P-D2): a copy younger than 10–30 s is the answer; one younger than 1–5 min is the answer AND is refreshed behind the request; older, the page waits as before | **TTFB 993 → 107 ms (home), 445 → 90 (category), 415 → 52 (product)** |
| 3 | The home handed the browser core's whole answer inside its HTML: every product's descriptions in two languages, keywords, thirty specification fields, its gallery | Watchizer home 1,463 KB of HTML, 788 KB of it this data; Brand Fashion 1,159 KB / 714 KB | `homeData.js`: only what a home card reads (the same cards are drawn — a test renders both and compares) | Watchizer home HTML **1,463 → 638 KB** (gzip 139 → 69; old's is 1,369 / 126); Brand Fashion 1,159 → 1,073 KB (gzip 188 → 171) |
| 4 | The brand strip: 132 logos, each with a thirteen-step `srcset` | 303 KB of the Watchizer home's 619 KB of markup | a fixed 90×32 logo carries two URLs (1× and 2×), the same two files as before | −220 KB of HTML |
| 5 | The menu's pictures warmed up as 600 px files at idle; the closed phone drawer loaded 1200 px files | +270 KB / 15 requests on a computer, +214 KB on a phone | through the image optimiser at the size drawn; the drawer's rows built when it opens; the warm-up 2.5 s after load, four at a time, on devices with a mouse only | 61 KB / 14 requests on a computer, **0 on a phone** |
| 6 | Hot Deals cards with no recorded rendition loaded the 1200 px file | 597 KB of pictures; phone LCP 6.2 s | the optimiser's copy at the card's size | 105 KB; **phone LCP 6232 → 1628 ms** |
| 7 | Banners (1,600 px files) loaded whole on every device; the brand banner's three products and Brand Fashion's second ("hover") photos loaded as 1200 px files | home banners 476 KB; Brand Fashion category 1.2 MB of second photos | banners chosen by width through the optimiser, the file itself still the widest choice; brand-banner products at 320 px; second photos at card size — **and not downloaded at all on a device that cannot hover** | Watchizer home banners 476 → 102 KB; Brand Fashion category **1,805 → 823 KB** on a phone |
| 8 | The menu's data (32 KB) and the campaigns' full cards (23 KB) rode inside every page | +68 KB of inline script before first paint | campaigns cut to what the surfaces draw; the menu asked for once after load and kept for the session (P-D1) | category HTML 470 → 405 KB (old's is 375) |
| 9 | Every card in an offer re-rendered every second | ~1 s of phone main-thread time in the first 8 s | only the strip's eight digits read the clock | phone category TBT 1762 → 867 ms (with 8, 10, 11) |
| 10 | Painting the header's Hot Deals switch (gradients, glow, animation) before first paint | +130–200 ms to first paint | its effects start one frame after the page has loaded (`data-pm-fx`) | ≈ −100 ms |
| 11 | A closed filter drawer held a second copy of the whole sidebar (330 elements): sent, laid out and hydrated on every listing page, on a computer too | 70 ms of layout + 100 ms of style before first paint on the phone; 22 KB of HTML | built by the tap that opens it; and a closed drawer is not rendered (`content-visibility`) | category HTML −22 KB and 140 fewer elements than OLD's page (1,530 against 1,671); phone FCP ≈ −80 ms |
| 12 | A listing's first photos were lazy — found late, and one of them is the largest paint | phone category LCP 3.3 s | the first two rows are not lazy; the first is asked for first; the photos' host is connected to with the page (`Link: rel=preconnect`) | **phone category LCP 3284 → 1688 ms** |
| 13 | The title and description were written into the `<body>` on pages whose shell is ready early (Next 15.2+ "streaming metadata") | Lighthouse SEO 92 on /hot-deals, /flash-sale and Brand Fashion's home; a reader of `<head>` only saw no description | `htmlLimitedBots: /.*/` in both `next.config.js` — metadata always in `<head>` | SEO 100 |

What is NOT a cause: the JavaScript is the same size as old's (572 vs 566 KB on the home, 275 vs 276 on a category); the
server's own render time is the same.

## P3. The numbers

### A. Old against new, per page and metric (Watchizer; 3 cold loads each, the median)

| Page | Device | Build | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | computer | old | 993 | 1484 | 1484 | 0 | 858 | 566 | 2586 | 115 |
| | | new, before this pass | 1287 | 2084 | 2084 | 0 | 198 | 571 | 4127 | 184 |
| | | **new, after** | 107 | 1288 | 1288 | 0 | 1105 | 572 | 2553 | 194 |
| | | *after against old* | −89 % | −13 % | −13 % | = | +29 % | +1 % | −1 % | +69 % |
| Home | phone | old | 897 | 2184 | 2228 | 0 | 1103 | 566 | 1815 | 120 |
| | | new, before this pass | 1104 | 2828 | 2872 | 0 | 1258 | 288 | 1977 | 92 |
| | | **new, after** | 154 | 2092 | 2124 | 0 | 1733 | 572 | 976 | 101 |
| | | *after against old* | −83 % | −4 % | −5 % | = | +57 % | +1 % | −46 % | −16 % |
| Category | computer | old | 445 | 960 | 960 | 0 | 101 | 276 | 488 | 88 |
| | | new, before this pass | 647 | 1460 | 1652 | 0 | 87 | 274 | 794 | 101 |
| | | **new, after** | 90 | 1048 | 1048 | 0 | 141 | 275 | 672 | 102 |
| | | *after against old* | −80 % | +9 % | +9 % | = | +40 % | −0 % | +38 % | +16 % |
| Category | phone | old | 459 | 1836 | 2368 | 0 | 634 | 276 | 428 | 76 |
| | | new, before this pass | 669 | 2500 | 3284 | 0 | 1762 | 274 | 1005 | 92 |
| | | **new, after** | 154 | 1688 | 1688 | 0 | 867 | 275 | 613 | 90 |
| | | *after against old* | −66 % | −8 % | −29 % | = | +37 % | −0 % | +43 % | +18 % |
| Product | computer | old | 415 | 928 | 1172 | 0 | 71 | 298 | 413 | 68 |
| | | new, before this pass | 591 | 1356 | 1472 | 0 | 32 | 296 | 718 | 81 |
| | | **new, after** | 52 | 1076 | 1256 | 0 | 85 | 297 | 597 | 82 |
| | | *after against old* | −87 % | +16 % | +7 % | = | +20 % | −0 % | +45 % | +21 % |
| Product | phone | old | 418 | 1556 | 1624 | 0 | 353 | 298 | 416 | 66 |
| | | new, before this pass | 684 | 2280 | 2372 | 0 | 1315 | 296 | 992 | 82 |
| | | **new, after** | 154 | 1412 | 1424 | 0 | 518 | 297 | 600 | 80 |
| | | *after against old* | −63 % | −9 % | −12 % | = | +47 % | −0 % | +44 % | +21 % |
| Hot Deals | computer | old | the page does not exist in the old build (404) | | | | | | | |
| | | new, before this pass | 106 | 1484 | 1484 | 0 | 68 | 294 | 1191 | 73 |
| | | **new, after** | 43 | 1320 | 1320 | 0 | 81 | 295 | 591 | 77 |
| Hot Deals | phone | old | the page does not exist in the old build (404) | | | | | | | |
| | | new, before this pass | 153 | 1788 | 6232 | 0 | 674 | 294 | 1460 | 77 |
| | | **new, after** | 154 | 1596 | 1628 | 0 | 534 | 295 | 582 | 77 |

Menu, from the pointer reaching a nav entry to a drawn panel (computer, home): old 109 ms → new before this pass 24 ms → **new after 30 ms**.

### B. The home without the 3D watch's model and light (the poster stays) — the page's own cost, apart from the watch's

| Device | Build | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|
| computer | old, 3D blocked | 925 | 1412 | 1412 | 0 | 108 | 566 | 1354 | 105 |
| | **new, 3D blocked** | 98 | 1308 | 1308 | 0 | 251 | 572 | 1321 | 199 |
| | *new against old* | −89 % | −7 % | −7 % | = | +132 % | +1 % | −2 % | +90 % |
| phone | old, 3D blocked | 952 | 2472 | 2520 | 0 | 1171 | 566 | 1404 | 120 |
| | **new, 3D blocked** | 154 | 2124 | 2160 | 0 | 1713 | 572 | 976 | 102 |
| | *new against old* | −84 % | −14 % | −14 % | = | +46 % | +1 % | −30 % | −15 % |

### C. Brand Fashion, the new build (it has no older version to compare with)

| Page | Device | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|
| Home | computer | 164 | 1384 | 1384 | 0 | 155 | 281 | 1322 | 240 |
| Home | phone | 154 | 2128 | 2128 | 0 | 1239 | 281 | 878 | 112 |
| Category | computer | 72 | 1004 | 1028 | 0 | 122 | 292 | 861 | 153 |
| Category | phone | 153 | 1592 | 1592 | 0 | 726 | 292 | 701 | 107 |
| Product | computer | 76 | 968 | 1128 | 0 | 76 | 274 | 834 | 123 |
| Product | phone | 154 | 1364 | 1380 | 0 | 568 | 274 | 639 | 86 |
| Hot Deals | computer | 37 | 1288 | 1288 | 0 | 52 | 273 | 576 | 82 |
| Hot Deals | phone | 154 | 1388 | 1496 | 0 | 508 | 273 | 567 | 76 |

### D. Lighthouse 12.8 (the engine of PageSpeed Insights) — LAB results, one run each

| Page | Form | Build | Performance | Accessibility | Best practices | SEO | FCP ms | LCP ms | TBT ms | CLS | Weight KB |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Watchizer home | mobile | old, first run | 52 | 87 | 96 | 100 | 1864 | 4301 | 3239 | 0 | 2651 |
|  |  | old, second run | 48 | 87 | 96 | 100 | 1821 | 4789 | 3823 | 0 | 2688 |
|  |  | new, before this pass | 45 | 85 | 100 | 100 | 2132 | 5484 | 4575 | 0 | 2847 |
|  |  | **new, after** | 45 | 100 | 100 | 100 | 1668 | 4858 | 4755 | 0 | 2345 |
| Watchizer home | desktop | old, first run | 70 | 88 | 93 | 100 | 466 | 975 | 867 | 0 | 2770 |
|  |  | old, second run | 71 | 88 | 93 | 100 | 453 | 984 | 817 | 0.002 | 2770 |
|  |  | new, before this pass | 64 | 85 | 96 | 100 | 505 | 1437 | 1036 | 0 | 3014 |
|  |  | **new, after** | 67 | 100 | 100 | 100 | 426 | 1120 | 1099 | 0 | 2549 |
| Watchizer category | mobile | old, first run | 75 | 95 | 96 | 100 | 1374 | 4955 | 257 | 0 | 477 |
|  |  | old, second run | 83 | 95 | 96 | 100 | 1371 | 3924 | 234 | 0 | 477 |
|  |  | new, before this pass | 79 | 95 | 100 | 100 | 1516 | 3984 | 337 | 0 | 602 |
|  |  | **new, after** | 76 | 100 | 100 | 100 | 1513 | 4241 | 342 | 0 | 600 |
| Watchizer category | desktop | old, first run | 99 | 88 | 93 | 100 | 378 | 955 | 0 | 0 | 541 |
|  |  | old, second run | 99 | 88 | 93 | 100 | 382 | 971 | 5 | 0 | 541 |
|  |  | new, before this pass | 99 | 88 | 96 | 100 | 414 | 886 | 43 | 0.001 | 730 |
|  |  | **new, after** | 98 | 100 | 100 | 100 | 413 | 1023 | 34 | 0 | 724 |
| Watchizer brand | mobile | old, first run | 85 | 95 | 96 | 100 | 1515 | 3980 | 163 | 0 | 463 |
|  |  | old, second run | 81 | 95 | 96 | 100 | 1366 | 4049 | 243 | 0 | 463 |
|  |  | new, before this pass | 78 | 95 | 100 | 100 | 1514 | 4127 | 329 | 0 | 589 |
|  |  | **new, after** | 79 | 100 | 100 | 100 | 1514 | 4116 | 296 | 0 | 587 |
| Watchizer brand | desktop | old, first run | 95 | 88 | 93 | 100 | 382 | 1526 | 0 | 0.003 | 537 |
|  |  | old, second run | 99 | 88 | 93 | 100 | 377 | 917 | 6 | 0.001 | 537 |
|  |  | new, before this pass | 98 | 89 | 96 | 100 | 414 | 1006 | 31 | 0.001 | 725 |
|  |  | **new, after** | 99 | 100 | 100 | 100 | 419 | 795 | 36 | 0.001 | 720 |
| Watchizer product | mobile | old, first run | 78 | 96 | 96 | 100 | 1372 | 4694 | 236 | 0 | 584 |
|  |  | old, second run | 77 | 96 | 96 | 100 | 1370 | 4696 | 265 | 0 | 584 |
|  |  | new, before this pass | 78 | 96 | 100 | 100 | 1374 | 3708 | 447 | 0 | 708 |
|  |  | **new, after** | 80 | 100 | 100 | 100 | 1379 | 3898 | 353 | 0 | 707 |
| Watchizer product | desktop | old, first run | 99 | 96 | 93 | 100 | 340 | 957 | 0 | 0 | 413 |
|  |  | old, second run | 97 | 96 | 93 | 100 | 340 | 1308 | 0 | 0 | 413 |
|  |  | new, before this pass | 99 | 96 | 96 | 100 | 341 | 945 | 22 | 0 | 602 |
|  |  | **new, after** | 98 | 100 | 100 | 100 | 375 | 1159 | 41 | 0 | 604 |
| Watchizer Hot Deals | mobile | new, before this pass | 80 | 100 | 100 | 92 | 1213 | 3915 | 348 | 0 | 570 |
|  |  | **new, after** | 71 | 100 | 100 | 100 | 1214 | 5684 | 321 | 0 | 574 |
| Watchizer Hot Deals | desktop | new, before this pass | 89 | 95 | 96 | 92 | 298 | 2167 | 27 | 0 | 582 |
|  |  | **new, after** | 99 | 100 | 100 | 100 | 294 | 861 | 0 | 0 | 591 |
| Watchizer Flash Sale | mobile | new, before this pass | 79 | 100 | 100 | 92 | 1215 | 4032 | 360 | 0 | 585 |
|  |  | **new, after** | 83 | 100 | 100 | 100 | 1214 | 4108 | 208 | 0 | 589 |
| Watchizer Flash Sale | desktop | new, before this pass | 99 | 95 | 96 | 92 | 293 | 862 | 24 | 0 | 593 |
|  |  | **new, after** | 89 | 100 | 100 | 100 | 292 | 2262 | 0 | 0 | 601 |
| Brand Fashion home | mobile | new, before this pass | 44 | 85 | 100 | 100 | 2305 | 7439 | 1301 | 0 | 2298 |
|  |  | **new, after** | 49 | 100 | 100 | 100 | 2117 | 5100 | 1763 | 0 | 1385 |
| Brand Fashion home | desktop | new, before this pass | 83 | 86 | 100 | 92 | 500 | 2546 | 137 | 0 | 2499 |
|  |  | **new, after** | 94 | 100 | 100 | 100 | 460 | 1279 | 144 | 0 | 1484 |
| Brand Fashion category | mobile | new, before this pass | 65 | 100 | 100 | 100 | 1525 | 5147 | 594 | 0 | 1805 |
|  |  | **new, after** | 77 | 100 | 100 | 100 | 1524 | 4454 | 305 | 0 | 823 |
| Brand Fashion category | desktop | new, before this pass | 98 | 93 | 100 | 100 | 423 | 1004 | 34 | 0 | 2039 |
|  |  | **new, after** | 98 | 100 | 100 | 100 | 432 | 1092 | 60 | 0 | 860 |
| Brand Fashion brand | mobile | new, before this pass | 60 | 100 | 100 | 100 | 1521 | 5520 | 808 | 0 | 2223 |
|  |  | **new, after** | 80 | 100 | 100 | 100 | 1524 | 3529 | 393 | 0 | 798 |
| Brand Fashion brand | desktop | new, before this pass | 98 | 93 | 100 | 100 | 421 | 956 | 44 | 0.001 | 2326 |
|  |  | **new, after** | 98 | 100 | 100 | 100 | 432 | 1110 | 30 | 0.001 | 848 |
| Brand Fashion product | mobile | new, before this pass | 80 | 100 | 100 | 100 | 1374 | 3693 | 378 | 0 | 1419 |
|  |  | **new, after** | 74 | 100 | 100 | 100 | 1516 | 4670 | 349 | 0 | 895 |
| Brand Fashion product | desktop | new, before this pass | 99 | 100 | 100 | 100 | 373 | 932 | 50 | 0 | 1535 |
|  |  | **new, after** | 99 | 100 | 100 | 100 | 382 | 988 | 58 | 0 | 934 |
| Brand Fashion Hot Deals | mobile | new, before this pass | 82 | 100 | 100 | 92 | 1212 | 3825 | 306 | 0 | 550 |
|  |  | **new, after** | 86 | 100 | 100 | 100 | 1213 | 3916 | 176 | 0 | 555 |
| Brand Fashion Hot Deals | desktop | new, before this pass | 100 | 100 | 100 | 92 | 295 | 808 | 26 | 0 | 572 |
|  |  | **new, after** | 99 | 100 | 100 | 100 | 294 | 848 | 2 | 0 | 576 |
| Brand Fashion Flash Sale | mobile | new, before this pass | 81 | 100 | 100 | 92 | 1213 | 3857 | 315 | 0 | 565 |
|  |  | **new, after** | 85 | 100 | 100 | 100 | 1214 | 3854 | 209 | 0 | 570 |
| Brand Fashion Flash Sale | desktop | new, before this pass | 87 | 100 | 100 | 92 | 294 | 2495 | 27 | 0 | 582 |
|  |  | **new, after** | 86 | 100 | 100 | 100 | 294 | 2607 | 20 | 0 | 586 |

Audits of Accessibility, Best practices and SEO still not passing on the new build:
- `label-content-name-mismatch` (accessibility): wz-category desktop, wz-brand desktop, bf-home mobile, bf-home desktop, bf-category mobile, bf-category desktop, bf-brand mobile, bf-brand desktop, bf-product mobile, bf-product desktop

## P4. Where a page's bytes and requests are (computer, one cold load, 8 s)

| What | old-category: KB (requests) | new-category: KB (requests) | old-product: KB (requests) | new-product: KB (requests) | old-home: KB (requests) | new-home: KB (requests) |
|---|---|---|---|---|---|---|
| data: link prefetch (the router) | 9 (12) | 8 (12) | 4 (5) | 4 (5) | 4 (5) | 4 (5) |
| data: other | 0 (4) | 11 (3) | 0 (5) | 11 (4) | 0 (13) | 11 (12) |
| data: the menu (asked once a session) | — | 97 (1) | — | 97 (1) | — | 97 (1) |
| other (Manifest) | 1 (1) | 1 (1) | 1 (1) | 1 (1) | 1 (1) | 1 (1) |
| other (Other) | 8 (6) | 8 (4) | 8 (7) | 8 (5) | 8 (6) | 8 (4) |
| pictures: banners | — | — | — | — | 476 (3) | 102 (5) |
| pictures: brand logos | 8 (8) | 8 (8) | 1 (1) | 1 (1) | — | — |
| pictures: categories (row + menu) | — | 55 (10) | — | 55 (10) | 29 (15) | 84 (25) |
| pictures: product photos (recorded renditions) | — | — | 37 (6) | 37 (6) | 45 (6) | 63 (8) |
| pictures: product photos through the optimiser | 109 (16) | 111 (17) | — | 3 (1) | — | 49 (9) |
| pictures: small product pictures through the optimiser (menu, chips) | — | 6 (4) | — | 6 (4) | — | 11 (7) |
| pictures: the logo | 7 (1) | 3 (1) | 7 (1) | 3 (1) | 7 (1) | 3 (1) |
| pictures: the shop's own (icons, posters) | — | — | — | — | 40 (2) | 40 (2) |
| scripts (JS) | 276 (31) | 275 (30) | 298 (33) | 297 (32) | 566 (37) | 572 (37) |
| styles (CSS) | 20 (8) | 35 (10) | 22 (8) | 36 (10) | 23 (9) | 40 (12) |
| the 3D watch (model + light) | — | — | — | — | 1232 (2) | 1232 (2) |
| the page (HTML) | 50 (1) | 54 (1) | 35 (1) | 38 (1) | 149 (1) | 75 (1) |
| **total** | **488 (88)** | **673 (102)** | **413 (68)** | **598 (82)** | **2581 (101)** | **2394 (132)** |

Reading it: a category or product page is **+185 KB and +14 requests** against old here. Of that, **97 KB is the menu's
data, which this machine's test server sends uncompressed — production's web server sends it as 10 KB**; the campaigns'
data is 11 KB here and 3 KB there. What remains in production is about **+90 KB on a computer** (61 KB of menu pictures
warmed up after load so the first hover is instant, 15 KB of styles for the campaign surfaces, the menu and the offer
strips, 10 KB the menu's data, 3 KB the campaigns', 4 KB of HTML) and **+33 KB on a phone** (no warm-up there). The home
is lighter than old's on both.

## P5. The goal, metric by metric (new after, against old)

| Metric | Home | Category | Product | Verdict |
|---|---|---|---|---|
| TTFB | −89 % / −83 % | −80 % / −66 % | −87 % / −63 % | **faster** (computer / phone) |
| FCP | −13 % / −4 % | +9 % / −8 % | +16 % / −9 % | phone faster; computer inside the harness's spread (P1, note 1) — **not a clear win** |
| LCP | −13 % / −5 % | +9 % / −29 % | +7 % / −12 % | **phone faster**; computer level |
| CLS | 0 | 0 | 0 | equal (old's home had 0.002 in one run) |
| TBT (for INP) | +29 % / +57 % | +40 % / +37 % | +20 % / +47 % | **behind old** — P6 |
| JS size | +1 % | 0 | 0 | equal |
| Total weight | −1 % / −46 % | +38 % / +43 % here; ≈ +18 % / +8 % in production | +45 % / +44 % here; ≈ +22 % / +8 % | home **lighter**; category and product **heavier** — P4, P6 |
| Requests | +69 % / −16 % | +16 % / +18 % | +21 % / +21 % | **more** — P6 |
| Menu, pointer to panel | 109 → 30 ms | | | **faster**, kept |

## P6. What is still behind old, why, and how each could be bought back — for Michael to decide

None of these was changed, because each is a feature items 1–43 asked for, or changes how the shop behaves.

1. **Main-thread blocking (TBT).** After the first paint the browser makes the page interactive in one piece; the new
   pages have more to make interactive — the Hot Deals switch and its clock, the menu, the offer strips on cards, and a
   home of 13 sections with 83 cards (item 38). Traced on the throttled phone's category page: old 353 ms in that one
   task, new 509 ms (traced before the last change, which took the drawer's copy of the sidebar out of it); plus two short tasks the old build does not have (the campaigns' and the menu's data arriving,
   ~60–150 ms each on the 4×-slowed CPU). On a real mid-range phone these are a quarter of the figures.
   *What was tried and taken back:* dividing the page with `<Suspense>` boundaries so it becomes interactive in parts.
   Measured: React's server then sends any part larger than 12.8 KB **out of order** — the header and the home's sliders
   arrived at the end of the document and were swapped in by an inline script. First paint came much sooner (Watchizer
   home on the phone 2176 → 1592 ms, Brand Fashion's 2192 → 1148 ms) but the home's blocking time rose (1701 → 2475 ms),
   the header could paint late on a slow line, and a reader without JavaScript would get the cards hidden. Reverted the
   same evening; the measurements are in `evidence-perf\cmp-new-r6.txt`. **Option for Michael:** ship it for the home's
   sliders only (the sections below the fold) — the largest single speed gain left, at the cost above.
2. **The 3D watch on Watchizer's home** (unchanged, old and new alike). On the throttled phone its model and lighting
   are a 3.5 s script task and 1.2 MB; it is why the home's Lighthouse Performance is 45–52 on a phone in BOTH builds.
   Blocked (the poster stays), the old home's TBT on a computer is 108 ms and the new one's 251 ms. It starts after
   load, at idle — the right moment for a real visitor. **Options:** leave it (chosen); start it on the first movement or
   touch; or run the home in Banner mode (no 3D) — the Banners screen's switch.
3. **The menu's warm-up** (item 36: never "Loading", the panel's pictures there on the first hover): 14 requests and
   61 KB on every first page of a visit, on computers only, 2.5 s after load. **Option:** warm on the pointer reaching
   the header instead — saves them for every visitor who never opens the menu; the first hover then waits ~100–300 ms
   for its pictures.
4. **Styles:** 35 KB in 10 files against old's 20 KB in 8 (the campaign surfaces, the menu, the offer strips, the home
   zone). The campaign sheet alone is 11.5 KB and loads on every page because the header's switch shares it. **Option
   (a developer's hour or two, with a careful look at every campaign surface afterwards):** split it so a page that
   draws no campaign surface loads about 7 KB less.
5. **A listing page's data is carried twice** inside its HTML (core's page and the adapted rows): about 100 KB of a
   category page's 403 KB. Cutting it touches what the filters read after load; not done tonight. Likewise the lookup
   tables (81 KB in every page, old and new).
6. **The home has more on it than old's** (13 sections against the old page's): +79 requests on a computer in the first
   8 s. Fewer sections or fewer cards per slider is Michael's call, not mine.

## P7. Decisions I made in this pass — options, reason, undo

**P-D1 — The menu's data is no longer inside every page (supersedes D7).** It was 32 KB of script in each page, read
before the first paint. Now: asked for once, when the page has loaded and the browser is idle (not on a hover), slimmed,
kept for the session (later pages have it before they finish loading); Watchizer's home, whose category row draws from
it, still carries it. Measured on the final builds, both shops (`evidence-perf\probe-menu-ready.txt`): **no loading
state is ever drawn**; from the second page of a visit, and from a couple of seconds into the first, the panel is
there 60 ms after the pointer with its 11 pictures loaded (30 ms in the timing run). **The one window it leaves:** a
hover in the first second or two of the FIRST page of a visit asks for the menu at that moment, and the panel opens
when the answer lands — 60 ms on a product page here, and up to 3 s on a category page, where this machine's
one-request-at-a-time test server had the page's own requests queued in front of it (production answers in parallel).
*Options:* (a) as it is — chosen; (b) ask as soon as the page's script starts instead of when it is idle — closes the
window, costs one request in the page's busiest second; (c) put the data back in every page (D7) — closes it
completely, costs 32 KB in every page. *Undo to (c):* restore the three lines in `app/(main)/layout.jsx` (the comment
there names them).

**P-D2 — Held reads (the largest gain of the pass).** Options: (a) leave every visitor waiting for core (old
behaviour); (b) hold core's answers in the storefront's server for a short window — chosen; (c) make the pages static —
not possible without redesigning how a page learns its language (every page reads the request). Windows: home 30 s
fresh / 5 min at most (the page's own `revalidate = 300` always promised five minutes); a listing 20 s / 2 min; a
product page 10 s / 60 s; a "no such product" answer is held for the fresh window only. **What it costs:** a price or a
stock figure on a card can be that old at the very worst; the cart and the checkout re-validate every price and stock
against core, as they always did, so nobody is charged a held price. A dashboard change to the home shows within 30 s
to 5 min (it was "within 5 min" on paper before). *Undo:* set a window's two numbers to 0 in `src/lib/coreRead.js`
(`HOLD`) — one object, both shops. Tests: `tests/coreReadHold.test.mjs`.

**P-D3 — Pictures with no recorded rendition go through the shop's own image optimiser at the size drawn** (banners,
the brand banner's products, second photos, menu pictures, Hot Deals cards). The banner file itself stays in the list
as the widest choice, so a large 2× screen gets the full artwork as before. The Node server does a little more image
work: each size once an hour, served stale while it is redone. *Undo:* `optimizedWide` / `optimized` calls in
`BannerHero.jsx`, `HeroZone.jsx`, `BrandBanner.jsx`, `BfProductCard.jsx`.

**P-D4 — Brand Fashion's second photo is not downloaded on a device that cannot hover** (it can never be seen there).
On a computer it works exactly as before (verified: fades in on hover). *Undo:* the last rule of the second-photo block
in `BfProductCard.css`.

**P-D5 — Metadata always in `<head>`** (`htmlLimitedBots: /.*/`). No cost measured. *Undo:* delete the line in each
`next.config.js`.

**P-D6 — Accessibility colours (Watchizer; Lighthouse "color-contrast").** Small text that measured under 4.5 : 1 was
darkened just enough; nothing large, decorative or on a dark ground changed. No wording changed anywhere.

| Where | Was | Now | Ratio |
|---|---|---|---|
| gold as small text — the active language, the section label, the product page's brand, the filter's "Clear" | `#C8A45C` / `#9A7B3C` | `--wz-gold-text` `#8A6B2A` (the trust chips' own gold) | 2.3–3.8 → 4.8 : 1 |
| breadcrumb and "Sort by" label | `#9A948A` | `#736E66` | 2.9 → 4.8 : 1 |
| "Show more" in a filter | 50 % ink | 68 % ink | 3.1 → 5.3 : 1 |
| footer's small print | white 40–45 % | white 50 % | 3.8 → 5.3 : 1 |
| struck-through price in the product page's sticky bar | black 32 % | black 56 % | 2.2 → 4.7 : 1 |
| "Clear all" in the sidebar, the phone summary's discount tag | `#EA2B0F` | `#DC270D` | 4.3 → 4.8 : 1 |
| "You save" badge on the product page | `#DC2626` | `#B91C1C` | 4.0 → 5.3 : 1 |
| banner counter ("2 / 3") pill | black 50 % | black 66 % | 3.9 → 4.5+ : 1 |

*Undo:* each value is one line with the old value in its comment; `--wz-gold-text` is one token in `app/globals.css`.

**P-D7 — Banner dots are 24 px apart (were 14 px)** so each is a 24×24 target (Lighthouse "target-size"); the dot
itself is the same 8 px (22 px when on), on the same line. *Undo:* the dots block in `BannerHero.css`.

**P-D8 — Smaller accessibility items, no visible change:** a banner or side banner with no words of its own is named
"Banner 2 of 3" / "Side banner 1" for a screen reader; the counter and the rating are `role="img"`; a category chip's
picture has an empty alt (its name is printed under it); Watchizer's cart button is named "Cart" followed by what it
shows; a screen-reader-only "Products" heading between a listing's H1 and its cards; Watchizer's footer titles are H2
(same size, 11 px); the price slider's handles are named and the slider is a 24 px target (handles still centred on
the line); Brand Fashion's sale choices are not announced as list items; the header logo's width and height attributes
are the picture's real proportions (68×46, not 150×46 — the drawer's logo jumped when it loaded).

## P8. Lighthouse — what it listed, what was fixed, what remains

Fixed (all in our code): `meta-description` (SEO), `aria-prohibited-attr`, `color-contrast`, `link-name`, `target-size`,
`label`, `listitem`, `heading-order`, `image-redundant-alt`, `button-name` (Accessibility), `image-aspect-ratio` (Best
practices), `uses-responsive-images` and `lcp-lazy-loaded` (Performance), `server-response-time` (P-D2).

Remaining, and why:
- `label-content-name-mismatch` — **weight 0, it does not move the score.** Brand Fashion's card link is named as one
  sentence ("brand, title, price, was …" — the design's §7.4) rather than by its visible words; Watchizer's pager says
  "Previous" where the button shows "Prev". Left as designed.
- `uses-long-cache-ttl` — **the asset host, not our code:** the `.avif` renditions are served with no cache lifetime
  (WebP has 7 days). A list item for Michael (P10).
- `valid-source-maps` (weight 0), `legacy-javascript` (11 KB of polyfills inside Next's own chunk), `unused-javascript`
  (27 KB in a shared library chunk): Next's build, not ours.
- Performance on a phone: the simulated LCP of 3.5–5.7 s is Lighthouse's *model* of a slow phone applied to a page
  loaded from this machine with no network delay — the page's scripts run before the first frame here, and the model
  counts them as needed for the paint. The same pages measured with the throttling actually applied (table A) paint
  their largest element at 1.4–2.1 s. The old build is modelled the same way (3.9–5.0 s), so the comparison holds; the
  absolute figure will be different on PageSpeed Insights, which loads the live site over a real network.

## P9. Lab against field — what to watch after going live

Everything above is a lab result on this machine. What ranks is **field data**: Search Console → Experience → Core Web
Vitals, and the CrUX figure at the top of a PageSpeed Insights report. It is a 28-day rolling window of real Chrome
visitors, so it **starts to move a few days after the deploy and settles after about four weeks**. To watch:
1. PageSpeed Insights on the live home, a category, a brand, a product, /hot-deals and /flash-sale the day after the
   deploy, phone and computer: Accessibility / Best Practices / SEO should read 100; note the Performance figures as
   the new baseline (they will differ from this machine's).
2. Search Console → Core Web Vitals, weekly: "Good URLs" for LCP (≤ 2.5 s) and CLS (≤ 0.1) should hold or rise; **INP
   (≤ 200 ms) is the one to watch** — the lab's stand-in for it is the metric that is still behind old.
3. Search Console → Pages: /hot-deals and /flash-sale indexed with their descriptions (they had none in `<head>` until
   this pass).
4. The server: the storefront's Node process now resizes a few more pictures and holds core's answers for up to five
   minutes — memory should stay flat; a home change on the dashboard should show within five minutes.

## P10. Lists for Michael (nothing here was decided for him)

1. **The asset host serves `.avif` renditions with no cache lifetime** (Lighthouse "uses-long-cache-ttl": cache
   lifetime 0 for every `…-320.avif`; `.webp` has 7 days). A returning visitor re-validates every product photo. It
   is the API host's web-server configuration, not the code: an `ExpiresByType image/avif` (or `Cache-Control`) line
   beside the `AddType image/avif .avif` the host's `.htaccess` already has. Not touched — production.
2. **Three dead links on the scratch copy, none new:** the FAQ's link to `/blog/how-to-tell-if-a-watch-is-original`
   (404, both languages — the post does not exist; already on the FINAL REPORT's list), and the experience page
   `/experience/hugo-boss-grand-prix-1513477` (404 here only: the product is not in this scratch copy; it is on
   production).
3. **One menu label is shortened with "…" at 1280×720** in Brand Fashion's "Men" pane (1 of 16 tiles); at 1366×768 and
   wider all 16 are whole. It is the tile size of the 17:31 menu design, not this pass.
4. **The trade-offs of P6** — the 3D watch, the menu warm-up, sending the home's sliders out of order, the campaign
   stylesheet, the listing's doubled data, the size of the home.
5. **The windows of P-D2** (how old a card's price can be): 5 minutes on the home, 2 on a listing, 1 on a product page.
   Say if any should be shorter; each is one number.

## P11. Checks re-run on the final tree (builds of 23:17–23:18)

| Check | Result |
|---|---|
| Production builds, both shops, Google's font hosts blocked | exit 0, 0 lookups (six builds this evening; the last at 23:17 / 23:18) |
| Storefront unit tests (vitest) | Watchizer **165 passed** (19 files), Brand Fashion **123 passed** (15 files) — 23 new: the home's payload draws the same cards, held reads, the card's cap and a banner's widths |
| ESLint, both shops | **0 errors** (45 and 31 warnings, the standing ones) |
| Core: PHPStan level 10 / Pint / `tsc --noEmit` | `[OK] No errors` / passed / clean (core's code did not change in this pass) |
| Core: twins and the API allow-list | 10 passed, 289 assertions — every listed twin byte-identical; `src/lib/homeData.js` and two tests added to `TWINS.txt` |
| Core: the full suite as a gate, clean scratch copy | **2,286 passed, 31 failed — all 31 in the known baseline — 0 new**, 39 skipped; 0 tables changed by the suite; `mysql.db` OK before and after (746 s, finished 00:28) |
| The pages render the same thing | before/after HTML compared on 8 pages (home EN/AR, category, product, Hot Deals, both shops): **same cards, same text, same links, same pictures**; the only differences are the ones made on purpose (a screen-reader "Cart" and "Products", the footer's H2s, the description now in `<head>`) |
| Console errors | **0** on 80 crawled pages (both shops, computer and phone, EN and AR) and on 269 probe loads |
| Internal links | 669 checked; 181 answered 500 during the fast sweep (this machine's core rate-limits a crawl), re-asked one at a time: **3 dead, all known** (P10.2); no new one |
| Items 32–37 and 41 | blend: 12/12 masks on each of Brand Fashion's two blended sliders, `mix-blend-mode: normal`, the photo untouched (32); every forward navigation lands at 0 — 24 of 24 — and Back restores the position — 8 of 8 (33); Brands panel: equal cards, the pane filled, no inner scroll at 1366/1536/1920, both shops (34); category row and the home at 70 phone sizes and 8 computer sizes: 0 overflow (35); menu: 54 pane measurements, 0 inner scroll, 0 broken pictures, never a loading state drawn (36); banner arrows hit, wrap both ways, EN and AR, both shops (37); offer cards identical in size to normal ones, 10 strips inside the photo box, one clock (41) |
| This pass's own changes, in a browser | the filter drawer on a phone (empty while closed, 9–10 filter groups when opened, a filter ticks, it closes), the price slider (24 px targets, handles centred on the line), banner dots (24×24, the third dot goes to slide 3), banners and brand-banner pictures loaded through the optimiser at 640–1024 px / 320 px, Brand Fashion's second photo (fades in on hover on a computer; 0 of 24 fetched on a phone), the description in `<head>` on /hot-deals, Brand Fashion's home and /ar/flash-sale |
| Layout shift | 0 on every page measured; one figure to know: 0.008 on Watchizer's Arabic Flash Sale page (the Arabic face arriving in the nav row) |

## P12. End of the pass — nothing is left running

Stopped, in the order asked: the old-version shop (:3010), both shops (:3000, :3001), the scratch core (`php -S` :8020)
and every headless browser at 00:01; the core suite then ran alone; **mysqld shut down with `mariadb-admin … shutdown` at
00:29:22** — port 3307 closed, no `node`, `php` or `mysqld` process left. Git is untouched: branch `wave-5`, HEAD
`9a369cd`, no stash, nothing staged by me; no SSH, no deploy, no production write, no Hostinger call. The scratch copies
(`scratch_night_20261105`, `scratch_gate_20261105`) are as they were, except that the four test campaigns on the preview
copy now end on 2026-11-06 23:55 (re-armed at 22:55 for the final measurement — they had ended).

**Evidence** (outside the repo): `D:\coding\watchizer website\checkpoints\2026-11-05_round2\evidence-perf\` — every
measurement file behind the tables (`cmp-*.json/.txt`, `lh-*.json/.txt`, `weight-final.md`, the first-paint and trace
probes), the probes' outputs (`probe-perf.txt`, `probe-menu-ready.txt`, `reg-*.txt`), the crawl, the gate's log, and the
scripts that made them. The tree as a patch plus every untracked file is in the same checkpoint's `tree_0034_perf-final` folder. The commits
script was regenerated from the final tree (`deploy-scripts\COMMITS-PART2-20261105.ps1`: 295 files in 11 commits).

---

# FOLLOW-UP TO THE PERFORMANCE PASS — 2026-11-06 (Michael's decisions on its "for you to decide" list, carried out)

**This section is the current state. Where it and the PERFORMANCE PASS section above disagree, this one is right:
P6 items 2, 3, 4 and 5 are done or decided, P-D2's home window is two minutes, and P10 items 1 and 3 are closed.**

**Continued the same afternoon by "SECOND FOLLOW-UP" at the end of this file — your decisions on F10.2 (the two small
lookups: done) and F10.5 (the 3D watch's weight: left as it is). F13's "nothing is left running" was true at 09:25;
the preview has been up again since 12:49 (G5).**

## F0. The short version

- **Everything on the list is done**, with two things the list did not have (the live offers read once for every
  card — F11; a small inner scroll in Brand Fashion's Arabic menu — F7) and five corrections to what I told you before
  (F9). Nothing from items 1–43 was removed or weakened; no slider is streamed.
- **Against the old build, measured again today, same machine and data:**
  - **faster, clearly:** first byte on every page (−66 to −87 %); first paint and largest paint on a computer (home
    −20 %, category −27 % / −37 %, product −39 % first paint); largest paint on the throttled phone (category −30 %,
    product −18 %); **the home's main-thread blocking on a computer, 930 → 164 ms** (it was 1105 after the performance
    pass — the 3D watch, F2); the home's weight on a phone (−46 %); the menu (105 → 41 ms);
  - **level:** layout shift (0), JavaScript size, the home's first and largest paint on the phone, the home's weight
    on a computer, **the number of requests of a category or product page (87 against 88, 75 against 76, 67 against
    68, 65 against 68 — it was +14 after the performance pass)**;
  - **still behind old:** main-thread blocking on the throttled phone — home 1278 → 1626 ms, category 669 → 788 ms,
    product 422 → 494 ms (after the performance pass: 1733, 867, 518) — and on a computer for a category and a product
    page by about 110 ms (one task of ~170 ms where old has several short ones); and a category or product page's
    weight, **+114 KB measured here, about +22 KB in production** (F8 says what each byte is). What is left is what the
    page has more of than old — the menu, the Hot Deals switch, the offer strips, a home of thirteen sections — being
    made interactive in one go. F10 lists what could still be bought, for you to decide.
- **Lighthouse** (lab): Accessibility, Best Practices and SEO are **100 on every page type of both shops, phone and computer — 40 runs of 40**; Performance is up on the home (Watchizer on a computer 67 → 83 and 93, where old reads 67; Brand Fashion on a phone 49 → 66 and 67), level on category and brand pages, lower in this round on the product page on a phone (69–76 against old's 78–79), and on the campaign pages it reads 84–90 on a computer in Lighthouse's simulation and 94–97 with the throttling applied (F8).
- **These are lab results.** Google's real-user data (Search Console → Core Web Vitals, CrUX) only moves in the weeks
  after the deploy; the performance pass's P9 says what to watch, INP first.

## F1. Your decisions, one by one

| # | You decided | Done | Proof |
|---|---|---|---|
| 1a | Listing's doubled data: remove it | a listing page's HTML carries core's page once | category HTML 403 → **361 KB** (old's is 385); `tests/listingPack.test.mjs` (6): the cards drawn are the same cards — F3 |
| 1b | Campaign stylesheet: split it | `promo-surfaces.css` is loaded only by pages that draw a campaign surface | 22 surface pages compared property by property with the one-file original: **0 differences**; category / brand / product / cart pages do not load it — F4 |
| 1c | Menu picture warm-up: off the first load, menu still ready | asked for when the pointer heads for the menu | a page left alone for 8 s asks for **0** menu pictures (was 14); pointer travelling to "Watches": panel open with 11 of 11 pictures, no loading state — F5 |
| 1d | Do not stream the home sliders | not done, nothing of the trial is in the tree | 0 `<div hidden id="S:` in the served pages |
| 1e | The 3D watch: say exactly what happens, apply only what the customer cannot see | reported; two changes applied, each measured; the rest listed with numbers | blocking on a computer **1228 → 64–71 ms**; the canvas **identical pixel for pixel** to the old build's — F2 |
| 2 | Home window 5 → 2 minutes; an offer starting or ending refreshes the held copy at once; a test | done on both shops; core names the next start | `tests/campaignHold.test.mjs` (8), core `CampaignTest` (+1), `tests/liveOffers.test.mjs` (4) — F6 |
| 3 | `.avif` lifetime: the exact lines, in the deploy record | written, with the check, the fallback and the undo; production not touched | `DEPLOY_PART2_20261105.md` §11.3 |
| 4 | Brand Fashion's Men label cut at 1280 | fixed — and it was three panes, not one | 84 panes measured (both shops, English and Arabic, 1100–1920 px): **0 cut from 1280 up, 0 inner scroll** — F7 |
| 5 | The migration count for part 2 | **SEVEN**, listed by name; item 43 B has none; the script and the record both expect exactly seven | `DEPLOY_PART2_20261105.md` §11.1; the record's §5 and §6 corrected in place |
| 6 | Re-measure the same way; Lighthouse on the 12 page types | done — F8 | `evidence-followup\` |
| 7 | All checks; records; commits script; checkpoint; stop everything | done — F12, F13 | |

## F2. The 3D watch — what happens today, why it still counted, what was changed

**What was already built (you are right):** the home's first paint is a still picture of the watch — captured from the
canvas itself, same pose and light — eager and high priority; it is the largest paint. The 3D is not in the page's
JavaScript at all: its code is asked for only **after the window's load event, when the banner is in view, when the
browser reports itself idle** — never under reduced motion or Save-Data — and the canvas is cross-faded over the still
when its first frame is ready. That part needed no change.

**The timeline, measured before any change** (one cold load, times from the navigation's start;
`evidence-followup\w3d-before-*.txt`):

| | Computer | Throttled phone (CPU 4× slower, 1.6 Mbit/s) |
|---|---|---|
| First paint / largest paint (the still) | 899 / 899 ms | 2363 / 2405 ms |
| The window's load event | 542 ms | 3493 ms |
| The 3D's six script files asked for | **1415 ms** (0.9 s after load, at idle) | **9851 ms** (the browser was not idle before) |
| The studio light (`.hdr`, 411 KB) | asked 1831 ms, in at 1837 ms | asked 12,336 ms, in at 16,583 ms |
| The model (`.glb`) | asked 1901 ms, in at 1966 ms — 821 KB | asked 12,790 ms, in at 17,445 ms — 516 KB |
| The 3D's files in all | **1,515 KB in 8 requests** | **1,210 KB in 8 requests** |
| Long tasks after the 3D's first request | 54 ms, **1110 ms**, 214 ms | 272, 110, 88, 95, 187, 175, **888**, 60, 299, 65, 57 ms |
| Blocking time from those tasks | **1228 ms** | **1745 ms** |

**Why it still counted in the measured blocking time and weight:**
- *On a computer* everything above happens inside the eight seconds each measurement watches: the files are 59 % of
  the home's bytes there (1,515 of 2,553 KB) and the 1110 ms task was the home's whole blocking time — the page itself
  had none after its first paint in that run. Loading "after the page is ready" moves the work off the first paint; it
  does not make it free, and a lab tool counts every long task until the page goes quiet.
- *On the throttled phone* the 3D starts at about ten seconds — **after** the eight-second window. So the phone rows
  of the tables (blocking 1733 ms, weight 976 KB) never contained the watch; that blocking is the page's own. It is
  Lighthouse, which waits until the network and the processor are quiet, that counts the watch on a phone — the home's
  45–52 there.
- *What the 1110 ms task was* (CPU profile, `prof3d-before-computer.txt`): not the model. three.js turns the studio
  light into the map a metal reflects (a "PMREM") the first time a material is compiled, and it does so synchronously —
  it creates the filter's shaders and uses them in the same call, so the page waits for the graphics driver to compile
  and link them: 0.9–1.1 s on this machine's Intel graphics (Direct3D), in one piece. On the throttled phone profile
  the same wait is the 888 ms task (it is the graphics driver's time, so slowing the processor does not stretch it).
  My earlier "a 3.5 s script task" was Lighthouse's simulated figure for it, not a measured task — F9.

**What was changed — nothing a customer can see** (Watchizer only: `src/Components/Experience/studioLight.js` new,
`GrandPrixCanvas.jsx`, `watchModel.js`):

| # | Change | Measured (blocking time of the 3D's own tasks) |
|---|---|---|
| 1 | **The studio map is filtered without freezing the page.** The same generator (three's own, same input, same output) is driven in two steps: its shaders are handed to the driver and awaited — the browser compiles them in its graphics process — and only then is the filter run, with programs that are already linked. On a bare page: the filter call blocks **760 ms as three runs it, 19 ms** this way | computer 1228 → **192–208 ms**; phone 1745 → **916 ms** |
| 2 | **The model is prepared, batched and its nine pictures handed to the graphics card in short tasks** with a pause between them (the same functions, the same order) | computer → **64–71 ms** (largest task 100 ms); phone → **700 ms** (largest task 248 ms — three.js itself being read by the browser, which cannot be divided) |

In the home's own numbers (three cold loads, median): **blocking time on a computer 1105 → 164 ms** (old: 930 ms); with
the 3D's files blocked altogether it is 107 ms, so the watch now costs the page about 60 ms. Lighthouse on a computer:
Performance 67 → 83 and 93 in two runs, its blocking figure 1099 → 154 and 180 ms.

**That nothing changed for the eye, proved three ways:** (a) the filtered map read back from the graphics card, all
786,432 values of it, is **identical** made three's way and this way (`pmrem-identity.txt`); (b) the live canvas,
captured from the old build and from the new one — same window, the still and the drifting bokeh hidden — is
**identical pixel for pixel**: 337,050 pixels on a computer and 131,820 on a phone, English and Arabic, largest
difference 0 of 255 (`canvascmp-*.txt`; the same check told English from Arabic apart by 55 % of the pixels, so it is
not blind); (c) `tests/watchBatches.test.mjs`: the batches built in steps are the batches built in one go.

**What you asked about and was already there:** the model IS compressed — Meshopt for the geometry (302 KB), quantised
positions and normals, WebP for its nine pictures; the phone gets its own file with smaller pictures (881 KB against
1,199 KB), and the server sends both gzipped (821 / 516 KB on the wire). Draco would not make them smaller than Meshopt
does here, and a second pass over files the brand supplied is not something I would do without you.

**Not applied, because each changes what the customer sees — for you to decide, with its numbers:**

| Option | What it saves | What the customer would notice |
|---|---|---|
| Start the 3D only on the first touch, scroll or pointer move (phones only, or everywhere) | a visitor who never touches the page downloads none of the 1,210 KB (phone) / 1,515 KB (computer) and the page has none of its tasks; on a computer the home is then 1,322 KB instead of 2,554 KB | the watch cannot be turned until a moment after the first touch (1–2 s on broadband, 5–8 s on a slow phone line); until then it is the still |
| Phones get the still only | 1,210 KB and about 700 ms of (4×-slowed) blocking on every phone visit | no turning watch on a phone at all |
| Smaller pictures inside the model | the nine pictures are 704 KB of the computer file and 386 KB of the phone file; the experience page's first model has them at 256 px in 22 KB (a 471 KB file, 145 KB on the wire) | the dial's print and the engraving go soft when the watch is zoomed |
| A smaller studio light (256×128 instead of 512×256) | about 300 of its 411 KB | reflections on the polished steel get blurrier |
| Banner mode (the Banners screen's switch) | everything: no 3D on the home | the hero is the banners |

The same freeze exists in the old gold-watch hero (`WatchCanvas.jsx`, drawn when the hero is set to "3D watch") and in
the experience page's stage (behind its loader). Neither is on the home today; neither was touched. The fix is one
import and one component each when you want it.

## F3. A listing page's data, once

The server page put its whole adapted answer into the HTML: every card twice (a row for the card component, which
itself keeps every field of core's card, and core's card again under `v2`) and every count block twice. It now puts
**core's page alone** (`v2Listing.js` `packListing`); the hook that reads it rebuilds the rest with the same adapter
(`unpackListing`, in `useListing`'s `select`, which is now a stable function — it used to run again on every render).
The browser's own refetch is untouched. Both shops, the two listing pages (`/listing` and the category / brand / other
facet pages).

Measured: a category page's HTML **403 → 361 KB** on Watchizer (old's is 385 KB) and 400 KB on Brand Fashion; gzipped
45 → 44 KB against old's 45. `tests/listingPack.test.mjs` takes core's real answer for three cards through the HTML's
JSON and back and compares the result with the adapter's own, field for field, then through the real card transform:
the same cards.

*Left as it is:* the lookup tables (81 KB in every page, old and new alike) — not on your list, and every page reads
them.

## F4. The campaign stylesheet, split

`promo.css` (52 KB before compression) was on every page because the header's switch, the clock, the offer strip on a
card and the payment badges need it. What only a campaign SURFACE draws — the deal card, the home's two sliders, the
campaign page — is now `promo-surfaces.css` (30 KB), imported by the two components that draw one (`CampaignRail`,
`CampaignPage`), always after `promo.css` (22 KB). The split was made by a script, rule by rule, each file keeping the
original order; a rule that names a surface class went to the new file, a selector list that mixed the two was divided,
a `@keyframes` went where it is animated. No declaration was lost (counted).

**The careful look afterwards, done as a measurement:** on 22 pages that draw a surface — the home, /hot-deals,
/flash-sale and the home in Hot Deals mode, both shops, computer and phone, English and Arabic — every element inside a
surface and its `::before` / `::after` had every computed property read twice: as the page is served, and with the
ORIGINAL one-file stylesheet added again after everything. **0 differences** in 4,900 style sets (a clock digit that
ticked between the two readings is left out; a custom property is compared without its white space). And a category,
brand, product, listing or cart page loads no stylesheet that contains a surface rule (`splitcheck.txt`).

What it is worth: a page that draws no surface carries about 3 KB less CSS on the wire (35 → 32 KB here; old's 20).
Honestly: that is less than the 7 KB I estimated — the surfaces' rules compress well. It is in, it is safe, and it is
the smallest of the gains.

## F5. The menu's pictures

Before: 2.5 s after every page load, on a computer, the first section's pictures were asked for — 14 requests and 61 KB
for a panel most visits never open. Now nothing is asked on a clock. The first section's pictures are asked for the
first time the pointer moves in the upper part of the window (the header and 240 px under it, or the top 40 % of the
window, whichever is larger) — well before it can reach an entry — six at a time; every section's when the pointer
reaches the header or focus enters it, as before. Phones were already exempt.

Measured on a category page as the first page of a visit, both shops (`probe-warm.txt`):

| Case | Result |
|---|---|
| Nothing moves for 8 s | **0** menu pictures asked for (the menu's data: 1 request, as before) |
| After 6 s the pointer travels from the middle of the page to "Watches" (12 steps, 360 ms) | 60 ms after arriving: panel open, **11 of 11 pictures loaded**, no loading state |
| The pointer lands on "Watches" in one step (no travel at all — the worst case) | the same: open, 11 of 11 at 60 ms |
| The pointer goes there 400 ms after the page becomes interactive (before the menu's data was asked for) | the same |

A loading state is never drawn in any of them. **What to know:** this machine serves a picture in a few milliseconds.
On a real connection the first hover of a visit can open the panel a moment before its last pictures arrive (each is
4–6 KB; the tiles' boxes are reserved, nothing moves). From the second page of a visit they are in the browser's cache.
*Undo:* the previous effect is in git history of `MegaMenu.jsx`; or widen the zone (one number).

## F6. Held reads: the windows, and an offer's start and end

**The windows now:** home **30 s / 2 minutes at most** (was 5), a listing 20 s / 2 minutes, a product page 10 s / 1
minute. One thing I had wrong (F9): Brand Fashion's home is read through a different function, which had kept a longer
window of its own — its cards could be up to ten minutes old, not five. It reads with the home's window now, so "two
minutes at most" is true on both shops.

**What an offer touches, traced through the code before changing anything:** a campaign does not change a price — it
is a selection of products with a start and an end. Everything a page draws of an offer comes from ONE answer of core,
the live campaigns: the header's switch, the home's two sliders, the two campaign pages, the strip on a card and the
note on a product page (a card asks "is my product in a live offer?" of that answer's product list). The held copies of
the home, of a listing and of a product page carry no campaign state at all. So the copy that matters is the held
campaigns answer (30 s as the answer, refreshed behind the request after that).

**What was wrong with it:** an END was already known to the browser (it hides a campaign at its last second) but the
server could go on rendering the ended campaign into the HTML for up to 30 seconds; a START was not known to anyone
until the next poll — up to 30 s on the server, up to a minute in an open page.

**What it does now:**
- Core's campaigns answer names, beside its clock, **the next scheduled start** (`next_start_at`: the earliest start
  after now among the storefront's active campaigns — one indexed read). Every end was already in the answer.
- The storefront's server computes, from the answer itself and on **core's clock** (never its own), the instant the
  copy stops being true — the earliest end or start. **From that second the copy is not served: the request waits for
  core**, exactly as if nothing were held. So the first page rendered after a sale ends has no strip, switch, slider or
  campaign page of it, and the first after a sale starts has them all.
- If core cannot be asked at that second, the last good copy is handed out **without whatever has ended** — a sale
  that is over is never drawn, even then.
- An open page asks again **at the start second** (one timer per page), so the strips appear on a page somebody is
  looking at without waiting for the minute's poll. At an end it already dropped the offer by itself.

**The tests:** `tests/campaignHold.test.mjs` (both shops, 8 tests, on a clock the test moves): one second before an
end, and before a start, the copy is the answer and core is not asked; AT that second core is asked and the page gets
its answer; the instant is read on core's clock when the two clocks are an hour apart; with core down at the end the
copy goes out without the ended offer; with nothing in sight it holds as before. Core: `CampaignTest` — the next start
is named to the second, a switched-off campaign is not one, and it moves on when it comes. `tests/liveOffers.test.mjs`:
a card says which offer it is in, in the server HTML; the Flash Sale wins; a campaign that is over says nothing.

**What is still not "at once", and cannot be without core telling the shops:** a change made BY HAND on the dashboard —
a campaign switched off before its time, its dates moved, a product taken out of it. That reaches a page within 30
seconds (the server's copy) and an open page within a minute (its poll), as before. Making it instant needs core to
notify the storefronts; say if you want it.

**What the two-minute home costs:** a visitor who is the first on the home for more than two minutes waits for core
before the first byte, as every visitor of the old build did (0.7–0.9 s here). With five minutes that was rarer.
Lighthouse met it once in this round (F8). *Undo:* `HOLD.home` in `src/lib/coreRead.js`, one number.

## F7. The menu label — three panes, not one — and one thing found beside it

Measured first, at 1280×720, every pane of both shops: "Chronograph" was cut with "…" in **Brand Fashion's Men** (73 px
for the 79 it needs), in **Brand Fashion's Women** (77 for 90) and in **Watchizer's Women** (85 for 90). I had reported
one (F9). A name may already take two lines; a single long word cannot.

The rules follow the room in the pane, not the window (`megaMenu.css`, a twin):
- **six tiles in a row** in a pane narrower than 800 px: the count goes under the name — as a dense grid already has
  it — so the name has the tile's whole width; the picture is 4:3 instead of square and gives back the line the count
  takes, so the pane is not taller;
- **a dense grid** (seven or more in a row) in a pane narrower than 760 px: the tiles stand side by side without a gap
  and with 1 px of padding — 11 px more for the name; the picture keeps its width.

**Beside it:** Brand Fashion's Arabic Fashion pane was 6 px taller than the panel at 1280×720 and scrolled inside by
that much (before this change — the regression runs of yesterday used taller windows). In a window 740 px tall or less
the suggested products now stand 8 px closer to the tiles; it fits.

Measured after, 84 panes — every top-level entry, both shops, English and Arabic, windows 1100 to 1920 px wide
(`probe-tiles-after.txt`): **no name cut from 1280 up, no pane scrolling inside, no panel past the window**; at 1440
and wider nothing is different from before. Under 1280 (1100 px) names are still cut in places, fewer than before; that
width is below what you asked for and was not made worse.

What you will see at 1280–1366 px: in the Fashion and Women panes the count under the name and a slightly shorter
picture; in Men the tiles touching. *Undo:* the two `@container` blocks and the `@media (max-height: 740px)` block at
the end of the tile rules in `megaMenu.css`.

## F8. The numbers — old against the previous pass against now

**How it was measured:** exactly as before — the same machine, the same scratch core and data with a Hot Deals and a
Flash Sale campaign live, production builds (`next build` + `next start`, pixel ids blank), `MSYS_NO_PATHCONV=1`, every
URL confirmed before the run (old's /hot-deals is its 404, as before); a computer (1440×900) and the throttled phone
(390×844, CPU 4× slower, 1.6 Mbit/s, 150 ms), **three cold loads each, the median**.

**Three things to know when reading the columns:**
1. **"Old" was measured again today** (about 07:00), an hour's work apart from "now" (final build of 07:23, measured
   07:25–07:40). **"Previous pass" is last night's measurement** of the performance pass's final build — that build no
   longer exists, so it could not be measured again. Table F-A2 shows the same old build on both days: this harness
   moves by up to ±8 % on the phone and by more on a computer from one day to the next (today a fresh browser starts
   faster: old's product page paints at 776 ms where it took 928 last night). So "now against old" is the fair
   comparison; "now against the previous pass" is fair for bytes and requests, and only indicative for times.
2. **Blocking time on a computer is a few tasks around the 50 ms line**, so its percentage means little: old's
   category page read 101 ms last night and 14 ms today. The tables give the difference in milliseconds.
3. **The home's 194 requests on a computer include the measurement's own pointer**: its last step moves the pointer
   onto the menu, and the menu then loads every panel's pictures (79 small files). A page nobody points at makes 117
   requests against old's 101 (table F-E).

### F-A. Old against the previous pass against now (Watchizer; 3 cold loads each, the median)

| Page | Device | Build | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | computer | old | 840 | 1432 | 1432 | 0.002 | 930 | 566 | 2586 | 115 |
| | | previous pass | 107 | 1288 | 1288 | 0 | 1105 | 572 | 2553 | 194 |
| | | **now** | 128 | 1140 | 1140 | 0 | 164 | 571 | 2554 | 194 |
| | | *now against old* | −85 % | −20 % | −20 % | 0.002 → 0 | −766 ms | +1 % | −1 % | +69 % |
| | | *now against the previous pass* | +20 % | −11 % | −11 % | = | −941 ms | = | = | = |
| Home | phone | old | 992 | 2368 | 2452 | 0 | 1278 | 566 | 1815 | 120 |
| | | previous pass | 154 | 2092 | 2124 | 0 | 1733 | 572 | 976 | 101 |
| | | **now** | 153 | 2372 | 2404 | 0 | 1626 | 571 | 976 | 97 |
| | | *now against old* | −85 % | = | −2 % | = | +348 ms | +1 % | −46 % | −19 % |
| | | *now against the previous pass* | −1 % | +13 % | +13 % | = | −107 ms | = | = | −4 % |
| Category | computer | old | 481 | 936 | 1092 | 0 | 14 | 276 | 488 | 88 |
| | | previous pass | 90 | 1048 | 1048 | 0 | 141 | 275 | 672 | 102 |
| | | **now** | 76 | 684 | 684 | 0 | 127 | 275 | 601 | 87 |
| | | *now against old* | −84 % | −27 % | −37 % | = | +113 ms | = | +23 % | −1 % |
| | | *now against the previous pass* | −16 % | −35 % | −35 % | = | −14 ms | = | −11 % | −15 % |
| Category | phone | old | 508 | 1904 | 2456 | 0 | 669 | 276 | 428 | 76 |
| | | previous pass | 154 | 1688 | 1688 | 0 | 867 | 275 | 613 | 90 |
| | | **now** | 154 | 1712 | 1712 | 0 | 788 | 275 | 542 | 75 |
| | | *now against old* | −70 % | −10 % | −30 % | = | +119 ms | = | +27 % | −1 % |
| | | *now against the previous pass* | = | +1 % | +1 % | = | −79 ms | = | −12 % | −17 % |
| Product | computer | old | 422 | 776 | 944 | 0 | 5 | 298 | 413 | 68 |
| | | previous pass | 52 | 1076 | 1256 | 0 | 85 | 297 | 597 | 82 |
| | | **now** | 54 | 472 | 948 | 0 | 117 | 297 | 530 | 67 |
| | | *now against old* | −87 % | −39 % | = | = | +112 ms | = | +28 % | −1 % |
| | | *now against the previous pass* | +4 % | −56 % | −25 % | = | +32 ms | = | −11 % | −18 % |
| Product | phone | old | 455 | 1632 | 1716 | 0 | 422 | 298 | 416 | 68 |
| | | previous pass | 154 | 1412 | 1424 | 0 | 518 | 297 | 600 | 80 |
| | | **now** | 154 | 1404 | 1404 | 0 | 494 | 297 | 533 | 65 |
| | | *now against old* | −66 % | −14 % | −18 % | = | +72 ms | = | +28 % | −4 % |
| | | *now against the previous pass* | = | −1 % | −1 % | = | −24 ms | = | −11 % | −19 % |
| Hot Deals | computer | old | the page does not exist in the old build (404) | | | | | | | |
| | | previous pass | 43 | 1320 | 1320 | 0 | 81 | 295 | 591 | 77 |
| | | **now** | 43 | 864 | 864 | 0 | 76 | 295 | 528 | 62 |
| | | *now against the previous pass* | = | −35 % | −35 % | = | −5 ms | = | −11 % | −19 % |
| Hot Deals | phone | old | the page does not exist in the old build (404) | | | | | | | |
| | | previous pass | 154 | 1596 | 1628 | 0 | 534 | 295 | 582 | 77 |
| | | **now** | 156 | 1848 | 1848 | 0 | 554 | 295 | 520 | 62 |
| | | *now against the previous pass* | +1 % | +16 % | +14 % | = | +20 ms | = | −11 % | −19 % |

Menu, from the pointer reaching a nav entry to a drawn panel (computer, home): old 105 ms → previous pass 30 ms → **now 41 ms**.

### F-A2. The old build, measured on both days (the same build: the spread of this harness from one day to the next)

| Page | Device | Day | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | computer | 2026-11-05 | 993 | 1484 | 1484 | 0 | 858 | 566 | 2586 | 115 |
| | | 2026-11-06 | 840 | 1432 | 1432 | 0.002 | 930 | 566 | 2586 | 115 |
| Home | phone | 2026-11-05 | 897 | 2184 | 2228 | 0 | 1103 | 566 | 1815 | 120 |
| | | 2026-11-06 | 992 | 2368 | 2452 | 0 | 1278 | 566 | 1815 | 120 |
| Category | computer | 2026-11-05 | 445 | 960 | 960 | 0 | 101 | 276 | 488 | 88 |
| | | 2026-11-06 | 481 | 936 | 1092 | 0 | 14 | 276 | 488 | 88 |
| Category | phone | 2026-11-05 | 459 | 1836 | 2368 | 0 | 634 | 276 | 428 | 76 |
| | | 2026-11-06 | 508 | 1904 | 2456 | 0 | 669 | 276 | 428 | 76 |
| Product | computer | 2026-11-05 | 415 | 928 | 1172 | 0 | 71 | 298 | 413 | 68 |
| | | 2026-11-06 | 422 | 776 | 944 | 0 | 5 | 298 | 413 | 68 |
| Product | phone | 2026-11-05 | 418 | 1556 | 1624 | 0 | 353 | 298 | 416 | 66 |
| | | 2026-11-06 | 455 | 1632 | 1716 | 0 | 422 | 298 | 416 | 68 |

### F-B. The home without the 3D watch's model and light (the poster stays) — the page's own cost, apart from the watch's

| Device | Build | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|
| computer | old, 3D blocked | 971 | 1536 | 1536 | 0 | 93 | 566 | 1354 | 105 |
| | previous pass, 3D blocked | 97 | 1456 | 1456 | 0 | 323 | 572 | 1308 | 199 |
| | **now, 3D blocked** | 102 | 1088 | 1088 | 0 | 107 | 571 | 1322 | 184 |
| | *now, with the 3D watch* | 128 | 1140 | 1140 | 0 | 164 | 571 | 2554 | 194 |
| phone | old, 3D blocked | 900 | 2352 | 2416 | 0 | 1246 | 566 | 1404 | 120 |
| | previous pass, 3D blocked | 154 | 1628 | 1660 | 0 | 2315 | 572 | 1028 | 113 |
| | **now, 3D blocked** | 155 | 2124 | 2156 | 0 | 1610 | 571 | 976 | 98 |
| | *now, with the 3D watch* | 153 | 2372 | 2404 | 0 | 1626 | 571 | 976 | 97 |

### F-C. Brand Fashion (it has no older version to compare with): the previous pass against now

| Page | Device | Build | TTFB ms | FCP ms | LCP ms | CLS | TBT ms | JS KB | Weight KB | Requests |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | computer | previous pass | 164 | 1384 | 1384 | 0 | 155 | 281 | 1322 | 240 |
|  |  | **now** | 194 | 1268 | 1268 | 0 | 36 | 282 | 1324 | 226 |
| | | *now against the previous pass* | +18 % | −8 % | −8 % | = | −119 ms | = | = | −6 % |
| Home | phone | previous pass | 154 | 2128 | 2128 | 0 | 1239 | 281 | 878 | 112 |
|  |  | **now** | 155 | 2252 | 2252 | 0 | 1129 | 282 | 881 | 113 |
| | | *now against the previous pass* | +1 % | +6 % | +6 % | = | −110 ms | = | = | +1 % |
| Category | computer | previous pass | 72 | 1004 | 1028 | 0 | 122 | 292 | 861 | 153 |
|  |  | **now** | 86 | 620 | 772 | 0 | 52 | 293 | 790 | 138 |
| | | *now against the previous pass* | +19 % | −38 % | −25 % | = | −70 ms | = | −8 % | −10 % |
| Category | phone | previous pass | 153 | 1592 | 1592 | 0 | 726 | 292 | 701 | 107 |
|  |  | **now** | 153 | 1616 | 1616 | 0 | 769 | 293 | 630 | 92 |
| | | *now against the previous pass* | = | +2 % | +2 % | = | +43 ms | = | −10 % | −14 % |
| Product | computer | previous pass | 76 | 968 | 1128 | 0 | 76 | 274 | 834 | 123 |
|  |  | **now** | 72 | 504 | 612 | 0 | 23 | 275 | 765 | 108 |
| | | *now against the previous pass* | −5 % | −48 % | −46 % | = | −53 ms | = | −8 % | −12 % |
| Product | phone | previous pass | 154 | 1364 | 1380 | 0 | 568 | 274 | 639 | 86 |
|  |  | **now** | 155 | 1360 | 1440 | 0 | 529 | 275 | 572 | 71 |
| | | *now against the previous pass* | +1 % | = | +4 % | = | −39 ms | = | −10 % | −17 % |
| Hot Deals | computer | previous pass | 37 | 1288 | 1288 | 0 | 52 | 273 | 576 | 82 |
|  |  | **now** | 53 | 852 | 852 | 0 | 32 | 274 | 514 | 68 |
| | | *now against the previous pass* | +43 % | −34 % | −34 % | = | −20 ms | = | −11 % | −17 % |
| Hot Deals | phone | previous pass | 154 | 1388 | 1496 | 0 | 508 | 273 | 567 | 76 |
|  |  | **now** | 154 | 1572 | 1572 | 0 | 481 | 274 | 506 | 62 |
| | | *now against the previous pass* | = | +13 % | +5 % | = | −27 ms | = | −11 % | −18 % |

### F-D. Lighthouse (lab): Performance / Accessibility / Best Practices / SEO — where a page was run twice, both runs (first · second)

| Page | Form | Old (today) | Previous pass | **Now** | Now, first run: FCP / LCP / TBT ms / CLS | Now: weight KB |
|---|---|---|---|---|---|---|
| Watchizer home | phone | 51 / 87 / 96 / 100 | 45 / 100 / 100 / 100 | **49 / 100 / 100 / 100 · 52 / 100 / 100 / 100** | 1829 / 5035 / 1794 / 0 | 2345 |
| Watchizer home | computer | 67 / 88 / 93 / 100 | 67 / 100 / 100 / 100 | **83 / 100 / 100 / 100 · 93 / 100 / 100 / 100** | 422 / 2457 / 154 / 0 | 2484 |
| Watchizer category | phone | 78 / 95 / 96 / 100 · 81 / 95 / 96 / 100 | 76 / 100 / 100 / 100 | **68 / 100 / 100 / 100 · 80 / 100 / 100 / 100** | 1518 / 5724 / 351 / 0 | 593 |
| Watchizer category | computer | 98 / 88 / 93 / 100 · 99 / 88 / 93 / 100 | 98 / 100 / 100 / 100 | **98 / 100 / 100 / 100 · 99 / 100 / 100 / 100** | 381 / 1006 / 5 / 0 | 654 |
| Watchizer brand | phone | 83 / 95 / 96 / 100 | 79 / 100 / 100 / 100 | **79 / 100 / 100 / 100** | 1514 / 4233 / 274 / 0 | 580 |
| Watchizer brand | computer | 99 / 88 / 93 / 100 | 99 / 100 / 100 / 100 | **99 / 100 / 100 / 100** | 380 / 980 / 4 / 0.001 | 649 |
| Watchizer product | phone | 78 / 96 / 96 / 100 · 79 / 96 / 96 / 100 | 80 / 100 / 100 / 100 | **69 / 100 / 100 / 100 · 71 / 100 / 100 / 100** | 1369 / 5821 / 353 / 0 | 704 |
| Watchizer product | computer | 99 / 96 / 93 / 100 · 99 / 96 / 93 / 100 | 98 / 100 / 100 / 100 | **99 / 100 / 100 / 100 · 99 / 100 / 100 / 100** | 342 / 953 / 24 / 0 | 537 |
| Watchizer Hot Deals | phone | no such page (404) | 71 / 100 / 100 / 100 | **72 / 100 / 100 / 100 · 71 / 100 / 100 / 100** | 1213 / 5664 / 323 / 0 | 576 |
| Watchizer Hot Deals | computer | no such page (404) | 99 / 100 / 100 / 100 | **90 / 100 / 100 / 100 · 87 / 100 / 100 / 100** | 331 / 2026 / 5 / 0 | 528 |
| Watchizer Flash Sale | phone | no such page (404) | 83 / 100 / 100 / 100 | **73 / 100 / 100 / 100 · 73 / 100 / 100 / 100** | 1213 / 5563 / 316 / 0 | 591 |
| Watchizer Flash Sale | computer | no such page (404) | 89 / 100 / 100 / 100 | **90 / 100 / 100 / 100 · 90 / 100 / 100 / 100** | 333 / 2029 / 4 / 0 | 538 |
| Brand Fashion home | phone | no older version | 49 / 100 / 100 / 100 | **66 / 100 / 100 / 100 · 67 / 100 / 100 / 100** | 2116 / 6106 / 266 / 0 | 1388 |
| Brand Fashion home | computer | no older version | 94 / 100 / 100 / 100 | **84 / 100 / 100 / 100 · 85 / 100 / 100 / 100** | 461 / 2714 / 14 / 0 | 1421 |
| Brand Fashion category | phone | no older version | 77 / 100 / 100 / 100 | **81 / 100 / 100 / 100** | 1522 / 4349 / 197 / 0 | 816 |
| Brand Fashion category | computer | no older version | 98 / 100 / 100 / 100 | **98 / 100 / 100 / 100** | 431 / 1034 / 8 / 0 | 789 |
| Brand Fashion brand | phone | no older version | 80 / 100 / 100 / 100 | **81 / 100 / 100 / 100** | 1523 / 4291 / 224 / 0 | 791 |
| Brand Fashion brand | computer | no older version | 98 / 100 / 100 / 100 | **98 / 100 / 100 / 100** | 425 / 1006 / 10 / 0.001 | 777 |
| Brand Fashion product | phone | no older version | 74 / 100 / 100 / 100 | **80 / 100 / 100 / 100** | 1512 / 4484 / 211 / 0 | 883 |
| Brand Fashion product | computer | no older version | 99 / 100 / 100 / 100 | **99 / 100 / 100 / 100** | 381 / 889 / 5 / 0 | 887 |
| Brand Fashion Hot Deals | phone | no older version | 86 / 100 / 100 / 100 | **71 / 100 / 100 / 100 · 70 / 100 / 100 / 100** | 1212 / 5877 / 336 / 0 | 557 |
| Brand Fashion Hot Deals | computer | no older version | 99 / 100 / 100 / 100 | **87 / 100 / 100 / 100 · 87 / 100 / 100 / 100** | 294 / 2432 / 6 / 0 | 514 |
| Brand Fashion Flash Sale | phone | no older version | 85 / 100 / 100 / 100 | **72 / 100 / 100 / 100 · 84 / 100 / 100 / 100** | 1213 / 5836 / 311 / 0 | 572 |
| Brand Fashion Flash Sale | computer | no older version | 86 / 100 / 100 / 100 | **87 / 100 / 100 / 100 · 88 / 100 / 100 / 100** | 294 / 2488 / 7 / 0 | 524 |

Now, across all 40 runs: Accessibility min 100, Best Practices min 100, SEO min 100; Performance from 49 to 99.

Audits listed as not passed outside Performance (now):

- bf-brand desktop: accessibility: label-content-name-mismatch
- bf-brand mobile: accessibility: label-content-name-mismatch
- bf-category desktop: accessibility: label-content-name-mismatch
- bf-category mobile: accessibility: label-content-name-mismatch
- bf-home desktop: accessibility: label-content-name-mismatch
- bf-home mobile: accessibility: label-content-name-mismatch
- bf-home (second run) desktop: accessibility: label-content-name-mismatch
- bf-home (second run) mobile: accessibility: label-content-name-mismatch
- bf-product desktop: accessibility: label-content-name-mismatch
- bf-product mobile: accessibility: label-content-name-mismatch
- wz-brand desktop: accessibility: label-content-name-mismatch
- wz-category desktop: accessibility: label-content-name-mismatch
- wz-category (second run) desktop: accessibility: label-content-name-mismatch

### F-E. Where a page's bytes and requests are now (computer, one cold load, 8 s, the pointer not moved)

| What | old-category: KB (requests) | new-category: KB (requests) | old-product: KB (requests) | new-product: KB (requests) | old-home: KB (requests) | new-home: KB (requests) |
|---|---|---|---|---|---|---|
| data: link prefetch (the router) | 9 (12) | 8 (12) | 4 (5) | 4 (5) | 4 (5) | 4 (5) |
| data: other | 0 (4) | 11 (3) | 0 (5) | 11 (4) | 0 (13) | 11 (12) |
| data: the menu (asked once a session) | — | 97 (1) | — | 97 (1) | — | 97 (1) |
| other (Manifest) | 1 (1) | 1 (1) | 1 (1) | 1 (1) | 1 (1) | 1 (1) |
| other (Other) | 8 (6) | 8 (4) | 8 (7) | 8 (5) | 8 (6) | 8 (4) |
| pictures: banners | — | — | — | — | 476 (3) | 102 (5) |
| pictures: brand logos | 8 (8) | 8 (8) | 1 (1) | 1 (1) | — | — |
| pictures: categories (row + menu) | — | — | — | — | 29 (15) | 29 (15) |
| pictures: product photos (recorded renditions) | — | — | 37 (6) | 37 (6) | 45 (6) | 63 (8) |
| pictures: product photos through the optimiser | 109 (16) | 107 (16) | — | — | — | 46 (8) |
| pictures: small product pictures through the optimiser (menu, chips) | — | — | — | — | — | 5 (3) |
| pictures: the logo | 7 (1) | 3 (1) | 7 (1) | 3 (1) | 7 (1) | 3 (1) |
| pictures: the shop's own (icons, posters) | — | — | — | — | 40 (2) | 40 (2) |
| scripts (JS) | 276 (31) | 275 (30) | 298 (33) | 297 (32) | 566 (37) | 571 (36) |
| styles (CSS) | 20 (8) | 32 (10) | 22 (8) | 32 (10) | 23 (9) | 41 (13) |
| the 3D watch (model + light) | — | — | — | — | 1232 (2) | 1232 (2) |
| the page (HTML) | 50 (1) | 51 (1) | 35 (1) | 38 (1) | 149 (1) | 75 (1) |
| **total** | **488 (88)** | **602 (87)** | **413 (68)** | **531 (67)** | **2581 (101)** | **2330 (117)** |

Reading it: a category page is **+114 KB and −1 request** against old here. **97 KB of that is the menu's data, which
this machine's test server sends uncompressed — production's web server sends it as about 10 KB**; the campaigns'
data is 11 KB here and about 3 KB there. What remains in production is about **+22 KB** (12 KB of styles — the menu,
the header's switch, the offer strips; 10 KB the menu's data; 3 KB the campaigns'; 1 KB of HTML; the logo is 4 KB
smaller), on a computer and a phone alike. After the performance pass it was +90 KB on a computer and +33 KB on a
phone. The category page's HTML is now smaller than old's (361 against 385 KB).

### What the Lighthouse table says, plainly

- **Accessibility, Best Practices, SEO: 100 on every one of the 40 runs** (12 page types of both shops, phone and
  computer, 16 of them twice). The only audit listed outside Performance is `label-content-name-mismatch`, which
  carries no weight (it was there before; P8).
- **Performance, the home:** Watchizer on a computer **67 → 83 and 93** (old: 67), its blocking figure 1099 → 154–180
  ms — the 3D watch (F2). On a phone 45 → 49 and 52 (old 51), blocking 4755 → 1794 and 1643 ms (old 4825). Brand
  Fashion's home on a phone **49 → 66 and 67**.
- **Category and brand pages:** level with old and with the previous pass (phone 79–81; one run of Watchizer's
  category page read 68 with a 5.7 s simulated largest paint — the same page read 80 twenty minutes later).
- **The product page on a phone: 69, 71 and 76 in three runs; old 78 and 79; the previous pass 80.** This row is
  lower than old in this round and I have not isolated why. Its largest paint is the product photo, which comes from
  the real asset host over the internet and through Lighthouse's simulation: 4.0, 5.7 and 5.8 s between three runs of
  the same build. In real loads (table F-A) the same page's largest paint on the throttled phone is 1404 ms against
  old's 1716. Its blocking figure is 293–444 ms against old's 230–264 — the part that is real (F10.1).
- **The campaign pages (Hot Deals, Flash Sale) and Brand Fashion's home on a computer: 84–90, where the previous pass
  read 99 on some runs and 86–89 on others.** The detailed reports say why: on these pages Lighthouse's SIMULATION puts
  the largest paint at 2.0–2.7 s, while the paint it actually OBSERVED was at first paint. The simulation adds every
  request that started before the observed paint — and on this machine the page's data requests go through a test
  core that answers one request at a time (1.1–1.4 s each, in the reports) and, with no network delay, start before
  the first frame. Run with the throttling APPLIED instead of simulated, the same pages read **Performance 97, largest
  paint 1.0 s (Hot Deals), 97 / 0.96 s (Brand Fashion's Flash Sale), 94 / 1.2 s (Brand Fashion's home)**, twice each
  (`lh-applied-desktop.txt`). Last night the same pages flipped between the two readings from run to run (99 and 88
  for the same page); today all landed on the slow one. PageSpeed Insights on the live site, where core answers in
  parallel, is the reading that counts — it is the third check of the deploy record's §10.
- **One real cost of the stylesheet split, on this harness only:** a campaign page now has six stylesheets where it
  had five, and a browser opens six connections to one host over HTTP/1.1 — which is what the local server speaks. The
  sixth stylesheet waits for a free connection: Hot Deals paints at 1848 ms on the throttled phone where last night's
  build painted at 1628 (Brand Fashion: 1572 against 1496). The live shop is served over HTTP/2 with HTTP/3 offered
  (checked today, read-only), where requests do not queue for connections. If PageSpeed shows otherwise after the
  deploy, the undo is F11's first line.

## F9. Corrections to what I told you before

1. **"The 3D watch is a 3.5 s script task and 1.2 MB" (P6.2, and my summary).** The 1.2 MB was right for the phone
   (1,210 KB); on a computer it is 1,515 KB. The "3.5 s task" was Lighthouse's simulated blocking figure, not a task I
   had measured: the longest real task was 1110 ms on a computer and 888 ms on the throttled phone, and its cause was
   the studio light's filter, not the model. And on the throttled phone the watch starts after the eight seconds the
   tables watch, so the phone rows never contained it.
2. **"The home is held five minutes at most" (P-D2, the deploy record §10).** True for Watchizer. Brand Fashion's home
   goes through a different read, which I had left on a longer window: its cards could be up to ten minutes old. Both
   are two minutes now.
3. **"One menu label is shortened … in Brand Fashion's Men pane" (P10.3).** There were three, in both shops; I had
   only measured that pane.
4. **"Splitting the campaign stylesheet saves about 7 KB" (P6.4).** Measured: about 3 KB on the wire.
5. **"About +90 KB on a computer and +33 KB on a phone in production" (P4)** was right for that build; it is about
   +22 KB now.

## F10. What is still behind old, and what could still be bought — for you to decide

1. **Main-thread blocking on the throttled phone** (home +348 ms, category +119 ms, product +72 ms against old; on a
   computer +113 ms on a category or product page). Profiled today, the category page on the 4×-slowed processor:
   there is no single cause. The new page runs about a third more script than old's — React's own work 471 → 630 ms,
   reading the page's modules 305 → 428 ms, the header and menu 165 → 241 ms — because it has more to make
   interactive, and React makes a page interactive in one task (old's is 361 ms, the new one's 468 ms). One avoidable
   piece was found and removed today: every product card created three data subscriptions and two timers to learn
   whether it is in an offer — 249 and 166 on the home. They are read once for the whole page now (F11, F-D5): the
   category page's task 525 → 468 ms, the home's 763 → 647 ms.
   **Options:** (a) leave it — on a real mid-range phone these are a quarter of the figures, and the field data after
   the deploy will say whether INP needs it; (b) send the parts of the page below the first screen out of order
   (the trial you decided against for the home's sliders — the same trade applies to any page); (c) fewer cards per
   listing page (24 today) or fewer sections on the home — yours to say.
2. **Two small lookups are re-read every five minutes, and that one request waits for them** (the category names and
   the menu's facts — two small reads; 1.3 s on this machine's one-at-a-time core). The old build does the same; it is not part of
   the held reads. Lighthouse landed on it twice in this round. **Option:** answer from the copy and refresh behind,
   as the campaigns do — a category renamed on the dashboard would then show within five minutes as today, and no
   visitor would wait. Not on your list, so not done; it is a ten-line change.
   **→ Approved by you and done the same afternoon: "SECOND FOLLOW-UP", G1–G4 (and G-D2 corrects "within five
   minutes as today").**
3. **The weight of a category or product page: about +22 KB in production** — the menu's data (10 KB), the styles of
   the menu, the switch and the offer strips (12 KB). Both are what items 1–43 asked for; nothing is left to remove
   without removing a feature.
4. **The home's requests:** 117 against old's 101 with nobody pointing at it — thirteen sections against old's. Yours.
5. **The 3D watch's weight** — F2's table of options. **→ Your decision the same afternoon: leave it as it is.**

## F11. Decisions I made in this follow-up — reason, undo

**F-D1 — Core names the next campaign start (`next_start_at`).** You asked that an offer STARTING refreshes the held
copy at once. An end is in the answer the shops already hold; a start was not, so the only storefront-only way was to
ask core more often — never "at once". One indexed read and one key in an answer that is not cached; an older shop
ignores it. *Undo:* remove the key (`StorefrontCampaigns::build`, `Campaigns::nextStart`, one test); the shops fall
back to their polls by themselves.

**F-D2 — The menu's pictures warm up on the pointer's way to the menu**, not when the page is idle (you allowed
either). On idle they would still be downloaded by every visitor with a mouse; this way a visitor who never goes near
the menu downloads none. *Cost:* F5's note. *Undo:* `MegaMenu.jsx`, the effect commented "WHEN THE POINTER IS HEADING
FOR THE MENU".

**F-D3 — The menu label fix changes the look at 1280–1366 px:** the count under the name and a 4:3 picture in a
six-tile row; no gap between tiles in a dense grid; 8 px less above the suggested products in a short window. The
alternatives were a smaller type (fits by 2 px — one longer word and it is cut again) or a third row (the pane
scrolls). *Undo:* F7.

**F-D4 — Brand Fashion's home reads with the home's window** (F9.2). *Undo:* drop the two last arguments of
`getServerV2Home` in `serverV2.js` — but then it is ten minutes again.

**F-D5 — The live offers are read once for every product card** (`LiveOffersProvider` in the main layout of both
shops; `useProductOffer` reads its value). Not on your list; found while profiling F10.1, and it removes work without
removing anything a customer sees: the strips are still in the server's HTML, a product in both campaigns is still the
Flash Sale's, an offer still leaves at its last second (`tests/liveOffers.test.mjs`; the item 41 probes pass). A
product card outside the main layout would show no strip — there is none today (the two not-found pages draw no
cards). *Undo:* the previous body of `useProductOffer` is in git history; remove the provider from `app/(main)/layout.jsx`.

**F-D6 — The 3D watch's fix reads three.js's private names** (five of them, as of three 0.185). If a later three
renames one, the code falls back to three's own way by itself — the page freezes for a second again and nothing
breaks. `studioLight.js` says so at its head. *Undo:* in `GrandPrixCanvas.jsx` put `<Environment files={env} />` back
in place of `<StudioLight>`; `git diff` shows the dozen lines.

**F-D7 — The deploy script was touched** (three small edits, `bash -n` clean): it accepts the live `.htaccess` at 65
lines or at 70 with the AVIF block, it stops if the archive's `StorefrontCampaigns.php` does not name
`next_start_at`, and step 9 stops if the live campaigns answer does not carry it. Nothing else in it changed.

**F-D8 — The stylesheet split stays as you decided**, with its measured worth (3 KB less on pages without a campaign
surface) and its measured cost on this harness (F8's last point). *Undo, if PageSpeed says so after the deploy:* move
the contents of `promo-surfaces.css` back to the end of `promo.css` and delete the two `import './promo-surfaces.css'`
lines — the rules are unchanged, so the order is the original one again.

## F12. Checks re-run on the final tree (builds of 07:23 and 07:24)

| Check | Result |
|---|---|
| Production builds, both shops, Google's font hosts blocked | exit 0, 0 lookups (six builds of Watchizer and three of Brand Fashion today; the last at 07:23 / 07:24) |
| Storefront unit tests (vitest) | Watchizer **186 passed** (23 files), Brand Fashion **141 passed** (18 files) — 21 and 18 new: the listing packed and unpacked, the held campaigns at a start and an end, the live offers from the one provider, and (Watchizer) the watch's batches built in steps |
| ESLint, both shops | **0 errors** (45 and 31 warnings, the standing ones) |
| Core: PHPStan level 10 / Pint / `tsc --noEmit` | `[OK] No errors` / passed on the changed files (`pint --dirty`, the project's convention) / clean |
| Core: the campaign tests | 12 passed, 303 assertions — one new (the next start) |
| Core: twins and the API allow-list | 10 passed, 294 assertions — every listed twin byte-identical; five files added to `TWINS.txt` (`promo-surfaces.css`, three tests and one fixture), and the Watchizer-only files named there |
| Core: the full suite as a gate, clean scratch copy | **green** — 2,287 passed, 39 skipped, the 31 known baseline failures, **0 new**, 0 baseline entries now passing, **0 tables changed**, `mysql.db` OK before and after; 723 s (`evidence-followup\gate-followup.txt`). One more passed than last night: the new campaign test |
| The campaign surfaces after the stylesheet split | 22 pages, 5,044 elements, 5,128 style sets compared with the one-file original: **0 differences**; 6 pages without a surface load no surface rule (F4) |
| The 3D watch | the canvas goes live on the home, computer and phone, English and Arabic; **identical pixel for pixel to the old build's**; 0 console errors (F2) |
| The menu | 84 panes: 0 names cut from 1280 up, 0 inner scroll (F7); the warm-up: 8 cases, never a loading state, 0 pictures asked on a page left alone (F5) |
| Console errors | **0** on 80 crawled pages (both shops, computer and phone, English and Arabic), on the 238 regression loads, and on the 120 loads of this follow-up's own probes (84 menu panes, 8 warm-up cases, 28 stylesheet comparisons) |
| Internal links | 670 checked; 171 failed during the fast sweep (this machine's core rate-limits a crawl — 168 of them answered 500), re-asked one at a time: **3 dead, all known** — the FAQ's Arabic link to the blog post that does not exist, and the experience page in both languages, whose product is not in this scratch copy; no new one. The crawl's 9 failed requests are the same rate limit (429 on the cart and offers reads of five static pages) |
| Items 32–37 and 41 — last night's ten probe sets run again (238 loads) and compared measurement by measurement with last night's | **0 console errors.** Identical: the blend (32), every forward navigation landing at 0 and Back restoring the position (33), the Brands panel (34), the category row and the home at 70 phone sizes (35), the banner arrows — hit, wrap both ways, English and Arabic, both shops (37), the offer cards and product pages in an offer (41: same sizes, same strips; only the clock's reading differs, by the time of day). The menu (36): identical except what F7 changed on purpose — no cut or broken word left (Watchizer's Men pane had one at 1280×720 last night), panels 10–15 px shorter there, and in Brand Fashion's six-tile panes at 1366 px the count under the name with a 91×64 picture where it was 91×91 |
| Layout shift | 0 on every page measured |

## F13. End of the follow-up — nothing is left running

Written at 09:25 on 2026-11-06.

- **Stopped, in this order:** the old-version shop (port 3010), both shops (3000, 3001) and the scratch core (8020) at
  08:55, when the last browser check was done; then, after the gate, mysqld at 09:21:33 with `mariadb-admin shutdown`
  (its log ends with `Shutdown complete`). Proved afterwards: ports 3000, 3001, 3010, 8020 and 3307 closed; no node,
  php, mysqld or headless-browser process of this work left.
- **The preview you asked for at 04:51 is therefore down again**, as the follow-up's last line asked. Both `.next`
  folders hold the final builds (07:23 and 07:24); it is back with mysqld, `start-core-scratch.ps1` and
  `start.ps1 -App wz` / `-App bf` whenever you say.
- **Git: untouched** — branch `wave-5`, HEAD `9a369cd`, 0 stashes, nothing staged; 338 changed or new paths in the
  tree. Nothing was deployed and nothing on production was written. The only contact with the live sites was
  read-only: five picture requests to the two API hosts for their cache headers (the deploy record's §11.3) and one
  request to watchizereg.com for the protocol it is served over.
- **The scratch copies:** `scratch_night_20261105` (the preview's) keeps its four test campaigns, which end at 23:55
  tonight; `scratch_gate_20261105` is identical before and after the gate. The dev database was not used.
- **One thing I did wrong and put right:** at 08:56 a command meant to run two test files was given an empty list and
  started the whole suite without the gate's checks. It was stopped within minutes — its PHP processes proved gone, no
  connection left on the scratch copy — and the gate was run properly afterwards; the result in F12 is that run's.
- **The public API key** appeared in two of my own tool outputs while I was reading Lighthouse reports. It is in no
  file: the reports and every scratch file were scrubbed (9 files), and the evidence folder was checked after the
  copy (0 occurrences).
- **Commits script** regenerated from this tree: `deploy-scripts\COMMITS-PART2-20261105.ps1`, **317 files in 11
  commits**, 21 left out by rule (screenshots and the Search Console exports). The follow-up's twelve new files ride
  in "the developer's review (round 2)", whose message now names the follow-up; a file an earlier commit already
  carries rides with that commit, as before.
- **Outside the repo:** the checkpoint `checkpoints\2026-11-05_round2\tree_*_followup-final` (the tree as a patch,
  every untracked file) and `checkpoints\2026-11-05_round2\evidence-followup\` (every measurement file and script of
  this follow-up).

# SECOND FOLLOW-UP — 2026-11-06, afternoon (Michael's two decisions on F10)

Written at 13:55 on 2026-11-06. Standing rules as before: no git, no deploy, no SSH, no production write, the scratch
copy only.

## G0. The short version

1. **The 3D watch's weight (F10.5): left as it is, by your decision.** No file was touched; F2's table of options
   stays as a record only.
2. **The two small lookups (F10.2): done.** The category names and the menu's facts are now answered from the copy
   the server holds and refreshed behind the request. Measured on the same two pages, asked every four seconds for
   eleven and a half minutes: **before, two requests waited — 776 ms and 1,209 ms, five minutes apart; after, none —
   the slowest of 173 was 88 ms**, and the two requests that set off a refresh answered in 26 and 39 ms.
3. **Nothing a customer sees changed:** every one of the 344 responses of the two runs that did not wait is the same
   size to the byte before and after, and the menu's 84 panes measure exactly as this morning.
4. **One thing I told you in F10.2 was not exact**, and one small behaviour changed beside the one you approved —
   both in G3.
5. **The preview is running** (both shops on the builds of 13:16 and 13:17, the scratch core, mysqld) and stays up
   until you say you are done.

## G1. The two lookups — what they are and what changed

Every page's server render reads two small things from core before it can draw anything: **the lookup tables**
(`catalog/meta` — the names of the categories, sub-categories, brands and the rest, plus one read of the storefront's
category tree for its addresses) and **the menu's facts** (`catalog/nav` — which brands and sub-categories have
products). Three small requests in all. Until today the storefront's server kept the answers for five minutes and then
read them again **while the request that happened to come next waited**. The old build does the same.

Now (`src/lib/coreRead.js` `heldLookup`, used by `src/lib/serverCatalog.js` `getServerTables` and `getServerNav`; both
shops, twins):

- for **five minutes** the copy is the answer and core is not asked — as before;
- after that the copy is **still the answer, at once**, and core is asked again behind the request — once, however
  many pages are opened meanwhile; the next visitor has the new one;
- a request waits for them only when nothing is held yet (the first request a server process ever serves — after a
  deploy or a restart, as always) or after **a whole day** in which nobody opened any page;
- a core that cannot be asked never empties the copy; a definite "no such thing" from core is never held.

No component, no stylesheet and no core file changed. Files: `coreRead.js` (the rule, and `heldValue` learned `keep`),
`serverCatalog.js` (its two hand-kept promises became two calls of that rule), a new test `tests/lookupHold.test.mjs`
— each in both shops, byte-identical, and the new test is listed in `TWINS.txt`.

## G2. The request that used to wait — before and after

**How:** the production builds on this machine against the scratch core, as in every measurement of this record.
`lookupwait.mjs` asks the category page and the product page in turn, one request every four seconds, for 690 seconds
— longer than two of the five-minute windows — and records each request's time to first byte. Each page is asked every
eight seconds, so its own held reads (a listing's, a product's) never make it wait: a slow request is the lookups'.
The core's own request log was read beside it.

| | Before (build of 07:23; 13:01–13:13) | After (build of 13:16; 13:18–13:30) |
|---|---|---|
| Requests (category + product) | 173 (87 + 86), all 200 | 173 (87 + 86), all 200 |
| Median time to first byte, category / product | 42 / 28 ms | 41 / 28 ms |
| 95th percentile, all requests | 53 ms | 53 ms |
| **Requests that waited (over 300 ms)** | **2: 776 ms at 13:06:16 (product) and 1,209 ms at 13:11:16 (category) — 300.6 s apart** | **0** |
| Slowest request | 1,209 ms | 88 ms (the run's first) |
| What core's log shows at those seconds | `catalog/meta`, the category tree and `catalog/nav` asked at 13:06:16 and at 13:11:16 — while the request waited | the same three asked at 13:23:22 and at 13:28:22 — and the requests that set them off answered in **26 ms** (product) and **39 ms** (category) |
| Size of the response | 361,095 / 210,810 bytes, every time — except the two that waited (43 bytes more each) | 361,095 / 210,810 bytes, all 173 |

(Core's log is on UTC, two hours behind this machine's clock; the times above are this machine's.) The wait is 0.8–1.2 s
here because this machine's core answers one request at a time; on production the three requests are quicker, so the
wait that is gone was shorter there — how much shorter was not measured — but it was paid by one visitor every five
minutes on each shop, and Lighthouse landed on it twice in this morning's round (F10.2).

Files: `lookupwait-before.json`, `lookupwait-after.json`, `lookupwait.mjs`, `core-8020-before-lookup.out.log`.

## G3. What it costs, and what I decided beside it — reason, undo

**G-D1 — The copy answers for up to a day, not ten minutes.** You asked that no request waits for them. With a short
limit (the campaigns' is five minutes) a shop nobody opened for a few minutes would make its next visitor wait again —
exactly the request Lighthouse and a quiet hour both land on. So the limit is 24 hours, the same age up to which the
server already serves its last good copy when core is down. *Cost:* on a shop nobody visited for hours, the first
visitor back is given the names and menu facts as they were when the last visitor left; the second has the current
ones. *Undo or tighten:* `LOOKUP_HOLD.stale` in `coreRead.js` — one number.

**G-D2 — A correction to F10.2.** I wrote that a category renamed on the dashboard "would then show within five
minutes as today". Exactly: it shows on the first page opened **after the five minutes have passed and one more page
has been opened** — the page that sets off the refresh still gets the old name. On a shop with visitors that is a few
seconds more than today; after a quiet spell it is G-D1's cost. Prices and stock are not part of these lookups (they
stay on their own windows: two minutes at most).

**G-D3 — A "no such thing" for the menu's facts is no longer kept five minutes.** Before, if core answered 404 for
`catalog/nav`, that "no" was kept for the five minutes (the names' never was). Now neither is kept: that request hears
it, as before, and the next one asks core again. It only matters if core's route is broken — and then it recovers on
the next request instead of five minutes later. *Undo:* drop `keep` from `heldLookup`.

**G-D4 — When core is down for more than a day,** the held names keep being served for as long as pages keep being
asked for (before, the server's read failed after 24 hours and the browser tried instead — and failed the same way).
Nothing else works in that state either; recorded so that it is not a surprise.

**Undo of the whole change:** `git diff` on `src/lib/serverCatalog.js` shows the `_navPromise` / `_tablesPromise`
lines it replaced; putting them back (both shops) restores the wait, and nothing else depends on `heldLookup`.

## G4. Checks run for this change

| Check | Result |
|---|---|
| Storefront unit tests (vitest) | Watchizer **196 passed** (24 files), Brand Fashion **151 passed** (19 files) — 10 new in each: the copy answers five minutes without a request; after that at once, with core asked behind and the next visitor on the new names; one request to core while a refresh runs; the first visitor after 23 hours of silence does not wait; after a whole day the request waits; a failed refresh keeps the copy and is tried again five minutes later; a "no such thing" is never held and drops the copy; a failure with nothing held fails as before and the next request retries; the windows |
| ESLint, both shops | **0 errors** (45 and 31 warnings, the standing ones) |
| `tsc --noEmit` (core's dashboard) | clean (no core file changed) |
| Twins and the API allow-list (core tests, on the scratch gate copy) | 10 passed, 295 assertions — `coreRead.js`, `serverCatalog.js` and the new test byte-identical in both shops |
| Production builds, both shops, Google's font hosts blocked | exit 0, 0 lookups — Watchizer 13:16:53, Brand Fashion 13:17:54 |
| The menu, 84 panes (six panes of each shop, English at five widths and Arabic at two) — the surface these two lookups feed | the probe's output is **identical line for line** to this morning's (252 lines, 0 differences); 0 console errors |
| A short crawl: 11 page types × both shops × computer and phone = 44 loads (home in English and Arabic, category, sub-category, brand, listing, Hot Deals, a product, a product in an offer, FAQ, the 404), the menu hovered and the drawer opened on the home | **0 console errors, 0 failed requests** on all 44 |
| Not re-run, because nothing they cover was touched | the core suite, PHPStan, Pint (no PHP file changed), Lighthouse, the 80-page crawl with its link sweep, the regression probes of items 32–37 and 41 other than the menu |

## G5. State at the end

- **Running, on purpose:** both shops (ports 3000 and 3001, the builds above), the scratch core (8020, database
  `scratch_night_20261105`, mail to the log) and mysqld (3307). The preview was down from 13:15:28 to 13:18:00 for
  the two builds. mysqld is stopped only when you say you are done. The scratch copy's test campaigns end at 23:55
  tonight by the database's clock (UTC — 01:55 on this machine's).
- **Git: untouched** — branch `wave-5`, HEAD `9a369cd`, nothing staged. Nothing deployed, nothing on production
  read or written in this round.
- **Commits script** regenerated from this tree at 13:43: `deploy-scripts\COMMITS-PART2-20261105.ps1`, **319 files in
  11 commits** (317 this morning plus the new test in each shop), 21 left out by rule. The two new files ride in "the
  developer's review (round 2)", whose message now names this change; `coreRead.js` and `serverCatalog.js` were
  already in that commit.
- **The deploy record:** §11.6 (what the deploy needs to know about this change — nothing to do on the server).
- **One thing I did wrong and put right:** at 13:44 I ran the check that keeps the public API key out of the records
  over the whole checkpoint folder instead of the new files only, and it rewrote seven files of the old-version
  comparison build kept there (`checkpoints\2026-11-05_round2\old-build`: its `.env.local` and six built chunks).
  Each had exactly one replacement, in the key's own place; all seven were restored from the shop's `.env.local` and
  checked (sizes, one key each, no marker left). Nothing in the repository and nothing of the running preview was
  touched. The new checkpoint, the evidence and the script copies were then checked read-only: 0 occurrences.
- **Outside the repo:** `checkpoints\2026-11-05_round2\evidence-followup\` gained this round's files (the two
  measurement runs, the script, the core log, the menu probe and the crawl); the tree as it stands is in a new
  `checkpoints\2026-11-05_round2\tree_*_lookups` folder (the patch and every untracked file).
