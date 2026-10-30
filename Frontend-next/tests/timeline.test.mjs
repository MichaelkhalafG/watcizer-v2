import { describe, expect, it } from 'vitest'
import { SECTIONS, BOUNDS, TOTAL_VH, TRACKS, sectionAt, explodeAt, turnAt, lightAt, cameraAt, sceneAt, idleAt, sample } from '../src/Components/Experience/timeline'
import { STEPS } from '../src/Components/Experience/explode'

/*
 * ONE SCROLL POSITION IS ONE FRAME (2026-10-28, Watchizer only): every track is a function of p alone, continuous,
 * so scrolling back plays the exact reverse; the disassembly follows the brief's order; the page ends assembled.
 */

const mid = (id) => {
  const b = BOUNDS.find((x) => x.id === id)
  return (b.start + b.end) / 2
}
const endOf = (id) => BOUNDS.find((x) => x.id === id).end - 1e-9

describe('sections', () => {
  it('are the twelve of the story, in order, tiling [0, 1], about thirty screens long', () => {
    expect(SECTIONS.map((s) => s.id)).toEqual(['hero', 'case', 'dial', 'subdials', 'crown', 'bracelet', 'open', 'movement', 'parts', 'together', 'specs', 'final'])
    expect(BOUNDS[0].start).toBe(0)
    expect(BOUNDS[BOUNDS.length - 1].end).toBeCloseTo(1, 12)
    for (let i = 1; i < BOUNDS.length; i++) expect(BOUNDS[i].start).toBeCloseTo(BOUNDS[i - 1].end, 12)
    expect(TOTAL_VH).toBeGreaterThanOrEqual(2800)
    expect(TOTAL_VH).toBeLessThanOrEqual(3600)
  })

  it('sectionAt is monotonic and clamps', () => {
    expect(sectionAt(-1).id).toBe('hero')
    expect(sectionAt(2).id).toBe('final')
    let last = -1
    for (let p = 0; p <= 1; p += 0.001) {
      const s = sectionAt(p)
      expect(s.index).toBeGreaterThanOrEqual(last)
      last = s.index
    }
  })

  it('every key is inside its section (0 < t ≤ 1) and every track starts at p = 0', () => {
    for (const s of SECTIONS) for (const k of s.keys) expect(k.t > 0 && k.t <= 1).toBe(true)
    for (const name of Object.keys(TRACKS)) expect(TRACKS[name][0].p).toBe(0)
  })
})

describe('explode', () => {
  it('is assembled through the macro sections, apart in the brief’s order, fully apart in "parts", home by the end of "together"', () => {
    for (const id of ['hero', 'case', 'dial', 'subdials', 'crown', 'bracelet']) expect(explodeAt(mid(id))).toEqual(STEPS.map(() => 0))
    const open = explodeAt(endOf('open'))
    expect(open[0]).toBeCloseTo(1, 6) // bracelet
    expect(open[1]).toBeCloseTo(1, 6) // caseback
    for (let k = 2; k < STEPS.length; k++) expect(open[k]).toBe(0)
    const mv = explodeAt(endOf('movement'))
    expect(mv[2]).toBeCloseTo(1, 6)
    expect(mv[3]).toBe(0)
    for (const v of explodeAt(endOf('parts'))) expect(v).toBeGreaterThanOrEqual(1)
    expect(explodeAt(endOf('together'))).toEqual(STEPS.map(() => 0))
    expect(explodeAt(mid('specs'))).toEqual(STEPS.map(() => 0))
    expect(explodeAt(1)).toEqual(STEPS.map(() => 0))
  })

  it('the reassembly is staggered: the crown is home before the bracelet starts back', () => {
    const b = BOUNDS.find((x) => x.id === 'together')
    const at = (local) => explodeAt(b.start + (b.end - b.start) * local)
    const early = at(0.3)
    expect(early[6]).toBeLessThan(early[0])
    const late = at(0.75)
    expect(late[6]).toBe(0)
    expect(late[0]).toBeGreaterThan(0)
  })

  it('every amount is within [0, 2.5] (a part may travel beyond its offset, never backwards past home) and continuous', () => {
    let prev = explodeAt(0)
    for (let p = 0.0005; p <= 1; p += 0.0005) {
      const a = explodeAt(p)
      a.forEach((v, i) => {
        expect(v).toBeGreaterThanOrEqual(0)
        expect(v).toBeLessThanOrEqual(2.5)
        expect(Math.abs(v - prev[i])).toBeLessThan(0.08)
      })
      prev = a
    }
  })
})

describe('turn, light and camera', () => {
  it('the turn never goes backwards and makes two full turns by the end', () => {
    let prev = turnAt(0)
    for (let p = 0.001; p <= 1; p += 0.001) {
      const v = turnAt(p)
      expect(v).toBeGreaterThanOrEqual(prev - 1e-9)
      prev = v
    }
    expect(turnAt(1)).toBeCloseTo(Math.PI * 4, 6)
  })

  it('the backdrop darkens for the movement and comes back for the end; every value is continuous', () => {
    expect(lightAt(mid('movement')).bg < lightAt(mid('specs')).bg).toBe(true)
    expect(lightAt(1).bg).toBe('#2c1c11') // the warm palette (Michael, 2026-10-29)
    let prev = lightAt(0)
    for (let p = 0.001; p <= 1; p += 0.001) {
      const l = lightAt(p)
      for (const k of ['key', 'keyX', 'env', 'exp', 'shadow']) expect(Math.abs(l[k] - prev[k])).toBeLessThan(0.05)
      expect(l.bg).toMatch(/^#[0-9a-f]{6}$/)
      prev = l
    }
  })

  it('the camera names an anchor and a distance at every point, macro shots close, the hero and the end alike', () => {
    for (let p = 0; p <= 1; p += 0.01) {
      const { a, b, t } = cameraAt(p)
      for (const k of [a, b]) {
        expect(typeof k.at).toBe('string')
        expect(k.dist).toBeGreaterThan(30)
        expect(k.dist).toBeLessThan(400)
        expect(k.dir.length).toBe(3)
      }
      expect(t).toBeGreaterThanOrEqual(0)
      expect(t).toBeLessThanOrEqual(1)
    }
    expect(cameraAt(mid('dial')).b.dist).toBeLessThan(100)
    // the opening frames the whole watch small, dead centre, face on; the end frames it face on beside the buy call
    const first = cameraAt(0).a
    const last = cameraAt(1).b
    for (const k of [first, last]) {
      expect(k.at).toBe('watch')
      expect(k.dir[1]).toBeGreaterThan(0.9) // the dial toward the visitor
    }
    expect(first.side).toBe(0)
    expect(first.dist).toBeGreaterThanOrEqual(250) // the case at ~30 % of a desktop viewport's height at fov 30
    expect(last.side).toBeGreaterThan(0)
  })

  it('the idle pose owns both ends and hands over within three per cent of scroll', () => {
    expect(idleAt(0)).toBe(1)
    expect(idleAt(1)).toBe(1)
    expect(idleAt(0.015)).toBeGreaterThan(0)
    expect(idleAt(0.015)).toBeLessThan(1)
    expect(idleAt(0.05)).toBe(0)
    expect(idleAt(0.5)).toBe(0)
    expect(idleAt(0.985)).toBeGreaterThan(0)
  })

  it('sample carries every track', () => {
    const s = sample(0.5)
    expect(Object.keys(s).sort()).toEqual(['cam', 'explode', 'light', 'scene', 'section', 'turn'])
  })

  it('the environment is never black, is continuous, and flares at the crystal and the reassembly', () => {
    let prev = sceneAt(0)
    let flares = 0
    for (let p = 0.001; p <= 1; p += 0.001) {
      const sc = sceneAt(p)
      for (const k of ['top', 'bottom', 'light']) expect(sc[k]).toMatch(/^#[0-9a-f]{6}$/)
      // "no black" in the warm palette (Michael, 2026-10-29): the bottom colour keeps a warm cast — its red channel
      // at least 0x18 and above its blue — never neutral black
      const r = parseInt(sc.bottom.slice(1, 3), 16)
      const b = parseInt(sc.bottom.slice(5, 7), 16)
      expect(r).toBeGreaterThanOrEqual(0x18)
      expect(r).toBeGreaterThan(b)
      for (const k of ['beam', 'haze', 'flare', 'vignette', 'glow', 'particles']) expect(Math.abs(sc[k] - prev[k])).toBeLessThan(0.08)
      if (sc.flare > 0.6) flares++
      prev = sc
    }
    expect(flares).toBeGreaterThan(20)
    expect(sceneAt(mid('parts')).wordId).toBe('parts')
  })
})
