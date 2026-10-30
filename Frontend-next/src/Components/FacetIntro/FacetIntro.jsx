import './FacetIntro.css'

// The brand or category page's own words (M1y, 2026-10-30): written in the dashboard's "Page texts" screen and sent
// by core with the listing as `meta.context`, in both languages. Server-rendered under the grid, so the products stay
// the first thing a shopper sees and the text is in the HTML a search engine reads. Plain paragraphs only — the
// dashboard field is plain text, split on blank lines; nothing in it is ever treated as markup.
export default function FacetIntro({ context, lang = 'en', heading = null }) {
  const text = context?.[lang]?.description
  if (!text) return null
  const paragraphs = String(text)
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean)
  if (!paragraphs.length) return null
  return (
    <section className="facet-intro" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      {heading ? <h2 className="facet-intro__heading">{heading}</h2> : null}
      {paragraphs.map((p, i) => (
        <p key={i} className="facet-intro__text">
          {p}
        </p>
      ))}
    </section>
  )
}
