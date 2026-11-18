import './VerifiedReviews.css'

// THE LIST OF A PRODUCT'S REVIEWS (SEO Phase 5, 2026-11-14; since 2026-11-17 from everyone) — a TWIN. Since 2026-11-18
// only the list: the Reviews tab (ReviewsPanel.jsx) draws the summary, the bars, "Show more" and the form around it. The
// reviews that are live (published at once unless the team held one for a link or a phone number, or hid it), with
// their photos. A review whose writer bought and received this product says so: "Verified buyer".
const stars = (n) => '★'.repeat(Math.max(0, Math.min(5, n))) + '☆'.repeat(Math.max(0, 5 - n))

export default function VerifiedReviews({ items, lang = 'en' }) {
  const ar = lang === 'ar'
  if (!items?.length) return null
  return (
    <ul className="wz-reviews__list">
      {items.map((r) => (
        <li key={r.id} className="wz-reviews__item">
          <p className="wz-reviews__head">
            <span className="wz-reviews__stars" role="img" aria-label={ar ? `${r.rating} من 5` : `${r.rating} out of 5`}>
              {stars(r.rating)}
            </span>
            <strong dir="auto">{r.name}</strong>
            {r.verified ? <span className="wz-reviews__verified">{ar ? 'مشتري موثّق' : 'Verified buyer'}</span> : null}
            {r.date ? <time dateTime={r.date}>{r.date}</time> : null}
          </p>
          <p className="wz-reviews__body" dir="auto">
            {r.body}
          </p>
          {r.photos?.length ? (
            <div className="wz-reviews__photos">
              {r.photos.map((src) => (
                <a key={src} href={src} target="_blank" rel="noopener noreferrer">
                  <img src={src} alt={ar ? `صورة من ${r.name}` : `Photo from ${r.name}`} width="96" height="96" loading="lazy" decoding="async" />
                </a>
              ))}
            </div>
          ) : null}
        </li>
      ))}
    </ul>
  )
}
