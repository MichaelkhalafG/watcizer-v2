import { markdownToPlain } from './markdown'

// The questions and answers inside a page text (the twelve updates, Part 6.2, 2026-11-05). A TWIN.
//
// A page text is Markdown. A question is a sub-heading (### or ####) that ENDS WITH A QUESTION MARK — the Latin
// one or the Arabic one — and its answer is everything under it up to the next heading. That is the whole
// convention, and it is what the dashboard's hint tells the team: no special block, no extra field.
//
// The pairs become the page's FAQPage structured data, as PLAIN TEXT (a link's words stay, its address does not),
// so what Google is told is exactly what the shopper reads on the page. A heading that is not a question, a
// question with no answer, and anything inside a fenced code block are not questions.

const HEADING = /^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/
const IS_QUESTION = /[?؟]\s*$/

/** @returns {Array<{ q: string, a: string }>} */
export function faqFromMarkdown(text) {
  const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n')
  const out = []
  let current = null
  let fenced = false
  const close = () => {
    if (current) {
      const a = markdownToPlain(current.lines.join('\n'))
      if (a) out.push({ q: current.q, a })
    }
    current = null
  }
  for (const line of lines) {
    if (/^\s{0,3}(```|~~~)/.test(line)) {
      fenced = !fenced
      continue
    }
    if (fenced) continue
    const h = HEADING.exec(line)
    if (h) {
      close()
      const title = markdownToPlain(h[2])
      if (h[1].length >= 3 && IS_QUESTION.test(title)) current = { q: title, lines: [] }
      continue
    }
    if (current) current.lines.push(line)
  }
  close()
  return out
}

/** The FAQPage node for a page text, or null when it asks nothing. */
export function faqJsonLd(text, lang) {
  const pairs = faqFromMarkdown(text)
  if (!pairs.length) return null
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    inLanguage: lang,
    mainEntity: pairs.map(({ q, a }) => ({
      '@type': 'Question',
      name: q,
      acceptedAnswer: { '@type': 'Answer', text: a },
    })),
  }
}

/**
 * A page text as its `##` sections, each named by what it IS — so the page can lay them out across its width
 * instead of in one narrow column (the developer's review, 2026-11-05):
 *
 *   lead    the words before the first `##` (title null)
 *   facts   the FIRST section that is a list of "**Label:** value" lines (three or more) — the page's numbers
 *   faq     a section that holds questions (a `###` heading ending in a question mark)
 *   card    every other section
 *
 * Only a heading of exactly two `#` starts a section; anything inside a fenced code block is left alone.
 *
 * @returns {Array<{ kind: 'lead'|'facts'|'faq'|'card', title: string|null, body: string }>}
 */
export function sectionsFromMarkdown(text) {
  const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n')
  const raw = [{ title: null, lines: [] }]
  let fenced = false
  for (const line of lines) {
    if (/^\s{0,3}(```|~~~)/.test(line)) fenced = !fenced
    const h = fenced ? null : /^\s{0,3}##\s+(.*?)\s*#*\s*$/.exec(line)
    if (h && !/^\s{0,3}###/.test(line)) raw.push({ title: markdownToPlain(h[1]), lines: [] })
    else raw[raw.length - 1].lines.push(line)
  }
  let factsTaken = false
  const out = []
  raw.forEach((section, i) => {
    const body = section.lines.join('\n').trim()
    if (!body && !section.title) return
    let kind = i === 0 ? 'lead' : 'card'
    if (i > 0) {
      if (faqFromMarkdown(body).length > 0) kind = 'faq'
      else if (!factsTaken && (body.match(/^\s*[-*]\s+\*\*[^*]+:\*\*/gm) || []).length >= 3) {
        kind = 'facts'
        factsTaken = true
      }
    }
    out.push({ kind, title: section.title, body })
  })
  return out
}

/**
 * The questions of a section's body, each with its answer still as MARKDOWN (for the page; `faqFromMarkdown`
 * gives the same pairs as plain text for the structured data).
 *
 * @returns {Array<{ q: string, body: string }>}
 */
export function questionsOf(body) {
  const out = []
  let current = null
  for (const line of String(body || '').replace(/\r\n?/g, '\n').split('\n')) {
    const h = HEADING.exec(line)
    if (h && h[1].length >= 3) {
      if (current && current.lines.join('\n').trim()) out.push({ q: current.q, body: current.lines.join('\n').trim() })
      const title = markdownToPlain(h[2])
      current = IS_QUESTION.test(title) ? { q: title, lines: [] } : null
    } else if (current) current.lines.push(line)
  }
  if (current && current.lines.join('\n').trim()) out.push({ q: current.q, body: current.lines.join('\n').trim() })
  return out
}
