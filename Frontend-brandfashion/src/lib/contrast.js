// WCAG 2.x colour arithmetic — pure functions, no DOM, no rounding until the caller asks.
//
// The tokens page (app/(dev)/tokens) is a GATE, not a swatch sheet: every ratio it prints is
// computed here from the token values in app/globals.css at request time. Nothing on that page
// may carry a hand-written number, which is why this module exists on its own and is the only
// place the arithmetic lives.
//
// References: WCAG 2.1 §1.4.3 (text 4.5:1 AA, 3:1 large), §1.4.11 (non-text 3:1), and the
// relative-luminance definition (sRGB, the 0.03928 / 12.92 / 2.4 formula). L* is CIE 1976 from
// luminance Y with the D65 white (Y = 1), the figure spec §4.3 quotes for the aisle tints.

const HEX = /^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i
const RGB = /^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+%?)\s*)?\)$/i

/** `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()` → { r, g, b, a } with channels 0–255 and a 0–1. */
export function parseColor(input) {
  const s = String(input ?? '').trim()
  let m = HEX.exec(s)
  if (m) {
    let h = m[1]
    if (h.length === 3 || h.length === 4) h = h.split('').map((c) => c + c).join('')
    const n = parseInt(h.slice(0, 6), 16)
    const a = h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255, a }
  }
  m = RGB.exec(s)
  if (m) {
    let a = 1
    if (m[4] != null) a = m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4])
    return { r: +m[1], g: +m[2], b: +m[3], a }
  }
  throw new Error(`contrast: cannot parse colour "${s}"`)
}

/** Alpha-blend `fg` over an OPAQUE `bg` (the hairline `line` token is rgba and only means something on a ground). */
export function composite(fg, bg) {
  const a = fg.a ?? 1
  return {
    r: fg.r * a + bg.r * (1 - a),
    g: fg.g * a + bg.g * (1 - a),
    b: fg.b * a + bg.b * (1 - a),
    a: 1,
  }
}

function channel(c) {
  const v = c / 255
  return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
}

/** WCAG relative luminance, 0 (black) – 1 (white). */
export function luminance({ r, g, b }) {
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

/** Contrast ratio of `fg` on `bg` (strings or parsed), 1–21. A translucent `fg` is composited over `bg` first. */
export function contrast(fg, bg) {
  const B = typeof bg === 'string' ? parseColor(bg) : bg
  const ground = (B.a ?? 1) < 1 ? composite(B, { r: 255, g: 255, b: 255, a: 1 }) : B
  const F0 = typeof fg === 'string' ? parseColor(fg) : fg
  const F = (F0.a ?? 1) < 1 ? composite(F0, ground) : F0
  const l1 = luminance(F)
  const l2 = luminance(ground)
  const [hi, lo] = l1 >= l2 ? [l1, l2] : [l2, l1]
  return (hi + 0.05) / (lo + 0.05)
}

/** CIE L* of an opaque colour (D65), 0–100 — the lightness spec §4.3 quotes for the tints. */
export function lightness(color) {
  const c = typeof color === 'string' ? parseColor(color) : color
  const y = luminance(c)
  const f = y > 216 / 24389 ? Math.cbrt(y) : (24389 / 27) * y / 116 + 16 / 116
  return 116 * f - 16
}

/** Two-decimal string, the form the spec prints ratios in. */
export const r2 = (x) => (Math.round(x * 100) / 100).toFixed(2)

/**
 * The requirement a pair is held to, by what the colour DOES:
 *   text        — WCAG 1.4.3 AA, 4.5:1 (the spec forbids text under 12px and lighter than ink-3, so
 *                 there is no "large text" discount anywhere on this shop)
 *   ui          — WCAG 1.4.11, 3:1: borders a user must see, focus rings, swatch rings
 *   decorative  — exempt by design (spec §4.1: `line` carries no meaning)
 *   disabled    — exempt: WCAG 1.4.3 and 1.4.11 exclude inactive user-interface components
 *   forbidden   — a pair the spec names as NOT permitted; measured so the reason is visible
 */
export const REQUIREMENT = Object.freeze({ text: 4.5, ui: 3, decorative: null, disabled: null, forbidden: 4.5 })

/** 'pass' | 'fail' | 'exempt' for a ratio against a kind. */
export function verdict(ratio, kind) {
  const need = REQUIREMENT[kind]
  if (need == null) return 'exempt'
  return ratio >= need ? 'pass' : 'fail'
}
