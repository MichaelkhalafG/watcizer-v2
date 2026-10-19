// `mix-blend-mode: multiply`, computed — for the card dev page's post-blend measurements (B3).
//
// Spec §8.3 lays the product photo over the aisle tint with multiply. Multiply is per-channel
// (a × b / 255), so the SURFACE a shopper actually sees under the photo is darker than the tint
// wherever the photo is not pure white: a white studio background dissolves into the tint
// unchanged, a grey one becomes grey × tint. Anything laid over that surface (the badge pill) sits
// on the blended colour, not on the token — which is why the flat ratios of the tokens page do not
// cover it and the card page measures the blend.

import { parseColor } from './contrast'

const hex2 = (n) => Math.round(Math.max(0, Math.min(255, n))).toString(16).padStart(2, '0')

/** multiply(top, bottom) → '#rrggbb' of the blended, opaque result (both inputs opaque). */
export function multiply(top, bottom) {
  const a = typeof top === 'string' ? parseColor(top) : top
  const b = typeof bottom === 'string' ? parseColor(bottom) : bottom
  return `#${hex2((a.r * b.r) / 255)}${hex2((a.g * b.g) / 255)}${hex2((a.b * b.b) / 255)}`.toUpperCase()
}
