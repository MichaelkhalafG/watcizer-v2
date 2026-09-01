import PropTypes from 'prop-types'
import { trustSignals } from '../../config/trust.config'
import './TrustSignals.css'

// Single trust item. Renders as a link when an href is provided (e.g. WhatsApp),
// otherwise as a plain inline label. Pure static render — no API calls.
function Signal({ icon, label, href }) {
  const content = (
    <span className="wz-trust-item">
      <span className="wz-trust-item-icon" aria-hidden="true">
        {icon}
      </span>
      <span>{label}</span>
    </span>
  )

  if (href) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer" className="wz-trust-item">
        {content}
      </a>
    )
  }
  return content
}

Signal.propTypes = {
  icon: PropTypes.string.isRequired,
  label: PropTypes.string.isRequired,
  href: PropTypes.string,
}

// PDP returns row: a product-type-aware, bilingual returns policy (same icon/row
// styling as the other signals) with a small muted condition note beneath it.
// Fashion items are exchange/return within 4 days; watches add a 14-day exchange.
function ReturnsSignal({ isFashion, isRTL }) {
  const { icon, policy, condition } = trustSignals.returns
  const p = isFashion ? policy.fashion : policy.watch
  return (
    <div className="wz-trust-returns">
      <span className="wz-trust-item">
        <span className="wz-trust-item-icon" aria-hidden="true">
          {icon}
        </span>
        <span>{isRTL ? p.ar : p.en}</span>
      </span>
      <small className="wz-trust-note">{isRTL ? condition.ar : condition.en}</small>
    </div>
  )
}

ReturnsSignal.propTypes = {
  isFashion: PropTypes.bool,
  isRTL: PropTypes.bool,
}

// Live "social proof" row — rating stars + review count, and a low-stock urgency
// pill. Only rendered when the caller passes real data (e.g. the PDP), so cart /
// checkout, which span multiple items, never show a misleading single-item stat.
const LOW_STOCK_THRESHOLD = 5

function LiveSignals({ rating, reviewCount, stockLeft, isRTL }) {
  const hasRating = reviewCount > 0 && rating > 0
  const isLow = typeof stockLeft === 'number' && stockLeft > 0 && stockLeft <= LOW_STOCK_THRESHOLD
  if (!hasRating && !isLow) return null

  const rounded = Math.max(0, Math.min(5, Math.round(rating)))

  return (
    <div className="wz-trust-live">
      {hasRating && (
        <span className="wz-trust-rating" aria-label={`${rating.toFixed(1)} / 5`}>
          <span className="wz-trust-stars" aria-hidden="true">
            {'★★★★★☆☆☆☆☆'.slice(5 - rounded, 10 - rounded)}
          </span>
          <span className="wz-trust-rating-num">{rating.toFixed(1)}</span>
          <span className="wz-trust-reviews">
            · {reviewCount} {isRTL ? 'تقييم' : reviewCount === 1 ? 'review' : 'reviews'}
          </span>
        </span>
      )}
      {isLow && (
        <span className="wz-trust-urgent">
          {isRTL ? `باقي ${stockLeft} فقط — اطلب الآن` : `Only ${stockLeft} left — order soon`}
        </span>
      )}
    </div>
  )
}

LiveSignals.propTypes = {
  rating: PropTypes.number,
  reviewCount: PropTypes.number,
  stockLeft: PropTypes.number,
  isRTL: PropTypes.bool,
}

// Trust badges shown across the buying journey. Variants:
//  - pdp:      all 4 signals + payment methods, vertical stack (framed)
//  - checkout: returns + secure + whatsapp, horizontal bar (framed)
//  - cart:     returns + secure, compact horizontal row
// Optional live props (rating/reviewCount/stockLeft) add a social-proof + urgency
// row on top — the PDP passes real product data; cart/checkout omit them.
// Styled with dedicated wz-trust-* classes (no Bootstrap). Responsive: collapses
// to a vertical stack on mobile via the .wz-trust--row media query.
function TrustSignals({ variant = 'pdp', rating = 0, reviewCount = 0, stockLeft = null, isRTL = false, isFashion = false }) {
  const { returns, guarantee, secure, whatsapp, payments } = trustSignals

  const live = (
    <LiveSignals rating={rating} reviewCount={reviewCount} stockLeft={stockLeft} isRTL={isRTL} />
  )

  if (variant === 'cart') {
    return (
      <div className="wz-trust wz-trust--row">
        {live}
        <Signal {...returns} />
        <Signal {...secure} />
      </div>
    )
  }

  if (variant === 'checkout') {
    return (
      <div className="wz-trust wz-trust--row wz-trust--framed">
        {live}
        <Signal {...returns} />
        <Signal {...secure} />
        <Signal {...whatsapp} />
      </div>
    )
  }

  // variant === 'pdp'
  return (
    <div className="wz-trust wz-trust--framed wz-trust--pdp">
      {live}
      <ReturnsSignal isFashion={isFashion} isRTL={isRTL} />
      <Signal {...guarantee} />
      <Signal {...secure} />
      <Signal {...whatsapp} />
      <div className="wz-trust-payments">
        {payments.map((method) => (
          <span key={method} className="wz-trust-badge">
            {method}
          </span>
        ))}
      </div>
    </div>
  )
}

TrustSignals.propTypes = {
  variant: PropTypes.oneOf(['pdp', 'checkout', 'cart']),
  rating: PropTypes.number,
  reviewCount: PropTypes.number,
  stockLeft: PropTypes.number,
  isRTL: PropTypes.bool,
  isFashion: PropTypes.bool,
}

export default TrustSignals
