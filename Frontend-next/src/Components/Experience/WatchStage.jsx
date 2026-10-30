'use client'
import { Component, Suspense, forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react'
import { Canvas, useFrame, useThree } from '@react-three/fiber'
import { Environment, useGLTF, useProgress } from '@react-three/drei'
import { Vector3, NeutralToneMapping, SRGBColorSpace, DoubleSide } from 'three'
import { prepareWatch, applyExplode, buildBatches, bindEnvironment, resolveAnchor, shadowTexture, takeDrag, MM } from './watchModel'
import { sample, idleAt } from './timeline'
import Atmosphere from './Atmosphere'

/*
 * THE STAGE — one three.js scene for the brand's model, used twice (2026-10-28, Watchizer only):
 *
 *   mode="story"  the experience page: ONE fixed, full-screen canvas behind the whole page. The page's scroll
 *                 progress 0 → 1 is sampled through timeline.js into the camera (anchor, direction, distance,
 *                 field of view), the explode of every part, the turn, the key light's sweep, the environment's
 *                 intensity, the exposure — and the ATMOSPHERE (Atmosphere.jsx: the backdrop, beams, haze, flare,
 *                 particles and the ghost words, all inside this canvas, one pass). Every frame the stage also
 *                 projects the named parts to screen coordinates and hands them to the page (`onFrame`) for the
 *                 callout lines. A frame is drawn only when asked (frameloop "demand"): the page's loop asks on
 *                 every scroll change and, for the environment's own motion, at a steady cadence (`tick`).
 *   mode="hero"   the home campaign hero: a 3 s eased intro turn under a light that sweeps across the steel,
 *                 then the watch rests; on a pointer device it leans a little toward the pointer. No atmosphere.
 *
 * Loaded by its callers through a dynamic import after first paint, so three.js is in neither route's first
 * chunk and in no other route at all. The model is the Meshopt GLB (drei's loader ships the decoder); the
 * phone variant (textures at 1024) is chosen by the caller. The parts are drawn through BatchedMesh (one draw
 * call per material) — watchModel.js.
 *
 * Reduced motion never reaches here: the callers show the poster and do not mount the stage.
 */

const UP = new Vector3(0, 1, 0)
const HERO_HOME = { dir: [0.5, 0.7, 0.62], dist: 168, at: [0, -10, 0], fov: 30 }
/** A drag across the whole stage width turns the watch half a turn. */
const SPIN_GAIN = Math.PI
const POINT_NAMES = ['watch', 'case', 'dial', 'subdial6', 'hands', 'crown', 'pushers', 'bracelet', 'clasp', 'caseback', 'movement', 'crystal', 'bezel', 'tachy']

const tmpPos = new Vector3()
const tmpAt = new Vector3()
const tmpAtA = new Vector3()
const tmpAtB = new Vector3()
const tmpDir = new Vector3()
const tmpRight = new Vector3()
const tmpUp = new Vector3()
const tmpP = new Vector3()
const lerp = (a, b, t) => a + (b - a) * t

function Watch({ src, phone, mode, progressRef, timeRef, pointerRef, dragRef, pausedRef, wide, onReady, onFrame, atmo, drift, upgrade, onUpgrade }) {
  const { scene } = useGLTF(src, false, true)
  const gl = useThree((s) => s.gl)
  const camera = useThree((s) => s.camera)
  const root = useThree((s) => s.scene)
  const invalidate = useThree((s) => s.invalidate)
  const size = useThree((s) => s.size)
  const group = useRef()
  const key = useRef()
  const shadow = useRef()
  const intro = useRef({ start: null, done: false })
  const lean = useRef({ x: 0, y: 0 })
  const lastFov = useRef(0)
  const points = useRef({})
  const idleLean = useRef({ x: 0, y: 0 })
  // the drag spin: angle (rad) and its velocity (rad/frame); the parent feeds normalised horizontal travel
  const spin = useRef({ angle: 0, vel: 0 })
  const swayTimer = useRef(0)

  const prepared = useMemo(() => prepareWatch(scene, { phone }), [scene, phone])
  const drawn = useMemo(() => {
    try {
      return buildBatches(prepared)
    } catch {
      return null // plain meshes below
    }
  }, [prepared])
  const shadowMap = useMemo(() => shadowTexture(), [])

  // onReady through a ref: the pages pass a new arrow every render, and an effect keyed on it re-announced "ready"
  // after any re-render — which is how a lost context's setLive(false) was undone within the same tick (2026-10-29)
  const readyRef = useRef(onReady)
  useEffect(() => {
    readyRef.current = onReady
  }, [onReady])
  useEffect(() => {
    let alive = true
    const ready = () => {
      if (alive) {
        invalidate()
        readyRef.current?.()
      }
    }
    if (typeof gl.compileAsync === 'function') gl.compileAsync(root, camera).then(ready, ready)
    else ready()
    return () => {
      alive = false
    }
  }, [gl, camera, root, invalidate])

  // The full textures, after the first model has drawn: each file decoded off the main thread and put onto the SAME
  // texture object the first model drew with (its slot, its colour space, flipY false as glTF has it) — the shader does
  // not change, nothing recompiles, the frame only sharpens. A file that fails leaves the first texture in place.
  const upgradeRef = useRef(onUpgrade)
  useEffect(() => {
    upgradeRef.current = onUpgrade
  }, [onUpgrade])
  useEffect(() => {
    if (!upgrade) return undefined
    let alive = true
    const run = async () => {
      let manifest
      try {
        manifest = await (await fetch(upgrade)).json()
      } catch {
        upgradeRef.current?.(1, true)
        return
      }
      const jobs = []
      for (const m of prepared.materials) {
        const entry = manifest[m.name]
        if (!entry) continue
        for (const [slot, url] of Object.entries(entry)) if (m[slot]) jobs.push({ tex: m[slot], url })
      }
      // one file per texture object (two slots can share one)
      const byTex = new Map()
      for (const j of jobs) if (!byTex.has(j.tex)) byTex.set(j.tex, j.url)
      let done = 0
      const total = byTex.size || 1
      await Promise.all(
        [...byTex].map(async ([tex, url]) => {
          try {
            const blob = await (await fetch(url)).blob()
            const bitmap = await createImageBitmap(blob, { imageOrientation: 'none', premultiplyAlpha: 'none', colorSpaceConversion: 'none' })
            if (!alive) return
            tex.image = bitmap
            tex.needsUpdate = true
            invalidate()
          } catch {
            /* the first model's texture stays */
          }
          done += 1
          if (alive) upgradeRef.current?.(done / total, done === total)
        }),
      )
      if (alive && byTex.size === 0) upgradeRef.current?.(1, true)
    }
    run()
    return () => {
      alive = false
    }
  }, [upgrade, prepared, invalidate])

  useFrame((state) => {
    const g = group.current
    if (!g) return
    const cam = state.camera
    // the drag spin, weighty: travel while the finger is down, inertia after; the story eases the angle back to the
    // choreography's pose, the hero keeps it (the visitor left the watch there)
    const sp = spin.current
    const d = dragRef?.current
    let spinning = false
    if (d && d.active) {
      const dv = takeDrag(d) * SPIN_GAIN
      sp.angle += dv
      sp.vel = sp.vel * 0.6 + dv * 0.4
      spinning = true
    } else {
      sp.angle += sp.vel
      sp.vel *= 0.93
      if (mode === 'story') sp.angle *= 0.955 // eases back over ~1.5 s
      spinning = Math.abs(sp.vel) > 0.0004 || (mode === 'story' && Math.abs(sp.angle) > 0.002)
    }
    if (mode === 'story') {
      const p = progressRef.current
      const f = sample(p)
      applyExplode(prepared.parts, f.explode)
      prepared.root.updateMatrixWorld(true)
      drawn?.sync()
      g.rotation.y = f.turn + sp.angle * idleAt(p) // the drag counts at the two idle ends only
      if (spinning) invalidate()

      // the camera: both keys resolved to the model's frame, then eased between
      const { a, b, t } = f.cam
      resolveAnchor(prepared, a.at, tmpAtA)
      resolveAnchor(prepared, b.at, tmpAtB)
      tmpAt.lerpVectors(tmpAtA, tmpAtB, t)
      tmpDir.set(lerp(a.dir[0], b.dir[0], t), lerp(a.dir[1], b.dir[1], t), lerp(a.dir[2], b.dir[2], t)).normalize()
      let dist = lerp(a.dist, b.dist, t) * MM
      const fov = lerp(a.fov, b.fov, t)
      // the idle pose at either end: the watch leans toward the pointer (a phone: a slow drift), weighty, eased —
      // its weight fades over the first 3 % of scroll, so the hand-over to the choreography is seamless
      const idle = idleAt(p)
      const lean = idleLean.current
      if (idle > 0.001) {
        const tt = timeRef.current
        const want = drift ? { x: Math.sin(tt * 0.23) * 0.6, y: Math.sin(tt * 0.17 + 1.2) * 0.35 } : pointerRef.current
        lean.x += (want.x - lean.x) * 0.045
        lean.y += (want.y - lean.y) * 0.045
        const yaw = lean.x * 0.16 * idle // ±9°
        const pitch = -lean.y * 0.11 * idle // ±6°
        tmpDir.applyAxisAngle(UP, yaw)
        tmpRight.crossVectors(tmpDir, UP).normalize()
        tmpDir.applyAxisAngle(tmpRight, pitch).normalize()
        if (Math.abs(want.x - lean.x) > 0.002 || Math.abs(want.y - lean.y) > 0.002 || drift) invalidate()
      } else if (lean.x || lean.y) {
        lean.x *= 0.9
        lean.y *= 0.9
      }
      const side = wide ? lerp(a.side, b.side, t) * wide : 0 // ±1 mirrors the shift for a right-to-left page
      const aspect = size.width / Math.max(1, size.height)
      const portrait = aspect < 1
      // a portrait screen is narrow: fit the view to its width (the keys are set for landscape); a key may carry its own
      // portrait distance multiple (pDistMul) and lift (pLift); a centred whole-watch key needs less of the fit
      const liftOf = (k) => (portrait && k.pLift !== undefined ? k.pLift : k.lift)
      const lift = lerp(liftOf(a), liftOf(b), t)
      if (portrait) {
        const fit = (k) => (k.pDistMul !== undefined ? k.pDistMul : Math.pow(1 / aspect, Math.abs(k.side) < 0.01 && Math.abs(k.lift) < 0.01 ? 0.17 : 0.85))
        dist *= lerp(fit(a), fit(b), t)
      }
      tmpPos.copy(tmpAt).addScaledVector(tmpDir, dist)
      tmpPos.applyAxisAngle(UP, f.turn)
      tmpAt.applyAxisAngle(UP, f.turn)
      tmpRight.subVectors(tmpAt, tmpPos).cross(UP).normalize()
      if (side) {
        const k = dist * 0.2 * side
        tmpPos.addScaledVector(tmpRight, -k)
        tmpAt.addScaledVector(tmpRight, -k)
      }
      if (lift) {
        // along the SCREEN's up (a face-on view looks down the world's up, where that axis would do nothing)
        const k = lift * dist * (portrait ? 0.32 : 0.16)
        tmpUp.crossVectors(tmpRight, tmpDir).normalize()
        tmpPos.addScaledVector(tmpUp, -k)
        tmpAt.addScaledVector(tmpUp, -k)
      }
      cam.position.copy(tmpPos)
      cam.up.copy(UP)
      cam.lookAt(tmpAt)
      if (Math.abs(fov - lastFov.current) > 0.01) {
        cam.fov = fov
        cam.updateProjectionMatrix()
        lastFov.current = fov
      }
      cam.updateMatrixWorld()

      // light, environment, exposure, shadow
      const L = f.light
      if (key.current) {
        key.current.intensity = L.key * 0.25 // cut on 2026-10-28/29: the direct lights greyed the black dial
        key.current.position.set(L.keyX, 0.5, 0.4)
      }
      state.scene.environmentIntensity = L.env
      // bind the loaded environment to every material once, so each material's own share of it applies (three
      // 0.185 ignores material.envMapIntensity while material.envMap is null); the timeline's env scales each share
      bindEnvironment(prepared, state.scene.environment, L.env)
      state.gl.toneMappingExposure = L.exp
      if (shadow.current) shadow.current.material.opacity = L.shadow

      // the atmosphere, from the scene track and the clock
      atmo.current?.set(f.scene, { p, time: timeRef.current, lightPos: f.scene.lightPos })

      // the parts on screen, for the callout lines
      const out = points.current
      for (const name of POINT_NAMES) {
        if (!prepared.anchors[name]) continue
        resolveAnchor(prepared, name, tmpP)
        tmpP.applyAxisAngle(UP, f.turn)
        tmpP.project(cam)
        const vis = tmpP.z < 1 && tmpP.x > -0.92 && tmpP.x < 0.92 && tmpP.y > -0.85 && tmpP.y < 0.85
        const o = out[name] || (out[name] = [0, 0, false])
        o[0] = (tmpP.x + 1) * 0.5 * size.width
        o[1] = (1 - tmpP.y) * 0.5 * size.height
        o[2] = vis
      }
      onFrame?.(f, out)
      return
    }
    // hero: the poster's pose is the first frame (no turn-in, so the swap from the still is seamless); a 3 s light
    // sweep across the steel, the drag spin, a small lean toward the pointer, and a slow sway while nothing else moves
    const s = intro.current
    const now = state.clock.elapsedTime
    if (s.start === null) s.start = now
    const tt = Math.min(1, (now - s.start) / 3)
    const e = 1 - Math.pow(1 - tt, 3)
    const target = lean.current
    const want = pointerRef?.current || { x: 0, y: 0 }
    target.x += (want.x - target.x) * 0.08
    target.y += (want.y - target.y) * 0.08
    const paused = pausedRef?.current === true
    const sway = paused || (d && d.active) ? 0 : 1
    g.rotation.y = sp.angle + target.x * 0.14 + Math.sin(now * 0.31) * 0.07 * sway
    g.rotation.x = target.y * 0.08 + Math.sin(now * 0.23 + 1.1) * 0.025 * sway
    if (key.current) key.current.position.set(-0.5 + 1.0 * e, 0.4, 0.35)
    const moving = tt < 1 || spinning || Math.abs(want.x - target.x) > 0.002 || Math.abs(want.y - target.y) > 0.002
    if (moving) invalidate()
    else if (!paused && !swayTimer.current) {
      // the sway alone: a frame every ~70 ms (≈ 14 fps) — motion a visitor reads as alive, at a fraction of the cost
      swayTimer.current = setTimeout(() => {
        swayTimer.current = 0
        invalidate()
      }, 70)
    }
    if (!s.done && tt >= 1) s.done = true
  })
  useEffect(() => () => clearTimeout(swayTimer.current), [])

  // the hero draws the hierarchy directly once (batches too, synced once)
  useEffect(() => {
    if (mode !== 'story') {
      prepared.root.updateMatrixWorld(true)
      drawn?.sync()
      invalidate()
    }
  }, [mode, prepared, drawn, invalidate])

  const bottom = prepared.bounds.min.y
  const wideSpan = Math.max(prepared.size.x, prepared.size.z)

  return (
    <>
      <directionalLight ref={key} position={[-0.2, 0.5, 0.4]} intensity={0.35} color="#fff6e8" />
      <group ref={group}>
        {drawn ? (
          <>
            {drawn.batches.map((b, i) => (
              <primitive key={i} object={b} />
            ))}
            {drawn.plain.map((m, i) => (
              <primitive key={`p${i}`} object={m} />
            ))}
          </>
        ) : (
          <primitive object={prepared.root} />
        )}
        {shadowMap ? (
          <mesh ref={shadow} position={[prepared.centre.x, bottom - 0.0015, prepared.centre.z]} rotation={[-Math.PI / 2, 0, 0]}>
            <planeGeometry args={[wideSpan * 1.6, wideSpan * 1.6]} />
            <meshBasicMaterial map={shadowMap} transparent opacity={0.6} depthWrite={false} side={DoubleSide} />
          </mesh>
        ) : null}
      </group>
    </>
  )
}

class StageBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  componentDidCatch() {}
  render() {
    if (this.state.failed) {
      this.props.onFail?.()
      return null
    }
    return this.props.children
  }
}

class EnvBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  componentDidCatch() {}
  render() {
    return this.state.failed ? null : this.props.children
  }
}

// the real loading progress (the model, its textures, the environment) through three's default loading manager
function Progress({ onProgress }) {
  const { progress, active } = useProgress()
  useEffect(() => {
    onProgress?.(progress, active)
  }, [progress, active, onProgress])
  return null
}

const Invalidator = forwardRef(function Invalidator(_, ref) {
  const invalidate = useThree((s) => s.invalidate)
  useImperativeHandle(ref, () => ({ invalidate }), [invalidate])
  return null
})

/**
 * @param {{ src: string, env?: string, phone?: boolean, mode: 'story'|'hero', wide?: 0|1|-1, words?: Array, font?: string, wordColor?: string, onReady?: () => void, onFail?: () => void, onFrame?: (frame, points) => void, onProgress?: (pct: number, active: boolean) => void, onLost?: () => void, onRestored?: () => void }} props
 * ref: `{ setProgress(p), setPointer(x, y), tick(seconds), dragStart(), dragMove(dxFraction), dragEnd(), setPaused(b) }` —
 * a drag turns the watch (half a turn per stage width, with inertia; the story eases it back); p in [0, 1] across the page; tick advances the
 * environment's clock and asks for a frame.
 */
const WatchStage = forwardRef(function WatchStage({ src, env, phone = false, mode = 'story', wide = 0, words = [], font, wordColor, onReady, onFail, onFrame, onProgress, onLost, onRestored, upgrade, onUpgrade }, ref) {
  const progressRef = useRef(0)
  const timeRef = useRef(0)
  const pointerRef = useRef({ x: 0, y: 0 })
  const dragRef = useRef({ active: false, dx: 0 })
  const pausedRef = useRef(false)
  const inv = useRef(null)
  const atmo = useRef(null)
  useImperativeHandle(
    ref,
    () => ({
      setProgress(p) {
        const next = Math.min(1, Math.max(0, Number(p) || 0))
        if (Math.abs(next - progressRef.current) < 0.00005) return
        progressRef.current = next
        inv.current?.invalidate()
      },
      setPointer(x, y) {
        pointerRef.current = { x: Math.max(-1, Math.min(1, x)), y: Math.max(-1, Math.min(1, y)) }
        inv.current?.invalidate()
      },
      tick(seconds) {
        timeRef.current = seconds
        inv.current?.invalidate()
      },
      // a drag: start, then horizontal travel as a fraction of the stage's width (signed), then release
      dragStart() {
        dragRef.current.active = true
        dragRef.current.dx = 0
      },
      dragMove(dxFraction) {
        if (!dragRef.current.active) return
        dragRef.current.dx += Math.max(-0.5, Math.min(0.5, Number(dxFraction) || 0))
        inv.current?.invalidate()
      },
      dragEnd() {
        dragRef.current.active = false
        inv.current?.invalidate()
      },
      // off screen or hidden: no idle frames at all
      setPaused(paused) {
        pausedRef.current = !!paused
        if (!paused) inv.current?.invalidate()
      },
    }),
    [],
  )

  const heroCam = useMemo(() => {
    const d = new Vector3().fromArray(HERO_HOME.dir).normalize()
    const at = new Vector3().fromArray(HERO_HOME.at)
    const pos = at.clone().addScaledVector(d, HERO_HOME.dist).multiplyScalar(MM)
    return { pos: pos.toArray(), at: at.multiplyScalar(MM).toArray() }
  }, [])

  return (
    <StageBoundary onFail={onFail}>
      <Canvas
        frameloop="demand"
        dpr={[1, 1.5]}
        gl={{
          alpha: true,
          antialias: true,
          powerPreference: 'high-performance',
          failIfMajorPerformanceCaveat: false,
          preserveDrawingBuffer: false,
          toneMapping: NeutralToneMapping, // Khronos neutral: ACES lifted the dial's darks to grey (2026-10-29)
          toneMappingExposure: 0.92,
          outputColorSpace: SRGBColorSpace,
        }}
        camera={{ fov: HERO_HOME.fov, near: 0.004, far: 5, position: heroCam.pos }}
        onCreated={({ gl, camera, invalidate }) => {
          camera.lookAt(heroCam.at[0], heroCam.at[1], heroCam.at[2])
          const el = gl.domElement
          // a lost context: preventDefault tells the browser we will take a restored one; the page shows its poster
          // meanwhile (onLost) and fades the canvas back once three has rebuilt its GL state (onRestored). Note: leaving
          // the page also logs a loss — fiber calls forceContextLoss() on unmount to free the GPU at once; that one is
          // intended and never reaches these callbacks (the element is gone).
          el.addEventListener('webglcontextlost', (e) => { e.preventDefault(); onLost?.() }, false)
          el.addEventListener('webglcontextrestored', () => { invalidate(); onRestored?.() }, false)
        }}
      >
        <Invalidator ref={inv} />
        {onProgress ? <Progress onProgress={onProgress} /> : null}
        {mode === 'story' ? <Atmosphere ref={atmo} tier={phone ? 'lite' : 'full'} words={words} font={font} wordColor={wordColor} /> : null}
        <ambientLight intensity={0.02} />
        <directionalLight position={[0.3, 0.6, 0.45]} intensity={0.12} color="#fff8ee" />
        <directionalLight position={[-0.5, 0.25, -0.3]} intensity={0.16} color="#dfe8ff" />
        <Suspense fallback={null}>
          {env ? (
            <EnvBoundary>
              <Environment files={env} />
            </EnvBoundary>
          ) : null}
          <Watch src={src} phone={phone} mode={mode} progressRef={progressRef} timeRef={timeRef} pointerRef={pointerRef} dragRef={dragRef} pausedRef={pausedRef} upgrade={upgrade} onUpgrade={onUpgrade} wide={wide} onReady={onReady} onFrame={onFrame} atmo={atmo} drift={phone} />
        </Suspense>
      </Canvas>
    </StageBoundary>
  )
})

export default WatchStage
