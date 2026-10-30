/*
 * THE SCROLL TIMELINE of the experience page — pure numbers, no three.js (2026-10-28, Watchizer only).
 *
 * One scroll position across the story is a progress p in [0, 1]. This module turns p into: which BEAT is
 * on (the opening, the turn, seven disassembly steps, the reassembly, the final shot), how far each
 * disassembly step has come apart (0 = assembled, 1 = fully apart), and how far the watch has turned.
 * Everything is a function of p alone, so scrolling back up plays the same frames in reverse exactly.
 *
 * THE STEPS ARE THE MODEL'S. The brand-supplied GLB carries, on every part, `extras.part.step` and
 * `explodeOffsetMM` / `internalOffsetMM` (the exporter's own disassembly order and directions). The seven
 * beats below group those steps in the order the brief asks for; nothing here moves a part the model
 * does not say how to move.
 */

/** The seven disassembly beats, in scroll order, each naming the model's `part.step` values it moves. */
export const STEPS = [
  { id: 'bracelet', steps: [1] }, // bracelet, end links, spring bars, clasp
  { id: 'caseback', steps: [2, 3, 4] }, // caseback (+ its gasket, then the movement holder)
  { id: 'movement', steps: [5] }, // the movement, its parts spread by their internal offsets
  { id: 'crystal', steps: [10, 11] }, // crystal (+ gasket, tachymeter ring), then the bezel
  { id: 'dial', steps: [6, 7] }, // dial base and face, then sub-dials, indices, date frame
  { id: 'hands', steps: [8] },
  { id: 'crown', steps: [12] }, // crown, stem, pushers
]

/** Every beat in order, with its share of the scroll (relative weights). */
export const BEATS = [
  { id: 'opening', weight: 1 },
  { id: 'turn', weight: 1.4 },
  ...STEPS.map((s) => ({ id: s.id, weight: 1 })),
  { id: 'reassembly', weight: 1.5 },
  { id: 'final', weight: 0.8 },
]

const TOTAL = BEATS.reduce((a, b) => a + b.weight, 0)
/** [start, end) of each beat in p. */
export const BOUNDS = (() => {
  let at = 0
  return BEATS.map((b) => {
    const start = at / TOTAL
    at += b.weight
    return { id: b.id, start, end: at / TOTAL }
  })
})()

export const clamp01 = (v) => Math.min(1, Math.max(0, Number.isFinite(v) ? v : 0))
export const smooth = (t) => {
  const x = clamp01(t)
  return x * x * (3 - 2 * x)
}

/** The beat at p: its index, id and the local progress inside it (0 → 1). */
export function beatAt(p) {
  const x = clamp01(p)
  let i = BOUNDS.findIndex((b) => x < b.end)
  if (i < 0) i = BOUNDS.length - 1
  const b = BOUNDS[i]
  return { index: i, id: b.id, local: clamp01((x - b.start) / (b.end - b.start)) }
}

// Inside a disassembly beat the parts come apart over its first 55 % and hold (the caption reads) over the rest.
const MOVE_SHARE = 0.55
const firstStep = BEATS.findIndex((b) => b.id === STEPS[0].id)
const reassemblyIndex = BEATS.findIndex((b) => b.id === 'reassembly')

/**
 * How far apart each of the seven steps is at p, in STEPS order: 0 assembled … 1 fully apart.
 * Disassembly is cumulative (a step stays apart while the later ones come apart); the reassembly returns the
 * parts in reverse order, overlapping, so the watch closes up from the crown back to the bracelet.
 */
export function explodeAmounts(p) {
  const { index, local } = beatAt(p)
  return STEPS.map((_, k) => {
    const beat = firstStep + k
    if (index < beat) return 0
    if (index === beat) return smooth(local / MOVE_SHARE)
    if (index < reassemblyIndex) return 1
    if (index === reassemblyIndex) {
      const n = STEPS.length
      const order = n - 1 - k // the crown (last apart) returns first
      const span = 0.45
      const start = (order / n) * (1 - span)
      return 1 - smooth((local - start) / span)
    }
    return 0
  })
}

/** The watch's turn about its vertical axis at p, in radians: one full, eased turn across the `turn` beat, else a slow drift. */
export function turnAt(p) {
  const { index, local } = beatAt(p)
  const turnIndex = 1
  const x = clamp01(p)
  const drift = x * 0.9 // a slow, continuous drift across the whole story
  if (index < turnIndex) return drift
  if (index === turnIndex) return drift + smooth(local) * Math.PI * 2
  return drift + Math.PI * 2
}

/** The caption that reads at p: the beat id, with how visible it is (fades in over the first 20 % of a beat, out over its last 10 %). */
export function captionAt(p) {
  const { id, local } = beatAt(p)
  const vis = Math.min(smooth(local / 0.2), 1 - smooth((local - 0.9) / 0.1))
  return { id, visible: clamp01(vis) }
}
