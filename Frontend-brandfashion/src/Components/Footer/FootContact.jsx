import { FaWhatsapp } from 'react-icons/fa'
import { PHONE, TEL, WHATSAPP } from '@/src/content/contact'

// THE SHOP'S OWN NUMBER, AS TEXT, ON EVERY PAGE (Michael, 2026-11-17; the 2026-11-16 comparison: the shops that show
// a number everywhere are the ones assistants and shoppers trust). A TWIN: each shop's src/content/contact.js holds
// its own customer-service number. The developer's number is never here — it stays
// in the credit line only. No aria-label on either link: what a screen reader says is what is written.
// Plain component, no hooks: the desktop footer (a client component) and the phone's footer row (server) both draw it.
export default function FootContact({ lang = 'en', className = '' }) {
  const ar = lang === 'ar'
  return (
    <p className={`wz-foot-contact${className ? ` ${className}` : ''}`} lang={lang}>
      <span className="wz-foot-contact__label">{ar ? 'خدمة العملاء، اتصال أو واتساب:' : 'Customer service, call or WhatsApp:'}</span>
      <a className="wz-foot-contact__num" href={TEL} dir="ltr">{PHONE}</a>
      <a className="wz-foot-contact__wa" href={WHATSAPP} target="_blank" rel="noopener noreferrer">
        <FaWhatsapp aria-hidden="true" />
        <span>{ar ? 'واتساب' : 'WhatsApp'}</span>
      </a>
    </p>
  )
}
