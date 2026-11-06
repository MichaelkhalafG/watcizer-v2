'use client'
import { useId } from 'react'

// The glyphs and the two pieces of art the campaign surfaces draw (2026-11-05). A TWIN. Inline SVG: no icon
// font, no request, and the art's motion is plain CSS (promo.css), switched off under prefers-reduced-motion.

export function BoltIcon({ size = 18 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true">
      <path fill="currentColor" d="M13.6 1.5 4.2 13.3a.6.6 0 0 0 .5 1h5.6l-1.5 8a.4.4 0 0 0 .7.3l9.6-12a.6.6 0 0 0-.5-1h-5.5l1.2-7.8a.4.4 0 0 0-.7-.3z" />
    </svg>
  )
}

export function FlameIcon({ size = 18 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true">
      <path
        fill="currentColor"
        d="M12.7 1.6c.5 3.3-.9 5.2-2.6 7C8.300 10.500 6 12.400 6 15.800 6 19.500 8.800 22.400 12.200 22.400c3.500 0 6.200-2.700 6.200-6.300 0-2.300-1-4-2.200-5.500-.4 1.300-1 2.200-2 2.800.6-3.900-.200-8.300-1.500-11.800zm-.5 12.600c1.400 1.200 2.300 2.400 2.300 4 0 1.500-1.100 2.600-2.400 2.600s-2.400-1.100-2.400-2.600c0-1.600 1.100-2.600 2.500-4z"
      />
    </svg>
  )
}

/** Points to the inline END by default; `flip` turns it to the inline START. */
export function Chevron({ flip = false, size = 18 }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      style={flip ? { transform: 'scaleX(-1)' } : undefined}
    >
      <path d="m9 6 6 6-6 6" />
    </svg>
  )
}

export function GridIcon({ size = 16 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
      <path d="M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z" />
    </svg>
  )
}

/**
 * THE FLAME (Hot Deals): three tongues, one inside the other — red, orange, a white-hot core — each flickering
 * to its own rhythm, so the whole never repeats on a beat the eye can count. Decorative: hidden from
 * assistive tech. The gradients carry a per-instance id, so two flames on one page never share (or lose) a fill.
 */
export function FlameArt({ className = '' }) {
  const id = useId().replace(/:/g, '')
  return (
    <svg className={`pm-flameart ${className}`} viewBox="0 0 200 260" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id={`${id}a`} x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stopColor="#ff2f0a" />
          <stop offset="0.55" stopColor="#ff6a13" />
          <stop offset="1" stopColor="#ffb327" />
        </linearGradient>
        <linearGradient id={`${id}b`} x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stopColor="#ff7a14" />
          <stop offset="0.6" stopColor="#ffb52a" />
          <stop offset="1" stopColor="#ffe066" />
        </linearGradient>
        <linearGradient id={`${id}c`} x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stopColor="#ffd84d" />
          <stop offset="1" stopColor="#fffbe8" />
        </linearGradient>
      </defs>
      <path
        className="pm-flameart__l pm-flameart__l--1"
        fill={`url(#${id}a)`}
        d="M100 4C112 52 168 84 172 150C176 212 142 256 100 256C58 256 24 214 28 156C30 122 50 100 62 78C66 100 74 112 86 118C78 78 84 38 100 4Z"
      />
      <path
        className="pm-flameart__l pm-flameart__l--2"
        fill={`url(#${id}b)`}
        d="M102 78C110 112 148 132 148 178C148 222 126 250 100 250C74 250 52 224 54 184C55 162 68 146 78 132C80 148 86 156 94 160C90 132 94 104 102 78Z"
      />
      <path
        className="pm-flameart__l pm-flameart__l--3"
        fill={`url(#${id}c)`}
        d="M100 150C106 172 126 184 126 208C126 232 114 246 100 246C86 246 74 232 74 210C74 196 82 186 88 178C90 188 94 192 98 194C96 178 97 164 100 150Z"
      />
    </svg>
  )
}

/** THE BOLT (Flash Sale): one strike, lit from inside, with the thin arcs a discharge leaves beside it. Decorative. */
export function BoltArt({ className = '' }) {
  const id = useId().replace(/:/g, '')
  return (
    <svg className={`pm-boltart ${className}`} viewBox="0 0 200 260" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id={`${id}a`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#fffbe0" />
          <stop offset="0.45" stopColor="#ffe234" />
          <stop offset="1" stopColor="#ffb400" />
        </linearGradient>
      </defs>
      <path className="pm-boltart__arc pm-boltart__arc--1" fill="none" stroke="#46d7ff" strokeWidth="2" strokeLinecap="round" d="M22 96l14 10-8 16 18 8" />
      <path className="pm-boltart__arc pm-boltart__arc--2" fill="none" stroke="#46d7ff" strokeWidth="2" strokeLinecap="round" d="M176 150l-12 12 10 12-16 14" />
      <path className="pm-boltart__bolt" fill={`url(#${id}a)`} d="M118 2 36 142h54L68 258l98-152h-58z" />
    </svg>
  )
}

/** Sparks rising off a fire: seven dots, placed and timed by promo.css. Decorative. */
export function Embers() {
  return (
    <span className="pm-embers" aria-hidden="true">
      <i />
      <i />
      <i />
      <i />
      <i />
      <i />
      <i />
    </span>
  )
}
