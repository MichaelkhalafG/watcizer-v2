import { averageLine, reviewsCount, REVIEW_WORDS } from '../../lib/reviewWords'
import './ReviewsPanel.css'

// THE STAR LINE UNDER THE PRODUCT'S NAME (2026-11-19) — a TWIN. The average and the count of the product's approved
// reviews, a link to the Reviews tab; with none yet, "Be the first to review". Drawn from the product's own payload, so
// it is in the first HTML and holds its height from the start (no layout shift). `onOpen` opens the tab and scrolls to it.
export default function ReviewStarLine({ reviews, lang = 'en', onOpen, className = '' }) {
  const ar = lang === 'ar'
  const count = Number(reviews?.count || 0)
  const average = Number(reviews?.average || 0)
  const has = count > 0 && average > 0
  const pct = has ? Math.max(0, Math.min(100, (average / 5) * 100)) : 0
  return (
    <a href="#reviews" className={`wz-starline ${className}`.trim()} dir={ar ? 'rtl' : 'ltr'} onClick={onOpen}>
      <span className={`wz-starline__stars${has ? '' : ' is-empty'}`} style={{ '--wz-star-pct': `${pct}%` }} aria-hidden="true">
        ★★★★★
      </span>
      <span className="wz-starline__text">
        {has ? `${averageLine(average, lang)} · ${reviewsCount(count, lang)}` : REVIEW_WORDS[ar ? 'ar' : 'en'].first}
      </span>
    </a>
  )
}
