/*
 * THE SCROLL TIMELINE of the experience page — pure numbers, no three.js (2026-10-28, Watchizer only).
 *
 * The whole page is one scroll, p in [0, 1], over twelve SECTIONS. Each section states its length (vh) and the
 * KEYFRAMES it ends on (or passes through): where the camera looks from and at, how far apart each disassembly
 * step is, the light and the backdrop, and the watch's turn. Every track is sampled at p by an eased interpolation
 * between the keyframes that bracket it, so one scroll position is one frame, and scrolling back is the exact
 * reverse. Nothing here knows the model's geometry: a camera keyframe names an ANCHOR (a part), which the stage
 * resolves to a position at run time (watchModel.js), so a macro shot follows the part even while it is apart.
 *
 * THE DISASSEMBLY STEPS ARE THE MODEL'S (explode.js STEPS): bracelet & clasp, caseback, movement, crystal & bezel,
 * dial & sub-dials, hands, crown & pushers — the exporter's own `part.step` values and offsets.
 */
import { STEPS } from './explode'

export const clamp01 = (v) => Math.min(1, Math.max(0, Number.isFinite(v) ? v : 0))
export const smooth = (t) => {
  const x = clamp01(t)
  return x * x * (3 - 2 * x)
}
const N = STEPS.length
const zeros = () => STEPS.map(() => 0)
const ones = () => STEPS.map(() => 1)
const apart = (...ks) => STEPS.map((_, k) => (ks.includes(k) ? 1 : 0))

/*
 * Camera keys: `at` an anchor name, `dir` the direction the camera looks FROM (the model's frame: +y the dial,
 * +x the crown, −z twelve o'clock), `dist` in millimetres, `fov` vertical degrees, `side` where the watch sits
 * on a wide screen (+1 right of a left-hand text column, −1 left, 0 centre) and `lift` where it sits on a portrait
 * screen (+ lower, under the text; − higher, above a caption). Light keys: `bg` the page backdrop, `key` the
 * key light's intensity and `keyX` its x (a sweep when it changes), `env` the environment's intensity, `exp` the
 * exposure, `shadow` the contact shadow's opacity. `turn` is radians about the vertical axis. `explode` is
 * one amount per step; `stagger` on a key spreads the steps' arrivals across the move (the reassembly).
 */
export const SECTIONS = [
  {
    id: 'hero',
    vh: 100,
    keys: [{ t: 1, cam: { at: 'watch', dir: [0.0, 0.94, 0.34], dist: 274, fov: 30, side: 0, lift: 0, pDistMul: 1.25, pLift: -0.2 }, explode: zeros(), light: { bg: '#2c1c11', key: 1.1, keyX: -0.2, env: 1.0, exp: 0.92, shadow: 0.6 }, turn: 0, scene: { top: '#4a2f17', bottom: '#1b1209', light: '#e8a552', beam: 0.35, beamAngle: 0.4, haze: 0.5, flare: 0, vignette: 0.33, glow: 0.35, particles: 0.5, lightPos: [0.72, 0.78], word: 0, wordId: 'hero', wordX: 0, wordY: 0 } }],
  },
  {
    id: 'case',
    vh: 300,
    keys: [{ t: 1, cam: { at: 'case', dir: [0.55, 0.42, 0.72], dist: 225, fov: 30, side: -1, lift: -0.35 }, light: { bg: '#111214', key: 1.2, keyX: 0.4, env: 1.0, exp: 0.92, shadow: 0.55 }, turn: Math.PI * 2, scene: { top: '#432a15', bottom: '#1a1109', light: '#e8a552', beam: 0.55, beamAngle: 1.1, haze: 0.55, flare: 0, vignette: 0.30, glow: 0.3, particles: 0.7, lightPos: [0.2, 0.85], word: 0.9, wordId: 'case', wordX: 0.1, wordY: 0.05 } }],
  },
  {
    id: 'dial',
    vh: 280,
    keys: [{ t: 1, cam: { at: 'dial', dir: [0.22, 0.9, 0.38], dist: 78, fov: 28, side: 1.6, lift: -0.45 }, light: { bg: '#15171b', key: 1.15, keyX: 0.4, env: 0.9, exp: 0.95, shadow: 0.4 }, turn: Math.PI * 2 + 0.3, scene: { top: '#3d2613', bottom: '#1a1109', light: '#f0c27a', beam: 0.3, beamAngle: 0.6, haze: 0.35, flare: 0, vignette: 0.36, glow: 0.25, particles: 0.3, lightPos: [0.75, 0.3], word: 0.8, wordId: 'dial', wordX: -0.2, wordY: 0.15 } }],
  },
  {
    id: 'subdials',
    vh: 280,
    keys: [
      { t: 0.5, cam: { at: 'subdial6', dir: [-0.3, 0.72, 0.62], dist: 74, fov: 26, side: -1.6, lift: -0.45 }, light: { bg: '#101114', key: 1.2, keyX: 0.4, env: 0.9, exp: 0.95, shadow: 0.3 } },
      { t: 1, cam: { at: 'hands', dir: [0.42, 0.68, -0.6], dist: 72, fov: 26, side: -1.6, lift: -0.45 }, turn: Math.PI * 2 + 0.6, scene: { top: '#3a2412', bottom: '#1a1109', light: '#f0c27a', beam: 0.25, beamAngle: 2.2, haze: 0.3, flare: 0, vignette: 0.39, glow: 0.2, particles: 0.25, lightPos: [0.3, 0.2], word: 0.7, wordId: 'subdials', wordX: 0.2, wordY: -0.2 } },
    ],
  },
  {
    id: 'crown',
    vh: 260,
    keys: [{ t: 1, cam: { at: 'pushers', dir: [0.9, 0.32, 0.28], dist: 105, fov: 28, side: 1.5, lift: -0.45 }, light: { bg: '#0c0c0e', key: 1.45, keyX: -0.5, env: 0.85, exp: 0.95, shadow: 0.3 }, turn: Math.PI * 2 + 0.6, scene: { top: '#4a2f17', bottom: '#1b1209', light: '#e8a552', beam: 0.7, beamAngle: -0.6, haze: 0.45, flare: 0, vignette: 0.30, glow: 0.45, particles: 0.5, lightPos: [0.15, 0.7], word: 0.9, wordId: 'crown', wordX: -0.15, wordY: 0.1 } }],
  },
  {
    id: 'bracelet',
    vh: 340,
    keys: [
      { t: 0.45, cam: { at: 'bracelet', dir: [0.62, 0.5, 0.6], dist: 64, fov: 28, side: -1.6, lift: -0.45 }, light: { bg: '#111214', key: 1.25, keyX: 0.3, env: 1.0, exp: 0.95, shadow: 0.3 } },
      { t: 1, explode: zeros(), cam: { at: 'clasp', dir: [0.15, -0.92, 0.36], dist: 54, fov: 28, side: -1.6, lift: -0.45 }, turn: Math.PI * 2 + 0.9, scene: { top: '#523419', bottom: '#1a1109', light: '#f0c27a', beam: 0.4, beamAngle: 0.9, haze: 0.5, flare: 0, vignette: 0.30, glow: 0.3, particles: 0.6, lightPos: [0.8, 0.75], word: 0.85, wordId: 'bracelet', wordX: 0.15, wordY: -0.1 } },
    ],
  },
  {
    id: 'open',
    vh: 340,
    keys: [
      { t: 0.3, cam: { at: 'watch', dir: [0.7, -0.32, 0.64], dist: 235, fov: 30, side: 1, lift: -0.3 }, explode: zeros(), light: { bg: '#08080a', key: 1.0, keyX: -0.4, env: 0.8, exp: 0.9, shadow: 0 } },
      { t: 0.65, explode: apart(0), cam: { at: 'watch', dir: [0.72, -0.4, 0.56], dist: 240, fov: 30, side: 1, lift: -0.3 } },
      { t: 1, explode: apart(0, 1), cam: { at: 'caseback', dir: [0.7, -0.52, 0.48], dist: 160, fov: 30, side: 1.3, lift: -0.3 }, turn: Math.PI * 2 + 1.1, scene: { top: '#341f10', bottom: '#1c120a', light: '#e8a552', beam: 0.35, beamAngle: 1.6, haze: 0.6, flare: 0, vignette: 0.36, glow: 0.3, particles: 0.8, lightPos: [0.5, 0.9], word: 0.8, wordId: 'open', wordX: 0, wordY: 0.2 } },
    ],
  },
  {
    id: 'movement',
    vh: 340,
    keys: [
      // the caseback goes on down (2.2 × its offset) so the camera can come in under the case and look up at the movement
      { t: 0.5, explode: [1.5, 2.2, 1, 0, 0, 0, 0], cam: { at: 'movement', dir: [0.3, -0.85, 0.45], dist: 100, fov: 28, side: -1.5, lift: -0.45 }, light: { bg: '#050507', key: 1.3, keyX: 0.5, env: 0.7, exp: 0.95, shadow: 0 } },
      { t: 1, explode: [1.5, 2.2, 1, 0, 0, 0, 0], cam: { at: 'movement', dir: [0.05, -0.97, 0.25], dist: 84, fov: 26, side: -1.5, lift: -0.45 }, turn: Math.PI * 2 + 1.4, scene: { top: '#372211', bottom: '#1c120a', light: '#f0c27a', beam: 0.2, beamAngle: 0.3, haze: 0.8, flare: 0, vignette: 0.45, glow: 0.2, particles: 1.0, lightPos: [0.5, 0.5], word: 0.6, wordId: 'movement', wordX: 0.2, wordY: 0.25 } },
    ],
  },
  {
    id: 'parts',
    vh: 360,
    keys: [
      { t: 0.22, scene: { top: '#523419', bottom: '#1a1109', light: '#f4d08f', beam: 0.8, beamAngle: 0.0, haze: 0.5, flare: 0.9, vignette: 0.27, glow: 0.5, particles: 0.9, lightPos: [0.55, 0.72], word: 0.9, wordId: 'parts', wordX: -0.1, wordY: 0 } },
      { t: 0.55, explode: [1.2, 1.6, 1, 1, 1, 1, 1], cam: { at: 'stack', dir: [0.75, 0.3, 0.6], dist: 250, fov: 32, side: 1, lift: -0.1 }, light: { bg: '#15171c', key: 1.15, keyX: 0.2, env: 0.9, exp: 0.95, shadow: 0 } },
      { t: 1, explode: [1.2, 1.6, 1, 1, 1, 1, 1], cam: { at: 'stack', dir: [-0.6, 0.38, 0.7], dist: 240, fov: 32, side: 1, lift: -0.1 }, turn: Math.PI * 2 + 2.0, scene: { top: '#523419', bottom: '#1a1109', light: '#f4d08f', beam: 0.8, beamAngle: 0.0, haze: 0.5, flare: 0, vignette: 0.27, glow: 0.5, particles: 0.9, lightPos: [0.5, 0.75], word: 0.9, wordId: 'parts', wordX: -0.1, wordY: 0 } },
    ],
  },
  {
    id: 'together',
    vh: 340,
    keys: [
      { t: 0.8, scene: { top: '#5a3a1f', bottom: '#1f140b', light: '#f4d08f', beam: 1.0, beamAngle: -0.3, haze: 0.55, flare: 1.0, vignette: 0.24, glow: 0.9, particles: 0.8, lightPos: [0.3, 0.75], word: 0.9, wordId: 'together', wordX: 0.1, wordY: 0.05 } },
      { t: 1, explode: zeros(), stagger: 0.07, cam: { at: 'watch', dir: [0.5, 0.6, 0.62], dist: 185, fov: 30, side: -1, lift: -0.3 }, light: { bg: '#1a1b20', key: 1.6, keyX: 0.9, env: 1.0, exp: 1.0, shadow: 0.6 }, turn: Math.PI * 2 + 2.9, scene: { top: '#5a3a1f', bottom: '#1f140b', light: '#f4d08f', beam: 1.0, beamAngle: -0.3, haze: 0.55, flare: 0, vignette: 0.24, glow: 0.7, particles: 0.8, lightPos: [0.25, 0.8], word: 0.9, wordId: 'together', wordX: 0.1, wordY: 0.05 } },
    ],
  },
  {
    id: 'specs',
    vh: 240,
    keys: [{ t: 1, cam: { at: 'watch', dir: [0.45, 0.68, 0.58], dist: 300, fov: 30, side: 1.2, lift: 0.5 }, light: { bg: '#121316', key: 1.2, keyX: 0.4, env: 1.0, exp: 0.95, shadow: 0.5 }, turn: Math.PI * 2 + 3.4, scene: { top: '#4a2f17', bottom: '#1b1209', light: '#e8a552', beam: 0.3, beamAngle: 0.8, haze: 0.4, flare: 0, vignette: 0.30, glow: 0.3, particles: 0.4, lightPos: [0.8, 0.8], word: 0.5, wordId: 'specs', wordX: -0.25, wordY: 0.3 } }],
  },
  {
    id: 'final',
    vh: 170,
    keys: [{ t: 1, cam: { at: 'watch', dir: [0.0, 0.94, 0.34], dist: 225, fov: 30, side: 0.92, lift: -0.17, pDistMul: 1.55, pLift: -0.25 }, light: { bg: '#2c1c11', key: 1.2, keyX: 0.2, env: 1.0, exp: 0.95, shadow: 0.6 }, turn: Math.PI * 4, scene: { top: '#523419', bottom: '#1b1209', light: '#e8a552', beam: 0.45, beamAngle: 0.2, haze: 0.5, flare: 0, vignette: 0.30, glow: 0.4, particles: 0.6, lightPos: [0.5, 0.8], word: 0, wordId: 'final', wordX: 0, wordY: 0 } }],
  },
]

export const TOTAL_VH = SECTIONS.reduce((a, s) => a + s.vh, 0)

/** [start, end) of each section in p. */
export const BOUNDS = (() => {
  let at = 0
  return SECTIONS.map((s) => {
    const start = at / TOTAL_VH
    at += s.vh
    return { id: s.id, start, end: at / TOTAL_VH, vh: s.vh }
  })
})()

/** The section at p: index, id, local progress (0 → 1 across it). */
export function sectionAt(p) {
  const x = clamp01(p)
  let i = BOUNDS.findIndex((b) => x < b.end)
  if (i < 0) i = BOUNDS.length - 1
  const b = BOUNDS[i]
  return { index: i, id: b.id, local: clamp01((x - b.start) / (b.end - b.start)) }
}

// Flatten each track into [{ p, value }] in scroll order, carrying a track's last value across sections that do not set it.
const flatten = (track) => {
  const out = []
  let last = null
  SECTIONS.forEach((s, i) => {
    const b = BOUNDS[i]
    for (const k of s.keys) {
      if (k[track] === undefined) continue
      const value = k[track]
      out.push({ p: b.start + (b.end - b.start) * k.t, value, stagger: k.stagger || 0 })
      last = value
    }
  })
  if (!out.length) return out
  // the first key also holds from p = 0
  if (out[0].p > 0) out.unshift({ p: 0, value: out[0].value, stagger: 0 })
  void last
  return out
}
export const TRACKS = { cam: flatten('cam'), explode: flatten('explode'), light: flatten('light'), turn: flatten('turn'), scene: flatten('scene') }

/** The two keys that bracket p on a track and the eased position between them. */
export function bracket(track, p) {
  const keys = TRACKS[track]
  const x = clamp01(p)
  let i = 0
  while (i < keys.length - 1 && keys[i + 1].p <= x) i++
  const a = keys[i]
  const b = keys[Math.min(keys.length - 1, i + 1)]
  const span = b.p - a.p
  const raw = span > 0 ? clamp01((x - a.p) / span) : 1
  return { a: a.value, b: b.value, t: smooth(raw), raw, stagger: b.stagger || 0 }
}

const lerp = (a, b, t) => a + (b - a) * t
const hex = (h) => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)]
const toHex = (c) => '#' + c.map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('')

/** The explode amounts at p — the reassembly's steps return one after another when the key says `stagger`. */
export function explodeAt(p) {
  const { a, b, raw, stagger } = bracket('explode', p)
  const tidy = (v) => (v < 1e-9 ? 0 : Math.abs(v - 1) < 1e-9 ? 1 : v)
  return STEPS.map((_, k) => {
    if (!stagger) return tidy(lerp(a[k], b[k], smooth(raw)))
    // the last step apart (the crown) comes home first
    const order = N - 1 - k
    const start = order * stagger
    const span = 1 - (N - 1) * stagger
    return tidy(lerp(a[k], b[k], smooth((raw - start) / span)))
  })
}

export function turnAt(p) {
  const { a, b, t } = bracket('turn', p)
  return lerp(a, b, t)
}

export function lightAt(p) {
  const { a, b, t } = bracket('light', p)
  const ca = hex(a.bg)
  const cb = hex(b.bg)
  return {
    bg: toHex([lerp(ca[0], cb[0], t), lerp(ca[1], cb[1], t), lerp(ca[2], cb[2], t)]),
    key: lerp(a.key, b.key, t),
    keyX: lerp(a.keyX, b.keyX, t),
    env: lerp(a.env, b.env, t),
    exp: lerp(a.exp, b.exp, t),
    shadow: lerp(a.shadow, b.shadow, t),
  }
}

/** The environment at p (Atmosphere.jsx): colours, beams, haze, flare, vignette, particles, the ghost word. */
export function sceneAt(p) {
  const { a, b, t } = bracket('scene', p)
  const c = (ka, kb) => {
    const x = hex(ka)
    const y = hex(kb)
    return toHex([lerp(x[0], y[0], t), lerp(x[1], y[1], t), lerp(x[2], y[2], t)])
  }
  return {
    top: c(a.top, b.top),
    bottom: c(a.bottom, b.bottom),
    light: c(a.light, b.light),
    beam: lerp(a.beam, b.beam, t),
    beamAngle: lerp(a.beamAngle, b.beamAngle, t),
    haze: lerp(a.haze, b.haze, t),
    flare: lerp(a.flare, b.flare, t),
    vignette: lerp(a.vignette, b.vignette, t),
    glow: lerp(a.glow, b.glow, t),
    particles: lerp(a.particles, b.particles, t),
    lightPos: [lerp(a.lightPos[0], b.lightPos[0], t), lerp(a.lightPos[1], b.lightPos[1], t)],
    // the ghost word: the section's own, crossfading at the boundary
    wordId: t < 0.5 ? a.wordId : b.wordId,
    word: t < 0.5 ? a.word * (1 - t * 2) : b.word * ((t - 0.5) * 2),
    wordX: lerp(a.wordX, b.wordX, t),
    wordY: lerp(a.wordY, b.wordY, t),
  }
}

/** How much the idle pose owns the camera at p: 1 at either end of the page, gone 3 % in — the hand-over. */
export function idleAt(p) {
  const x = clamp01(p)
  return Math.max(1 - smooth(x / 0.03), smooth((x - 0.97) / 0.03))
}

/** The camera's bracketing keys at p (anchors unresolved — the stage resolves and lerps). */
export function cameraAt(p) {
  return bracket('cam', p)
}

/** The whole frame at p, minus the camera's resolution. */
export function sample(p) {
  return { section: sectionAt(p), explode: explodeAt(p), turn: turnAt(p), light: lightAt(p), cam: cameraAt(p), scene: sceneAt(p) }
}
