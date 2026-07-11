# WATCHIZER V3 — FINAL QA + DEPLOY-PREP REPORT
**Date:** 2026-07-11
**Build:** Next.js 15.5.20 production (`pnpm build` exit 0, `--max-old-space-size=8192`)
**Products:** 2,150 · **Backend:** Laravel 11 + MySQL
**Method:** curl against the running production build (`:3000`); Laravel `:8000`. Read-only, no code changed.

---

## 1. FINAL QA — 28/28 PASSING ✅

| # | Check | Expected | Actual | Result |
|---|---|---|---|---|
| 1 | `GET /` | 200 | 200 | ✅ |
| 2 | home `wz-pc__title` | 24–48 | **44** | ✅ |
| 3 | home `application/ld+json` | ≥1 | 2 | ✅ |
| 4 | `GET /product/rolex-submariner-date` | 200 | 200 | ✅ |
| 5 | product `og:title` | present | present | ✅ |
| 6 | product `application/ld+json` | ≥2 | **4** | ✅ |
| 7 | `GET /listing` | 200 | 200 | ✅ |
| 8 | listing `BreadcrumbList` | ≥1 | 2 | ✅ |
| 9 | listing `og:image` | present | present (FIX I5) | ✅ |
| 10 | `GET /brand/omega` | 200 | 200 | ✅ |
| 11 | `GET /category/watches` | 200 | 200 | ✅ |
| 12 | `GET /blogs` | 200 | 200 | ✅ |
| 13 | `GET /cart` | 200 | 200 | ✅ |
| 14 | `GET /checkout` | 200 | 200 | ✅ |
| 15 | `GET /login` | 200 | 200 | ✅ |
| 16 | `GET /register` | 200 | 200 | ✅ |
| 17 | `GET /forgot-password` | 200 | 200 | ✅ |
| 18 | `GET /this-does-not-exist` | 404 | 404 | ✅ |
| 19 | `GET /product/fake-slug` | 404 | 404 | ✅ |
| 20 | sitemap `<loc>` count | report | **2,190** | ✅ |
| 21 | sitemap `<loc>` with a space | 0 | **0** | ✅ |
| 22 | robots.txt | show | see below | ✅ |
| 23 | listing `href="/product/` | >0 | 24 | ✅ |
| 24 | home `href="/product/` | >0 | 44 | ✅ |
| 25 | `_next/image` optimization | 200 + avif/webp | **200 + image/avif** (with Accept negotiation) | ✅ |
| 26 | `wz-lang=ar` → `lang="ar"` | present | present (FIX I4) | ✅ |
| 27 | `wz-lang=ar` → `dir="rtl"` | present | present (FIX I4) | ✅ |
| 28 | error.jsx × 3 exist | 3 files | all 3 (FIX I1) | ✅ |

**robots.txt:**
```
User-agent: *
Disallow: /cart
Disallow: /checkout
Disallow: /edit-profile
Disallow: /order-list
Disallow: /wish-list
Disallow: /search
Disallow: /listingsearch
Disallow: /*?*
Allow: /
sitemap: https://watchizereg.com/sitemap.xml
```

**Notes:**
- **Item 25:** a raw `curl -I` (no Accept header) gets `image/jpeg` (correct content-negotiation fallback); with a browser `Accept: image/avif,image/webp` it serves **AVIF** — verified working.
- **Item 26/27:** full tag under `wz-lang=ar` = `<html lang="ar" dir="rtl" …>` — SSR-rendered from the cookie.

---

## 2. BUILD STATS (item 29)

- **Total routes:** 22.
- **Static (○):** **0** · **Dynamic (ƒ):** **22 (all routes)**.
  - The root layout reads the `wz-lang` cookie (FIX I4), which opts the whole app out of static pre-rendering. Every route renders per-request. *Side effect: no static-generation phase, so the SSG-worker OOM that plagued earlier builds no longer occurs.*
- **Build time:** ~130 s · **Build heap:** `--max-old-space-size=8192` (needed locally on this RAM-tight box; 4096 typically suffices on a 3 GB+ server).
- **Shared First Load JS:** **103 kB** · **Largest page:** `/product`, `/offer` **191 kB** · facets 177 kB · home 171 kB.

---

## 3. DEPLOY PREPARATION ✅

**Files created (Frontend-next/):**
- **`ecosystem.config.js`** — PM2 cluster (2 instances, `max_memory_restart 1G`, `NODE_OPTIONS=--max-old-space-size=2048`). Syntactically valid.
- **`deploy.sh`** — pull → `pnpm install --frozen-lockfile` → build (heap 4096) → `pm2 reload`. LF line endings. *(Deploy server must `chmod +x deploy.sh`.)*
- **`DEPLOY.md`** — requirements, env, deploy steps, PM2 commands, Nginx reverse-proxy block, rollback, memory notes.

**`.env.production` status:**
| Key | Value | Status |
|---|---|---|
| `NEXT_PUBLIC_API_BASE` | `https://dash.watchizereg.com/api` | ✅ |
| `LARAVEL_ORIGIN` | `https://dash.watchizereg.com` | ✅ |
| `NEXT_PUBLIC_ASSET_BASE` | `https://dash.watchizereg.com` | ✅ (no localhost leak — FIX I-2) |
| `NEXT_PUBLIC_IMAGE_CDN_BASE` | *(empty)* | ✅ intentional |
| `NEXT_PUBLIC_PAYMOB_ENABLED` | `true` | ✅ added (non-secret flag) |
| `NEXT_PUBLIC_PUBLIC_API_KEY` | **MISSING** | ⚠️ **action item** |

---

## 4. ISSUES / ACTION ITEMS

**Blocking for deploy (1):**
- **`NEXT_PUBLIC_PUBLIC_API_KEY` must be set** in `.env.production` (or the host env) on the deploy server. It's a `NEXT_PUBLIC_` client-exposed public key; it was deliberately NOT copied from `.env.local` (committing a key is a human decision). Without it, API calls fail.

**Non-blocking notes:**
- All routes are dynamic (FIX I4 tradeoff) → run behind **PM2 cluster + Nginx** (both prepared). This also provides the concurrency-bounding + auto-restart that covers the C-1 extreme-load ceiling.
- Build heap: use 4096 on the server; bump to 8192 if it OOMs.
- Backend: PHP `memory_limit ≥ 512M` (see `backend/DEPLOY.md`).

---

## 5. VERDICT

**Final QA: 28/28 routes/checks passing.**
**Deploy prep: PM2 config + deploy script + Nginx + docs — complete.**
**Issues found: 1 (the API key env var — a 1-line human action).**

### DEPLOY READY: **YES** — pending the single action item (`NEXT_PUBLIC_PUBLIC_API_KEY` in the production environment).
