import PropTypes from 'prop-types'
import { trustSignals } from '../../config/trust.config'

// Single trust item. Renders as a link when an href is provided (e.g. WhatsApp),
// otherwise as a plain inline label. Pure static render — no API calls.
function Signal({ icon, label, href }) {
  const content = (
    <span className="d-inline-flex align-items-center gap-2">
      <span aria-hidden="true" style={{ fontSize: '1.1rem', lineHeight: 1 }}>
        {icon}
      </span>
      <span style={{ fontSize: '0.9rem' }}>{label}</span>
    </span>
  )

  if (href) {
    return (
      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        className="text-decoration-none text-reset"
      >
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
//  - pdp:      all 4 signals + payment methods, vertical stack
//  - checkout: returns + secure + whatsapp, horizontal bar
//  - cart:     returns + secure, compact horizontal row
// Uses only Bootstrap utility classes already present in the project.
// Responsive: collapses to a vertical stack on mobile via flex-column / flex-sm-row.
function TrustSignals({ variant = 'pdp' }) {
  const { returns, guarantee, secure, whatsapp, payments } = trustSignals

  if (variant === 'cart') {
    return (
      <div className="trust-signals d-flex flex-column flex-sm-row flex-wrap gap-2 gap-sm-3 align-items-start align-items-sm-center text-secondary py-2">
        <Signal {...returns} />
        <Signal {...secure} />
      </div>
    )
  }

  if (variant === 'checkout') {
    return (
      <div className="trust-signals d-flex flex-column flex-sm-row flex-wrap gap-2 gap-sm-4 align-items-start align-items-sm-center text-secondary border rounded-3 px-3 py-2 my-3">
        <Signal {...returns} />
        <Signal {...secure} />
        <Signal {...whatsapp} />
      </div>
    )
  }

  // variant === 'pdp'
  return (
    <div className="trust-signals d-flex flex-column gap-2 border rounded-3 p-3 my-3 text-secondary">
      <Signal {...returns} />
      <Signal {...guarantee} />
      <Signal {...secure} />
      <Signal {...whatsapp} />
      <div className="d-flex flex-wrap gap-2 mt-1">
        {payments.map((method) => (
          <span
            key={method}
            className="badge bg-light text-dark border"
            style={{ fontSize: '0.75rem' }}
          >
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
