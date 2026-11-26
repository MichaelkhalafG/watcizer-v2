# Item 6 - the held page texts: candidates and facts (prepared 2026-11-25)

Read from the 17 Nov production-like copy (scratch_relpest_20261117); the final list is re-read on the production export.
No Search Console figure is in this file. The ranking by impressions is the one step left for the data.

## Rules applied

- Only CATEGORY pages: the six held BRAND pages per shop are houses kept from search engines or thin brands, which get
  no copy by rule (D8 of 18 Nov).
- A page qualifies when it has at least 3 indexable products in stock (below that it is noindex and copy is never seen).
- Dress is left out: it is empty on production (D23) and 301s to the classic watches since 0a9c47e.
- Every claim from our own pages (delivery, cash on delivery with inspection, 14-day exchange, the warranty page, the
  two Cairo stores for Watchizer) and the catalogue counts; outside facts only from the approved list in
  config/content_gate.php, with a Sources section where the gate asks for one. Four-digit numbers in words (D3).
- Loaded through seo:load-copy and checked by seo:gate on a scratch copy; a page ships only when both languages pass.

## Candidates (indexable products / in stock)

### Watchizer

| Page | Indexable | In stock | Qualifies |
|---|---|---|---|
| watches | 1310 | 1375 | yes |
| watches/chronograph | 1032 | 1048 | yes |
| fashion | 602 | 581 | yes |
| fashion/bags | 333 | 319 | yes |
| fashion/wallets | 173 | 167 | yes |
| watches/casual | 144 | 153 | yes |
| fashion/belts | 126 | 125 | yes |
| watches/dress | 82 | 103 | no: empty on production, redirected |
| watches/sport | 67 | 79 | yes |
| watches/automatic | 32 | 57 | yes |
| fashion/bracelets | 41 | 40 | yes |
| watches/skeleton | 2 | 28 | no: under 3 |
| watches/diver | 19 | 20 | yes |
| watches/digital-watch | 8 | 8 | yes |
| fashion/jewelry | 8 | 8 | yes |
| watches/gmt | 7 | 7 | yes |
| fashion/perfumes | 2 | 2 | no: under 3 |
| fashion/socks | 2 | 2 | no: under 3 |
| watches/tourbillon | 0 | 1 | no: under 3 |
| electronics/chargers | 32 | 0 | no: under 3 |
| electronics/phone-holders | 5 | 0 | no: under 3 |
| electronics/power-banks | 3 | 0 | no: under 3 |
| electronics/screen-protectors | 15 | 0 | no: under 3 |
| electronics | 72 | 0 | no: under 3 |
| electronics/audio | 4 | 0 | no: under 3 |

### Brand Fashion

| Page | Indexable | In stock | Qualifies |
|---|---|---|---|
| watches | 5698 | 1705 | yes |
| watches/chronograph | 1052 | 1066 | yes |
| fashion | 2493 | 589 | yes |
| fashion/bags | 928 | 324 | yes |
| fashion/wallets | 387 | 168 | yes |
| watches/casual | 145 | 154 | yes |
| fashion/belts | 205 | 123 | yes |
| watches/dress | 83 | 103 | no: empty on production, redirected |
| watches/sport | 66 | 78 | yes |
| watches/automatic | 118 | 60 | yes |
| fashion/bracelets | 39 | 39 | yes |
| watches/skeleton | 2 | 28 | no: under 3 |
| watches/diver | 19 | 20 | yes |
| watches/digital-watch | 15 | 9 | yes |
| fashion/jewelry | 10 | 9 | yes |
| watches/gmt | 7 | 7 | yes |
| fashion/perfumes | 162 | 3 | yes |
| watches/tourbillon | 0 | 1 | no: under 3 |
| electronics | 108 | 0 | no: under 3 |
| electronics/audio | 7 | 0 | no: under 3 |
| electronics/chargers | 40 | 0 | no: under 3 |
| electronics/phone-holders | 11 | 0 | no: under 3 |
| electronics/power-banks | 8 | 0 | no: under 3 |
| electronics/screen-protectors | 22 | 0 | no: under 3 |

## Next

1. Rank the qualifying pages by Search Console impressions (28 days, Pages), then by in-stock products.
2. Write the first 12 per shop in that order (EN 800-1,200 words, AR 680-1,200, at least 4 sections, the FAQ answers in
   range), run seo:load-copy and seo:gate on the export's copy, and ship only what passes.

## 26 Nov: the held list on the production export, and the ranking

Michael (26 Nov): item 6 is OUT of the eight changes' deploy. The texts go out later as a separate data-only step with
its own script, written in a fresh session. Nothing of item 6 is in core-20261125.tar.gz or the deploy script.

Read on export-20261125 (scratch_reh_20261126, production's copy of 25 Nov): core_seo_texts holds 24 HELD pages (48
texts): Watchizer 5 brands and 10 categories, Brand Fashion 4 brands and 5 categories.

- Held brands, no copy by rule (D8 of 18 Nov: houses kept from search engines, or thin): audemars-piguet, hublot,
  patek-philippe, rolex (both shops), just-cavalli (Watchizer).
- Held categories that do not qualify (under 3 indexable products, or redirected): watches/skeleton,
  watches/tourbillon, watches/dress (both shops), fashion/perfumes and fashion/socks (Watchizer).
- THE PAGES TO WRITE, in rank order (Search Console 28 days to 25 Nov, Pages, the page and its /ar twin together;
  then in-stock products):

| Rank | Shop | Page | Impressions | In stock | Note |
|---|---|---|---|---|---|
| 1 | Brand Fashion | watches/diver (/subtypes/diver) | - | 20 | the shop's most-cited page in Bing's AI answers (Copilot), Michael 26 Nov: FIRST |
| 2 | Watchizer | watches (/category/watches) | 506 | 1375 | the shop's top category page |
| 3 | Watchizer | watches/casual (/subtypes/casual) | 84 | 153 | |
| 4 | Watchizer | watches/automatic (/subtypes/automatic) | 27 | 57 | |
| 5 | Watchizer | watches/diver (/subtypes/diver) | 3 | 20 | |
| 6 | Watchizer | watches/gmt (/subtypes/gmt) | 0 | 7 | |
| 7 | Brand Fashion | watches/gmt (/subtypes/gmt) | 0 | 7 | |

Seven pages, fourteen texts: fewer than the plan's 24 (D17), because only these are held AND qualify. The passed pages
keep their texts. Before writing, read the list again on the export of that day (a page can pass or change in between).
