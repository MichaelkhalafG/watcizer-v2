import { describe, expect, it, vi } from 'vitest'
import { BoxGeometry, Mesh, MeshStandardMaterial, MeshPhysicalMaterial, SphereGeometry, Texture } from 'three'
import { buildBatches, buildBatchesInSteps, texturesOf } from '../src/Components/Experience/watchModel'

/*
 * THE MODEL PREPARED IN SHORT TASKS IS THE SAME MODEL (the follow-up of 2026-11-06, Watchizer only).
 *
 * The home's banner used to build the watch's batches in one go — one task of 180 ms on the throttled phone profile.
 * `buildBatchesInSteps` builds the same batches a few at a time, with a pause between them. These tests hold that it
 * is the same result: the same batches in the same order, the same parts in each, the same geometry, the same plain
 * (glass) meshes — and that it does pause.
 */

const scene = () => {
  const steel = new MeshStandardMaterial({ name: 'steel' })
  const dial = new MeshStandardMaterial({ name: 'dial' })
  const glass = new MeshPhysicalMaterial({ name: 'crystal', transmission: 1 })
  const meshes = [
    new Mesh(new BoxGeometry(1, 1, 1), steel),
    new Mesh(new SphereGeometry(0.5, 8, 6), dial),
    new Mesh(new BoxGeometry(2, 1, 1), steel),
    new Mesh(new SphereGeometry(0.4, 8, 6), glass),
    new Mesh(new BoxGeometry(1, 2, 1), steel),
    new Mesh(new BoxGeometry(1, 1, 3), dial),
  ]
  meshes.forEach((m, i) => {
    m.position.set(i, 0, 0)
    m.updateMatrixWorld(true)
  })
  return { meshes, materials: [steel, dial, glass] }
}

const shape = (drawn) => ({
  batches: drawn.batches.map((b) => ({
    material: b.material.name,
    vertices: b.geometry.attributes.position.count,
    indices: b.geometry.index.count,
    positions: Array.from(b.geometry.attributes.position.array.slice(0, 60)),
  })),
  plain: drawn.plain.map((m) => m.material.name),
  count: drawn.count,
})

describe('the watch\'s batches, built in steps', () => {
  it('are the batches built in one go: same order, same parts, same geometry, same glass', async () => {
    const whole = buildBatches(scene())
    const stepped = await buildBatchesInSteps(scene(), async () => {}, 0)
    expect(shape(stepped)).toEqual(shape(whole))
    expect(shape(whole).batches.map((b) => b.material)).toEqual(['steel', 'dial'])
    expect(shape(whole).plain).toEqual(['crystal'])
    expect(whole.count).toBe(5)
    // every part's place is copied into its instance by the same call
    whole.sync()
    stepped.sync()
  })

  it('pauses between batches once the budget is spent, and not when it is not', async () => {
    const pause = vi.fn(async () => {})
    await buildBatchesInSteps(scene(), pause, -1) // every batch is "over budget": a pause after each
    expect(pause).toHaveBeenCalledTimes(2)
    pause.mockClear()
    await buildBatchesInSteps(scene(), pause, 60_000)
    expect(pause).not.toHaveBeenCalled()
  })

  it('lists every picture of the model once, and never the studio map', () => {
    const map = new Texture()
    const normal = new Texture()
    const env = new Texture()
    const a = new MeshStandardMaterial({ map, normalMap: normal, envMap: env })
    const b = new MeshStandardMaterial({ map, roughnessMap: normal })
    const found = texturesOf([a, b])
    expect(found).toHaveLength(2)
    expect(found).toContain(map)
    expect(found).toContain(normal)
    expect(found).not.toContain(env)
  })
})
