// HTML reading for the SEO crawler — regex based on purpose (no dependency). It reads what a
// crawler sees in the SERVER HTML: head tags, headings, landmarks, links, images, JSON-LD, text.

const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', '#39': "'", '#x27': "'" }

export function decode(s) {
  if (!s) return s
  return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+\d*);/gi, (m, e) => {
    const k = e.toLowerCase()
    if (k in ENT) return ENT[k]
    if (k.startsWith('#x')) return String.fromCodePoint(parseInt(k.slice(2), 16))
    if (k.startsWith('#')) return String.fromCodePoint(parseInt(k.slice(1), 10))
    return m
  })
}

export function attrs(tag) {
  const out = {}
  const re = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g
  const inner = tag.replace(/^<\s*[a-zA-Z0-9]+/, '').replace(/\/?>$/, '')
  let m
  while ((m = re.exec(inner))) out[m[1].toLowerCase()] = decode(m[3] ?? m[4] ?? m[5] ?? '')
  return out
}

export function stripScripts(html) {
  return html
    .replace(/<script\b[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style\b[\s\S]*?<\/style>/gi, ' ')
    .replace(/<noscript\b[\s\S]*?<\/noscript>/gi, ' ')
    .replace(/<template\b[\s\S]*?<\/template>/gi, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
}

export function text(html) {
  return decode(stripScripts(html).replace(/<br\s*\/?>/gi, ' ').replace(/<\/(p|div|li|h[1-6]|td|th|dt|dd|section|tr)>/gi, ' ').replace(/<[^>]+>/g, ' '))
    .replace(/\s+/g, ' ')
    .trim()
}

/** Words: tokens holding at least one letter or digit (Arabic included). */
export function words(s) {
  if (!s) return 0
  return s.split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w)).length
}

/** The outermost <tag>…</tag> region (first open, matching close by depth). */
export function region(html, tag) {
  const open = new RegExp(`<${tag}\\b[^>]*>`, 'i')
  const m = open.exec(html)
  if (!m) return null
  const re = new RegExp(`<${tag}\\b[^>]*>|</${tag}>`, 'gi')
  re.lastIndex = m.index
  let depth = 0
  let x
  while ((x = re.exec(html))) {
    if (x[0][1] === '/') depth--
    else depth++
    if (depth === 0) return html.slice(m.index, x.index + x[0].length)
  }
  return html.slice(m.index)
}

export function regions(html, tag) {
  const out = []
  let rest = html
  let guard = 0
  while (guard++ < 200) {
    const r = region(rest, tag)
    if (!r) break
    out.push(r)
    rest = rest.slice(rest.indexOf(r) + r.length)
  }
  return out
}

export function head(html) {
  const h = (html.match(/<head\b[\s\S]*?<\/head>/i) || [html.slice(0, 60000)])[0]
  const metas = [...h.matchAll(/<meta\b[^>]*>/gi)].map((m) => attrs(m[0]))
  const links = [...h.matchAll(/<link\b[^>]*>/gi)].map((m) => attrs(m[0]))
  const titles = [...h.matchAll(/<title\b[^>]*>([\s\S]*?)<\/title>/gi)].map((m) => decode(m[1]).trim())
  // Next streams some metadata into <body> — read meta/link/title from the whole document too.
  const allMetas = [...html.matchAll(/<meta\b[^>]*>/gi)].map((m) => attrs(m[0]))
  const allLinks = [...html.matchAll(/<link\b[^>]*>/gi)].map((m) => attrs(m[0]))
  const allTitles = [...stripScripts(html).matchAll(/<title\b[^>]*>([\s\S]*?)<\/title>/gi)].map((m) => decode(m[1]).trim())
  return { metas: allMetas.length ? allMetas : metas, links: allLinks.length ? allLinks : links, titles: allTitles.length ? allTitles : titles }
}

export function metaBy(metas, key, val) {
  return metas.filter((m) => (m[key] || '').toLowerCase() === val.toLowerCase()).map((m) => m.content ?? '')
}

export function jsonLd(html) {
  const out = []
  for (const m of html.matchAll(/<script\b[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
    try {
      out.push({ ok: true, data: JSON.parse(m[1]) })
    } catch (e) {
      out.push({ ok: false, error: String(e.message).slice(0, 120), raw: m[1].slice(0, 200) })
    }
  }
  return out
}

export function headings(html) {
  const body = stripScripts(html)
  return [...body.matchAll(/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/gi)].map((m) => ({ level: +m[1], text: text(m[2]).slice(0, 200) }))
}

export function anchors(html, base) {
  const body = stripScripts(html)
  const out = []
  for (const m of body.matchAll(/<a\b[^>]*>/gi)) {
    const a = attrs(m[0])
    if (a.href === undefined) {
      out.push({ href: null })
      continue
    }
    let abs = null
    try {
      abs = new URL(a.href, base).href
    } catch {}
    out.push({ href: a.href, abs, rel: a.rel || '', hreflang: a.hreflang || '' })
  }
  return out
}

export function images(html) {
  const body = stripScripts(html)
  return [...body.matchAll(/<img\b[^>]*>/gi)].map((m) => {
    const a = attrs(m[0])
    return {
      src: a.src || '',
      srcset: !!(a.srcset),
      alt: a.alt,
      width: a.width || '',
      height: a.height || '',
      loading: a.loading || '',
      fetchpriority: a.fetchpriority || '',
    }
  })
}

/** Label/value pairs a spec block shows: dt/dd, th/td, and `…-label` / `…-value` spans. */
export function specs(html) {
  const body = stripScripts(html)
  const out = []
  for (const m of body.matchAll(/<dt\b[^>]*>([\s\S]*?)<\/dt>\s*<dd\b[^>]*>([\s\S]*?)<\/dd>/gi)) out.push([text(m[1]), text(m[2])])
  for (const m of body.matchAll(/<th\b[^>]*>([\s\S]*?)<\/th>\s*<td\b[^>]*>([\s\S]*?)<\/td>/gi)) out.push([text(m[1]), text(m[2])])
  // a label is short plain text; the value is read up to its own closing tag (no block-spanning match)
  for (const m of body.matchAll(/<(span|div)\b[^>]*class="[^"]*-label[^"]*"[^>]*>([^<]{1,80})<\/\1>\s*<(span|div)\b[^>]*class="[^"]*-value[^"]*"[^>]*>([\s\S]{0,400}?)<\/\3>/gi)) out.push([text(m[2]), text(m[4])])
  return out
}

export function count(html, re) {
  return (stripScripts(html).match(re) || []).length
}
