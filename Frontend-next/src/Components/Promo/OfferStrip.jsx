'use client'
import { clockText } from '../../lib/campaign'
import { useOfferLeft } from '../../Hooks/queries/usePromo'

// A product that is in a live Hot Deals or Flash Sale campaign, wherever it is drawn (the developer's review,
// 2026-11-05, items 41 and 42). A TWIN.
//
//   <OfferStrip offer={offer} lang />    a thin strip INSIDE a card's photo box, at its foot: the offer's mark (a
//                                        flame or a bolt), its name and the time left. It is laid over the photo
//                                        box and takes no room of its own, so a card in an offer is exactly the
//                                        size of any other card — no row gets taller and nothing shifts.
//   <OfferNote offer={offer} lang />     the same facts as one line near a product page's price.
//
// `offer` comes from `useProductOffer(productId)` (usePromo.js): `{ kind: 'flash' | 'hot', ends_at, offset }`, or
// null — and null from the second the offer ends, so the strip, the colour and the note leave by themselves. The
// TIME is read here, by the small `Time` element alone, from the page's ONE clock (campaign.js): a tick re-renders
// eight digits, not the card around them, and no strip starts a timer of its own.
//
// The time is drawn in fixed cells (tabular digits, the width of "00:00:00"), so a changing digit moves nothing;
// before the browser's clock is known it reads as dashes of the same width.

const TEXT = {
  en: { flash: 'Flash sale', hot: 'Hot deal', ends: 'ends in', left: (t) => `ends in ${t}` },
  ar: { flash: 'تخفيض خاطف', hot: 'عرض ساخن', ends: 'ينتهي خلال', left: (t) => `ينتهي خلال ${t}` },
}

export const offerClass = (offer) => (offer ? ` pm-offer pm-offer--${offer.kind}` : '')

function Mark({ kind }) {
  return kind === 'flash' ? (
    <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false">
      <path d="M13.5 2 4 13.5h6L9 22l10.5-12.5h-6.2L13.5 2Z" fill="currentColor" />
    </svg>
  ) : (
    <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true" focusable="false">
      <path d="M12 2c1 3.6 4.6 5.6 4.6 10.2A4.6 4.6 0 0 1 12 17a2.6 2.6 0 0 1-2.6-2.7c0-1.5.9-2.3 1.4-3.6-2.7 1-4.6 3.7-4.6 6.5A5.8 5.8 0 0 0 12 23a6.4 6.4 0 0 0 6.4-6.5C18.4 10 13.6 7.4 12 2Z" fill="currentColor" />
    </svg>
  )
}

function Time({ offer, label }) {
  const left = useOfferLeft(offer)
  const text = left == null ? '--:--:--' : clockText(left)
  return (
    <span className="pm-offer__time" dir="ltr" role="timer" aria-label={label(left == null ? '' : text)} suppressHydrationWarning>
      {text.split('').map((ch, i) => (
        <span key={i} className={ch === ':' ? 'pm-offer__sep' : 'pm-offer__digit'} aria-hidden="true" suppressHydrationWarning>
          {ch}
        </span>
      ))}
    </span>
  )
}

export default function OfferStrip({ offer, lang = 'en' }) {
  if (!offer) return null
  const t = TEXT[lang === 'ar' ? 'ar' : 'en']
  return (
    <span className={`pm-offer__strip pm-offer__strip--${offer.kind}`} dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      <span className="pm-offer__name">
        <Mark kind={offer.kind} />
        {t[offer.kind]}
      </span>
      <Time offer={offer} label={t.left} />
    </span>
  )
}

export function OfferNote({ offer, lang = 'en' }) {
  if (!offer) return null
  const t = TEXT[lang === 'ar' ? 'ar' : 'en']
  return (
    <p className={`pm-offer__note pm-offer__note--${offer.kind}`} dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      <span className="pm-offer__badge">
        <Mark kind={offer.kind} />
        {t[offer.kind]}
      </span>
      <span className="pm-offer__ends" aria-hidden="true">
        {t.ends}
      </span>
      <Time offer={offer} label={t.left} />
    </p>
  )
}
