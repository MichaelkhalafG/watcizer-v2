import { Box3, BatchedMesh, BufferAttribute, Sphere, Vector3, CanvasTexture } from 'three'
import { STEPS } from './explode'

/*
 * The brand-supplied model, prepared for the stage (2026-10-28, Watchizer only).
 *
 * The GLB's root node carries a 0.001 scale (the model is built in millimetres), and every movable part carries
 * `extras.part` — three's GLTFLoader puts it in `userData.part` — with the exporter's `step`, `explodeOffsetMM`
 * and `internalOffsetMM`. This module reads those and nothing else: no part is renamed, re-pivoted or
 * re-coloured. Rendering decisions made here, none of which touches the model's data:
 *   • the crystal's physical transmission (a second render pass per frame) is replaced by plain transparency on
 *     phones (the 2026-09 hero measurement);
 *   • no mesh casts or receives three.js shadows (the contact shadow is a textured plane);
 *   • the 273 part meshes are DRAWN THROUGH BATCHES — one BatchedMesh per material (26), each part an instance
 *     whose matrix is copied from the part's node every frame — so a frame costs ~27 draw calls instead of 273.
 *     The node hierarchy (with its explode offsets) stays the source of truth and is never rendered itself; the
 *     batches draw the same geometry with the same materials. A browser whose BatchedMesh fails falls back to
 *     the plain meshes.
 */

export const MM = 0.001

// The parts the camera can be aimed at (timeline.js `cam.at`): node names as the model has them.
const ANCHOR_NODES = {
  case: 'CASE_ASSEMBLY',
  dial: 'DIAL_ASSEMBLY',
  subdial6: 'subdial_6_face',
  hands: 'HAND_ASSEMBLY',
  crown: 'crown',
  bracelet: 'link_six_03',
  clasp: 'CLASP_ASSEMBLY',
  caseback: 'CASEBACK_ASSEMBLY',
  movement: 'INTERNAL_MOVEMENT',
  crystal: 'crystal',
  bezel: 'bezel_ring',
  tachy: 'tachymeter_ring',
}

/**
 * @param {import('three').Object3D} scene  the loaded gltf.scene (cloned here; the cache's copy is left alone)
 * @param {{ phone?: boolean }} opts
 */
/** The dial's face and the three subdial faces — the satin surfaces that must read black. */
const DIAL_FACE = /^(dial_face|subdial_\d+_face)$/
/** Their share of the studio map (measured on the poster harness, 2026-10-29: 1.0 read lum 111 of 255, DIAL_ENV reads black). */
export const DIAL_ENV = 0.12
/** The crystal's share: its mirror of the studio sits over the dial. */
export const CRYSTAL_ENV = 1.0

/**
 * Binds the loaded environment to every material once and scales each material's own share of it by the timeline's
 * env. Needed because three 0.185 reads scene.environmentIntensity instead of material.envMapIntensity while
 * material.envMap is null (WebGLRenderer: `m_uniforms.envMapIntensity.value = scene.environmentIntensity`).
 * @param {{ materials: import('three').Material[] }} prepared
 * @param {import('three').Texture|null} envTex scene.environment, null until the HDR has loaded
 * @param {number} scale the timeline's env for this frame
 */
/** Takes the drag travel accumulated since the last frame (a fraction of the stage's width) and clears it. */
export function takeDrag(drag) {
  const dx = drag.dx
  drag.dx = 0
  return dx
}

export function bindEnvironment(prepared, envTex, scale) {
  if (!envTex) return
  for (const m of prepared.materials) {
    if (m.envMap !== envTex) {
      m.envMap = envTex
      m.needsUpdate = true
    }
    m.envMapIntensity = m.userData.envBase * scale
  }
}

export function prepareWatch(scene, { phone = false } = {}) {
  const root = scene.clone(true)
  const parts = []
  const meshes = []
  const materials = [] // every material once, for the stage's env binding
  root.traverse((o) => {
    const part = o.userData?.part
    if (part && Array.isArray(part.explodeOffsetMM)) {
      parts.push({
        node: o,
        id: part.id,
        step: Number(part.step),
        base: o.position.clone(),
        explode: new Vector3().fromArray(part.explodeOffsetMM.map(Number)),
        internal: new Vector3().fromArray((Array.isArray(part.internalOffsetMM) ? part.internalOffsetMM : [0, 0, 0]).map(Number)),
      })
    }
    if (o.isMesh && o.material) {
      const m = o.material
      // Each material's share of the studio map. It only applies once the stage binds the loaded environment to the
      // material (three 0.185 reads scene.environmentIntensity instead whenever material.envMap is null — every
      // per-material value before 2026-10-29 was a no-op, which is why the dial stayed grey through four rounds).
      // The black satin dial and the subdial faces take DIAL_ENV of the map: their satin specular is what read grey.
      m.userData.envBase = DIAL_FACE.test(o.name) ? DIAL_ENV : 1.0
      m.envMapIntensity = m.userData.envBase
      if (!materials.includes(m)) materials.push(m)
      if (m.transmission > 0) {
        if (phone) {
          m.transmission = 0
          m.thickness = 0
          m.transparent = true
          m.opacity = 0.05 // 0.1 lifted the dial under it from lum 16 to 35 (poster harness, 2026-10-29)
          m.depthWrite = false
        } else {
          m.thickness = 0.3
          m.ior = 1.5
        }
        // the crystal's mirror of the studio made the dial under it read white at the opening angle
        m.userData.envBase = CRYSTAL_ENV
        m.envMapIntensity = CRYSTAL_ENV
        if ('specularIntensity' in m) m.specularIntensity = 0.2
        m.needsUpdate = true
      }
      o.castShadow = false
      o.receiveShadow = false
      meshes.push(o)
    }
  })

  root.updateMatrixWorld(true)
  const bounds = new Box3().setFromObject(root) // metres, assembled
  const centre = bounds.getCenter(new Vector3())
  const size = bounds.getSize(new Vector3())

  // Where each disassembly step's parts sit when fully apart (metres, the model's frame).
  const stepIndex = new Map()
  STEPS.forEach((s, k) => s.steps.forEach((st) => stepIndex.set(st, k)))
  const stepBoxes = STEPS.map(() => new Box3())
  const tmp = new Box3()
  for (const p of parts) {
    const k = stepIndex.get(p.step)
    if (k === undefined) continue
    tmp.setFromObject(p.node)
    if (tmp.isEmpty()) continue
    const off = p.explode.clone().multiplyScalar(MM)
    tmp.min.add(off)
    tmp.max.add(off)
    stepBoxes[k].union(tmp)
  }
  const stepFrames = stepBoxes.map((b, k) => {
    if (b.isEmpty()) return { centre: centre.clone(), radius: size.length() / 2, id: STEPS[k].id }
    const sphere = b.getBoundingSphere(new Sphere())
    return { centre: sphere.center, radius: Math.max(sphere.radius, 0.012), id: STEPS[k].id }
  })
  const stack = stepBoxes.reduce((acc, b) => (b.isEmpty() ? acc : acc.union(b)), bounds.clone())

  // Anchors: a part's assembled world centre, plus (at run time) how far its node has moved — so a camera aimed
  // at the caseback follows the caseback while it is apart.
  const anchors = {}
  const worldAt = (node) => node.getWorldPosition(new Vector3())
  for (const [name, nodeName] of Object.entries(ANCHOR_NODES)) {
    const node = root.getObjectByName(nodeName)
    if (!node) continue
    const box = new Box3().setFromObject(node)
    anchors[name] = { node, centre: box.isEmpty() ? worldAt(node) : box.getCenter(new Vector3()), base: worldAt(node) }
  }
  const up = root.getObjectByName('upper_chrono_pusher_cap')
  const lo = root.getObjectByName('lower_chrono_pusher_cap')
  if (up && lo) {
    const c = new Box3().setFromObject(up).getCenter(new Vector3()).add(new Box3().setFromObject(lo).getCenter(new Vector3())).multiplyScalar(0.5)
    const crown = anchors.crown
    anchors.pushers = { node: up, centre: crown ? c.lerp(crown.centre, 0.35) : c, base: worldAt(up) }
  }
  anchors.watch = { node: null, centre: centre.clone(), base: null }
  anchors.stack = { node: null, centre: stack.getCenter(new Vector3()), base: null }

  return { root, parts, meshes, materials, bounds, centre, size, stepFrames, anchors }
}

const tmpV = new Vector3()
/** An anchor's current position (the model's frame, metres), following its part. */
export function resolveAnchor(prepared, name, out) {
  const a = prepared.anchors[name] || prepared.anchors.watch
  out.copy(a.centre)
  if (a.node && a.base) {
    a.node.getWorldPosition(tmpV)
    out.add(tmpV).sub(a.base)
  }
  return out
}

/**
 * Moves every part to where it belongs at these explode amounts (STEPS order, 0 … 1 each). Positions are in
 * the part's own parent space, millimetres, as the model's offsets are given.
 */
const stepIndexCache = new Map()
STEPS.forEach((s, k) => s.steps.forEach((st) => stepIndexCache.set(st, k)))
export function applyExplode(parts, amounts) {
  for (const p of parts) {
    const k = stepIndexCache.get(p.step)
    const a = k === undefined ? 0 : amounts[k] || 0
    p.node.position.set(p.base.x + (p.explode.x + p.internal.x) * a, p.base.y + (p.explode.y + p.internal.y) * a, p.base.z + (p.explode.z + p.internal.z) * a)
  }
}

/**
 * One BatchedMesh per material over the part meshes. Returns `{ batches, bind }` — `bind` lives in the node
 * hierarchy's matrixWorld: call `sync()` after `root.updateMatrixWorld(true)` to copy every part's matrix into
 * its instance. Transmissive materials are left as plain meshes (three draws them in their own pass).
 */
export function buildBatches(prepared) {
  const groups = new Map()
  const plain = []
  for (const mesh of prepared.meshes) {
    const m = mesh.material
    if (m.transmission > 0 || Array.isArray(m)) {
      plain.push(mesh)
      continue
    }
    if (!groups.has(m)) groups.set(m, [])
    groups.get(m).push(mesh)
  }
  const batches = []
  const bindings = []
  for (const [material, list] of groups) {
    let verts = 0
    let indices = 0
    for (const mesh of list) {
      const g = mesh.geometry
      verts += g.attributes.position.count
      indices += g.index ? g.index.count : g.attributes.position.count
    }
    const batch = new BatchedMesh(list.length, verts, indices, material)
    batch.perObjectFrustumCulled = true
    batch.sortObjects = true
    for (const mesh of list) {
      const g = mesh.geometry
      if (!g.attributes.uv) {
        // a batch needs one attribute set across its geometries; an untextured part gets a zero uv it never reads
        g.setAttribute('uv', new BufferAttribute(new Float32Array(g.attributes.position.count * 2), 2))
      }
      if (!g.index) {
        // likewise one index layout: a non-indexed part gets the identity index
        const n = g.attributes.position.count
        const idx = n > 65535 ? new Uint32Array(n) : new Uint16Array(n)
        for (let i = 0; i < n; i++) idx[i] = i
        g.setIndex(new BufferAttribute(idx, 1))
      }
      const gid = batch.addGeometry(g)
      const iid = batch.addInstance(gid)
      bindings.push({ batch, iid, mesh })
    }
    batches.push(batch)
  }
  const sync = () => {
    for (const b of bindings) b.batch.setMatrixAt(b.iid, b.mesh.matrixWorld)
  }
  return { batches, plain, sync, count: bindings.length }
}

/** A soft radial disc for the contact shadow — drawn once, on a canvas, never fetched. */
export function shadowTexture(size = 256) {
  if (typeof document === 'undefined') return null
  const c = document.createElement('canvas')
  c.width = size
  c.height = size
  const ctx = c.getContext('2d')
  const g = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2)
  g.addColorStop(0, 'rgba(0,0,0,0.75)')
  g.addColorStop(0.45, 'rgba(0,0,0,0.35)')
  g.addColorStop(1, 'rgba(0,0,0,0)')
  ctx.fillStyle = g
  ctx.fillRect(0, 0, size, size)
  const tex = new CanvasTexture(c)
  tex.needsUpdate = true
  return tex
}
