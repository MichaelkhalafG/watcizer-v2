'use client'
import { useState } from 'react'
import { API_BASE, STOREFRONT_CODE } from '../../lib/env'
import { averageLine, REVIEW_WORDS } from '../../lib/reviewWords'
import VerifiedReviews from './VerifiedReviews'
import ProductReviewForm from './ProductReviewForm'
import './ReviewsPanel.css'

// THE REVIEWS TAB (2026-11-18) — a TWIN. The average and the bars per star (5 to 1), the list with the "Verified buyer"
// badge, "Show more" when the product has more than its first page, and the existing guest form (Turnstile, the limits
// and the link/phone hold are unchanged; its script loads only when the form opens). The first page comes with the
// product (in the server HTML, so the review markup matches what is on the page); later pages are fetched on demand
// from core's read-only `products/{id}/reviews` — a core without that route answers 404 and the button goes away.
export default function ReviewsPanel({ reviews, form, productId, lang = 'en' }) {
  const ar = lang === 'ar'
  const w = REVIEW_WORDS[ar ? 'ar' : 'en']
  const count = Number(reviews?.count || 0)
  const first = Array.isArray(reviews?.items) ? reviews.items : []
  const [more, setMore] = useState([])
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(count > first.length)
  const [state, setState] = useState('idle')

  const loadMore = async () => {
    setState('loading')
    try {
      const res = await fetch(`${API_BASE.replace(/\/$/, '')}/v2/${STOREFRONT_CODE}/products/${productId}/reviews?page=${page + 1}`, {
        headers: { Accept: 'application/json' },
      })
      if (!res.ok) {
        setHasMore(false)
        setState(res.status === 404 ? 'idle' : 'failed')
        return
      }
      const body = await res.json()
      const data = body?.data || {}
      const seen = new Set([...first, ...more].map((r) => r.id))
      setMore((cur) => [...cur, ...(data.items || []).filter((r) => !seen.has(r.id))])
      setPage(Number(data.page) || page + 1)
      setHasMore(Boolean(data.has_more))
      setState('idle')
    } catch {
      setState('failed')
    }
  }

  const breakdown = reviews?.breakdown && typeof reviews.breakdown === 'object' ? reviews.breakdown : null
  return (
    <div className="wz-revtab" dir={ar ? 'rtl' : 'ltr'}>
      {count > 0 ? (
        <div className="wz-revtab__summary">
          <div className="wz-revtab__score">
            <span className="wz-revtab__avg">{Number(reviews.average || 0).toFixed(1)}</span>
            <span
              className="wz-starline__stars"
              style={{ '--wz-star-pct': `${Math.max(0, Math.min(100, (Number(reviews.average || 0) / 5) * 100))}%` }}
              role="img"
              aria-label={averageLine(reviews.average, lang)}
            >
              ★★★★★
            </span>
            <span className="wz-revtab__based">{w.based(count)}</span>
          </div>
          {breakdown ? (
            <ul className="wz-revtab__bars">
              {[5, 4, 3, 2, 1].map((s) => {
                const n = Number(breakdown[s] ?? breakdown[String(s)] ?? 0)
                const pct = count > 0 ? Math.round((n / count) * 100) : 0
                return (
                  <li key={s} className="wz-revtab__bar" aria-label={w.bar(s, n)}>
                    <span className="wz-revtab__bar-label" aria-hidden="true">{w.starsShort(s)}</span>
                    <span className="wz-revtab__bar-track" aria-hidden="true">
                      <span className="wz-revtab__bar-fill" style={{ inlineSize: `${pct}%` }} />
                    </span>
                    <span className="wz-revtab__bar-count" aria-hidden="true">{n}</span>
                  </li>
                )
              })}
            </ul>
          ) : null}
        </div>
      ) : (
        <p className="wz-revtab__none">{w.none}</p>
      )}

      {count > 0 ? <p className="wz-reviews__note">{w.verifiedNote}</p> : null}
      <VerifiedReviews items={[...first, ...more]} lang={lang} />

      {hasMore ? (
        <button type="button" className="wz-revtab__more" onClick={loadMore} disabled={state === 'loading'} aria-busy={state === 'loading'}>
          {state === 'loading' ? w.loading : w.more}
        </button>
      ) : null}
      {state === 'failed' ? (
        <p className="wz-revtab__error" role="alert">
          {w.moreFailed}
        </p>
      ) : null}

      <ProductReviewForm productId={productId} lang={lang} form={form} />
    </div>
  )
}
