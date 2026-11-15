import Link from '@/src/Components/LocaleLink'
import { landingLabel } from '@/src/lib/landing'
import { blogPath } from '@/src/lib/blogPath'
import './FacetLead.css'

// ABOVE THE PRODUCTS of a brand, category or landing page (SEO Phase 2, 2026-11-14). A TWIN. Two things, each only
// when there is something to show:
//   - the page's INTRO from the dashboard (60–90 words, `intro` of its page text, in the page's language) — the guide,
//     the facts and the questions stay under the products (FacetIntro);
//   - on a BRAND page, real links to its landing pages ("Men", "Women", "Bags", "Women's bags"), the ones core says it
//     has (`meta.landings`): crawlable links from the parent to every landing page, as the brief asks;
//   - on a brand page and its landing pages, the brand's latest articles (SEO Phase 3 item 4, 2026-11-14) and a link to
//     all of them (/blogs/brand/<brand>).
// Server-rendered; no state.
const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

// `dealer` (2026-11-14): the authorized-dealer sentence for this brand on this shop ('' = none)
export default function FacetLead({ context = null, landings = [], articles = [], brand = null, dealer = '', lang = 'en' }) {
  const intro = String(context?.[lang]?.intro || '').trim()
  const links = (landings || []).filter((p) => p?.path)
  const guides = (articles || []).filter((a) => a?.slug)
  if (!intro && links.length === 0 && guides.length === 0 && !dealer) return null
  const ar = lang === 'ar'
  return (
    <div className="wz-facet-lead" dir={ar ? 'rtl' : 'ltr'}>
      {dealer ? <p className="wz-facet-lead__intro"><strong>{dealer}</strong></p> : null}
      {intro ? <p className="wz-facet-lead__intro">{intro}</p> : null}
      {links.length > 0 ? (
        <nav className="wz-facet-lead__links" aria-label={ar ? 'تسوّق حسب' : 'Shop by'}>
          <span className="wz-facet-lead__label">{ar ? 'تسوّق حسب:' : 'Shop by:'}</span>
          <ul>
            {links.map((p) => (
              <li key={p.path}>
                <Link href={p.path} className="wz-facet-lead__link">
                  {landingLabel(p, lang)}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
      {guides.length > 0 ? (
        <nav className="wz-facet-lead__links" aria-label={ar ? 'أدلة ومقالات' : 'Guides'}>
          <span className="wz-facet-lead__label">{ar ? 'أدلة ومقالات:' : 'Guides:'}</span>
          <ul>
            {guides.map((a) => (
              <li key={a.slug}>
                <Link href={blogPath(a, lang)} className="wz-facet-lead__link">
                  {pick(a.title, lang)}
                </Link>
              </li>
            ))}
            {brand ? (
              <li>
                <Link href={`/blogs/brand/${brand}`} className="wz-facet-lead__link">
                  {ar ? 'كل المقالات' : 'All articles'}
                </Link>
              </li>
            ) : null}
          </ul>
        </nav>
      ) : null}
    </div>
  )
}
