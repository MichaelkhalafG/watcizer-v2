# Watchizer storefront — design survey

**Status: SURVEY ONLY. 2026-10-21. Not one line was edited. Pick the numbers you want and I will do only those.**

**How this was looked at:** headless Chrome against **the live site** (`https://watchizereg.com`, read-only GETs)
at **1440 × 900** and **390 × 844**, full-page screenshots of home, listing, product, cart, about and contact.
Live-site screenshots are in the evidence folder. Every claim below is either visible in a screenshot or
confirmed in the source, and each item names the file. Brand Fashion is out of scope.

**Two things I checked and am NOT reporting as defects**, so you know they were looked at:

* The cart drawer appears open over the home page in the full-page phone screenshot. **It is not.** A fixed
  panel is painted into a full-page capture wherever its fixed coordinates fall. Probed on a cold load and on a
  reload at 390 px: zero cart panels on screen, `body` not locked. A capture artefact.
* No page overflows horizontally at either width (`scrollWidth === innerWidth` on all six).

---

## Group A — one-word and one-line copy fixes (lowest risk, no layout touched)

### 1. The home headline reads "TimelessPrecision" to a screen reader
`src/Components/Hero/WatchHero.jsx:79-81`. The markup is `The Art of` + `<span> Timeless</span>` + `<br />` +
`Precision`. The `<br>` makes the visual line break but puts no space in the text, so the accessible name —
and the page's `<h1>` text content, which is what the probe read back — is **"The Art of TimelessPrecision"**.
**Change:** add `{' '}` after the span (or move the break into CSS). **Risk: none.** Arabic is unaffected (it
breaks between two separate words).

### 2. The same stock fact is worded two ways, in English only
`src/Components/Product/ProductCard.jsx:93` says `Express · N in stock`; line 98 says `Market · N available`.
`ProductDetailClient.jsx:889/893` repeats the split. **The Arabic already uses one word (`متاح`) for both**, so
the intent is settled and only English drifted. **Change:** one word, four lines, two files — make both
`in stock`. **Risk: none.**

### 3. "View All" / "All Offers" / "See All" — three labels for one control
`ProductSlider.jsx:66` → `View All →`; `OfferSlider.jsx:58` → `All Offers →`; `CategoryTiles.jsx:59` →
`See All →`. They sit within one screen of each other on the home page and share a class
(`wz-section-action`), so this is wording, not styling. **Arabic is already down to two.** **Change:** pick one
English word-for-word label and use it in all three. **Risk: none.** *(Note: the red variant on Offers is a
deliberate accent — keep the colour, change only the words.)*

### 4. The urgency line is printed twice on the product page
`ProductDetailClient.jsx:1094` renders `Only N left!` in red beside the stock dot, and
`TrustSignals.jsx:94` renders `Only N left — order soon` as a pill about 500 px lower. Same number, same page,
two phrasings. Printing scarcity twice reads as pressure, which is the opposite of the "certified, guaranteed"
tone the hero sets. **Change:** keep one. I would keep the pill in `TrustSignals` (it sits with the returns and
payment facts, where a shopper is deciding) and drop the inline one. **Risk: very low** — one conditional
removed, no layout shift because the inline line is inside an existing row.

### 5. The cart page is titled in Arabic on the English site
`app/(main)/(shop)/cart/page.jsx` is five lines — `'use client'` and `<Cart />` — with no `metadata` and no
`generateMetadata`, so it inherits the root layout's Arabic default. The live English cart page's `<title>` is
`Watchizer - أفخم الساعات والإكسسوارات | تسوق الآن بأسعار مميزة`. Visible in the browser tab, in a bookmark, and
to anything that reads the title.

**Change — and it is not the obvious one:** a `'use client'` page **cannot** export metadata, so "add
`generateMetadata`" does not work here. Either add a tiny `app/(main)/(shop)/cart/layout.jsx` that carries the
metadata and renders its children, or make `page.jsx` a server component that renders the client `<Cart />`.
I would use the layout: it is additive and leaves the existing file untouched. **Risk: very low** either way.
*(The cart is `noindex` territory, so this is about the browser tab, not SEO.)*

---

## Group B — small visual inconsistencies (CSS or one component, no behaviour)

### 6. The trust strip mixes colour emoji with monochrome glyphs
`src/config/trust.config.js:17-19`: `guarantee` uses `✓`, `secure` uses `🔒`, `whatsapp` uses `💬`, and the
returns row uses `↵`. On the live product page the lock and speech bubble render as full-colour emoji in a
block that is otherwise black type on white — they are the only two colour objects in that panel, and they
read as pasted in. **Change:** one family. Either the three inline SVGs the site already ships elsewhere, or
all four as text glyphs. **Risk: low** — a config file plus whatever renders `icon`.

### 7. Price colour contradicts itself between the card and the product page
On every card the current price is red/orange (`wz-pc__price`); on the product page it is black with the
discount as a separate pink pill. The same product shows its price in two colours two clicks apart. Red prices
read as discount-store, which fights the positioning. **Change:** pick one. I would make the card price match
the product page (dark), leave the struck-through old price grey, and let the red `−N%` badge carry the
discount signal — it already does. **Risk: low, but it is the most VISIBLE item here** — it touches every card
on every page, so it is the one to look at in a screenshot before saying yes.

### 8. The announcement marquee runs underneath the social icons
At 1440 the top bar's scrolling text passes beneath the Facebook and Instagram icons at the right edge — in
the screenshot "…DELIVERY ACROSS EGY**P**" is overlapped mid-word. There is no gutter or fade between the
marquee track and the icon cluster. **Change:** reserve the icons' width on the track (padding-inline-end) and
add a short fade mask at both ends. **Risk: low**, CSS only.

### 9. The category circles are four different kinds of picture
In one row: *Diver* is a red illustrated badge, *Dress* is a black dress silhouette on white, *Sport* is a grey
"sports" wordmark, *Chronograph* and the rest are cropped product photos. The two white-background ones have no
visible edge against the light band, so they read as holes in the row. **Change (cheap, no data work):** give
`.wz-cats` circles a 1 px ring and a very light neutral fill so every chip has the same silhouette whatever the
artwork. **Risk: low**, CSS only. *(Making the artwork itself consistent is a content job — §C.)*

### 10. Only two of eleven navigation items carry an icon
`BRANDS` has a small building glyph and `OFFERS` a red `%`; the other nine have none. Two decorated items in a
row of eleven looks unfinished rather than emphasised. **Change:** drop both icons, or give the row a
consistent rule (e.g. icons only on the two promotional entries — which is what this is, so the simplest honest
fix is to keep `%` on Offers and drop the Brands glyph). **Risk: low.**

### 11. "NEW" is on every card in view
Eight of eight cards on the first listing screen carry the black `NEW` pill, and most also carry a red `−17%`.
A badge that is on everything carries no information and adds two coloured objects to every tile. **Change:**
tighten the `NEW` window (it is a date comparison in the card) so it means something, or drop the badge from
the listing grid and keep it on the home rails. **Risk: low** — but this is a *merchandising* decision as much
as a design one, so it may be yours rather than mine.

---

## Group C — small, but they need a number or a picture from you

### 12. The price filter's upper bound is 10,000,000 EGP
`src/Components/SideBar/SideBar.jsx:7` — `PRICE_MAX = 10000000`, the fallback "when the listing serves no price
bounds". **Watchizer never gets bounds**: compat's `facets` is value→count only (`CompatListing.php:85`), and
only v2's listing serves `facets.price`. So the live slider always spans 0 → 10,000,000+ EGP, which makes it
unusable — the whole catalogue sits in the first 0.1% of the track. **Two possible fixes:**
(a) one line — set a sane fallback ceiling (the catalogue's real top price, rounded up); or
(b) the real fix — Watchizer's listing moves to v2, which serves the bound (slice 1 of the inventory document).
**Risk: (a) none, (b) is a separate project.** I would do (a) now regardless.

### 13. Nineteen of sixty-eight home cards have no photograph
Measured from the **live** API, not from a screenshot: `catalog/home` returns 68 product cards and 172 image
rows, and **19 cards have no image row at all**. The card reserves its ~340 px square and renders nothing, so
the "Premium" and "Luxury" rails show rows of blank boxes with prices underneath. **This is a data gap, not a
layout bug** — but the design question is what a card should do when the photo is missing. **Change:** render
the existing `PLACEHOLDER_IMG` (the site already ships one, `src/utils/imageUrl.js`) instead of empty space.
**Risk: low.** The real fix is 19 photographs, which is yours.

### 14. One home rail contains a single product
The live `catalog/home` returns **11 rails**; their product counts are 12, 4, 8, 8, 8, 8, 8, **1**, 4, 7, 8. At
four cards per row a one-product rail is a full-width band with one card and ~75% empty space, under its own
heading and eyebrow. Brand Fashion solved exactly this with a short-rail rule; Watchizer has none. **Change:**
hide a rail below a minimum (2 on phone, 3 on desktop) — a guard in `HomeClient.jsx`'s `rails` memo.
**Risk: low**, but it changes what the home page shows, so it is your call how aggressive the threshold is.
*(Or fill the rail from the dashboard — also yours.)*

---

## Group D — the rhythm problem (small changes, biggest single effect)

### 15. Eight identical rails in a row, each with a marketing sentence above it
The home page is **9,132 px** tall at 1440 and nine of its eleven bands are the same component with the same
anatomy: eyebrow line, serif title, bordered "View All" pill, four cards, repeat. The eyebrows are strings like
`HIGH-QUALITY WATCHES WITH EXCELLENT CRAFTSMANSHIP` and `PREMIUM LUXURY TIMEPIECES FROM ICONIC MAISONS`, set in
tracked-out small caps above every heading (`ProductSlider.jsx:61`, `wz-section-label`). A tracked-out
all-caps line above every heading is the single most recognisable "generated page" tell, and here there are
eight of them saying nearly the same thing.

**Important: those sentences are DATA, not code** — they are each grade's `description` column, rendered as the
eyebrow. So there are two independent changes:

* **15a (code, small):** stop rendering the eyebrow on the grade rails — keep it on Offers ("Limited Offers")
  and Categories ("Shop by"), where it names a kind rather than advertises. One conditional in
  `ProductSlider.jsx`. **Risk: low.** This alone removes eight near-identical lines.
* **15b (content, yours):** or rewrite the eight descriptions so each says something specific. No code.

### 16. Every band has the same vertical weight
Nine bands of identical height, identical padding, identical heading size, one after another. Nothing on the
page says "this one matters more". **The cheapest change with a real effect:** let the first rail after the
category strip (Season Offers) keep its current prominence and reduce the heading size / top padding of the
repeat rails by a step, so the page has a top-to-bottom taper instead of a flat pulse. **Risk: low**, CSS only
— one modifier class on the rails after the first. I would do this **together with 15a** or not at all; apart,
neither reads as much.

---

## NOT SMALL — do not mix these into the list above

Each of these is worth doing in my view, and none of them is a bounded fix. I am not proposing to start any of
them, and nothing above depends on them.

**N1. The home page is too long and its order is accidental.** 9,132 px, eleven bands, nine of which are grade
rails in grade-id order. A shopper scrolling to "New Arrival" passes seven near-duplicate bands. This is a
merchandising structure question — which rails, in what order, how many — and it belongs on the Home rails
screen as a decision, not in CSS. It is also the thing that would most change how the site feels.

**N2. The listing's default sort shows eight near-identical belts.** The first screen of "All Products" is
eight black leather belts at 1,650 EGP. Again merchandising (default sort), not design.

**N3. Brand data is wrong on some cards.** On the live listing, three cards whose titles begin "Coach Men's…"
are labelled **GUCCI**. That is a catalogue data defect, visible to every shopper, and it is worth more than
anything in Group A — but it is a data fix, not a design one, and I have not investigated how widespread it is.
Say the word and I will measure it properly (read-only) and report separately.

**N4. The 3D hero fills the phone's first screen with a watch and nothing else.** At 390 × 844 the headline,
the body copy and both buttons are below the fold; the first screen is the watch render and the header. Fixing
it means changing the hero's mobile composition, which is a design decision about your signature element — not
a spacing tweak.

---

## What I would pick, if you want a short answer

**1, 2, 3, 4, 5, 8, 12, 13** — all copy or CSS, all no-risk, all defensible on their own, and together they
remove most of the "assembled" feel from the pages a shopper actually reads.
**Then 15a + 16 together**, because that pair is what changes the home page's rhythm.
**7 and 11 I would show you a screenshot of first**, because they touch every card on the site.
