'use client'
import { useEffect, useState } from 'react'
import { clockText, countdownParts, msLeft, two } from '../../lib/campaign'

// The countdown of a live campaign (Part 2, 2026-11-05). A TWIN.
//
// It counts to the campaign's real end on the server's clock (`offset`), once a second, and stops at zero —
// the parent removes the whole surface at that moment (useLiveCampaign), so a finished countdown is never
// left on the page and nothing here restarts.
//
// THE FIRST RENDER IS THE ANSWER'S OWN SECOND (2026-11-07, React #418 on the live site). The countdown is in the
// server HTML (nothing arrives late and shifts the page), and that HTML is held for minutes by the storefront's
// cache — so the second it printed and the second the browser hydrated on differed, and at a day boundary (the
// `3d` prefix, the day box) the two differed in SHAPE, which no `suppressHydrationWarning` covers. The first render
// now reads `now` — the moment of core's answer, carried in the same HTML as the campaign (useLiveCampaign
// `serverNow`) — identical on the server and in the hydration render; the first tick, right after mount, moves it
// to the browser's clock. No hydration warning is suppressed any more.
//
// `boxes`  — a unit per box with its name under it: the campaign page and the home sliders.
// `inline` — `02:14:58` as a clock: the header switch and the menu's Sale panel. Every character sits in a cell
//            of fixed width (promo.css), so the clock — and the control around it — is exactly as wide at 09:59:59
//            as at 10:00:00 whatever the shop's typeface does with its digits: nothing moves when a second passes.

const UNITS = {
  en: { d: 'days', h: 'hrs', m: 'min', s: 'sec', label: (t) => `Ends in ${t}` },
  ar: { d: 'يوم', h: 'ساعة', m: 'دقيقة', s: 'ثانية', label: (t) => `ينتهي خلال ${t}` },
}

export default function Countdown({ endsAt, offset = 0, now: serverNow = null, lang = 'en', variant = 'boxes', className = '' }) {
  // the answer's second when the page knows it (the same number on the server and in the hydration render), else this device's
  const [now, setNow] = useState(() => (Number.isFinite(serverNow) && serverNow > 0 ? serverNow : Date.now()))
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- this device's clock is only read after mount (the first render is the answer's second)
    setNow(Date.now())
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
      <span className={`pm-clock ${className}`} role="timer" aria-label={spoken} dir="ltr">
        {clockText(left)
          .split('')
          .map((ch, i) => (
            <span key={i} className={ch === ':' ? 'pm-clock__s' : 'pm-clock__d'} aria-hidden="true">
              {ch}
            </span>
          ))}
      </span>
    )
  }

  const boxes = [d > 0 ? [d, t.d] : null, [h, t.h], [m, t.m], [s, t.s]].filter(Boolean)
  return (
    <span className={`pm-count ${className}`} role="timer" aria-label={spoken} dir="ltr">
      {boxes.map(([value, name]) => (
        <span className="pm-count__box" key={name} aria-hidden="true">
          <span className="pm-count__num">{two(value)}</span>
          <span className="pm-count__unit">{name}</span>
        </span>
      ))}
    </span>
  )
}
