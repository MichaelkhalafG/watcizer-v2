# Watchizer's Meta catalogue feed — handover (2026-10-10)

## For the media buyer (to pass on as it is)

**What changes.** Watchizer now publishes a real product feed for Meta: every product on the shop,
updated every hour, with its pictures, its price and sale price, and whether it is in stock. Until
now the catalogue was built "from the pixel" — only what the pixel happened to see, which is why
most products had no images.

**Connecting it** (Commerce Manager → your catalogue → Data sources → Add items → Data feed →
scheduled feed):

- **Feed URL:** `https://api.watchizereg.com/feeds/meta/watchizer/<token>.csv` — the developer sends
  you the full URL. Treat it like a password: anyone with it can download the catalogue.
- **Schedule:** hourly. Currency EGP.
- The file is a CSV; Meta reads its columns by name, nothing to map.

**Turn the pixel-built catalogue OFF once the feed is connected** — or point that catalogue's data
source at the feed instead. Otherwise the same products arrive twice, from two sources, and the
duplicates compete with each other.

**Your events keep matching.** Each product's `id` in the feed is exactly the id the pixel and the
server-side Purchase event already send (`content_ids`), so ViewContent / AddToCart / Purchase match
feed items from day one. **One exception:** on an *offer* page the pixel sends the offer's own id, not a
product id — those few events will not match a feed item.

**Columns you can build sets and campaigns on:**

| Column | What it holds |
|---|---|
| `product_type` | the shop's own category path, e.g. `Watches > Chronograph`, `Fashion > Bags` |
| `custom_label_0` | brand |
| `custom_label_1` | top category — `Watches`, `Fashion`, `Electronics` |
| `custom_label_2` | `express` = in stock in the shop, ships now · `market` = in stock through the supplier, may take longer · empty = out of stock |

**What the catalogue is, in numbers** (production, the feed's first run on 2026-10-10, 996 products):

| Stock | Products | Share |
|---|---|---|
| `express` — in stock in the shop, ships now | 66 | 6.6 % |
| `market` — in stock through the supplier only | 829 | 83.2 % |
| out of stock (listed as `out of stock`, never hidden) | 101 | 10.1 % |

About 1 product in 15 ships immediately. `custom_label_2` carries this per product, so an
**express-only ad set** is a product set filtered on `custom_label_2 = express`.

**287 products sit at a top-level category only** (271 Watches, 13 Electronics, 3 Fashion — 29.4 % of the 975 in the 2026-10-08 data; recount on the live feed with `product_type` without ` > `):
their `product_type` is one level deep (`Watches`), so a set built on a sub-category
(`Watches > Chronograph`) will not reach them. Their placement is the shop team's to complete.

Every product currently carries a sale price; that is the shop's pricing, and the feed states it as
it is. One brand is stored in lower case (`naviforce`, 52 products) and appears that way in `brand`
and `custom_label_0`.

Language: English. An Arabic version can follow as a language feed on the same ids — ask.

## For the media buyer — the two pixels and one Events Manager rule (2026-10-10)

**Both pixels run on every Watchizer page, in both languages** (measured on the live product page,
English and Arabic: `fbq.getState()` lists both). Each pixel receives each event once — two pixels,
two separate counts, not one event counted twice into the same pixel:

| Pixel | What it is (from the site's own configuration) |
|---|---|
| `1611910119460872` | the **old** pixel — it ran on the previous storefront and holds the campaign history. Automatic click-event detection is ON for it (it records one extra event per click) |
| `1614877760150035` | the **new** pixel — the one the client administers, and the only one the server-side Purchase (Conversions API) reports to |

**Delete the codeless Purchase rule on `1614877760150035`.** In Events Manager, on the new pixel, in its
event setup tool (Meta renames these menus; it is where button-click / "codeless" events live) there is a **Purchase** rule for `https://watchizereg.com/cart`
that reads its value from the cart page's total (`.wz-cart-total`) and sets the currency to EGP. A
Purchase on the cart page has no order behind it: every time it fires it records a sale that did not
happen, inflates the conversion count and ROAS, and double-counts against the real Purchase the site
sends when a cash-on-delivery order is placed (and the server-side one for card orders). Delete the
rule; the site already sends every real Purchase. (Found in the pixel's public configuration; whether
it has already fired is visible in the pixel's event history for Purchase on /cart.)

**Before the old pixel `1611910119460872` is removed from the site — what stops when it goes.** Removing
it from the site stops NEW data reaching it; its history stays in Events Manager. What depends on that
new data:

- **Website custom audiences built on it** (visitors, viewers of products, add-to-cart, purchasers)
  stop growing and empty out as members pass their retention window (up to 180 days).
- **Lookalike audiences seeded from those audiences** stop refreshing.
- **Retargeting** campaigns and ad sets using those audiences shrink with them.
- **Campaigns optimising for a conversion on this pixel** lose their signal the day it goes — switch
  each one to the new pixel's event first.
- **Custom conversions and any automatic-event setup defined on it** stop recording.
- **The catalogue**: if the pixel-built catalogue is fed by this pixel, it stops updating — moot once
  the product feed is connected (above), which does not depend on either pixel.

Safe order: rebuild the audiences on the new pixel (it has been receiving the same events since
at least 2026-09-22), move every campaign's optimisation and audiences to it, let the new audiences fill, and
only then ask for the old pixel to be taken off the site — one line, reversible.

## For the developer — operating it

- **Token:** `FEED_META_WATCHIZER_TOKEN` in `core/.env` (40 letters/digits:
  `php -r "echo bin2hex(random_bytes(20)), PHP_EOL;"`), then `php artisan config:cache`. Empty = no
  feed, the URL answers 404.
- **Generate now:** `php artisan feeds:meta` (the schedule runs it hourly at :40). It prints the
  product count, the stock split and any value over Meta's limits (reported, never cut).
- **Pull a product Meta flags:** add its id to `exclude` for the storefront in `config/feeds.php`,
  upload that file, `php artisan config:cache`, `php artisan feeds:meta`. No other code changes.
  (Owner decision 2026-10-09: the full catalogue goes in — backlog RISK-META-AUTH.)
- **Rotate the token:** change it in `.env`, `config:cache`, send the new URL to the media buyer —
  the old URL then 404s.
- **The URL serves the file `feeds:meta` wrote and never builds one.** Every run leaves one line per
  storefront in `storage/logs/scheduled.log` (its own channel — production's `LOG_LEVEL=warning`
  would drop an `info` line in `laravel.log`):
  `grep 'feeds:meta storefront=watchizer' storage/logs/scheduled.log | tail -3` →
  `… INFO: feeds:meta storefront=watchizer products=996 bytes=912828 file=unchanged ms=… over_limit=0` (the first production run, 2026-10-10).
  `file=unchanged` hour after hour is the ETag staying stable; `file=replaced` means the catalogue
  changed. With a token set, no line in the last hour = the schedule is not running
  (`php artisan schedule:list | grep feeds`). With NO token the run is off and writes nothing; a token
  that is set but unusable writes one `WARNING: feeds:meta storefront=… token set but unusable` line per run.
- **The allow-list line** in the API host's `.htaccess` (runbook §4.1.1) is the one way this fails
  silently: without it Apache answers 404 before core does. A core deploy overwrites `.htaccess`, so
  restore from the backup — and the backup must be one taken AFTER the line was added.
- **Brand Fashion** has no feed until its own domain is live; it never serves Watchizer's.
- **Google Merchant:** not built — gated on O10 (backlog FEED-GOOGLE).
