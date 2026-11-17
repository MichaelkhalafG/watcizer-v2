'use client'
import { useEffect, useState } from 'react'
import Link from '../LocaleLink'
import { useUIStore } from '../../Store/uiStore'
import { useCampaigns } from '../../Hooks/queries/usePromo'
import { FloatingSlot } from '../Floating/FloatingStack'
import { SPIN_PATH, dismissFloat, isFloatDismissed, kindOf, readSpin } from '../../lib/wheel'
import { WHEEL_BRAND } from '../../content/wheelBrand'
import './wheel.css'

// THE WHEEL'S FLOATING BUTTON (2026-11-07; redrawn 2026-11-13; both shops, a twin since 2026-11-14): a LINK to /spin (no modal) in the
// page's floating corner above the Hot Deals pill. A small copy of OUR wheel — the dashboard's segment colours, a gold
// rim with pegs, a pointer at the top and a still hub that says GO — turning slowly with a short faster "tease" every
// few seconds, under a two-line label ("SPIN TO / WIN", "لف / واكسب") kept small beside the wheel. Every motion is a
// CSS transform on its own HTML layer (the compositor runs it; no script, no repaint of the SVG), and all of it stops
// under prefers-reduced-motion. Shown only while the wheel is switched on — read from the campaigns answer every page
// already holds (`wheel.enabled`, and `wheel.colors` for the segments), so it costs no request — and not on the cart,
// the checkout, a product page or /spin itself (FloatingStack + src/lib/floating.js). Dismissible: the small × hides it
// for the session. A visitor who already spun this round is invited to their result instead ("Your code", "Your
// invite", or — after a "better luck" — "Your spin"); the same link opens their card.

const STR = {
  en: {
    spin: 'Spin the wheel', code: 'Your discount code', invite: 'Your invite code', none: 'Your spin', close: 'Hide', hub: 'GO',
    lines: { spin: ['SPIN TO', 'WIN'], code: ['YOUR', 'CODE'], invite: ['YOUR', 'INVITE'], none: ['YOUR', 'SPIN'] },
  },
  ar: {
    spin: 'أدِر العجلة', code: 'كود خصمك', invite: 'كود دعوتك', none: 'لفّتك', close: 'إخفاء', hub: 'يلا',
    lines: { spin: ['لف', 'واكسب'], code: ['كود', 'خصمك'], invite: ['كود', 'دعوتك'], none: ['شوف', 'لفّتك'] },
  },
}

// the SHOP's palette (src/content/wheelBrand.js — Watchizer: the client's seven wheel colours; Brand Fashion: its own) for
// a wheel the answer does not describe, or a colour it lacks
const DISC = WHEEL_BRAND.palette
const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i

/** The segments' colours, in the wheel's order: the dashboard's when the answer carries them, the palette otherwise. */
export function launcherColors(colors) {
  if (!Array.isArray(colors) || colors.length < 2) return DISC
  return colors.slice(0, 16).map((c, i) => (typeof c === 'string' && HEX.test(c.trim()) ? c.trim() : DISC[i % DISC.length]))
}

const C = 32 // the drawing's centre, in a 64-unit square
const R = 27 // the segments' radius; the rim is drawn on it

function Disc({ colors }) {
  const step = 360 / colors.length
  const at = (deg, r) => {
    const a = ((deg - 90) * Math.PI) / 180
    return [(C + r * Math.cos(a)).toFixed(2), (C + r * Math.sin(a)).toFixed(2)]
  }
  return (
    <svg className="wz-wfloat__disc" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" focusable="false">
      {colors.map((c, i) => {
        const [x0, y0] = at(i * step, R)
        const [x1, y1] = at((i + 1) * step, R)
        return <path key={i} d={`M${C} ${C}L${x0} ${y0}A${R} ${R} 0 0 1 ${x1} ${y1}Z`} fill={c} />
      })}
      {/* a soft shade from the hub outwards, so the colours read as a disc and not as a flat pie */}
      <circle cx={C} cy={C} r={R} fill="url(#wz-wf-shade)" />
      <circle cx={C} cy={C} r={R + 1.5} fill="none" stroke="#F1DFAE" strokeWidth="3" />
      <circle cx={C} cy={C} r={R + 3} fill="none" stroke="#2a0f45" strokeWidth="0.8" opacity="0.6" />
      {colors.map((_, i) => {
        const [x, y] = at(i * step, R + 1.5)
        return <circle key={i} cx={x} cy={y} r="1.5" fill="#fff8dc" stroke="#a7792b" strokeWidth="0.5" />
      })}
      <defs>
        <radialGradient id="wz-wf-shade">
          <stop offset="0.35" stopColor="#000" stopOpacity="0" />
          <stop offset="1" stopColor="#000" stopOpacity="0.22" />
        </radialGradient>
      </defs>
    </svg>
  )
}

export default function WheelFloat() {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const { data } = useCampaigns()
  const enabled = data?.wheel?.enabled === true
  const campaign = data?.wheel?.campaign || null
  const colors = launcherColors(data?.wheel?.colors)
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

  const [small, big] = t.lines[mine || 'spin']
  const label = mine ? t[mine] : t.spin
  return (
    <FloatingSlot name="wheel" order={10}>
      <div className="wz-wfloat" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
        {/* named by the words it shows plus the action, sr-only (2026-11-17, Lighthouse label-content-name-mismatch: an
            aria-label that left out "SPIN TO WIN" failed voice control) — the wheel drawing stays hidden */}
        <Link className={`wz-wfloat__btn wz-wfloat--md${mine ? ' is-mine' : ''}`} href={SPIN_PATH} prefetch={false} title={label}>
          <span className="wz-wfloat__label">
            <span className="wz-wfloat__small">{small}</span> <span className="wz-wfloat__big">{big}</span>
            <span className="wz-sr-only">: {label}</span>
          </span>
          <span className="wz-wfloat__wheel" aria-hidden="true">
            <span className="wz-wfloat__ring" />
            <span className="wz-wfloat__turn">
              <Disc colors={colors} />
            </span>
            <svg className="wz-wfloat__hub" viewBox="0 0 64 64" width="64" height="64" focusable="false">
              <path d="M32 11.5 26.6 1.5h10.8Z" fill="#F1DFAE" stroke="#2a0f45" strokeWidth="1.2" strokeLinejoin="round" />
              <circle cx="32" cy="32" r="11.5" fill="#2a0f45" stroke="#F1DFAE" strokeWidth="2" />
              <text x="32" y="32" textAnchor="middle" dominantBaseline="central" className="wz-wfloat__go">
                {t.hub}
              </text>
            </svg>
          </span>
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
