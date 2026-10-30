# SEO content round — record (2026-10-29)

Part 2 (home H1, PROPOSAL ONLY — nothing changed) and Part 3 (content, policy, JSON-LD) of Michael's 2026-10-29 brief.
Source data: the Search Console Performance exports in `core/docs/gsc/` (untracked — never `git add` them), last 3
months, web search: one for the whole site, one filtered to the page `https://watchizereg.com/`.

## Part 2 — the home H1

### What the home says today (source: `Frontend-next/app/(main)/page.jsx`, `GrandPrixBanner.jsx`)

| | EN | AR |
|---|---|---|
| H1 | The Art of Timeless Precision | فنّ الدقة الخالدة |
| title | Watchizer \| Luxury Watches & Accessories in Egypt | Watchizer - أفخم الساعات والإكسسوارات \| تسوق الآن بأسعار مميزة |
| meta description | Shop luxury watches and accessories at Watchizer — premium timepieces, elegant designs and unbeatable prices across Egypt. | اكتشف أفخم الساعات والإكسسوارات في Watchizer. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية. |

### What brings people to the home page (page filter `https://watchizereg.com/`, 3 months)

The home page took 217 clicks from 853 impressions as a page; its query table lists 600 queries (182 clicks).
`https://watchizereg.com/ar` had 5 impressions and 0 clicks, so the Arabic H1 carries almost no search weight today.

Top 15 queries by impressions:

| # | query | clicks | impressions | position |
|---|---|---|---|---|
| 1 | watchizer | 125 | 906 | 1.1 |
| 2 | watchizer egypt | 28 | 229 | 1.0 |
| 3 | رولكس سي دويلر ديب سي | 0 | 39 | 13.2 |
| 4 | tissot egypt | 5 | 32 | 13.0 |
| 5 | rolex egypt | 0 | 31 | 11.4 |
| 6 | رولكس جي إم تي ماستر | 0 | 28 | 18.4 |
| 7 | pinko egypt | 0 | 25 | 26.3 |
| 8 | tissot watch | 0 | 24 | 18.7 |
| 9 | just cavalli | 0 | 21 | 20.1 |
| 10 | just cavalli watches egypt | 0 | 19 | 8.6 |
| 11 | luxury watches egypt | 1 | 15 | 12.3 |
| 12 | tissot watches egypt | 1 | 15 | 18.0 |
| 13 | رولكس ديت جست 31 نسائي | 0 | 15 | 7.8 |
| 14 | rolex watch | 0 | 15 | 12.7 |
| 15 | ar11334 | 1 | 14 | 9.6 |

What it says:
- **The name does the work.** "watchizer" + "watchizer egypt" = 153 of 182 clicks (84%), at position 1. The title already
  carries the name, so the H1 does not need to.
- **The non-brand demand is "<brand> egypt" and "luxury watches egypt"** (68 queries name Egypt: 558 impressions,
  41 clicks), at positions 9–26 — page two. That is the gap an H1 can help with: say what is sold and where.
- **Arabic queries reaching the home are model names** (138 queries, 339 impressions, 1 click; mostly Rolex models
  at positions 8–24). They belong to product and brand pages, not to a home H1.
- "original/أصلي" appears in only 4 home queries (8 impressions): it is a trust word for the shopper who arrives,
  not a ranking term today.

### Options (PROPOSAL — the H1 is unchanged)

| | EN | AR |
|---|---|---|
| **1 (recommended)** | **Original Luxury Watches in Egypt** | **ساعات فاخرة أصلية في مصر** |
| 2 | Luxury Watches in Egypt from the Brands You Know | ساعات فاخرة في مصر من الماركات التي تعرفها |
| 3 | Original Watches in Egypt, with a Warranty of up to 3 Years | ساعات أصلية في مصر بضمان حتى ٣ سنوات |

Why 1: it is the literal non-brand query ("luxury watches egypt") plus the store's proof word, short enough for the
banner's one-line name slot at 1366 px, and it stays true for every product (2 names brands that change; 3 makes the
warranty the headline when the length varies per watch). "The Art of Timeless Precision" can stay as the banner's
small line under the H1 if the look should not change.

### Title and meta (suggestions — not changed)

| | EN | AR |
|---|---|---|
| title | Original Luxury Watches in Egypt \| Watchizer | ساعات فاخرة أصلية في مصر \| Watchizer |
| meta | Original watches from Tissot, Hugo Boss, Michael Kors and more, with a warranty of up to 3 years. Delivery to every governorate within 24 hours; free shipping over 10,000 EGP. | ساعات أصلية من تيسو وهوجو بوس ومايكل كورس وغيرها، بضمان حتى ٣ سنوات. توصيل لكل المحافظات خلال ٢٤ ساعة، وشحن مجاني للطلبات فوق ١٠٬٠٠٠ جنيه. |

The meta uses the client's facts (24 h delivery, free shipping over 10,000), which the policy pages do not say yet
(see the contradictions list). The three brands named have live brand pages (verified 200 on 2026-10-29).

---

## HANDOFF — 2026-10-29, Michael leaving (work stopped cleanly; nothing committed)

SAVE copy: `scratchpad/SAVE-campaign-10` (files + `git diff HEAD` patch), path in the reply.

### Done (with evidence)

- **Part 2, home H1** — the proposal above (top 15 queries, 3 EN + 3 AR options, title/meta). Nothing on the site changed.
- **Free shipping, rule A** (approved by Michael). Full suite on `scratch_suite_20261029` vs `tests/baseline/suite-baseline.txt`:
  baseline 61, this run 64, new 3 — all ours, all fixed, each rerun green; `composer phpstan` clean.
  - `OrderRecoveryTest` (422: the helper sent goods + delivery over 10,000) → the helper prices delivery with
    `FreeShipping::charged()`.
  - `CartQueryBudgetTest` (13 > 12 queries: the cart's free-shipping line read `storefronts`) → `FreeShipping::fromSettings()`
    + `summaryFor()`; the cart reads the request's resolved storefront row (no query). `FreeShippingTest` helper now
    forgets the resolved row after writing the threshold, as the dashboard save does.
  - `StorefrontTwinsTest` (pnpm-lock.yaml diverged by `lenis`) → dated DIVERGED line in `Frontend-brandfashion/TWINS.txt`;
    the five new identical files (freeShipping.js, useFreeShipping.js, storesJsonLd.js + their two tests) listed as twins.
- **422 / Paymob production risk** — `docs/deploy/FREE_SHIPPING_20261029.md` §6: core-first blocks every order ≥ 10,000
  until the storefronts deploy; storefronts-first is safe (old core sends no threshold → page charges delivery);
  RECOMMENDATION = deploy order, no transition code; Paymob is always passed the STORED order total (both checkouts).
- **Opening hours 10:00–24:00** (the only policy-page change): both storefronts' contact pages (both branches), Watchizer's
  about page, the store JSON-LD (`STORES_LD`, both apps, `closes: '23:59'`), Watchizer's home Store JSON-LD. Phone and
  WhatsApp hours on Watchizer stay 10:00–22:00 (new `storeHours` field; `TrustPage.jsx` reads it for stores, both apps).
  Brand Fashion gained store hours (it showed none). `tests/storesJsonLd.test.mjs`: 3/3 on both apps. Phone numbers
  untouched (Watchizer 01551096234, Brand Fashion 01283888490).
- **Blog posts written: Watchizer 2 of 6, Brand Fashion 0 of 6** — `core/database/seeders/content/blog/watchizer/`
  `how-to-tell-if-a-watch-is-original/{en,ar}.md`, `quartz-vs-automatic-watches/{en,ar}.md`. Every internal link used or
  planned is verified 200 on watchizereg.com and michaelkhalaf.com (`scratchpad/blog-links-checked.json`; the
  experience page is 404 live — do not link it yet).

### In progress (exact file and step)

- **Full suite gate re-run after the fixes** — started, then STOPPED for the handoff (not finished). Next: from core/,
  `DB_DATABASE=scratch_suite_20261029 DB_PORT=3307 bash tests/baseline/suite-baseline.sh`; expect 61 known, 0 new;
  then fill `FREE_SHIPPING_20261029.md` §5 with the numbers above and the gate's.
- **Blog posts** — next file: `core/database/seeders/content/blog/watchizer/watch-water-resistance-explained/en.md`
  (sources read and verified 200: Wikipedia "Water Resistant mark" — ISO 22810:2010, the 3/5/10/20 atm table, ISO 6425
  divers' 125% test; "Diving watch"; "Chronograph"). Then the AR twin, then buying-a-watch-online-in-egypt,
  watch-care-battery-and-bracelet-service, how-to-choose-a-chronograph (EN + AR each). Content uses the client's facts
  (24 h delivery, 14-day returns, 10:00–24:00, free over 10,000) as Michael decided.
- **Policy-page contradictions list** — data collected, not yet written here. Items: hours (now fixed); delivery
  Express 2–5 / Market 4–7 business days vs 24 h; returns 4 days (watches) / 14 exchange, fashion 4 days vs 14 days;
  condition "unused, original packaging" vs unopened with seals; no return-shipping or refund-method text; generic
  warranty text vs 1–3 years; payment without instalments; BF COD only vs "all methods"; home JSON-LD geo unverified.
  Next: write it as a table with URL + current text + client's fact, reading each from `src/content/trustPages.js`.

### Not started

Brand-text field per locale (DB migration, dashboard screen, storefront rendering, tests); category descriptions and
meta descriptions (≈ 110 categories × 2 languages); the FAQ; the blog seeder (`BlogPostsSeeder` reading the content
files: Watchizer `published_at` now, BF null; `core_blogs.slug` unique; markdown) + its run on a scratch copy; the
remaining 10 posts (4 Watchizer, 6 BF: choosing-a-watch-as-a-gift, steel-leather-or-rubber-watch-strap,
how-to-choose-a-watch-size, womens-watches-guide, matching-your-watch-belt-and-wallet, shopping-at-brand-fashion — BF's
number is 01283888490, no founding year); the open-questions list (task 7); the local production rebuild with links;
the COMMITS scripts (banner/experience, SEO content and blogs, free shipping; one `git add` per file; records last).

### Open decisions for Michael (each with the next step)

1. **Home H1** — recommended "Original Luxury Watches in Egypt" / «ساعات فاخرة أصلية في مصر» (options above). Next:
   on a yes, change `STORE_LINE` in `Frontend-next/src/Components/Home/GrandPrixBanner.jsx` (and the title/meta in
   `app/(main)/page.jsx` if wanted), re-run the banner fit check at the five sizes.
2. **Brand Fashion hours** — built as "both storefronts" = BF's two branches now show 10:00–24:00 on the contact page
   and in its JSON-LD (they showed no hours before). Next: keep, or revert `storeHours` in BF's `trustPages.js`.
3. **Watchizer phone/WhatsApp hours** — kept at 10:00–22:00; only the stores say 10:00–24:00. Next: if customer service
   also runs to midnight, set `contact.{en,ar}.hours` (and the description) in Watchizer's `trustPages.js`.
4. **Free-shipping deploy order** — recommended: storefronts first, core + migration at least an hour later (runbook §6).
   Next: confirm, or ask for the transition-window code instead.

### State left behind

- Local servers stopped (storefront :3010, core :8000) and mysqld shut down — confirmed in the reply.
- Scratch databases still on the 3307 server, not dropped: `scratch_suite_20261029`,
  `scratch_cart_proof_20261029`. The dev database `u591083448_watchizer` was not written. Drop the scratch copies
  when no longer needed.
- Known environment fact: dev account 1 has dashboard language `en` (since 2026-10-13); 30 of the baseline's 61 are that.
