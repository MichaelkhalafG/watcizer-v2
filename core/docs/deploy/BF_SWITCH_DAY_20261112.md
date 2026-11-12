# Brand Fashion switch day — the ONE runbook (2026-11-12)

**Written overnight 2026-11-11 → 12; nothing in it has been run on production.** One sitting (D-4, Michael: "the
client's season is urgent"). The domain changes by ONE statement, run once (D-2, decided overnight, Michael to
confirm). It replaces the order of `BF_DOMAIN_SWITCH_PLAN.md` §4–§8 and `BF_LEGACY_IMPORT_2026-11-11.md` §6 for today.
Those two stay the reference for the reasons; where this file differs, this file is right (each difference is named
in §11).

**Scripts** (copy them to the server's home on the morning; all bash, LF; each step a gate that prints STOP and exits
before the next write; each write names its UNDO):

| On | File (from `D:\coding\watchizer website\…`) | What |
|---|---|---|
| server | `deploy-scripts\deploy-part4-20261112.sh` | step 1: core with tonight's work (`DEPLOY_PART4_20261112.md`) |
| server | `checkpoints\2026-11-11_bf-legacy\switch-day\bf-fetch-2022.sh` + `images-2022.txt` (→ `~/bf-handover/`) | step 2b: the 951 originals the zip lacks, fetched read-only from the LIVE old site (must finish before 4e, and long before 8.5) |
| server | `checkpoints\2026-11-11_bf-legacy\switch-day\bf-measure.sh` | step 3: two minutes; the shard count |
| server | `…\switch-day\bf-switch-import.sh` | steps 4–5: backup, clean, prepare, reviewed, import, redirects, articles, offers |
| server | `…\switch-day\bf-images.sh` | step 4: the image pass in parallel shards, under nohup |
| server | `…\switch-day\bf-switch-core.sh` | steps 7 and 9: `.htaccess`, CORS, the storefront row (read / write / rollback), env, feeds |
| server | `…\switch-day\bf-switch-verify.sh` | step 8: the read-only check after the cutover (PASS / FAIL per line) |
| workstation | `…\switch-day\bf-switch-code.py` | step 8: the site address and `robots.txt`, 2 lines (`--undo`) |

**The critical path (★) — the steps that cannot overlap.** With the image pass at its measured speed (3 shards at
0.9 s an image ≈ 2 h), the day takes about **5½–6 hours**, most of it waiting on the pass:

```
★1 deploy (45 min) → ★3 measure (5) → ★4a backup+clean+prepare+reviewed (30) → ★4b IMAGE PASS (1–6 h, the table in step 3)
   → ★4c import (20–40) → ★5 redirects, articles, offers (15) → ★7 core switch (15) → ★8 app, DNS, SSL, verify (60–90)
beside 3 and 4a:          step 2b, the 2022 fetch (about 10 min; it must be FINISHED before 4b/4e starts)
off the path, DURING 4b:  step 6 (hPanel: domain, api sub-domain, the two mailboxes; DNS: api, mail), step 9a (Google
                          OAuth, Search Console TXT), the banners' re-upload
after 8:                  step 9b (MyFatoorah live, the feeds into Meta and Google, Search Console sitemap), step 10
```

**Who sees what, when.** The old WordPress shop keeps selling on `www.brandfashionegy.com` until step 8.5 moves its
DNS. The temporary domain `michaelkhalaf.com` (nobody uses it, D-1) stops working at step 7.4 and comes back as a
301 at step 8.7. Watchizer is untouched all day: every step that could reach it checks it and says roll back if not.

---

## 0. The evening before / first thing (5 min)

1. **Namecheap** → `brandfashionegy.com` → Advanced DNS: TTL of the `@` and `www` records → **5 min**. Each hour
   earlier is an hour shorter for the old record at step 8.5 (the current TTL is the longest wait).
2. Ask the client to **add or change nothing on the old WordPress site** from now on. The import reads the dump of
   9 Nov (or the fresh one of step 2). A product added there today exists only there.
3. Michael has to hand: the hPanel and Namecheap logins; the MyFatoorah portal; the Google Cloud console (the OAuth
   client Brand Fashion uses); Meta Business Manager; Search Console; the password manager.

## 1. ★ Deploy core — Part 4 (45 min)

1. `& 'D:\coding\watchizer website\checkpoints\2026-11-11_bf-legacy\COMMITS-20261112.ps1'` (Michael: the commits).
2. The archive: the PowerShell block of `DEPLOY_PART4_20261112.md` §4 (it builds the dashboard bundle). Upload
   the archive, and every script of the table above, to `~/`.
3. `EXPECTED_MANIFEST_SHA=<hash> bash ~/deploy-part4-20261112.sh` — every gate in the record. It adds ONE
   `.htaccess` line (the Google feed) with a backup, and runs ONE migration.
4. Merge `wave-5` into `main` and push (Michael). Hostinger rebuilds Watchizer (5–10 min, in parallel with step 3).
   **CHECK** whether the Brand Fashion Node app also rebuilds on this push. Harmless either way: its site address
   is still the temporary one (`siteAddress.js`, unchanged tonight, proved).

**Gate:** the script's `DONE`; Watchizer's home after its rebuild. **Undo:** `DEPLOY_PART4_20261112.md` §6.

## 2. Optional — a fresh WordPress dump (40 min; only if the old site changed after 9 Nov)

Only if the client added or changed products after the dump of **9 Nov**. Otherwise skip: the 9 Nov extract is the
one every rehearsal used.
1. A new database export of the old site → restore locally into `bf_legacy` →
   `BF_LEGACY_DB_PASSWORD=… php artisan bf:legacy-extract --wp-port=3307 --wp-database=bf_legacy --out=<dir>`.
2. Upload `<dir>` → `~/bf-handover/extract/` (replace the folder; keep the old one as `extract-20261109`).
3. The originals of a product added after 9 Nov are not in the zip (it ends with what the agency sent). Without
   them those products are imported **hidden**, the same as the 259 of 2022 (step 4).

**Undo:** the old extract folder back.

## 2b. The 2022 originals, from the live old site (about 10 min, beside steps 3 and 4a)

The agency has no `wp-content/uploads/2022/`, but the old site still serves those files until DNS moves (8.5). Start
right after step 1:
1. Upload `images-2022.txt` (951 paths) → `~/bf-handover/images-2022.txt`.
2. `IMAGES=~/bf-handover/originals bash ~/bf-fetch-2022.sh start` (under nohup), then
   `bash ~/bf-fetch-2022.sh status` until `finished`.

What it does:
- Read-only GETs, 2 at a time, a pause after each, 3 tries.
- A file is kept only on 200 with an `image/*` content-type, a non-empty body and real JPEG / PNG / GIF / WebP first
  bytes, at the same relative path.
- It follows NO redirect (after 8.5 those URLs 301 to our own copies).
- It is re-runnable: a file already there is skipped.
- It prints fetched / already there / failed, and lists the failed files.

**Proved 2026-11-12 from the workstation:**
- 7 of these URLs answered 200 `image/jpeg` / `image/png` with real JPEG / PNG bytes, and the 7 went through the real
  image pipeline.
- The script itself, end to end: 3 fetched, 1 already there, 1 non-existent path FAILED (its HTML 404 not kept, no
  partial file); a re-run skipped the 4 done.
- The rehearsal copy shows the effect: products 875 and 878, whose 2022 photos were present, imported **visible**
  with 4 images; 877, whose were not, imported hidden with 0.

**So the 259 products import VISIBLE, and the 40 products that missed only gallery photos get them too.** Only a file
on the failed list stays missing. Its product imports hidden only if that file is its FEATURED photo (re-run `start`
for the failed ones before 4e). **Gate:** `finished` with `failed 0` (or each failed file read and accepted), BEFORE
4e. **Undo:** delete `~/bf-handover/originals/2022/` (nothing points at it until the import).

## 3. ★ Measure the server (5 min)

`IMAGES=~/bf-handover/originals bash ~/bf-measure.sh` (read-only for the shop). It checks that `IMAGES` holds `2024/`
(if the zip had a prefix, it says where to point). It times one process on 50 originals and four at once, then prints
**the shard count**: what the account really gets, minus one process left for Watchizer's API. It also prints the
hours per shard count. The table at the workstation's speed, for orientation (24,000 originals):

| shards | 0.7 s/img | 0.9 s/img | 1.5 s/img | 2.0 s/img |
|---|---|---|---|---|
| 1 | 4.7 h | 6.0 h | 10.0 h | 13.3 h |
| 2 | 2.3 h | 3.0 h | 5.0 h | 6.7 h |
| 3 | 1.6 h | 2.0 h | 3.3 h | 4.4 h |
| 4 | 1.2 h | 1.5 h | 2.5 h | 3.3 h |

**Gate:** a shard count printed; `memory_limit` × shards under half the account's memory.

## 4. ★ The catalogue, while the old site still sells (1½–7 h — the image pass decides)

All on the server, `bash ~/bf-switch-import.sh <step>`. Each `-apply` refuses until its `-dry` ran (READ its log in
`~/bf-handover/logs/`), and refuses an output folder that exists:

| | Step | Rehearsed (production copy) | Undo |
|---|---|---|---|
| a | `backup` | `core:backup` + `backup:verify` | the dump is the undo of c–e |
| b | `clean-dry` → `clean-apply` | 6,526 removed, 32 archived | `bf:catalogue-clean --restore=~/bf-handover/clean-apply/backup.json --allow-remote` |
| c | `prepare-dry` → `prepare-apply` | 1 renamed (Buckle إبزيم), Clasp مشبك, 3 lookups, 14 brands, 17 categories on + in the menu, 5 twins, SATIN10 to 31 Dec | `bf:catalogue-prepare --restore=~/bf-handover/prepare/prepare-undo.json --allow-remote` |
| d | `reviewed-dry` → `reviewed-apply` | 112 removed, 3 kept (6980, 7049, 7058) | `bf:catalogue-clean --restore=~/bf-handover/reviewed/backup.json --allow-remote` |
| e | **the image pass:** `SHARDS=<step 3> bash ~/bf-images.sh start`, then `bash ~/bf-images.sh status` until **ALL SHARDS FINISHED** | 4 shards = 1 process, byte for byte | none needed (files nothing points at yet) |
| f | `import-dry` → `import-apply` (nohup; `import-status`) → `verify` | 7,161 created, 596 matched; every image reused; `inventory:verify` green | the dump of (a) |

- The pass survives a disconnect (nohup). After a kill, a reboot or a lost session, run the same `start` line
  again: each shard skips what is done. The import refuses to start while a shard runs.
- **The 2022 photos come from step 2b** (the agency has no 2022 folder). With 2b finished, the 259 products import
  visible and the 40 get their gallery photos. D-21 ("hidden") now applies only to a file still on 2b's failed list.
- **During (e)**, off the critical path: step 6, step 9a, the banners' re-upload (`DEPLOY_PART4` §5.2).

**Gate:** `verify` green; `import-status` shows `images_reused` ≈ all and `images_processed` ≈ only what a shard
could not read.

## 5. ★ Redirects, articles, offers (15 min)

`bash ~/bf-switch-import.sh redirects-dry` → `redirects-apply` → `blogs-dry` → `blogs-apply` → `redirects-apply` (again:
the articles' old URLs) → `offers-dry` → `offers-apply`.
- The redirects read the import's map AND the shard maps (fixed tonight; without it no old image URL would
  redirect). Rehearsed: 7,880 rows.
- The 11 articles are published in both languages on their original dates (O-1).
- `offers:copy` copies only what is LIVE: **Hot Deals ended 12 Nov 00:00**, so expect it absent. Black1000 becomes
  FRIDAY1000 (D-7). **WR10 becomes BR10:** once the team has created WR10 on Watchizer (the coupon of 2026-11-12),
  `offers:copy` copies it to Brand Fashion as its OWN rule BR10 (the same 10 %, dates and exclusion; proved on the
  production copy). The script passes `--code=WR10=BR10` always: without the pair a live WR10 would make the whole
  copy REFUSE, and while WR10 does not exist the pair is ignored.

**Undo:** `DELETE FROM storefront_redirects WHERE storefront_id = 2 AND source = 'bf_legacy';` · the articles from
the Blogs screen · the printed offer ids on the Promotions / Campaigns screens.

## 6. hPanel and DNS — DURING step 4e (30 min of work + waiting)

**6.1 hPanel** (the account that hosts core and the Brand Fashion Node app):
1. **Domains → add `brandfashionegy.com`.** The DNS stays at Namecheap; hPanel shows the IPs to point at.
   **CHECK** how the Brand Fashion Node app is bound. Write down its server IP (`nslookup michaelkhalaf.com`) and
   core's (`nslookup api.michaelkhalaf.com`).
2. **Subdomains → `api.brandfashionegy.com`** with the SAME document root as `api.michaelkhalaf.com` (core's
   `public/`).
3. **Emails → TWO mailboxes on `brandfashionegy.com`** (switch plan §7.1 step 1; each long random password goes into
   the password manager only):
   - `no-reply@brandfashionegy.com` — the shop's sender (core's mailbox row, 6.2's last step);
   - **`info@brandfashionegy.com`** — a real mailbox the team reads (O-7, decided by Michael 2026-11-12: (a)). The
     contact page already shows it (`trustPages.js` `EMAIL`); addresses are case-insensitive, so the page's
     `Info@…` reaches this box.
   The same MX / SPF / DKIM / DMARC records of 6.2 cover both. Create BOTH before the MX change, so no message to
   `info@` bounces.

**6.2 Namecheap DNS now** — Advanced DNS, the nameservers stay at Namecheap:

| Action | Type | Host | Value | When |
|---|---|---|---|---|
| add | A | `api` | core's IP (6.1) | **now** — nothing uses it yet |
| as §7.1 | MX / TXT SPF / CNAME DKIM ×3 / TXT DMARC | — | the exact table of switch plan §7.1 step 2 (Custom MX; ONE SPF; the old Private Email DKIM deleted) | now, **after BOTH mailboxes of 6.1.3 exist** (from this minute, mail to the domain arrives at Hostinger) |
| add (if hPanel asks) | TXT | as hPanel shows | its verification value | now |
| add | TXT | `@` | Search Console's domain verification (step 9a) | now |
| **change** | A | `@` | the Brand Fashion app's IP (6.1) | **step 8.5, not now** |
| **change** | A / CNAME | `www` | the same | **step 8.5, not now** |

Waiting: a new record (api) resolves within minutes. Then **hPanel → SSL** for `api.brandfashionegy.com`
(5–20 min). **Gate:** `nslookup api.brandfashionegy.com` = core's IP; `curl -I https://api.brandfashionegy.com/`
answers over TLS. Then the mail: switch plan §7.1 steps 3–5 (verify from outside, then the dashboard's mailbox fields,
then a test order's headers `SPF: pass`, `DKIM: pass` with `d=brandfashionegy.com`).

**Undo:** delete the added records; the old MX / SPF / DKIM back as switch plan §7.1 "today" lists them; the mailbox
fields back to `no-reply@bf.michaelkhalaf.com`.

## 7. ★ Core switches (15 min) — after 5 and 6.2's `api` gate

`bash ~/bf-switch-core.sh <step>`:
1. **`htaccess`**: both API-host lines name `brandfashionegy` (backup `~/htaccess.before-bfswitch-a.bak`, a
   2-line diff). Watchizer and the dashboard are re-checked, and the new host now reaches core (a JSON 404: no
   shop owns it yet). **Undo:** the `cp` it prints.
2. **`cors`**: `CORS_EXTRA_ORIGINS` gains `https://www.brandfashionegy.com,https://brandfashionegy.com` (kept: what
   is there). `config:cache`; checked through `config('cors.allowed_origins')`. **Undo:** the `cp` + `config:cache`
   it prints.
3. **`row-read`**: the row saved to `~/bf-handover/storefront-row.before-a.json` (expect domain `michaelkhalaf.com`,
   urls null).
4. **`row-write` — THE MOMENT.** `domain` → `brandfashionegy.com`; `settings.urls.frontend` →
   `https://www.brandfashionegy.com`; `StorefrontCache::forgetStorefront` (the resolved row, the host map, the URL
   map, the shop's payloads: the narrow flush the plan asked for). Then §5's five checks: the new API host 200, the
   old one 404, **Watchizer 200**, BF on Watchizer's host 404, an image on the new host 200.
   **Undo: `row-rollback`**: the row back byte for byte from the saved file (rehearsed on scratch: md5 identical).
   **Fixed tonight:** the plan's SQL used `JSON_SET(settings, '$.urls.frontend', …)`. `settings` has no `urls`
   on production, and JSON_SET does not create a missing parent: the domain would have changed while the `www`
   address silently stayed unset. The script merges `urls` as an object.

From 7.4 until 8.4 the temporary domain's pages fail (no shop owns `api.michaelkhalaf.com`). Real customers are
still on the old WordPress site.

## 8. ★ The app on the real domain (60–90 min)

**The order matters (Michael's review, 2026-11-12): the environment FIRST, then the code push, then the build.** If
Hostinger rebuilds the Brand Fashion app on a push, a build started by the push of 8.2 must already find the new
environment. With the old one it would call `api.michaelkhalaf.com`, which no shop owns after 7.4, and build and
cache empty pages.

1. **hPanel → the Brand Fashion Node app → environment** (every `NEXT_PUBLIC_*` is inlined at build time):
   - `NEXT_PUBLIC_API_BASE=https://api.brandfashionegy.com/api`
   - `NEXT_PUBLIC_ASSET_BASE=https://api.brandfashionegy.com`
   - `LARAVEL_ORIGIN=https://api.brandfashionegy.com`
   - `SITE_CANONICAL_HOST=www.brandfashionegy.com`
   - `NEXT_PUBLIC_META_PIXEL_ID=1594687941022756` (the old site's pixel, Block C)
   - **CHECK** `STOREFRONT_SERVER_KEY` is set (without it the old-URL lookups hit the 60/min limit and fall back to
     "served as is": measured in the rehearsal of 2026-11-11).
   Keep a copy of the old values (the undo). **Do not build yet.**
2. **Workstation:** `python -X utf8 -I bf-switch-code.py` (the site address and `robots.txt`, two lines; it refuses
   unless they are as expected; `--undo` puts both back). Michael: commit and push.
3. **Build** the app (hPanel), or let the push-triggered build of 8.2 run (**CHECK** in hPanel which happened; not
   both at once). **Gate:** the build succeeds; it used the new values (the build log or the app's environment page);
   the app answers on its current host.
4. **Bind** `brandfashionegy.com` and `www.brandfashionegy.com` to the app (hPanel; **CHECK** the menu, as in
   6.1.1). `michaelkhalaf.com` either stays bound (then step 7 below redirects it) or is freed.
5. **Namecheap:** `A @` and `www` → the app's IP (the two "step 8.5" rows of 6.2). **From here new visitors reach
   the new shop**; others reach the old one until the old TTL runs out.
6. **hPanel → SSL** for `brandfashionegy.com` and `www.` once they resolve (5–20 min). Until the certificate
   exists, a visitor whose DNS already moved sees a certificate warning: this window is the reason for a quiet hour.
   Then **force HTTPS**.
7. **`michaelkhalaf.com` → 301** to `https://www.brandfashionegy.com/{path}`, path and query kept, permanent. hPanel →
   the domain's redirect, or an `.htaccess` in its own document root once the app no longer owns it (switch plan
   §8.3). The app's middleware redirects only the bare and `www` forms, not another domain.
   `api.michaelkhalaf.com` gets NO redirect (404 is right for an API).
8. **`bash ~/bf-switch-verify.sh`** — PASS / FAIL per line: www 200; bare → www; http → https; path and query
   kept; michaelkhalaf → www; core on the new API host; **Watchizer**; canonical, hreflang and `robots.txt` on www;
   the sitemap; `wp-admin` 410; **100 random old URLs from the redirect map: one 301, then 200**. Then by hand: one
   cash-on-delivery order end to end, and its two e-mails' links name `brandfashionegy.com`.

**Undo** (switch plan §10): `row-rollback` (7.4); the app env back and rebuild; `bf-switch-code.py --undo`, commit,
push; the DNS `@` / `www` back to the old site's IP. Steps 6 and 7.1–7.2 are additive and may stay.

## 9. Third parties

**9a — during step 4e (before 7.4):**
- **Google Cloud → the OAuth client:** add the redirect URI `https://api.brandfashionegy.com/api/auth/google/callback`
  (exactly so: no trailing slash) and the JavaScript origins `https://www.brandfashionegy.com` and
  `https://brandfashionegy.com`. Consent screen: the authorised domain `brandfashionegy.com`. Keep the old ones until
  §8.4 of the plan. Without this, Google sign-in fails from 7.4.
- **Search Console:** the DOMAIN property `brandfashionegy.com` (DNS TXT, 6.2), while the old site still answers.
- **MyFatoorah portal — read only here:** note which base URL the portal names for this (Egyptian) account
  (`https://api-eg.myfatoorah.com` expected). **Create NO key now** (see 9b.1).

**9b — after 8.8:**
1. **MyFatoorah** (Block B). **The NEW live API key is created ONLY AFTER `@` and `www` point to Hostinger (8.5,
   checked by 8.8)** (Michael, 2026-11-12). A new key might revoke the old site's key, and the old site takes card
   payments until its DNS moves. Never earlier, whatever the portal seems to allow.
   - `MF_HOST=<the portal's host> bash ~/bf-switch-core.sh env` (also sets both feed tokens; backup, `config:cache`,
     checked).
   - Portal: create the new live API key; on the webhook page, the "secure key" ON, and note its value.
   - Dashboard → Brand Fashion → Payments → add **MyFatoorah**: the NEW live API key and the webhook signing key.
     Without the signing key the contract does not count as live: decided overnight, Michael to confirm.
   - In the portal: the webhook URL `https://api.brandfashionegy.com/api/pay/brandfashion/myfatoorah/callback`.
     **Remove the old site's webhook** (it stops answering anyway).
   - `bash ~/bf-switch-core.sh mf-methods`: the account's live codes. On the dashboard, one method row per method
     wanted (cards, Meeza, wallets, Fawry), each with the code AS LISTED, then switch them on.
   - **One real small card payment** (then refund it in the portal), and
     `php artisan payments:myfatoorah-methods brandfashion --payment=<its id>`: `InvoiceDisplayValue` must say
     **EGP**.
   - **Undo:** switch the methods off (the checkout is cash on delivery again at once).
2. **Feeds:** `bash ~/bf-switch-core.sh feeds` writes both now and prints the two links (credentials: paste them
   into these two screens only). Meta Commerce Manager: the Brand Fashion catalogue's data source = the Meta feed
   link (the ids are the old site's, so the catalogue's items continue). Merchant Center: the website URL
   `https://www.brandfashionegy.com`, the feed = the Google feed link (old items match as `gla_<id>`).
   **Undo:** empty the two tokens in `.env`, `config:cache` (the links 404).
3. **Meta Business → Domain verification** of `brandfashionegy.com`.
4. **Brand Fashion's own Conversions API pair** (D-25 b, Michael 2026-11-12). Two lines in core's `.env` on the
   server, never in the repository, a log or a screen:

   ```
   META_CAPI_BRANDFASHION_TOKEN=<Brand Fashion's Conversions API token>
   META_CAPI_BRANDFASHION_PIXEL_ID=1594687941022756
   ```

   - `bash ~/bf-switch-core.sh capi` writes both. It asks for the token at a prompt that does not echo, so the token
     is never in shell history. It refuses to overwrite a set value, backs up `.env`, runs `php artisan config:cache`,
     then `php artisan meta:capi-check --storefront=brandfashion`, which prints only the token's length and
     fingerprint.
   - Each shop's card orders go ONLY to its own pair. Watchizer's stays `META_CAPI_TOKEN` / `META_CAPI_PIXEL_ID`,
     unchanged. A shop without a complete pair sends nothing.
   - **Undo:** the `cp` it prints + `config:cache` (Brand Fashion's server-side Purchase stops; nothing goes to
     Watchizer's pixel).
   - The browser pixel works from 8.1's build.
5. **Search Console:** submit `https://www.brandfashionegy.com/sitemap.xml`. Later, the change of address for the
   temporary property, once the 301 of 8.7 is live.
6. **GA4 / TikTok:** **CHECK** which ids Brand Fashion has (switch plan §7).

## 10. The checklist — and the undo of each step

| | Done when | Undo |
|---|---|---|
| 1 | Part 4 `DONE`; Watchizer rebuilt | `DEPLOY_PART4` §6 |
| 2b | the 2022 fetch `finished`, failed 0 (or each failed file accepted) — BEFORE 4e | delete `originals/2022/` |
| 3 | a shard count | — |
| 4 | `verify` green; ~7,161 created; images reused | the restore files of b–d; the dump of a |
| 5 | redirects stored; 11 articles published; offers printed | the DELETE; the Blogs / Promotions screens |
| 6 | api resolves with TLS; both mailboxes exist; mail green; a test mail arrives at no-reply@ AND info@ | delete records; old MX/SPF/DKIM back |
| 7 | `htaccess`, `cors` checked; `row-write`'s five checks | the `cp`s printed; `row-rollback` |
| 8 | env set BEFORE the push; the build used it; `bf-switch-verify.sh`: FAIL 0; a COD order and its mails | env back + rebuild; `--undo`; DNS back |
| 9 | the MyFatoorah key created only after 8.5; an EGP payment; `meta:capi-check --storefront=brandfashion` OK; both feeds 200 in Meta and Google; OAuth sign-in on the new domain | methods off; the env backups; tokens empty |
| — | the banners 1364, 1365, 1366 re-uploaded as computer + phone | the old banner switched back |

## 11. Where this runbook differs from the two plans (all found or decided overnight)

- **§5's SQL would not have set `www`** (JSON_SET does not create a missing parent): fixed in `bf-switch-core.sh`.
- **The cache flush** is `StorefrontCache::forgetStorefront` (narrow), not `cache:clear`.
- **The API-host lines are 2 on the live file** (the plan's §1 table said 1).
- **The site address is ONE literal** now (§3 item 1 of the plan, written tonight, value unchanged): switch day is a
  2-line diff (`bf-switch-code.py`).
- **Brand Fashion's Meta feed existed only in prose:** its config entry was added tonight (off until its token).
- **The Conversions API is per shop now** (D-25 b, 2026-11-12): an env pair per shop; the dashboard field is a
  backlog item (`POST_CUTOVER_BACKLOG.md`, 2026-11-12).
- **The 2022 originals come from the live old site** (step 2b): the agency has none; the 259 products import visible.
- **Step 8's order:** the app's environment before the code push (a push-triggered build must find it).
- **MyFatoorah's new key only after 8.5**, and `info@` a real mailbox (O-7 a).
- **The images:** the originals are already on the server (no unzip step); the image pass runs before the import,
  in shards.
- **MyFatoorah is built** (the plan's §7 row said "not built"); the Google feed exists (the plan said "no Google
  feed").

## 12. Still unknown (each a CHECK above)

How the Brand Fashion Node app is bound in hPanel (6.1, 8.4); whether a push rebuilds it (1.4); MyFatoorah's live host
(9a); `STOREFRONT_SERVER_KEY` on the app (8.2); which GA4 /
TikTok ids Brand Fashion has (9b.6); the old TTL at Namecheap (0.1).
