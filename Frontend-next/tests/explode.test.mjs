import { describe, expect, it } from 'vitest'
import { BEATS, BOUNDS, STEPS, beatAt, explodeAmounts, turnAt, captionAt } from '../src/Components/Experience/explode'

/*
 * THE SCROLL TIMELINE IS A FUNCTION OF THE SCROLL ALONE (2026-10-28, Watchizer only): the same p gives the same
 * frame whichever way the shopper came to it, so scrolling back up plays everything in reverse exactly.
 */

describe('beats', () => {
  it('runs opening, turn, the seven steps in the brief’s order, reassembly, final — and the bounds tile [0, 1]', () => {
    expect(BEATS.map((b) => b.id)).toEqual(['opening', 'turn', 'bracelet', 'caseback', 'movement', 'crystal', 'dial', 'hands', 'crown', 'reassembly', 'final'])
    expect(STEPS.map((s) => s.id)).toEqual(['bracelet', 'caseback', 'movement', 'crystal', 'dial', 'hands', 'crown'])
    expect(BOUNDS[0].start).toBe(0)
    expect(BOUNDS[BOUNDS.length - 1].end).toBeCloseTo(1, 12)
    for (let i = 1; i < BOUNDS.length; i++) expect(BOUNDS[i].start).toBeCloseTo(BOUNDS[i - 1].end, 12)
  })

  it('names the model’s part.step values each beat moves, every step once', () => {
    const all = STEPS.flatMap((s) => s.steps)
    expect(new Set(all).size).toBe(all.length)
    expect(all.sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12])
  })

  it('beatAt is monotonic and clamps', () => {
    expect(beatAt(-1).id).toBe('opening')
    expect(beatAt(2).id).toBe('final')
    let last = -1
    for (let p = 0; p <= 1; p += 0.001) {
      const b = beatAt(p)
      expect(b.index).toBeGreaterThanOrEqual(last)
      last = b.index
      expect(b.local).toBeGreaterThanOrEqual(0)
      expect(b.local).toBeLessThanOrEqual(1)
    }
  })
})

describe('explodeAmounts', () => {
  const mid = (id) => {
    const b = BOUNDS.find((x) => x.id === id)
    return (b.start + b.end) / 2
  }
  it('is assembled before the first step and after the reassembly', () => {
    expect(explodeAmounts(0)).toEqual(STEPS.map(() => 0))
    expect(explodeAmounts(mid('turn'))).toEqual(STEPS.map(() => 0))
    expect(explodeAmounts(1)).toEqual(STEPS.map(() => 0))
    expect(explodeAmounts(mid('final'))).toEqual(STEPS.map(() => 0))
  })

  it('comes apart step by step and stays apart: at a step’s end that step and every earlier one are fully apart, the later ones not at all', () => {
    STEPS.forEach((s, k) => {
      const b = BOUNDS.find((x) => x.id === s.id)
      const a = explodeAmounts(b.end - 1e-9)
      for (let i = 0; i <= k; i++) expect(a[i]).toBeCloseTo(1, 6)
      for (let i = k + 1; i < STEPS.length; i++) expect(a[i]).toBe(0)
    })
  })

  it('each step moves over the first part of its beat and holds for the caption', () => {
    const b = BOUNDS.find((x) => x.id === 'caseback')
    const at = (local) => explodeAmounts(b.start + (b.end - b.start) * local)[1]
    expect(at(0)).toBe(0)
    expect(at(0.3)).toBeGreaterThan(0)
    expect(at(0.3)).toBeLessThan(1)
    expect(at(0.6)).toBeCloseTo(1, 6)
    expect(at(0.95)).toBeCloseTo(1, 6)
  })

  it('reassembles in reverse order: the crown is back before the bracelet starts home', () => {
    const b = BOUNDS.find((x) => x.id === 'reassembly')
    const at = (local) => explodeAmounts(b.start + (b.end - b.start) * local)
    expect(at(0)).toEqual(STEPS.map(() => 1))
    const early = at(0.3)
    expect(early[6]).toBeLessThan(0.3) // the crown mostly home
    expect(early[0]).toBeCloseTo(1, 6) // the bracelet still apart
    const late = at(0.75)
    expect(late[6]).toBe(0) // the crown home
    expect(late[0]).toBeGreaterThan(0) // the bracelet on its way
    expect(at(1)).toEqual(STEPS.map(() => 0))
  })

  it('every amount is within [0, 1] and continuous along the scroll (no jump larger than a fine step allows)', () => {
    let prev = explodeAmounts(0)
    for (let p = 0.0005; p <= 1; p += 0.0005) {
      const a = explodeAmounts(p)
      a.forEach((v, i) => {
        expect(v).toBeGreaterThanOrEqual(0)
        expect(v).toBeLessThanOrEqual(1)
        expect(Math.abs(v - prev[i])).toBeLessThan(0.05)
      })
      prev = a
    }
  })
})

describe('turnAt and captionAt', () => {
  it('turns exactly once across the turn beat, on top of a slow drift, and is monotonic', () => {
    const b = BOUNDS.find((x) => x.id === 'turn')
    expect(turnAt(b.end) - turnAt(b.start)).toBeCloseTo(Math.PI * 2 + 0.9 * (b.end - b.start), 6)
    let prev = turnAt(0)
    for (let p = 0.001; p <= 1; p += 0.001) {
      const v = turnAt(p)
      expect(v).toBeGreaterThanOrEqual(prev)
      prev = v
    }
  })

  it('a caption fades in at the start of its beat and out at its end', () => {
    const b = BOUNDS.find((x) => x.id === 'dial')
    const at = (local) => captionAt(b.start + (b.end - b.start) * local)
    expect(at(0).visible).toBe(0)
    expect(at(0.5).visible).toBe(1)
    expect(at(0.5).id).toBe('dial')
    expect(at(0.999).visible).toBeLessThan(0.1)
  })
})
