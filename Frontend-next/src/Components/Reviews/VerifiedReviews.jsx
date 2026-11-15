import './VerifiedReviews.css'

// THE PRODUCT'S VERIFIED REVIEWS (SEO Phase 5, 2026-11-14) — a TWIN, server-rendered under the product. Only the reviews
// the team approved on the dashboard, each written from the review link of a DELIVERED order (a verified purchase);
// the newest ten, with their photos. Nothing at all when there are none.
const stars = (n) => '★'.repeat(Math.max(0, Math.min(5, n))) + '☆'.repeat(Math.max(0, 5 - n))

export default function VerifiedReviews({ reviews, lang = 'en' }) {
  const count = Number(reviews?.count || 0)
  if (count <= 0) return null
  const ar = lang === 'ar'
  const avg = Number(reviews.average || 0).toFixed(1)
  return (
    <section className="wz-reviews" dir={ar ? 'rtl' : 'ltr'} aria-label={ar ? 'تقييمات العملاء' : 'Customer reviews'}>
      <h2 className="wz-reviews__title">{ar ? 'تقييمات العملاء' : 'Customer reviews'}</h2>
      <p className="wz-reviews__summary">
        <span className="wz-reviews__stars" aria-hidden="true">
          {stars(Math.round(Number(reviews.average || 0)))}
        </span>{' '}
        {ar ? `${avg} من 5 · ${count} تقييم` : `${avg} out of 5 · ${count} ${count === 1 ? 'review' : 'reviews'}`}
      </p>
      <p className="wz-reviews__note">
        {ar ? 'كل تقييم من عميل استلم طلبه (شراء مؤكد).' : 'Every review is from a customer who received their order (verified purchase).'}
      </p>
      <ul className="wz-reviews__list">
        {(reviews.items || []).map((r) => (
          <li key={r.id} className="wz-reviews__item">
            <p className="wz-reviews__head">
              <span className="wz-reviews__stars" aria-label={ar ? `${r.rating} من 5` : `${r.rating} out of 5`}>
                {stars(r.rating)}
              </span>
              <strong dir="auto">{r.name}</strong>
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
    </section>
  )
}
