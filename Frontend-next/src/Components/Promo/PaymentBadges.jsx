'use client'
import { useUIStore } from '../../Store/uiStore'
import { usePaymentBadges } from '../../Hooks/queries/usePromo'
import { txt } from '../../lib/campaign'
import './promo.css'

// The payment badges — footer, cart, checkout (Part 7, 2026-11-05). A TWIN.
//
// The list is core's: one badge per payment method THIS storefront's checkout offers, in the checkout's
// order. Nothing is typed here, so the site cannot show a way to pay that it does not take; a method
// switched off in the dashboard is gone from here within a minute.
//
// Each badge is a white tile. With a picture uploaded on the dashboard's Payments screen the tile shows it;
// without one it prints the method's name — never a logo guessed by this component.
//
// `tone`: "dark" for a dark ground (the footer), "light" for a light one (cart, checkout).
// `title`: true prints the small heading; the footer has its own.

const STR = {
  en: { title: 'Ways to pay', note: 'You choose one at checkout.' },
  ar: { title: 'طرق الدفع', note: 'تختار واحدة منها عند إتمام الطلب.' },
}

export default function PaymentBadges({ tone = 'light', title = true, note = false, className = '' }) {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const { data: badges } = usePaymentBadges()
  if (!badges?.length) return null

  return (
    <div className={`pm-pay pm-pay--${tone} ${className}`} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      {title ? <p className="pm-pay__title">{t.title}</p> : null}
      <ul className="pm-pay__list" aria-label={title ? undefined : t.title}>
        {badges.map((badge) => {
          const name = txt(badge.label, lang)
          return (
            <li className="pm-pay__badge" key={badge.method} title={name}>
              {badge.image?.src ? (
                // eslint-disable-next-line @next/next/no-img-element -- the dashboard's upload, on the asset host
                <img src={badge.image.src} alt={name} loading="lazy" decoding="async" width="64" height="40" />
              ) : (
                <span className="pm-pay__name">{name}</span>
              )}
            </li>
          )
        })}
      </ul>
      {note ? <p className="pm-pay__note">{t.note}</p> : null}
    </div>
  )
}
