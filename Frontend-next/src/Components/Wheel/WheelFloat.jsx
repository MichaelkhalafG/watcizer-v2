'use client'
import { useEffect, useState } from 'react'
import Link from '../LocaleLink'
import { useUIStore } from '../../Store/uiStore'
import { useCampaigns } from '../../Hooks/queries/usePromo'
import { FloatingSlot } from '../Floating/FloatingStack'
import { SPIN_PATH, dismissFloat, isFloatDismissed, kindOf, readSpin } from '../../lib/wheel'
import './wheel.css'

// THE WHEEL'S FLOATING BUTTON (2026-11-07; compact since 2026-11-08, WATCHIZER ONLY): a LINK to /spin (no modal) in
// the page's floating corner above the Hot Deals pill. On a phone it is a 48 px round button — a small coloured wheel
// in a gold ring, a soft pulse and a shine now and then, NO text — so it never covers a page's own buttons (the hero's
// "Explore the watch" was under the old pill). On a computer the same button grows a short label on hover or focus.
// Shown only while the wheel is switched on — read from the campaigns answer every page already holds
// (`wheel.enabled`), so it costs no request — and not on the cart, the checkout, a product page or /spin itself
// (FloatingStack + src/lib/floating.js). Dismissible: the small × hides it for the session. A visitor who already
// spun this round is invited to their result instead ("Your code", "Your invite", or — after a "better luck" — "Your
// spin"); the same link opens their card. The label promises nothing: not every spin wins (2026-11-09).

const STR = {
  en: { spin: 'Spin the wheel', code: 'Your discount code', invite: 'Your invite code', none: 'Your spin', close: 'Hide' },
  ar: { spin: 'أدِر العجلة', code: 'كود خصمك', invite: 'كود دعوتك', none: 'لفّتك', close: 'إخفاء' },
}

// the seven colours of the client's wheel: the button's own small disc (the page's real colours come from the dashboard)
const DISC = ['#0F9D6E', '#1F4FD8', '#6B7280', '#C8102E', '#7B3FE4', '#E0457B', '#F28C28']

export default function WheelFloat() {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const { data } = useCampaigns()
  const enabled = data?.wheel?.enabled === true
  const campaign = data?.wheel?.campaign || null
  const [dismissed, setDismissed] = useState(false)
  const [mine, setMine] = useState(null) // the kind of result this device holds for THIS round, or null
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mount; the server render has no button
    setDismissed(isFloatDismissed())
    // "your code" only for a spin of THIS round: after a new round the button invites again
    const kept = readSpin(campaign)
    setMine(kept ? (kindOf(kept) === 'gift' ? 'code' : kindOf(kept)) : null)
  }, [campaign])

  if (!enabled || dismissed) return null

  const label = mine ? t[mine] : t.spin
  const step = 360 / DISC.length
  return (
    <FloatingSlot name="wheel" order={10}>
      <div className="wz-wfloat" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
        <Link className={`wz-wfloat__btn${mine ? ' is-mine' : ''}`} href={SPIN_PATH} prefetch={false} aria-label={label} title={label}>
          <span className="wz-wfloat__ring" aria-hidden="true" />
          <span className="wz-wfloat__disc" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="32" height="32">
              {DISC.map((c, i) => {
                const a0 = ((i * step - 90) * Math.PI) / 180
                const a1 = (((i + 1) * step - 90) * Math.PI) / 180
                const x0 = 16 + 14 * Math.cos(a0)
                const y0 = 16 + 14 * Math.sin(a0)
                const x1 = 16 + 14 * Math.cos(a1)
                const y1 = 16 + 14 * Math.sin(a1)
                return <path key={c} d={`M16 16 L${x0.toFixed(2)} ${y0.toFixed(2)} A14 14 0 0 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z`} fill={c} />
              })}
              <circle cx="16" cy="16" r="14" fill="none" stroke="#F1DFAE" strokeWidth="1.5" />
              <circle cx="16" cy="16" r="3.2" fill="#F1DFAE" />
            </svg>
          </span>
          <span className="wz-wfloat__label">{label}</span>
        </Link>
        <button
          type="button"
          className="wz-wfloat__close"
          aria-label={t.close}
          onClick={() => {
            dismissFloat()
            setDismissed(true)
          }}
        >
          ×
        </button>
      </div>
    </FloatingSlot>
  )
}
