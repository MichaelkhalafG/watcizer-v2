'use client'
import { useMemo } from 'react'
import { FiCalendar, FiCreditCard, FiShoppingBag, FiSmartphone, FiTruck } from 'react-icons/fi'
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
//
// Michael, 2026-11-10: the pictures are 320×200 transparent PNGs with their own padding. The footer keeps its white
// cards (a dark ground) and the picture FILLS each card; the cart shows the pictures bare (`pm-pay--bare`: no tile);
// the checkout's payment rows show each method's picture at the end of the row ({@see MethodBadge}), its line icon
// only where no picture was uploaded. All three read this one answer (usePaymentBadges).

// `bare` (the cart and the checkout summary, 2026-11-10): the pictures side by side, no tile; a method without a picture
// shows its line icon (the checkout rows' own), else its name.
const METHOD_ICON = {
  card: FiCreditCard,
  bank_installment: FiCalendar,
  cagg: FiCalendar,
  valu: FiCalendar,
  wallet: FiSmartphone,
  apple_pay: FiSmartphone,
  cod: FiTruck,
  kiosk: FiShoppingBag,
}

const STR = {
  en: { title: 'Ways to pay', note: 'You choose one at checkout.' },
  ar: { title: 'طرق الدفع', note: 'تختار واحدة منها عند إتمام الطلب.' },
}

export default function PaymentBadges({ tone = 'light', title = true, note = false, bare = false, className = '' }) {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const { data: badges } = usePaymentBadges()
  if (!badges?.length) return null

  return (
    <div className={`pm-pay pm-pay--${tone}${bare ? ' pm-pay--bare' : ''} ${className}`} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      {title ? <p className="pm-pay__title">{t.title}</p> : null}
      <ul className="pm-pay__list" aria-label={title ? undefined : t.title}>
        {badges.map((badge) => {
          const name = txt(badge.label, lang)
          const Icon = bare && !badge.image?.src ? METHOD_ICON[badge.method] : null
          return (
            <li className={`pm-pay__badge${badge.image?.src ? '' : ' pm-pay__badge--name'}`} key={badge.method} title={name}>
              {badge.image?.src ? (
                // eslint-disable-next-line @next/next/no-img-element -- the dashboard's upload, on the asset host
                <img src={badge.image.src} alt={name} loading="lazy" decoding="async" width="320" height="200" />
              ) : Icon ? (
                <span className="pm-pay__icon" role="img" aria-label={name}>
                  <Icon aria-hidden="true" />
                </span>
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

/** Method key → its badge WITH a picture, from the same answer as the strip (the checkout's rows). */
export function usePaymentBadgeOf() {
  const { data: badges } = usePaymentBadges()
  return useMemo(() => {
    const byMethod = new Map()
    for (const badge of badges || []) if (badge?.image?.src) byMethod.set(badge.method, badge)
    return byMethod
  }, [badges])
}

/**
 * A method's uploaded picture at the end of a checkout row: no box, no frame (the PNG is transparent and padded),
 * 40 px high at its 320:200 shape, its size reserved before it loads.
 */
export function MethodBadge({ badge, name }) {
  return (
    // eslint-disable-next-line @next/next/no-img-element -- the dashboard's upload, on the asset host
    <img className="pm-method-badge" src={badge.image.src} alt={name} loading="lazy" decoding="async" width="64" height="40" />
  )
}
