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

// Trust badges shown across the buying journey. Variants:
//  - pdp:      all 4 signals + payment methods, vertical stack (framed)
//  - checkout: returns + secure + whatsapp, horizontal bar (framed)
//  - cart:     returns + secure, compact horizontal row
// Styled with dedicated wz-trust-* classes (no Bootstrap). Responsive: collapses
// to a vertical stack on mobile via the .wz-trust--row media query.
function TrustSignals({ variant = 'pdp' }) {
  const { returns, guarantee, secure, whatsapp, payments } = trustSignals

  if (variant === 'cart') {
    return (
      <div className="wz-trust wz-trust--row">
        <Signal {...returns} />
        <Signal {...secure} />
      </div>
    )
  }

  if (variant === 'checkout') {
    return (
      <div className="wz-trust wz-trust--row wz-trust--framed">
        <Signal {...returns} />
        <Signal {...secure} />
        <Signal {...whatsapp} />
      </div>
    )
  }

  // variant === 'pdp'
  return (
    <div className="wz-trust wz-trust--framed wz-trust--pdp">
      <Signal {...returns} />
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
}

export default TrustSignals
