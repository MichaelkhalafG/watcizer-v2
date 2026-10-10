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

## For the media buyer — pixels and one Events Manager rule (written 2026-10-10)

**One pixel from the next storefront deploy: `1614877760150035`** — the pixel the media buyer works
on, and the only one the server-side Purchase (Conversions API) reports to. The old pixel
`1611910119460872` is being removed from the site (owner decision 2026-10-10: no campaigns, no
audiences in use on it; the media buyer is on a new account and works only on …035). Until that
deploy both pixels load on every page; each receives each event once.

**Codeless rules in YOUR Events Manager are inflating your numbers — stated 2026-10-10.**
Pixel `1614877760150035` carries button-click rules set up in Events Manager's event setup tool (not by
the website; read from the pixel's public configuration on 2026-10-10):

| Rule | Fires when a visitor clicks something whose text contains | Effect |
|---|---|---|
| **Purchase** | `checkout` — the English cart page's **CHECKOUT** button | records a sale on every cart → checkout click, valued at the cart total (a second rule reads `.wz-cart-total` on `/cart`, currency EGP) |
| AddToCart | `add to cart`, `pre order` | a second AddToCart on top of the one the website sends once the cart has accepted the item |
| InitiateCheckout | `view cart` | an InitiateCheckout before anyone reaches checkout |
| ViewContent | the name of one product | a stray ViewContent |

**The Purchase rule is the costly one.** A click on CHECKOUT is not an order — the shopper has not even
seen the checkout form. What it costs you:

- **inflated conversion counts** — Purchases that are only clicks towards checkout;
- **inflated ROAS** — revenue that was never taken;
- **double-counting against the real Purchases** the website already sends: the browser Purchase
  when a cash-on-delivery order is placed, and the server-side (Conversions API) Purchase for card
  orders.

The website sends every real event; these rules add only false or duplicate ones. They are yours to
delete in Events Manager; we have not changed them and will not. Whether the Purchase rule has
already fired shows in the pixel's Purchase history (events from `/cart`).

*(Superseded 2026-10-10, kept for the record: a list of what removing the old pixel would stop —
audiences, lookalikes, retargeting, campaign signal. Not applicable: nothing runs on `…872`.)*

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
