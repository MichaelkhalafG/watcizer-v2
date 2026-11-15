// LIVE DATA BLOCKS and the article's reading aids (SEO Phase 3, 2026-11-14) — a TWIN.
//
// An article's body carries tokens instead of hard-coded lists ({{products brand=… limit=6}}, {{count …}}, …). Core
// resolves them (App\Domain\Content\DataBlocks: cached, warmed, every LIVE product, in stock first) and sends them
// with the article as `blocks`: token => block. This file only PLACES them:
//  - a list block (products, new-arrivals, compare, in-stock-alternatives) becomes its own segment where the token is;
//  - an inline block (count, price-range) becomes words inside the sentence;
//  - a token core did not resolve disappears (never shown as braces).
// Plus what the article page derives from the text: the table of contents, the reading time, the questions for the
// FAQPage markup.

export const BLOCK_PATTERN = /\{\{\s*(products|count|price-range|new-arrivals|compare|in-stock-alternatives)((?:\s+[a-z_]+=[^\s{}]+)*)\s*\}\}/g
const LIST_KINDS = new Set(['products', 'new-arrivals', 'compare', 'in-stock-alternatives'])

/** The token as core keys it: the kind, then each argument as k=v, single spaces, in the order written. */
export function canonicalToken(kind, rawArgs) {
  const args = [...String(rawArgs || '').matchAll(/([a-z_]+)=([^\s{}]+)/g)].map((m) => `${m[1]}=${m[2]}`)
  return [kind, ...args].join(' ')
}

const num = (n) => Math.round(Number(n) || 0).toLocaleString('en-US')

/** An inline block in words. */
export function inlineText(block, lang) {
  if (!block) return ''
  const ar = lang === 'ar'
  if (block.kind === 'count') return num(block.total)
  if (block.kind === 'price-range') {
    if (!block.total) return ''
    const cur = ar ? 'ج.م' : 'EGP'
    return block.min === block.max ? `${num(block.min)} ${cur}` : ar ? `${num(block.min)}–${num(block.max)} ${cur}` : `EGP ${num(block.min)}–${num(block.max)}`
  }
  return ''
}

/**
 * The body cut into segments: { type: 'md', text } with the inline blocks already filled, and { type: 'block', token,
 * block } for each list block that resolved to something to show.
 */
export function splitBody(text, blocks = {}, lang = 'en') {
  const out = []
  let md = ''
  let last = 0
  const src = String(text || '')
  for (const m of src.matchAll(BLOCK_PATTERN)) {
    const token = canonicalToken(m[1], m[2])
    const block = blocks?.[token]
    md += src.slice(last, m.index)
    last = m.index + m[0].length
    if (!LIST_KINDS.has(m[1])) {
      md += inlineText(block, lang)
      continue
    }
    if (!showsSomething(block)) continue
    if (md.trim()) out.push({ type: 'md', text: md })
    md = ''
    out.push({ type: 'block', token, block })
  }
  md += src.slice(last)
  if (md.trim()) out.push({ type: 'md', text: md })
  return out
}

/** A list block shows only when it has cards; "available now instead" only while its model is out of stock. */
export function showsSomething(block) {
  if (!block || !Array.isArray(block.items) || block.items.length === 0) return false
  if (block.kind === 'in-stock-alternatives' && block.model_in_stock) return false
  return true
}

// A heading's anchor — defined in markdown.js (the renderer gives the H2s these ids; markdown.js may import nothing of
// ours: BlogMarkdownSafeTest runs it under plain Node), re-exported here for the table of contents.
import { headingId } from './markdown.js'
export { headingId }

/** The H2 headings, in order, with their anchors (duplicates get -2, -3). */
export function tableOfContents(text) {
  const seen = new Map()
  const out = []
  for (const line of String(text || '').split('\n')) {
    const m = /^##\s+(.+?)\s*$/.exec(line)
    if (!m || line.startsWith('###')) continue
    const base = headingId(m[1])
    const n = (seen.get(base) || 0) + 1
    seen.set(base, n)
    out.push({ title: m[1].replace(/[*_`]/g, ''), id: n === 1 ? base : `${base}-${n}` })
  }
  return out
}

/** Minutes to read the prose (blocks and markup left out), at 200 words a minute; at least 1. */
export function readingMinutes(text) {
  const words = String(text || '')
    .replace(BLOCK_PATTERN, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/[#>*_`|-]/g, ' ')
    .split(/\s+/)
    .filter(Boolean).length
  return Math.max(1, Math.round(words / 200))
}

/** The questions and answers (a ### heading ending in ? or ؟ and the text under it) — core's MarkdownDoc rule. */
export function faqOf(text) {
  const out = []
  let current = null
  for (const line of String(text || '').split('\n')) {
    const h3 = /^###\s+(.+?)\s*$/.exec(line)
    if (h3) {
      current = /[?؟]$/.test(h3[1]) ? { question: h3[1], answer: '' } : null
      if (current) out.push(current)
      continue
    }
    if (/^##\s/.test(line)) {
      current = null
      continue
    }
    if (current) current.answer += `${line}\n`
  }
  return out
    .map((q) => ({ question: q.question.trim(), answer: plainAnswer(q.answer) }))
    .filter((q) => q.answer)
}

const plainAnswer = (s) =>
  String(s || '')
    .replace(BLOCK_PATTERN, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/(\*\*|__|\*|_|`)/g, '')
    .replace(/\s+/g, ' ')
    .trim()

/** The list blocks of the body, in order, that show — for the ItemList markup. */
export function listedBlocks(text, blocks = {}) {
  return splitBody(text, blocks).filter((s) => s.type === 'block')
}
