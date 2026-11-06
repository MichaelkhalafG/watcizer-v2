import { MarkdownBody } from '@/src/lib/markdown'
import { faqJsonLd, sectionsFromMarkdown, questionsOf } from '@/src/lib/pageFaq'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { localizeHref } from '@/src/utils/localePath'
import { ASSET_BASE } from '@/src/lib/env'
import './FacetIntro.css'

// The brand or category page's own words (M1y, 2026-10-30): written in the dashboard's "Page texts" screen and sent
// by core with the listing as `meta.context`, in both languages. Server-rendered under the grid, so the products stay
// the first thing a shopper sees and the text is in the HTML a search engine reads.
//
// MARKDOWN since 2026-11-05 (the twelve updates, Part 6): sections (h2), lists and a questions block, drawn by the
// same renderer as the articles — safe by construction (no HTML is ever interpreted, a link keeps only an https:,
// mailto:, tel: or site address, a picture only when it is one of our uploads). A text written before that day is
// plain paragraphs, which this draws as the same paragraphs.
//
// THE QUESTIONS in the text (a sub-heading ending in "?" with its answer under it) are also emitted as FAQPage
// structured data — that is what makes the block worth having; without the markup it is only more text.
//
// THE LAYOUT (the developer's review, 2026-11-05: "use the page width and design it properly, not a narrow plain
// column"). The text is still ONE Markdown field; its `##` sections are laid out by what they are:
//
//   the opening words    a lead paragraph, on the leading side of the page's column
//   the FACTS section    (the first section that is a list of "**Label:** value" lines) a sheet beside the lead —
//                        each line a row, its label in one column and its value in the other
//   every other section  a card, the cards side by side
//   the QUESTIONS        the full width under them, in two columns; each question opens its own answer
//                        (native <details>: keyboard and screen readers get it for free, and the answers are in
//                        the HTML whether open or not)
//
// A text with no sections is its lead alone, as before. Each shop gives it its own look with the --fi-* tokens in
// its globals.css; the structure is this twin's.
export default function FacetIntro({ context, lang = 'en', heading = null }) {
  const text = context?.[lang]?.description
  if (!text || !String(text).trim()) return null
  const ld = faqJsonLd(text, lang)
  const sections = sectionsFromMarkdown(String(text))
  const lead = sections.find((s) => s.kind === 'lead')
  const facts = sections.find((s) => s.kind === 'facts')
  const cards = sections.filter((s) => s.kind === 'card')
  const faq = sections.find((s) => s.kind === 'faq')
  const md = (body, className = '') => (
    <MarkdownBody text={body} assetBase={ASSET_BASE} className={`facet-intro__body ${className}`} internalHref={(h) => localizeHref(h, lang)} />
  )

  return (
    <section className="facet-intro" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      {ld ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} /> : null}
      {heading ? <h2 className="facet-intro__heading">{heading}</h2> : null}

      {lead || facts ? (
        <div className={`fi__top${lead && facts ? '' : ' fi__top--one'}`}>
          {lead ? <div className="fi__lead">{md(lead.body)}</div> : null}
          {facts ? (
            <aside className="fi__facts">
              <h2 className="fi__title">{facts.title}</h2>
              {md(facts.body, 'fi__facts-body')}
            </aside>
          ) : null}
        </div>
      ) : null}

      {cards.length ? (
        <div className="fi__cards">
          {cards.map((card, i) => (
            <article className="fi__card" key={i}>
              {card.title ? <h2 className="fi__title">{card.title}</h2> : null}
              {md(card.body)}
            </article>
          ))}
        </div>
      ) : null}

      {faq ? (
        <div className="fi__faq">
          {faq.title ? <h2 className="fi__title fi__title--faq">{faq.title}</h2> : null}
          <div className="fi__faq-list">
            {questionsOf(faq.body).map((item, i) => (
              <details className="fi__qa" key={i}>
                <summary>
                  <span>{item.q}</span>
                </summary>
                {md(item.body)}
              </details>
            ))}
          </div>
        </div>
      ) : null}
    </section>
  )
}
