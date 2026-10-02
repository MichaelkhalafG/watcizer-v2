'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import { TRUST_PAGES, TRUST_UPDATED } from '@/src/content/trustPages'
import '@/src/Components/Blog/blog.css'
import './trust.css'

// One trust page (/about-us, /contact-us, /privacy-policy, /terms-and-conditions — 2026-10-02) in the
// shopper's language. The text lives in src/content/trustPages.js; this only lays it out. Inline
// marks (**bold**, *italic*, [label](href)) become React ELEMENTS — nothing is ever parsed as HTML,
// the same rule as ArticleBody.

/** `**bold**`, `*italic*` and `[label](href)` → React nodes. Marks nest (a link inside bold). */
function inline(text, key = 'i') {
  const out = []
  let rest = text
  let n = 0
  while (rest.length) {
    const bold = rest.indexOf('**')
    const link = rest.indexOf('[')
    const ital = rest.search(/(?<!\*)\*(?!\*)/)
    const next = [bold, link, ital].filter((i) => i >= 0).sort((a, b) => a - b)[0]
    if (next === undefined) {
      out.push(rest)
      break
    }
    if (next > 0) out.push(rest.slice(0, next))
    rest = rest.slice(next)
    const k = `${key}.${n++}`
    if (next === bold) {
      const end = rest.indexOf('**', 2)
      if (end < 0) { out.push(rest); break }
      out.push(<strong key={k}>{inline(rest.slice(2, end), k)}</strong>)
      rest = rest.slice(end + 2)
    } else if (next === link) {
      const m = rest.match(/^\[([^\]]+)\]\(([^)\s]+)\)/)
      if (!m) { out.push('['); rest = rest.slice(1); continue }
      const [all, label, href] = m
      const children = inline(label, k)
      if (href.startsWith('/')) out.push(<Link key={k} href={href}>{children}</Link>)
      else if (href.startsWith('https://')) out.push(<a key={k} href={href} target="_blank" rel="noopener noreferrer">{children}</a>)
      else out.push(<a key={k} href={href}>{children}</a>) // tel: / mailto:
      rest = rest.slice(all.length)
    } else {
      const end = rest.indexOf('*', 1)
      if (end < 0) { out.push(rest); break }
      out.push(<em key={k}>{inline(rest.slice(1, end), k)}</em>)
      rest = rest.slice(end + 1)
    }
  }
  return out
}

function Blocks({ blocks }) {
  return blocks.map((b, i) => {
    if (b.h2) return <h2 key={i}>{inline(b.h2)}</h2>
    if (b.p) return <p key={i}>{inline(b.p)}</p>
    if (b.ul) return (
      <ul key={i}>
        {b.ul.map((item, j) => <li key={j}>{inline(item)}</li>)}
      </ul>
    )
    if (b.ol) return (
      <ol key={i} className="wz-trust__terms">
        {b.ol.map((item, j) => (
          <li key={j}>
            <p>{inline(item.text)}</p>
            {item.after ? <Blocks blocks={item.after} /> : null}
          </li>
        ))}
      </ol>
    )
    return null
  })
}

export default function TrustPage({ page }) {
  const { language } = useUIStore()
  const ar = language === 'ar'
  const def = TRUST_PAGES[page]
  const t = def[ar ? 'ar' : 'en']
  const updated = new Date(`${TRUST_UPDATED}T00:00:00Z`).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', {
    day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
  })

  return (
    <main className="wz-article wz-trust" dir={ar ? 'rtl' : 'ltr'}>
      <h1>{t.title}</h1>
      {def.updated ? <span className="wz-article__date">{ar ? `آخر تحديث: ${updated}` : `Last updated: ${updated}`}</span> : null}
      <div className="wz-article-body">
        <Blocks blocks={t.blocks} />
      </div>
    </main>
  )
}
