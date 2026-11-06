'use client'
import { useEffect, useState } from 'react'
import { clockText, countdownParts, msLeft, two } from '../../lib/campaign'

// The countdown of a live campaign (Part 2, 2026-11-05). A TWIN.
//
// It counts to the campaign's real end on the server's clock (`offset`), once a second, and stops at zero —
// the parent removes the whole surface at that moment (useLiveCampaign), so a finished countdown is never
// left on the page and nothing here restarts.
//
// SERVER HTML: the countdown is rendered on the server too (so nothing arrives late and shifts the page), and the
// second it shows there is not the second the browser hydrates on. `suppressHydrationWarning` on exactly the elements
// whose text or label is the time tells React that difference is expected; the first tick, a second later, corrects it.
//
// `boxes`  — a unit per box with its name under it: the campaign page and the home sliders.
// `inline` — `02:14:58` as a clock: the header switch and the menu's Sale panel. Every character sits in a cell
//            of fixed width (promo.css), so the clock — and the control around it — is exactly as wide at 09:59:59
//            as at 10:00:00 whatever the shop's typeface does with its digits: nothing moves when a second passes.

const UNITS = {
  en: { d: 'days', h: 'hrs', m: 'min', s: 'sec', label: (t) => `Ends in ${t}` },
  ar: { d: 'يوم', h: 'ساعة', m: 'دقيقة', s: 'ثانية', label: (t) => `ينتهي خلال ${t}` },
}

export default function Countdown({ endsAt, offset = 0, lang = 'en', variant = 'boxes', className = '' }) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(id)
  }, [])

  const t = UNITS[lang === 'ar' ? 'ar' : 'en']
  const left = msLeft(endsAt, now, offset)
  const { d, h, m, s } = countdownParts(left)
  // read out once, not every second: the visible digits are hidden from assistive tech
  const spoken = t.label(d > 0 ? `${d} ${t.d} ${h} ${t.h}` : `${h} ${t.h} ${m} ${t.m}`)

  if (variant === 'inline') {
    return (
      <span className={`pm-clock ${className}`} role="timer" aria-label={spoken} dir="ltr" suppressHydrationWarning>
        {clockText(left)
          .split('')
          .map((ch, i) => (
            <span key={i} className={ch === ':' ? 'pm-clock__s' : 'pm-clock__d'} aria-hidden="true" suppressHydrationWarning>
              {ch}
            </span>
          ))}
      </span>
    )
  }

  const boxes = [d > 0 ? [d, t.d] : null, [h, t.h], [m, t.m], [s, t.s]].filter(Boolean)
  return (
    <span className={`pm-count ${className}`} role="timer" aria-label={spoken} dir="ltr" suppressHydrationWarning>
      {boxes.map(([value, name]) => (
        <span className="pm-count__box" key={name} aria-hidden="true">
          <span className="pm-count__num" suppressHydrationWarning>
            {two(value)}
          </span>
          <span className="pm-count__unit">{name}</span>
        </span>
      ))}
    </span>
  )
}
