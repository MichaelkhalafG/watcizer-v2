'use client'
import { useEffect, useRef, useState } from 'react'
import { segmentAt, segmentLabel } from '../../lib/wheel'

// THE WHEEL ITSELF (2026-11-07; in colour since 2026-11-08, WATCHIZER ONLY): an SVG of N segments, each in the colour
// the team gave it on the dashboard, lit from the top-left (one sheen over the disc) and sunk at the rim (one shade),
// a gold rim with a ring of lamps that chase while the disc turns, a gold hub with the W, and a gold pointer at the top
// that TICKS as each separator passes under it. The parent sets `rotation` (degrees) and `phase`; the turn is one CSS
// transition on the disc (wheel.css), so the browser composites it. The pointer reads the disc's live angle each frame
// while it turns and nudges when the segment changes.
//
// Each segment carries two lines read from the centre outwards — the big thing (10%, FREE, GIFT, LUCK) and the small
// word under it (2ND WATCH, SHIPPING, PERFUME, NEXT TIME) — made from the segment's type and the team's label
// (src/lib/wheel.js segmentLabel), so seven prizes of five kinds stay legible on a phone. A gift whose list is out of
// stock is drawn dimmer: it is on the wheel, but the server will not land on it.
//
// The glow and the turning ring of light behind the disc are NOT here since 2026-11-10: they live in the page's fixed
// glow layer (SpinPage, `.sp-glow`), which follows the disc's centre — the stage clips its box and would cut them.
//
// Decorative to assistive tech (the result is announced by the page); the label carries the segment list once.

const SIZE = 320
const R = 150
const CX = SIZE / 2
const CY = SIZE / 2
const LAMPS = 24

const polar = (deg, r) => {
  const a = ((deg - 90) * Math.PI) / 180
  return [CX + r * Math.cos(a), CY + r * Math.sin(a)]
}

function segmentPath(index, count, r = R) {
  const a0 = (index * 360) / count
  const a1 = ((index + 1) * 360) / count
  const [x0, y0] = polar(a0, r)
  const [x1, y1] = polar(a1, r)
  const large = 360 / count > 180 ? 1 : 0
  return `M ${CX} ${CY} L ${x0.toFixed(2)} ${y0.toFixed(2)} A ${r} ${r} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z`
}

export default function SpinWheel({ segments, rotation = 0, lang = 'en', phase = 'idle', highlight = null }) {
  const count = Math.max(1, segments.length)
  const discRef = useRef(null)
  const [tick, setTick] = useState(0)
  const spinning = phase === 'spinning'

  // the pointer ticks on every separator that passes while the disc turns; a short buzz where the device has one
  useEffect(() => {
    if (!spinning) return undefined
    let frame = 0
    let last = null
    const read = () => {
      const el = discRef.current
      if (el) {
        const m = new DOMMatrixReadOnly(getComputedStyle(el).transform)
        const deg = (Math.atan2(m.b, m.a) * 180) / Math.PI
        const seg = segmentAt(deg, count)
        if (last !== null && seg !== last) setTick((n) => n + 1)
        last = seg
      }
      frame = requestAnimationFrame(read)
    }
    frame = requestAnimationFrame(read)
    return () => cancelAnimationFrame(frame)
  }, [spinning, count])

  const names = segments.map((s) => s.label?.[lang] ?? s.label?.en ?? '').join(' · ')
  const fontScale = count > 8 ? 0.72 : count > 6 ? 0.86 : 1

  return (
    <div className={`wz-wheel wz-wheel--${phase}`} role="img" aria-label={lang === 'ar' ? `عجلة الجوائز: ${names}` : `Prize wheel: ${names}`}>
      <div className="wz-wheel__pointer" data-tick={tick % 2} aria-hidden="true">
        <svg viewBox="0 0 40 46" width="40" height="46">
          <defs>
            <linearGradient id="wzPointerGold" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stopColor="#f6e7bd" />
              <stop offset="1" stopColor="#a8853f" />
            </linearGradient>
          </defs>
          <path d="M20 44 L4 10 Q20 0 36 10 Z" fill="url(#wzPointerGold)" stroke="#fff6dc" strokeWidth="1.2" />
          <circle cx="20" cy="14" r="5" fill="#2a0f45" stroke="#fff0b0" strokeWidth="1" />
        </svg>
      </div>
      <div className="wz-wheel__disc" ref={discRef} style={{ transform: `rotate(${rotation}deg)` }}>
        <svg viewBox={`0 0 ${SIZE} ${SIZE}`} width="100%" height="100%" aria-hidden="true" focusable="false">
          <defs>
            <radialGradient id="wzHubGold" cx="50%" cy="38%" r="62%">
              <stop offset="0" stopColor="#f7e9c2" />
              <stop offset="0.55" stopColor="#c8a45c" />
              <stop offset="1" stopColor="#7d6230" />
            </radialGradient>
            <linearGradient id="wzRimGold" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor="#f1dfae" />
              <stop offset="0.5" stopColor="#b48e46" />
              <stop offset="1" stopColor="#f1dfae" />
            </linearGradient>
            {/* one light over the whole disc: brighter at the top-left, clear at the centre, shaded at the rim */}
            <radialGradient id="wzSheen" cx="32%" cy="26%" r="80%">
              <stop offset="0" stopColor="#ffffff" stopOpacity="0.34" />
              <stop offset="0.45" stopColor="#ffffff" stopOpacity="0.06" />
              <stop offset="1" stopColor="#000000" stopOpacity="0.22" />
            </radialGradient>
            <radialGradient id="wzRimShade" cx="50%" cy="50%" r="50%">
              <stop offset="0.82" stopColor="#000000" stopOpacity="0" />
              <stop offset="1" stopColor="#000000" stopOpacity="0.45" />
            </radialGradient>
            <clipPath id="wzDiscClip">
              <circle cx={CX} cy={CY} r={R} />
            </clipPath>
          </defs>
          {/* the rim */}
          <circle cx={CX} cy={CY} r={R + 9} fill="url(#wzRimGold)" />
          <circle cx={CX} cy={CY} r={R + 2.5} fill="#2a0f45" />
          {/* the segments, each in its own colour; a gift with nothing in stock a shade dimmer */}
          {segments.map((s, i) => (
            <path key={s.id ?? i} d={segmentPath(i, count)} fill={s.color || '#444'} className={`wz-wheel__seg${highlight === i ? ' is-won' : ''}${s.drawable === false ? ' is-off' : ''}`} />
          ))}
          <g clipPath="url(#wzDiscClip)">
            <circle cx={CX} cy={CY} r={R} fill="url(#wzSheen)" />
            <circle cx={CX} cy={CY} r={R} fill="url(#wzRimShade)" />
          </g>
          {/* the separators, thin and light */}
          {segments.map((s, i) => {
            const [x, y] = polar((i * 360) / count, R)
            return <line key={`l-${s.id ?? i}`} x1={CX} y1={CY} x2={x} y2={y} stroke="#fff" strokeOpacity="0.55" strokeWidth="1.2" />
          })}
          {/* the labels, one per segment, read from the centre outwards; the lower half turned so no word is upside down */}
          {segments.map((s, i) => {
            const mid = ((i + 0.5) * 360) / count
            const [x, y] = polar(mid, R * 0.64)
            const { big, small } = segmentLabel(s, lang)
            const turn = mid > 90 && mid < 270 ? mid + 180 : mid
            const wordy = big.length > 3
            return (
              <g key={`t-${s.id ?? i}`} transform={`rotate(${turn} ${x.toFixed(2)} ${y.toFixed(2)}) translate(${x.toFixed(2)} ${y.toFixed(2)}) scale(${fontScale})`} className="wz-wheel__label">
                <text x="0" y="-3" textAnchor="middle" className={`wz-wheel__big${wordy ? ' wz-wheel__big--words' : ''}`}>
                  {big}
                </text>
                <text x="0" y="15" textAnchor="middle" className="wz-wheel__small">
                  {small}
                </text>
              </g>
            )
          })}
          {/* the lamps on the rim: two sets, lit in turn */}
          {Array.from({ length: LAMPS }).map((_, i) => {
            const [x, y] = polar((i * 360) / LAMPS, R + 5.8)
            return <circle key={i} cx={x} cy={y} r="2.1" className={`wz-wheel__lamp wz-wheel__lamp--${i % 2}`} />
          })}
          {/* the hub */}
          <circle cx={CX} cy={CY} r="36" fill="url(#wzHubGold)" />
          <circle cx={CX} cy={CY} r="28" fill="#2a0f45" stroke="#fff0b0" strokeWidth="1.2" />
          <text x={CX} y={CY + 10} textAnchor="middle" className="wz-wheel__hub">
            W
          </text>
        </svg>
      </div>
    </div>
  )
}
