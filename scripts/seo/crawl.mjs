#!/usr/bin/env node
// ── SEO baseline crawler (Phase 1 item 0, 2026-11-13) ──────────────────────────────────────────────
//
// READ-ONLY. GET requests only, one at a time, at most ~2 a second (--gap). It never posts, never logs
// in, never sends a cookie. Re-run it after each phase and compare the report.md files.
//
//   node scripts/seo/crawl.mjs                                   # both shops
//   node scripts/seo/crawl.mjs --site=watchizer
//   node scripts/seo/crawl.mjs --site=brandfashion,https://competitor.example   # a WordPress site: light crawl + blog word counts
//
// Options: --products=60 (product pages analysed per language), --status=150 (product URLs status-checked
// per language, the analysed ones included), --full=150 (a template with at most this many URLs per language
// is crawled whole), --brand-products=2 (extra product pages per brand page, for the warranty table),
// --discover=40 (internal links NOT in the sitemap that are fetched), --render=1 (pages per template and
// language rendered in headless Chrome to compare with the server HTML; 0 = off), --blog=50 (WordPress
// posts per language), --gap=550 (ms between requests), --out=<dir>, --bot-ua (send spoofed crawler user agents
// once per site — off by default: the host's edge 403s a fake Googlebot and briefly blocks the machine).
// --local=watchizer=http://127.0.0.1:3010,brandfashion=http://127.0.0.1:3011 (2026-11-14): crawl a LOCAL copy of a
// shop. Every URL keeps its real address (the sitemaps, canonicals and hreflang name production's host, and the
// report compares them as such); only the request goes to the local server, with the path and query kept.
//
// Output (default: new branding/docs/seo/crawls/<stamp>/ — gitignored, competitor data never enters the
// public repo): pages-<site>.jsonl (one record per fetched page), summary.json, report.md.
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as H from './lib/html.mjs'
import { validate } from './lib/schema.mjs'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'

const arg = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = a.match(/^--([^=]+)(?:=(.*))?$/)
  return m ? [m[1], m[2] ?? '1'] : [a, '1']
}))
const OPT = {
  products: +(arg.products ?? 60),
  status: +(arg.status ?? 150),
  full: +(arg.full ?? 150),
  brandProducts: +(arg['brand-products'] ?? 2),
  discover: +(arg.discover ?? 40),
  render: +(arg.render ?? 1),
  blog: +(arg.blog ?? 50),
  gap: Math.max(450, +(arg.gap ?? 550)),
}
const PROFILES = {
  watchizer: { name: 'watchizer', base: 'https://watchizereg.com', kind: 'shop' },
  brandfashion: { name: 'brandfashion', base: 'https://www.brandfashionegy.com', kind: 'shop' },
}
const sites = (arg.site || 'watchizer,brandfashion').split(',').map((s) => PROFILES[s] || { name: new URL(s).hostname.replace(/^www\./, ''), base: s.replace(/\/$/, ''), kind: 'auto' })
const stamp = new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '_')
const OUT = path.resolve(arg.out || path.join(HERE, '../../new branding/docs/seo/crawls', stamp))
fs.mkdirSync(OUT, { recursive: true })

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
// --local: a shop's real origin (apex and www) -> the local server that answers for it
const LOCAL = new Map()
for (const pair of (arg.local || '').split(',').filter(Boolean)) {
  const at = pair.indexOf('=')
  const name = pair.slice(0, at)
  const origin = pair.slice(at + 1).replace(/\/$/, '')
  const base = PROFILES[name]?.base
  if (!base || !/^https?:\/\//.test(origin)) throw new Error(`--local: unknown site or origin in "${pair}"`)
  const host = new URL(base).host.replace(/^www\./, '')
  for (const h of [host, `www.${host}`]) LOCAL.set(`https://${h}`, origin)
}
const toLocal = (url) => {
  if (!LOCAL.size) return url
  const u = new URL(url)
  const local = LOCAL.get(u.origin)
  return local ? local + u.pathname + u.search : url
}
let last = 0
async function get(url, headers = {}) {
  url = toLocal(url)
  const wait = last + OPT.gap - Date.now()
  if (wait > 0) await sleep(wait)
  last = Date.now()
  const t0 = performance.now()
  try {
    const r = await fetch(url, { headers: { 'user-agent': UA, accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'accept-language': 'en,ar;q=0.8', ...headers }, redirect: 'manual', signal: AbortSignal.timeout(40000) })
    const ttfb = performance.now() - t0
    const body = await r.text()
    return { status: r.status, headers: Object.fromEntries(r.headers), body, ttfb: Math.round(ttfb), total: Math.round(performance.now() - t0) }
  } catch (e) {
    return { status: 0, error: String(e.cause?.code || e.message), headers: {}, body: '', ttfb: 0, total: Math.round(performance.now() - t0) }
  }
}
async function follow(url, headers) {
  const chain = []
  let cur = url
  for (let i = 0; i < 6; i++) {
    const r = await get(cur, headers)
    chain.push({ url: cur, status: r.status })
    if (r.status >= 300 && r.status < 400 && r.headers.location) {
      cur = new URL(r.headers.location, cur).href
      continue
    }
    return { ...r, chain, finalUrl: cur }
  }
  return { status: -1, error: 'redirect loop', chain, finalUrl: cur, headers: {}, body: '' }
}

// One spelling per address: no fragment, percent-escapes in upper case (%d8 and %D8 are the same address).
// The trailing slash is KEPT: a site that uses one 301s the address without it (measured on a WordPress
// site, 2026-11-13: stripping it turned every fetch into a redirect).
function norm(u) {
  try {
    const x = new URL(u)
    x.hash = ''
    return x.href.replace(/%[0-9a-f]{2}/gi, (m) => m.toUpperCase())
  } catch {
    return u
  }
}

// ── templates ──────────────────────────────────────────────────────────────────────────────────────
const TRUST = new Set(['about-us', 'contact-us', 'privacy-policy', 'terms-and-conditions', 'faq', 'shipping', 'returns', 'warranty'])
const PRIVATE = /^\/(ar\/)?(cart|checkout|account|login|register|forgot-password|reset-password|auth|edit-profile|order-list|wish-list|order-confirmation|search|Search|spin)(\/|$|\?)/
function shopTemplate(u) {
  const x = new URL(u)
  const p = x.pathname.split('/').filter(Boolean).map(decodeURIComponent)
  const lang = p[0] === 'ar' ? 'ar' : 'en'
  if (lang === 'ar') p.shift()
  const s = p[0] || ''
  let t = 'other'
  if (!s) t = 'home'
  else if (s === 'product' || s === 'products') t = 'product'
  else if (s === 'brand') t = 'brand'
  else if (s === 'category') t = 'category'
  else if (s === 'subtypes') t = 'subtype'
  else if (s === 'grade') t = 'grade'
  else if (s === 'blog') t = 'article'
  else if (s === 'blogs') t = 'blog-index'
  else if (s === 'listing') t = x.search ? 'listing-filtered' : 'listing'
  else if (TRUST.has(s)) t = 'trust'
  else if (['hot-deals', 'flash-sale', 'offers', 'offer'].includes(s)) t = 'campaign'
  else if (s === 'experience') t = 'experience'
  else if (p.length === 2) t = 'type-brand'
  return { lang, template: t }
}
function wpTemplate(u, sitemapFile = '') {
  const x = new URL(u)
  const lang = /^\/ar(\/|$)/.test(x.pathname) ? 'ar' : 'en'
  const f = sitemapFile.replace(/\d*\.xml$/, '')
  let t = 'other'
  if (/(^|\/)post-sitemap/.test(f)) t = 'article'
  else if (/page-sitemap/.test(f)) t = x.pathname.replace(/^\/ar/, '') === '/' ? 'home' : 'page'
  else if (/product_cat-sitemap/.test(f)) t = 'category'
  else if (/product_tag-sitemap/.test(f)) t = 'tag'
  else if (/(brand|pwb-brand|pa_brand)/.test(f)) t = 'brand'
  else if (/product-sitemap/.test(f)) t = 'product'
  else if (/category-sitemap/.test(f)) t = 'blog-category'
  else if (/video-sitemap/.test(f)) t = 'video'
  else if (f) t = f.split('/').pop()
  if (x.pathname === '/' || x.pathname === '/ar/' || x.pathname === '/ar') t = 'home'
  return { lang, template: t }
}

// ── sitemaps ───────────────────────────────────────────────────────────────────────────────────────
const cdata = (s) => H.decode(s.trim().replace(/^<!\[CDATA\[|\]\]>$/g, '').trim())
async function readSitemaps(roots) {
  const urls = []
  const files = []
  const queue = [...roots]
  const seen = new Set()
  while (queue.length) {
    const sm = queue.shift()
    if (seen.has(sm)) continue
    seen.add(sm)
    const r = await get(sm, { accept: 'application/xml,text/xml,*/*' })
    const b = r.body || ''
    files.push({ url: sm, status: r.status, bytes: b.length, contentType: r.headers['content-type'] || '' })
    if (/<sitemapindex/i.test(b)) {
      for (const m of b.matchAll(/<sitemap>[\s\S]*?<loc>([\s\S]*?)<\/loc>[\s\S]*?<\/sitemap>/gi)) queue.push(cdata(m[1]))
      continue
    }
    const file = new URL(sm).pathname.split('/').pop()
    for (const m of b.matchAll(/<url>([\s\S]*?)<\/url>/gi)) {
      const block = m[1]
      const loc = cdata((block.match(/<loc>([\s\S]*?)<\/loc>/i) || [])[1] || '')
      if (!loc) continue
      urls.push({
        loc,
        file,
        lastmod: cdata((block.match(/<lastmod>([\s\S]*?)<\/lastmod>/i) || [])[1] || '') || null,
        alternates: [...block.matchAll(/<xhtml:link\b[^>]*>/gi)].map((x) => H.attrs(x[0])).map((a) => ({ hreflang: a.hreflang, href: a.href })),
        images: (block.match(/<image:image>/gi) || []).length,
        imageLocs: [...block.matchAll(/<image:loc>([\s\S]*?)<\/image:loc>/gi)].map((x) => cdata(x[1])),
      })
    }
  }
  return { urls, files }
}

// ── page analysis ──────────────────────────────────────────────────────────────────────────────────
const SOCIAL = /(facebook\.com|fb\.com|instagram\.com|tiktok\.com|youtube\.com|youtu\.be|twitter\.com|x\.com|snapchat\.com|pinterest\.|linkedin\.com|threads\.net|t\.me)/i
function analyze(site, url, res, meta) {
  const html = res.body || ''
  const ct = res.headers['content-type'] || ''
  const isHtml = /html/.test(ct)
  const rec = {
    url, template: meta.template, lang: meta.lang, source: meta.source, status: res.status, error: res.error,
    chain: res.chain?.length > 1 ? res.chain : undefined, finalUrl: res.finalUrl !== url ? res.finalUrl : undefined,
    ttfb: res.ttfb, total: res.total, bytes: html.length,
    headers: { cacheControl: res.headers['cache-control'], etag: res.headers.etag, lastModified: res.headers['last-modified'], xRobots: res.headers['x-robots-tag'], contentType: ct, server: res.headers.server, age: res.headers.age, xCache: res.headers['x-nextjs-cache'] || res.headers['x-hcdn-cache-status'] || res.headers['x-litespeed-cache'] || res.headers['cf-cache-status'] },
  }
  if (!isHtml || !html) return rec
  const hd = H.head(html)
  const htmlTag = H.attrs((html.match(/<html\b[^>]*>/i) || ['<html>'])[0])
  rec.htmlLang = htmlTag.lang || ''
  rec.htmlDir = htmlTag.dir || ''
  rec.titles = hd.titles
  rec.title = hd.titles[0] || ''
  const descs = H.metaBy(hd.metas, 'name', 'description')
  rec.description = descs[0] ?? null
  rec.descriptionCount = descs.length
  rec.robots = [...H.metaBy(hd.metas, 'name', 'robots'), ...H.metaBy(hd.metas, 'name', 'googlebot'), ...H.metaBy(hd.metas, 'name', 'bingbot')]
  rec.keywords = H.metaBy(hd.metas, 'name', 'keywords').length > 0
  const canon = hd.links.filter((l) => (l.rel || '').toLowerCase() === 'canonical').map((l) => l.href)
  rec.canonicals = canon
  rec.canonical = canon[0] ? norm(new URL(canon[0], url).href) : null
  rec.hreflang = hd.links.filter((l) => (l.rel || '').toLowerCase() === 'alternate' && l.hreflang).map((l) => ({ hreflang: l.hreflang, href: norm(new URL(l.href, url).href) }))
  const og = (k) => H.metaBy(hd.metas, 'property', `og:${k}`)[0] ?? null
  const ogName = (k) => H.metaBy(hd.metas, 'name', `og:${k}`)[0] ?? null
  rec.og = { title: og('title'), description: og('description'), image: og('image'), type: og('type'), typeAsName: ogName('type'), locale: og('locale'), url: og('url') }
  const tw = (k) => H.metaBy(hd.metas, 'name', `twitter:${k}`)[0] ?? null
  rec.twitter = { card: tw('card'), title: tw('title'), description: tw('description'), image: tw('image') }
  const body = H.stripScripts(html)
  const hs = H.headings(html)
  rec.h1 = hs.filter((h) => h.level === 1).map((h) => h.text)
  rec.headingCounts = [1, 2, 3, 4, 5, 6].map((l) => hs.filter((h) => h.level === l).length)
  let prev = 0
  rec.headingSkips = 0
  for (const h of hs) {
    if (prev && h.level > prev + 1) rec.headingSkips++
    prev = h.level
  }
  rec.landmarks = Object.fromEntries(['header', 'nav', 'main', 'article', 'aside', 'footer'].map((t) => [t, (body.match(new RegExp(`<${t}\\b`, 'gi')) || []).length]))
  rec.tables = (body.match(/<table\b/gi) || []).length
  rec.lists = (body.match(/<(ul|ol)\b/gi) || []).length
  const main = H.region(body, 'main')
  rec.wordsMain = main ? H.words(H.text(main)) : null
  rec.wordsBody = H.words(H.text(H.region(body, 'body') || body))
  // images
  const imgs = H.images(html)
  const contentImgs = main ? H.images(main) : imgs
  const altCount = {}
  for (const i of contentImgs) if (i.alt) altCount[i.alt] = (altCount[i.alt] || 0) + 1
  rec.images = {
    total: imgs.length, main: contentImgs.length,
    alt: contentImgs.filter((i) => i.alt && i.alt.trim()).length,
    emptyAlt: contentImgs.filter((i) => i.alt !== undefined && !i.alt.trim()).length,
    noAlt: contentImgs.filter((i) => i.alt === undefined).length,
    dupAlt: contentImgs.filter((i) => i.alt && altCount[i.alt] > 1).length,
    dims: contentImgs.filter((i) => i.width && i.height).length,
    lazy: contentImgs.filter((i) => i.loading === 'lazy').length,
    firstLoading: contentImgs[0] ? (contentImgs[0].loading || 'eager') : null,
    sampleAlts: [...new Set(contentImgs.map((i) => i.alt).filter(Boolean))].slice(0, 6),
  }
  // links
  const host = new URL(site.base).hostname
  const as = H.anchors(html, url)
  const internal = []
  const facts = { tel: [], whatsapp: [], mailto: [], social: [] }
  let external = 0
  for (const a of as) {
    if (a.href === null) continue
    const h = a.href.trim()
    if (/^tel:/i.test(h)) facts.tel.push(h.slice(4).replace(/\s/g, ''))
    else if (/^mailto:/i.test(h)) facts.mailto.push(h.slice(7).split('?')[0])
    else if (/wa\.me|whatsapp\.com/i.test(h)) facts.whatsapp.push(h)
    else if (a.abs && SOCIAL.test(new URL(a.abs).hostname)) facts.social.push(norm(a.abs))
    if (!a.abs || !/^https?:/.test(a.abs)) continue
    const hh = new URL(a.abs).hostname
    if (hh === host || hh === 'www.' + host || 'www.' + hh === host) internal.push(norm(a.abs))
    else external++
  }
  rec.links = {
    anchors: as.length, noHref: as.filter((a) => a.href === null).length, internal: internal.length, external,
    listingFilter: internal.filter((u) => /\/listing\?/.test(u)).length,
    toArticles: internal.filter((u) => /\/(blog|news)\/[^/]+/.test(new URL(u).pathname)).length,
  }
  rec.internalLinks = [...new Set(internal)]
  rec.facts = Object.fromEntries(Object.entries(facts).map(([k, v]) => [k, [...new Set(v)]]))
  // language switcher: an <a href> to this page's other-language alternate
  const other = rec.hreflang.find((h) => h.hreflang === (meta.lang === 'ar' ? 'en' : 'ar'))
  rec.switcher = other ? (rec.internalLinks.includes(other.href) ? 'link-to-equivalent' : 'no-link-to-equivalent') : 'no-alternate'
  // structured data
  const blocks = H.jsonLd(html)
  rec.jsonld = validate(blocks, { canonical: rec.canonical, ogImage: rec.og.image })
  rec.jsonldRaw = blocks.filter((b) => b.ok).map((b) => b.data)
  // product-page specifics
  const t = H.text(main || body)
  rec.checks = {
    specHeadings: hs.filter((h) => /^(specifications|details|المواصفات|التفاصيل)$/i.test(h.text.trim())).length,
    internalFieldShown: /\b(material|brand|category|color|colour)\s*id\s*:?\s*\d+/i.test(t),
    emptyCountdown: /--\s*:\s*--/.test(t),
    browse1: /\bBrowse 1\b|تصفح 1\b/.test(rec.description || ''),
  }
  const descHead = hs.findIndex((h) => /^(description|الوصف)$/i.test(h.text.trim()))
  rec.checks.descriptionHeading = descHead >= 0
  rec.specs = H.specs(main || body).slice(0, 60)
  if (meta.template === 'product') {
    const prod = rec.jsonldRaw.flatMap((d) => (Array.isArray(d) ? d : [d])).find((d) => d['@type'] === 'Product')
    const price = prod?.offers?.price ?? (Array.isArray(prod?.offers) ? prod.offers[0]?.price : undefined)
    if (price !== undefined && price !== '') {
      const n = Math.round(+price)
      const forms = [String(n), n.toLocaleString('en-US'), n.toLocaleString('ar-EG')]
      rec.checks.priceVisible = forms.some((f) => t.includes(f))
    }
    rec.product = {
      brand: typeof prod?.brand === 'string' ? prod.brand : prod?.brand?.name || null,
      name: prod?.name || null,
      sku: prod?.sku || null,
      model: (rec.specs.find(([k]) => /model number|رقم الموديل|الموديل/i.test(k)) || [])[1] || null,
      warranty: (rec.specs.find(([k]) => /^(warranty|الضمان|ضمان)$/i.test(k.trim())) || [])[1] || null,
      availability: String(prod?.offers?.availability || '').replace(/^https?:\/\/schema.org\//, '') || null,
    }
    rec.descriptionWords = H.words(rec.description || '')
  }
  return rec
}

function indexable(r) {
  if (r.status !== 200) return false
  if ((r.robots || []).some((x) => /noindex/i.test(x)) || /noindex/i.test(r.headers?.xRobots || '')) return false
  if (r.canonical && norm(r.canonical) !== norm(r.url)) return false
  return true
}

// ── crawl one site ─────────────────────────────────────────────────────────────────────────────────
function spread(list, n) {
  if (list.length <= n) return list
  const out = []
  const step = list.length / n
  for (let i = 0; i < n; i++) out.push(list[Math.floor(i * step)])
  return out
}

async function crawlSite(site) {
  const log = (...a) => console.log(`[${site.name}]`, ...a)
  const S = { site: site.name, base: site.base, started: new Date().toISOString() }
  // robots, llms
  const robots = await get(site.base + '/robots.txt', { accept: 'text/plain,*/*' })
  S.robots = { status: robots.status, body: robots.body.slice(0, 5000) }
  const llms = await get(site.base + '/llms.txt', { accept: 'text/plain,*/*' })
  S.llms = { status: llms.status, contentType: llms.headers['content-type'] || '', bytes: llms.body.length }
  const wpProbe = site.kind === 'auto' ? await get(site.base + '/wp-json/', { accept: 'application/json' }) : null
  site.wp = !!(wpProbe && wpProbe.status === 200 && /"namespaces"/.test(wpProbe.body))
  const smRoots = [...robots.body.matchAll(/^sitemap:\s*(\S+)/gim)].map((m) => m[1]).filter((u) => !/\.rss$/i.test(u))
  if (!smRoots.length) smRoots.push(site.base + '/sitemap.xml')
  log('sitemaps from', smRoots.join(' '))
  const sm = await readSitemaps(smRoots)
  S.sitemapFiles = sm.files
  const classify = (u, file) => (site.wp ? wpTemplate(u, file) : shopTemplate(u))
  const bySm = new Map()
  for (const u of sm.urls) {
    const c = classify(u.loc, u.file)
    bySm.set(norm(u.loc), { ...u, ...c })
  }
  const groups = {}
  for (const u of bySm.values()) (groups[`${u.template}|${u.lang}`] ||= []).push(u)
  S.sitemap = Object.fromEntries(Object.entries(groups).map(([k, v]) => [k, {
    urls: v.length,
    lastmod: v.filter((x) => x.lastmod).length,
    distinctLastmodDays: new Set(v.map((x) => (x.lastmod || '').slice(0, 10)).filter(Boolean)).size,
    withAlternates: v.filter((x) => x.alternates.length).length,
    images: v.reduce((a, x) => a + x.images, 0),
    imageHosts: [...new Set(v.flatMap((x) => x.imageLocs.map((i) => { try { return new URL(i).hostname } catch { return '?' } })))],
  }]))
  log('sitemap URLs', bySm.size, JSON.stringify(Object.fromEntries(Object.entries(S.sitemap).map(([k, v]) => [k, v.urls]))))

  // choose pages
  const competitor = site.kind === 'auto'
  const queue = []
  const queued = new Set()
  const push = (u, meta) => {
    const k = norm(u)
    if (queued.has(k)) return
    queued.add(k)
    queue.push({ url: k, ...meta })
  }
  push(site.base + '/', { ...classify(site.base + '/', ''), source: 'home' })
  if (!competitor) push(site.base + '/ar', { lang: 'ar', template: 'home', source: 'home' })
  const statusOnly = []
  for (const [k, list] of Object.entries(groups)) {
    const [template] = k.split('|')
    const cap = competitor ? ({ article: 15, page: 25, product: 15, category: 15, brand: 15 }[template] ?? 8) : template === 'product' ? OPT.products : OPT.full
    const pick = list.length <= (competitor ? cap : OPT.full) ? list : spread(list, cap)
    for (const u of pick) push(u.loc, { lang: u.lang, template: u.template, source: 'sitemap' })
    if (!competitor && template === 'product') {
      const picked = new Set(pick.map((u) => norm(u.loc)))
      for (const u of spread(list, OPT.status)) if (!picked.has(norm(u.loc))) statusOnly.push(u)
    }
  }
  log('pages to analyse', queue.length, 'status-only', statusOnly.length)

  const recs = []
  const file = fs.createWriteStream(path.join(OUT, `pages-${site.name}.jsonl`))
  let brandExtra = 0
  for (let i = 0; i < queue.length; i++) {
    const q = queue[i]
    const res = await follow(q.url)
    const rec = analyze(site, q.url, res, q)
    rec.inSitemap = bySm.has(q.url)
    recs.push(rec)
    file.write(JSON.stringify(rec) + '\n')
    if (i % 25 === 0) log(`${i + 1}/${queue.length}`, rec.status, q.template, q.url.slice(0, 90))
    // extra products per brand page, for the warranty-by-brand table
    if (!competitor && q.template === 'brand' && q.lang === 'en' && rec.status === 200 && OPT.brandProducts > 0) {
      const prods = (rec.internalLinks || []).filter((u) => /\/product\/[^/?]+$/.test(new URL(u).pathname) && !/\/ar\//.test(u))
      let n = 0
      for (const u of prods) {
        if (n >= OPT.brandProducts) break
        if (!queued.has(norm(u))) {
          push(u, { lang: 'en', template: 'product', source: 'brand-page' })
          brandExtra++
        }
        n++
      }
    }
  }
  log('brand-page products added', brandExtra)

  // status-only product URLs
  S.statusOnly = { checked: 0, byStatus: {}, failures: [] }
  for (const u of statusOnly) {
    let r = await get(u.loc)
    // no answer at all (status 0) is the edge throttling us, not a page fault: pause, ask once more
    if (r.status === 0) {
      await sleep(8000)
      r = await get(u.loc)
    }
    S.statusOnly.checked++
    S.statusOnly.byStatus[r.status] = (S.statusOnly.byStatus[r.status] || 0) + 1
    if (r.status !== 200) S.statusOnly.failures.push({ url: u.loc, status: r.status, lang: u.lang })
  }
  if (statusOnly.length) log('status-only', JSON.stringify(S.statusOnly.byStatus))

  // internal links not in the sitemap
  const inbound = new Map()
  const notInSitemap = new Map()
  for (const r of recs) {
    for (const u of r.internalLinks || []) {
      if (u === r.url) continue
      inbound.set(u, (inbound.get(u) || 0) + 1)
      const p = new URL(u).pathname + new URL(u).search
      if (!bySm.has(u) && !PRIVATE.test(p) && !/\/(_next|wp-content|wp-admin|wp-json|feed)\//.test(p) && !/\.(jpg|png|webp|svg|pdf|xml)$/i.test(p)) {
        const c = classify(u, '')
        const key = c.template
        ;(notInSitemap.get(key) || notInSitemap.set(key, new Set()).get(key)).add(u)
      }
    }
  }
  S.notInSitemap = Object.fromEntries([...notInSitemap].map(([k, v]) => [k, { count: v.size, examples: [...v].slice(0, 6) }]))
  const disc = spread([...notInSitemap.values()].flatMap((v) => spread([...v], Math.ceil(OPT.discover / Math.max(1, notInSitemap.size)))), OPT.discover)
  const discovered = []
  for (const u of disc) {
    const res = await follow(u)
    const c = classify(u, '')
    const rec = analyze(site, u, res, { ...c, source: 'discovered' })
    rec.inSitemap = false
    discovered.push(rec)
    file.write(JSON.stringify(rec) + '\n')
  }
  S.discovered = discovered.map((r) => ({ url: r.url, template: r.template, status: r.status, chain: r.chain, indexable: indexable(r), robots: r.robots, canonical: r.canonical }))
  file.end()

  // orphans: sitemap URLs of FULLY crawled templates with no inbound link from any crawled page
  S.orphans = {}
  for (const [k, list] of Object.entries(groups)) {
    const [template] = k.split('|')
    if (template === 'product' || list.length > OPT.full || competitor) continue
    const o = list.filter((u) => !inbound.get(norm(u.loc)))
    S.orphans[k] = { of: list.length, orphan: o.length, examples: o.slice(0, 8).map((u) => u.loc) }
  }
  const prodSample = recs.filter((r) => r.template === 'product' && r.source === 'sitemap')
  S.productInbound = { sampled: prodSample.length, linkedFromCrawledPages: prodSample.filter((r) => inbound.get(r.url)).length }

  // conditional requests (304?)
  S.conditional = []
  const byT = {}
  for (const r of recs) if (r.status === 200 && (r.headers.etag || r.headers.lastModified) && (byT[r.template] = (byT[r.template] || 0) + 1) <= 2) {
    const h = {}
    if (r.headers.etag) h['if-none-match'] = r.headers.etag
    if (r.headers.lastModified) h['if-modified-since'] = r.headers.lastModified
    const c = await get(r.url, h)
    S.conditional.push({ url: r.url, template: r.template, status: c.status })
  }
  // crawler user agents (spoofed UA: shows UA-based blocking on OUR side only, not IP-based bot protection)
  S.botUA = []
  const BOTS = {
    Googlebot: 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Googlebot-Image': 'Googlebot-Image/1.0',
    Bingbot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/142.0.0.0 Safari/537.36',
    'OAI-SearchBot': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
    'ChatGPT-User': 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
    PerplexityBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
    Applebot: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)',
  }
  // OPT-IN (--bot-ua): measured 2026-11-13, the host's edge answers a SPOOFED Googlebot/Bingbot/Applebot with 403
  // (it verifies those by IP) and then blocked this machine for ~2 minutes. The answer says nothing about the
  // real crawlers — Search Console's URL inspection does.
  const botTargets = arg['bot-ua'] ? [site.base + '/'] : []
  const imgTarget = arg['bot-ua'] ? recs.find((r) => r.template === 'product' && r.og?.image)?.og.image : null
  for (const [bot, ua] of Object.entries(BOTS)) {
    for (const u of bot === 'Googlebot-Image' ? [imgTarget].filter(Boolean) : botTargets) {
      const r = await get(u, { 'user-agent': ua })
      S.botUA.push({ bot, url: u, status: r.status, xRobots: r.headers['x-robots-tag'] || null, bytes: r.body.length, contentType: r.headers['content-type'] || '' })
    }
  }
  // image host checks (og:image of product pages)
  S.images = []
  const imgs = [...new Set(recs.filter((r) => r.template === 'product' && r.og?.image).map((r) => r.og.image))].slice(0, 3)
  for (const u of imgs) {
    const r = await fetch(u, { headers: { 'user-agent': UA } }).catch(() => null)
    if (!r) continue
    const b = new Uint8Array(await r.arrayBuffer())
    S.images.push({ url: u, status: r.status, contentType: r.headers.get('content-type'), bytes: b.length, dims: imgDims(b), cacheControl: r.headers.get('cache-control'), xRobots: r.headers.get('x-robots-tag') })
    await sleep(OPT.gap)
  }
  const imgHosts = [...new Set(imgs.map((u) => new URL(u).origin))]
  S.imageHostRobots = []
  for (const o of imgHosts) {
    const r = await get(o + '/robots.txt', { accept: 'text/plain' })
    S.imageHostRobots.push({ origin: o, status: r.status, body: r.body.slice(0, 1500) })
  }
  // WordPress: the blog's last N posts, EN and AR, main content only (the REST API's content.rendered)
  if (site.wp) S.blog = await wpBlog(site)
  S.finished = new Date().toISOString()
  return { S, recs: [...recs, ...discovered], bySm }
}

function imgDims(b) {
  const s = String.fromCharCode(...b.slice(12, 16))
  if (String.fromCharCode(...b.slice(0, 4)) === 'RIFF') {
    if (s === 'VP8X') return `${1 + (b[24] | (b[25] << 8) | (b[26] << 16))}x${1 + (b[27] | (b[28] << 8) | (b[29] << 16))}`
    if (s === 'VP8 ') return `${(b[26] | (b[27] << 8)) & 0x3fff}x${(b[28] | (b[29] << 8)) & 0x3fff}`
    if (s === 'VP8L') {
      const v = b[21] | (b[22] << 8) | (b[23] << 16) | (b[24] << 24)
      return `${(v & 0x3fff) + 1}x${((v >> 14) & 0x3fff) + 1}`
    }
  }
  if (b[0] === 0x89 && b[1] === 0x50) return `${(b[16] << 24) | (b[17] << 16) | (b[18] << 8) | b[19]}x${(b[20] << 24) | (b[21] << 16) | (b[22] << 8) | b[23]}`
  if (b[0] === 0xff && b[1] === 0xd8) {
    let i = 2
    while (i < b.length) {
      if (b[i] !== 0xff) break
      const m = b[i + 1]
      const len = (b[i + 2] << 8) | b[i + 3]
      if (m >= 0xc0 && m <= 0xc3) return `${(b[i + 7] << 8) | b[i + 8]}x${(b[i + 5] << 8) | b[i + 6]}`
      i += 2 + len
    }
  }
  return '?'
}

async function wpBlog(site) {
  const out = {}
  for (const lang of ['en', 'ar']) {
    const prefix = lang === 'ar' ? '/ar' : ''
    const r = await get(`${site.base}${prefix}/wp-json/wp/v2/posts?per_page=${Math.min(100, OPT.blog)}&orderby=date&order=desc&_fields=id,date,modified,link,title,content,author`, { accept: 'application/json' })
    let posts = []
    try {
      posts = JSON.parse(r.body)
    } catch {}
    if (!Array.isArray(posts)) posts = []
    posts = posts.filter((p) => (lang === 'ar') === /\/ar\//.test(p.link))
    const rows = posts.map((p) => {
      const html = p.content?.rendered || ''
      const noTables = html.replace(/<table\b[\s\S]*?<\/table>/gi, ' ').replace(/<figure\b[\s\S]*?<\/figure>/gi, ' ')
      const prose = noTables.replace(/<(ul|ol)\b[\s\S]*?<\/\1>/gi, ' ')
      const hs = H.headings(html)
      const links = H.anchors(html, p.link)
      return {
        link: p.link, date: p.date, modified: p.modified, title: H.decode(p.title?.rendered || ''),
        words: H.words(H.text(html)), wordsNoTables: H.words(H.text(noTables)), wordsProse: H.words(H.text(prose)),
        h2: hs.filter((h) => h.level === 2).length, h3: hs.filter((h) => h.level === 3).length,
        tables: (html.match(/<table\b/gi) || []).length, images: (html.match(/<img\b/gi) || []).length,
        faq: /faq|الأسئلة الشائعة|أسئلة شائعة/i.test(html),
        linksInternal: links.filter((a) => a.abs && new URL(a.abs).hostname.endsWith(new URL(site.base).hostname.replace(/^www\./, ''))).length,
        linksToProducts: links.filter((a) => a.abs && /\/product\//.test(a.abs)).length,
        linksExternal: links.filter((a) => a.abs && /^https?:/.test(a.abs) && !new URL(a.abs).hostname.endsWith(new URL(site.base).hostname.replace(/^www\./, ''))).length,
      }
    })
    const stat = (k) => {
      const v = rows.map((x) => x[k]).sort((a, b) => a - b)
      if (!v.length) return null
      return { avg: Math.round(v.reduce((a, b) => a + b, 0) / v.length), median: v.length % 2 ? v[(v.length - 1) / 2] : Math.round((v[v.length / 2 - 1] + v[v.length / 2]) / 2), min: v[0], max: v[v.length - 1] }
    }
    out[lang] = { status: r.status, posts: rows.length, newest: rows[0]?.date, oldest: rows.at(-1)?.date, words: stat('words'), wordsNoTables: stat('wordsNoTables'), wordsProse: stat('wordsProse'), h2: stat('h2'), tables: stat('tables'), images: stat('images'), linksToProducts: stat('linksToProducts'), linksExternal: stat('linksExternal'), withFaq: rows.filter((x) => x.faq).length, rows }
  }
  return out
}

// ── server HTML vs rendered ────────────────────────────────────────────────────────────────────────
async function renderCompare(site, recs) {
  if (!OPT.render) return null
  const { openBrowser } = await import('./lib/render.mjs')
  let b
  try {
    b = await openBrowser()
  } catch (e) {
    return { error: e.message }
  }
  const out = []
  const want = ['home', 'product', 'brand', 'category', 'subtype', 'listing', 'article', 'blog-index', 'trust', 'page']
  const pick = []
  for (const t of want) for (const lang of ['en', 'ar']) pick.push(...recs.filter((r) => r.template === t && r.lang === lang && r.status === 200).slice(0, OPT.render))
  for (const r of pick) {
    const { html, ms } = await b.render(toLocal(r.url))
    const rr = analyze(site, r.url, { status: 200, body: html, headers: { 'content-type': 'text/html' } }, r)
    const cmp = (a, c) => ({ server: a, rendered: c, same: JSON.stringify(a) === JSON.stringify(c) })
    out.push({
      url: r.url, template: r.template, lang: r.lang, ms,
      title: cmp(r.title, rr.title), description: cmp(r.description, rr.description), canonical: cmp(r.canonical, rr.canonical),
      robots: cmp(r.robots, rr.robots), h1: cmp(r.h1, rr.h1), jsonldTypes: cmp(r.jsonld?.map((j) => j.type), rr.jsonld?.map((j) => j.type)),
      internalLinks: cmp(r.links?.internal, rr.links?.internal), wordsMain: cmp(r.wordsMain, rr.wordsMain), images: cmp(r.images?.main, rr.images?.main),
      switcher: cmp(r.switcher, rr.switcher),
    })
    console.log(`[${site.name}] rendered`, r.template, r.lang, ms + 'ms')
  }
  await b.close()
  return out
}

// ── summary ────────────────────────────────────────────────────────────────────────────────────────
function q(v, p) {
  const s = v.filter((x) => typeof x === 'number').sort((a, b) => a - b)
  if (!s.length) return null
  return s[Math.min(s.length - 1, Math.floor(p * s.length))]
}
function summarize(S, recs, bySm) {
  const T = {}
  for (const r of recs) {
    const k = `${r.template}|${r.lang}`
    const t = (T[k] ||= { fetched: 0, status: {}, indexable: 0, noindex: 0, canonOther: 0, canonMissing: 0, titleMissing: 0, titleLen: [], descMissing: 0, descLen: [], descMulti: 0, h1: { zero: 0, one: 0, many: 0 }, hreflangOk: 0, xdefault: 0, switcher: {}, ttfb: [], jsonld: {}, errors: {}, warnings: {}, images: { main: 0, alt: 0, emptyAlt: 0, noAlt: 0, dupAlt: 0, dims: 0 }, keywords: 0, ogTypeAsName: 0, ogLocale: {}, htmlLang: {}, landmarksMissing: {}, wordsMain: [], cache: {}, etag: 0, lastModified: 0, robotsBad: 0, maxImageLarge: 0, listingLinks: 0, articleLinks: 0, checks: {}, redirected: 0 })
    t.fetched++
    t.status[r.status] = (t.status[r.status] || 0) + 1
    if (r.chain) t.redirected++
    t.ttfb.push(r.ttfb)
    if (r.status !== 200 || !r.title === undefined) continue
    if (indexable(r)) t.indexable++
    if ((r.robots || []).some((x) => /noindex/i.test(x))) t.noindex++
    if (!r.canonical) t.canonMissing++
    else if (norm(r.canonical) !== norm(r.url)) t.canonOther++
    if (!r.title) t.titleMissing++
    else t.titleLen.push([...r.title].length)
    if (!r.description) t.descMissing++
    else t.descLen.push([...r.description].length)
    if (r.descriptionCount > 1) t.descMulti++
    const h1 = r.h1?.length || 0
    t.h1[h1 === 0 ? 'zero' : h1 === 1 ? 'one' : 'many']++
    const hl = new Set((r.hreflang || []).map((h) => h.hreflang))
    if (hl.has('en') && hl.has('ar')) t.hreflangOk++
    if (hl.has('x-default')) t.xdefault++
    t.switcher[r.switcher] = (t.switcher[r.switcher] || 0) + 1
    for (const j of r.jsonld || []) {
      t.jsonld[j.type] = (t.jsonld[j.type] || 0) + 1
      for (const e of j.errors) t.errors[`${j.type}: ${e.replace(/\(.*\)/, '').trim()}`] = (t.errors[`${j.type}: ${e.replace(/\(.*\)/, '').trim()}`] || 0) + 1
      for (const w of j.warnings) t.warnings[`${j.type}: ${w}`] = (t.warnings[`${j.type}: ${w}`] || 0) + 1
    }
    for (const k2 of Object.keys(t.images)) t.images[k2] += r.images?.[k2] || 0
    if (r.keywords) t.keywords++
    if (r.og?.typeAsName && !r.og?.type) t.ogTypeAsName++
    t.ogLocale[r.og?.locale || '(none)'] = (t.ogLocale[r.og?.locale || '(none)'] || 0) + 1
    t.htmlLang[`${r.htmlLang}/${r.htmlDir}`] = (t.htmlLang[`${r.htmlLang}/${r.htmlDir}`] || 0) + 1
    for (const l of ['header', 'nav', 'main', 'footer']) if (!r.landmarks?.[l]) t.landmarksMissing[l] = (t.landmarksMissing[l] || 0) + 1
    if (r.wordsMain !== null) t.wordsMain.push(r.wordsMain)
    const cc = r.headers.cacheControl || '(none)'
    t.cache[cc] = (t.cache[cc] || 0) + 1
    if (r.headers.etag) t.etag++
    if (r.headers.lastModified) t.lastModified++
    if ((r.robots || []).some((x) => /noarchive|nocache|nosnippet|max-snippet:\s*(0|[1-9]\d?)\b/i.test(x)) || /noarchive|nosnippet/i.test(r.headers.xRobots || '')) t.robotsBad++
    if ((r.robots || []).some((x) => /max-image-preview:large/i.test(x))) t.maxImageLarge++
    if (r.links?.listingFilter) t.listingLinks++
    if (r.links?.toArticles) t.articleLinks++
    for (const [c, v] of Object.entries(r.checks || {})) if (v === true) t.checks[c] = (t.checks[c] || 0) + 1
    if (r.checks?.specHeadings > 1) t.checks.specBlocksTwice = (t.checks.specBlocksTwice || 0) + 1
    if (r.checks?.priceVisible === false) t.checks.priceNotVisible = (t.checks.priceNotVisible || 0) + 1
  }
  for (const t of Object.values(T)) {
    t.ttfb = { median: q(t.ttfb, 0.5), p90: q(t.ttfb, 0.9) }
    t.titleLen = { median: q(t.titleLen, 0.5), max: q(t.titleLen, 1), over60: t.titleLen.filter((x) => x > 60).length }
    t.descLen = { median: q(t.descLen, 0.5), max: q(t.descLen, 1), over155: t.descLen.filter((x) => x > 155).length }
    t.wordsMain = { median: q(t.wordsMain, 0.5), min: q(t.wordsMain, 0), max: q(t.wordsMain, 1) }
  }
  // duplicates (200 pages, per language)
  const dup = (field) => {
    const m = {}
    for (const r of recs) if (r.status === 200 && r[field]) (m[`${r.lang}|${r[field]}`] ||= []).push(r.url)
    return Object.entries(m).filter(([, v]) => v.length > 1).map(([k, v]) => ({ value: k.split('|').slice(1).join('|'), lang: k.split('|')[0], count: v.length, urls: v.slice(0, 4) })).sort((a, b) => b.count - a.count)
  }
  const dupTitles = dup('title')
  const dupDescs = dup('description')
  // hreflang reciprocity
  const byUrl = new Map(recs.map((r) => [r.url, r]))
  let pairs = 0
  let reciprocal = 0
  const brokenPairs = []
  for (const r of recs) {
    if (r.status !== 200 || r.lang !== 'en') continue
    const ar = (r.hreflang || []).find((h) => h.hreflang === 'ar')
    if (!ar || !byUrl.has(ar.href)) continue
    pairs++
    const back = (byUrl.get(ar.href).hreflang || []).find((h) => h.hreflang === 'en')
    if (back && back.href === r.url && byUrl.get(ar.href).status === 200) reciprocal++
    else brokenPairs.push({ en: r.url, ar: ar.href, arStatus: byUrl.get(ar.href).status, back: back?.href })
  }
  // redirect chains
  const chains = recs.filter((r) => r.chain).map((r) => ({ url: r.url, chain: r.chain.map((c) => `${c.status} ${c.url}`) }))
  // facts
  const facts = { tel: {}, whatsapp: {}, mailto: {}, social: {}, jsonldTel: {}, jsonldAddress: {}, jsonldSameAs: {} }
  for (const r of recs) {
    for (const k of ['tel', 'whatsapp', 'mailto', 'social']) for (const v of r.facts?.[k] || []) facts[k][v] = (facts[k][v] || 0) + 1
    const walk = (o) => {
      if (!o || typeof o !== 'object') return
      if (Array.isArray(o)) return o.forEach(walk)
      if (o.telephone) facts.jsonldTel[o.telephone] = (facts.jsonldTel[o.telephone] || 0) + 1
      if (o['@type'] === 'PostalAddress') {
        const a = [o.streetAddress, o.addressLocality, o.addressRegion, o.postalCode, o.addressCountry].filter(Boolean).join(', ')
        facts.jsonldAddress[a] = (facts.jsonldAddress[a] || 0) + 1
      }
      if (o.sameAs) for (const s of [].concat(o.sameAs)) facts.jsonldSameAs[s] = (facts.jsonldSameAs[s] || 0) + 1
      Object.values(o).forEach(walk)
    }
    walk(r.jsonldRaw)
  }
  // warranty per brand (product pages)
  const warranty = {}
  for (const r of recs) {
    if (r.template !== 'product' || r.lang !== 'en' || r.status !== 200 || !r.product?.brand) continue
    const w = (warranty[r.product.brand] ||= { products: 0, values: {} })
    w.products++
    const v = r.product.warranty || '(none shown)'
    w.values[v] = (w.values[v] || 0) + 1
  }
  // product title anatomy
  const prods = recs.filter((r) => r.template === 'product' && r.status === 200 && r.product)
  const titleAnatomy = {
    sampled: prods.length,
    titleHasModel: prods.filter((r) => r.product.model && r.title.replace(/\s/g, '').toLowerCase().includes(r.product.model.replace(/\s/g, '').toLowerCase())).length,
    withModelSpec: prods.filter((r) => r.product.model).length,
    titleTruncatedVsH1: prods.filter((r) => r.h1?.[0] && !r.title.includes(r.h1[0])).length,
    descriptionWordsMedian: q(prods.map((r) => r.descriptionWords), 0.5),
    availability: prods.reduce((a, r) => ((a[r.product.availability || '?'] = (a[r.product.availability || '?'] || 0) + 1), a), {}),
    skuLooksInternalId: prods.filter((r) => r.product.sku && /^\d{3,6}$/.test(String(r.product.sku))).length,
  }
  return { templates: T, dupTitles: dupTitles.slice(0, 40), dupTitleCount: dupTitles.length, dupDescs: dupDescs.slice(0, 40), dupDescCount: dupDescs.length, hreflang: { pairs, reciprocal, brokenPairs: brokenPairs.slice(0, 20) }, chains, facts, warranty, titleAnatomy }
}

// ── report ─────────────────────────────────────────────────────────────────────────────────────────
function md(all) {
  const L = []
  const p = (s = '') => L.push(s)
  p(`# SEO baseline crawl — ${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC`)
  p()
  p(`Command: \`node scripts/seo/crawl.mjs ${process.argv.slice(2).join(' ')}\` — read-only GETs, ${OPT.gap} ms apart.`)
  p()
  for (const { S, X, render } of all) {
    p(`## ${S.site} (${S.base})`)
    p()
    p(`robots.txt ${S.robots.status}; llms.txt ${S.llms.status}${S.llms.status === 200 ? ` (${S.llms.contentType}, ${S.llms.bytes} B)` : ''}; sitemap files ${S.sitemapFiles.length} (${S.sitemapFiles.filter((f) => f.status !== 200).length} not 200).`)
    p()
    p('### Sitemap URLs per template')
    p()
    p('| template | lang | URLs | with lastmod | distinct lastmod days | with alternates | image:image | image hosts |')
    p('|---|---|---|---|---|---|---|---|')
    for (const [k, v] of Object.entries(S.sitemap).sort()) p(`| ${k.split('|')[0]} | ${k.split('|')[1]} | ${v.urls} | ${v.lastmod} | ${v.distinctLastmodDays} | ${v.withAlternates} | ${v.images} | ${v.imageHosts.join(' ')} |`)
    p()
    p('### Crawled pages per template')
    p()
    p('| template | lang | fetched | status | indexable | noindex | canonical ≠ self | no canonical | redirected | TTFB median / p90 ms | Cache-Control | ETag | Last-Modified |')
    p('|---|---|---|---|---|---|---|---|---|---|---|---|---|')
    for (const [k, t] of Object.entries(X.templates).sort()) p(`| ${k.split('|')[0]} | ${k.split('|')[1]} | ${t.fetched} | ${Object.entries(t.status).map(([s, n]) => `${s}×${n}`).join(' ')} | ${t.indexable} | ${t.noindex} | ${t.canonOther} | ${t.canonMissing} | ${t.redirected} | ${t.ttfb.median} / ${t.ttfb.p90} | ${Object.keys(t.cache).join('; ').slice(0, 80)} | ${t.etag} | ${t.lastModified} |`)
    p()
    p('### Titles, descriptions, H1, language')
    p()
    p('| template | lang | title missing | title chars median/max (>60) | desc missing | desc chars median/max (>155) | 2+ descriptions | H1 0/1/2+ | hreflang en+ar / x-default | switcher | html lang/dir | og:locale | og:type as name= | meta keywords | words in main (median) |')
    p('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|')
    for (const [k, t] of Object.entries(X.templates).sort()) p(`| ${k.split('|')[0]} | ${k.split('|')[1]} | ${t.titleMissing} | ${t.titleLen.median}/${t.titleLen.max} (${t.titleLen.over60}) | ${t.descMissing} | ${t.descLen.median}/${t.descLen.max} (${t.descLen.over155}) | ${t.descMulti} | ${t.h1.zero}/${t.h1.one}/${t.h1.many} | ${t.hreflangOk} / ${t.xdefault} | ${Object.entries(t.switcher).map(([s, n]) => `${s}×${n}`).join(' ')} | ${Object.entries(t.htmlLang).map(([s, n]) => `${s}×${n}`).join(' ')} | ${Object.entries(t.ogLocale).map(([s, n]) => `${s}×${n}`).join(' ')} | ${t.ogTypeAsName} | ${t.keywords} | ${t.wordsMain.median} |`)
    p()
    p('### Robots meta, images, links, page checks')
    p()
    p('| template | lang | noarchive/nosnippet/low max-snippet | max-image-preview:large | content images | alt | empty alt | no alt | duplicate alt | width+height | pages linking /listing? | pages linking articles | checks |')
    p('|---|---|---|---|---|---|---|---|---|---|---|---|---|')
    for (const [k, t] of Object.entries(X.templates).sort()) p(`| ${k.split('|')[0]} | ${k.split('|')[1]} | ${t.robotsBad} | ${t.maxImageLarge} | ${t.images.main} | ${t.images.alt} | ${t.images.emptyAlt} | ${t.images.noAlt} | ${t.images.dupAlt} | ${t.images.dims} | ${t.listingLinks} | ${t.articleLinks} | ${Object.entries(t.checks).map(([s, n]) => `${s}×${n}`).join(' ')} |`)
    p()
    p('### Structured data (types found; errors and warnings by rule, counted per page)')
    p()
    for (const [k, t] of Object.entries(X.templates).sort()) {
      if (!Object.keys(t.jsonld).length && !t.fetched) continue
      p(`- **${k}** types: ${Object.entries(t.jsonld).map(([s, n]) => `${s}×${n}`).join(', ') || '(none)'}`)
      for (const [e, n] of Object.entries(t.errors)) p(`  - ERROR ${e} ×${n}`)
      for (const [e, n] of Object.entries(t.warnings)) p(`  - warn ${e} ×${n}`)
    }
    p()
    if (S.statusOnly?.checked) {
      p(`### Product URLs from the sitemap, status check (${S.statusOnly.checked} more, spread evenly)`)
      p()
      p(`${Object.entries(S.statusOnly.byStatus).map(([s, n]) => `${s}: ${n}`).join(', ')}. First failures: ${S.statusOnly.failures.slice(0, 5).map((f) => f.url).join(' , ')}`)
      p()
    }
    p(`### hreflang reciprocity: ${X.hreflang.reciprocal} of ${X.hreflang.pairs} crawled en↔ar pairs reciprocal and 200`)
    for (const b of X.hreflang.brokenPairs.slice(0, 8)) p(`- ${b.en} → ${b.ar} (${b.arStatus}), back: ${b.back}`)
    p()
    p(`### Duplicates among crawled 200 pages: ${X.dupTitleCount} titles, ${X.dupDescCount} descriptions shared by 2+ pages`)
    for (const d of X.dupTitles.slice(0, 8)) p(`- title ×${d.count} (${d.lang}): “${d.value.slice(0, 100)}”`)
    for (const d of X.dupDescs.slice(0, 8)) p(`- description ×${d.count} (${d.lang}): “${d.value.slice(0, 100)}”`)
    p()
    p(`### Redirect chains: ${X.chains.length}`)
    for (const c of X.chains.slice(0, 10)) p(`- ${c.chain.join(' → ')}`)
    p()
    p('### Internal links not in the sitemap (by template)')
    for (const [k, v] of Object.entries(S.notInSitemap)) p(`- ${k}: ${v.count} (e.g. ${v.examples.slice(0, 3).join(' , ')})`)
    p()
    p(`Fetched ${S.discovered.length} of them: ${Object.entries(S.discovered.reduce((a, d) => ((a[`${d.template} ${d.status}${d.indexable ? ' indexable' : ''}`] = (a[`${d.template} ${d.status}${d.indexable ? ' indexable' : ''}`] || 0) + 1), a), {})).map(([s, n]) => `${s} ×${n}`).join(', ')}`)
    p()
    if (Object.keys(S.orphans).length) {
      p('### Orphans (sitemap URLs of fully crawled templates with no link from any crawled server HTML)')
      for (const [k, v] of Object.entries(S.orphans)) p(`- ${k}: ${v.orphan} of ${v.of}${v.orphan ? ` (e.g. ${v.examples.slice(0, 3).join(' , ')})` : ''}`)
      p(`- products: ${S.productInbound.linkedFromCrawledPages} of ${S.productInbound.sampled} sampled product pages are linked from some crawled page`)
      p()
    }
    p('### Conditional requests (If-None-Match / If-Modified-Since)')
    p(S.conditional.length ? S.conditional.map((c) => `${c.template} → ${c.status}`).join(', ') : 'No crawled page sent an ETag or Last-Modified, so none can answer 304.')
    p()
    p('### Crawler user agents (spoofed UA — shows UA rules on our side, not the host\'s IP-based bot protection)')
    p(S.botUA.length ? S.botUA.map((b) => `${b.bot} ${new URL(b.url).pathname.slice(0, 40)} → ${b.status}${b.xRobots ? ` X-Robots-Tag: ${b.xRobots}` : ''}`).join('; ') : 'Not run (opt-in: --bot-ua).')
    p()
    p('### Product images (og:image)')
    for (const i of S.images) p(`- ${i.url} → ${i.status} ${i.contentType} ${i.bytes} B ${i.dims} cache: ${i.cacheControl} x-robots: ${i.xRobots || '-'}`)
    for (const r of S.imageHostRobots) p(`- ${r.origin}/robots.txt ${r.status}: ${r.body.replace(/^#.*$/gm, '').replace(/\s+/g, ' ').trim().slice(0, 200)}`)
    p()
    if (X.titleAnatomy.sampled) {
      const a = X.titleAnatomy
      p(`### Product pages (${a.sampled} analysed)`)
      p(`- title contains the model number: ${a.titleHasModel} of ${a.withModelSpec} that show a model number; title cut shorter than the H1: ${a.titleTruncatedVsH1}`)
      p(`- meta description words, median: ${a.descriptionWordsMedian}; JSON-LD sku looks like an internal id: ${a.skuLooksInternalId}`)
      p(`- availability: ${Object.entries(a.availability).map(([s, n]) => `${s} ×${n}`).join(', ')}`)
      p()
    }
    if (Object.keys(X.warranty).length) {
      p('### Warranty shown on product pages, by brand')
      p()
      p('| brand | product pages | warranty values shown |')
      p('|---|---|---|')
      for (const [b, w] of Object.entries(X.warranty).sort()) p(`| ${b} | ${w.products} | ${Object.entries(w.values).map(([v, n]) => `${v} ×${n}`).join('; ')} |`)
      p()
    }
    p('### Contact facts found in the server HTML (value × pages)')
    for (const k of ['tel', 'whatsapp', 'mailto', 'social', 'jsonldTel', 'jsonldAddress', 'jsonldSameAs']) {
      const e = Object.entries(X.facts[k]).sort((a, b) => b[1] - a[1])
      if (e.length) p(`- ${k}: ${e.slice(0, 12).map(([v, n]) => `${v} ×${n}`).join(' · ')}`)
    }
    p()
    if (render) {
      p('### Server HTML vs rendered (headless Chrome)')
      if (render.error) p(`Not run: ${render.error}`)
      else {
        p()
        p('| template | lang | title | description | canonical | robots | H1 | JSON-LD | internal links (server→rendered) | main words (server→rendered) | images | switcher |')
        p('|---|---|---|---|---|---|---|---|---|---|---|---|')
        const s = (x) => (x.same ? 'same' : 'DIFF')
        for (const r of render) p(`| ${r.template} | ${r.lang} | ${s(r.title)} | ${s(r.description)} | ${s(r.canonical)} | ${s(r.robots)} | ${s(r.h1)} | ${s(r.jsonldTypes)} | ${r.internalLinks.server}→${r.internalLinks.rendered} | ${r.wordsMain.server}→${r.wordsMain.rendered} | ${r.images.server}→${r.images.rendered} | ${r.switcher.server}→${r.switcher.rendered} |`)
      }
      p()
    }
    if (S.blog) {
      p('### Blog — newest posts, main content (REST content.rendered)')
      p()
      p('| lang | posts | newest → oldest | words avg / median | without tables avg / median | prose only (no tables, lists) avg / median | H2 median | tables median | images median | links to products median | external links median | with FAQ |')
      p('|---|---|---|---|---|---|---|---|---|---|---|---|')
      for (const [lang, b] of Object.entries(S.blog)) if (b.posts) p(`| ${lang} | ${b.posts} | ${b.newest?.slice(0, 10)} → ${b.oldest?.slice(0, 10)} | ${b.words.avg} / ${b.words.median} | ${b.wordsNoTables.avg} / ${b.wordsNoTables.median} | ${b.wordsProse.avg} / ${b.wordsProse.median} | ${b.h2.median} | ${b.tables.median} | ${b.images.median} | ${b.linksToProducts.median} | ${b.linksExternal.median} | ${b.withFaq} |`)
      p()
    }
  }
  return L.join('\n')
}

// ── run ────────────────────────────────────────────────────────────────────────────────────────────
const all = []
for (const site of sites) {
  const { S, recs, bySm } = await crawlSite(site)
  const X = summarize(S, recs, bySm)
  const render = site.kind === 'shop' ? await renderCompare(site, recs.filter((r) => r.source !== 'discovered')) : null
  all.push({ S, X, render })
  fs.writeFileSync(path.join(OUT, 'summary.json'), JSON.stringify(all, null, 1))
  fs.writeFileSync(path.join(OUT, 'report.md'), md(all))
}
console.log('report:', path.join(OUT, 'report.md'))
