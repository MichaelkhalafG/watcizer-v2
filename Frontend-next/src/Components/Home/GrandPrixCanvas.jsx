'use client'
import { Component, Suspense, memo, useCallback, useEffect, useRef, useState } from 'react'
import { Canvas, useFrame, useThree } from '@react-three/fiber'
import { OrbitControls, useEnvironment, useGLTF } from '@react-three/drei'
import { NeutralToneMapping, SRGBColorSpace } from 'three'
import { hasWebGL } from '../../utils/hasWebGL'
import { prepareWatch, buildBatchesInSteps, bindEnvironment, texturesOf } from '../Experience/watchModel'
import { prefilterEnvironment } from '../Experience/studioLight'

/*
 * THE GRAND PRIX BANNER'S CANVAS (2026-10-29) — the OLD 3D HERO'S BEHAVIOUR, setting for setting, around the
 * brand-supplied Hugo Boss model. Michael asked for the old hero exactly (src/Components/Hero/WatchCanvas.jsx, kept
 * untouched in the repo); every number below marked [OLD] is that file's, carried over as it is there:
 *
 *   [OLD] camera fov 38 at (0, 0.2, 4.0) — phones 5.0 — looking at the origin (the controls' target)
 *   [OLD] the watch's largest dimension normalised to 2.2 units — phones 2.8 — the scale on the GROUP, so the
 *         model's centre stays at the origin and it turns around itself (desktop now 2.35: Michael's 80–90 %)
 *   [OLD] the group nudged toward the hero's centre: −0.3 (right-to-left +0.3, the columns flip); phones 0 (was +0.4)
 *   the pose: FACE-ON from the first frame (Michael, 2026-10-29) — the old hero's 2.5 s swing from the side is gone,
 *         so the first frame is the poster's frame; the controls are live at once
 *   [OLD] OrbitControls: rotate 0.5, damping 0.08, zoom 0.8 within 3.5–5.8 (phones 4–9), polar 36°–144°, no pan,
 *         no auto-rotate; touch is the controls' own (one finger turns, two pinch-zoom; the page scrolls on the text)
 *   [OLD] frames only when something moves: 'never' off screen and while loading, 'always' for the intro,
 *         'demand' after it (the controls ask on every drag and while their damping settles)
 *   [OLD] the crystal as plain transparency (no transmission pass), shadows off, DPR 1–1.5, a lost context
 *         recoverable, a failed model or HDR ending at the poster
 *
 * WHAT DIFFERS, and why — only what the dial and this model need:
 *   • the model's dial faces up (+Y) in its file where the old model's faced the camera: one inner group turns it
 *     a quarter about X (12 o'clock up), so the old FROM / TO angles land on the dial exactly as they did;
 *   • the light is the experience page's (the dial must read black, Michael's rule): Khronos Neutral tone mapping
 *     at exposure 0.92 and three soft direct lights in place of ACES and the old 1.5 / 0.5 / 0.9 / ambient 0.3, the
 *     studio map bound to each material with the satin dial at 0.12 of it (watchModel.bindEnvironment — the old
 *     hero's envMapIntensity 1.2 never applied under three 0.185), the crystal at opacity 0.05 (0.12 greyed it);
 *   • the parts are drawn as one batch per material (27 draw calls, not 273: the phone's frame rate);
 *   • the materials are this canvas's own copies, so the experience page's stage (same cached file) is untouched;
 *   • the studio map is filtered BEFORE the watch's shaders are compiled, with the filter's own shaders compiled
 *     by the browser's graphics process first (studioLight.js, 2026-11-06): three did it inside the first compile
 *     and the page froze for 0.9–1.1 s after it had loaded. Same generator, same map, same picture;
 *   • the model is prepared, batched and its pictures handed to the graphics card in SHORT TASKS with a pause
 *     between them (2026-11-06) — the same work, never one long hold of the page; the canvas is shown, as before,
 *     only when everything its first frame needs is there.
 */

const isMobile = typeof window !== 'undefined' && window.innerWidth <= 768

// [OLD] 2.2 / 2.8; desktop 2.35 so the watch spans ~85 % of the banner's height (Michael, 2026-10-29: "80–90 %")
const TARGET_SIZE = isMobile ? 2.8 : 2.35
const CAMERA_Z = isMobile ? 5.0 : 4.0 // [OLD]
const MIN_DIST = isMobile ? 4 : 3.5 // [OLD]
const MAX_DIST = isMobile ? 9 : 5.8 // [OLD]
// [OLD] −0.3 / +0.3 on a wide screen; phones 0 (the old +0.4 suited the old model and put this one's case off the
// centre of the phone's column — measured on the capture, 2026-10-29)
const modelOffsetX = (isRTL) => (isMobile ? 0 : isRTL ? 0.3 : -0.3)
// The pose (Michael, 2026-10-29): FACE-ON — the dial square to the visitor from the first frame, the pose the poster
// is a still of. The old hero swung in from the side (x 0.45, y −1.6 → x 0.2, y −0.38, 2.5 s); a swing would make the
// first frame differ from the poster, so the start and the rest are one pose and the controls are live at once.
const FROM = { x: 0, y: 0 }
const TO = { x: 0, y: 0 }
const INTRO_DURATION = 0 // seconds
const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3) // [OLD]
// lets the page breathe between two pieces of the model's preparation (a visitor's tap or scroll gets in)
const pause = () => new Promise((resolve) => setTimeout(resolve, 0))


/**
 * `light`: undefined while the studio map is on its way, null when it could not be had (the watch is drawn without
 * it, as before), else the filtered map.
 */
function WatchModel({ src, light, onIntroDone, onLoaded, modelX }) {
  const { scene } = useGLTF(src, false, true)
  const group = useRef()
  const start = useRef(null)
  const done = useRef(false)
  const gl = useThree((state) => state.gl)
  const camera = useThree((state) => state.camera)
  const root = useThree((state) => state.scene)

  // the model, prepared once: its own material copies, the dial's share of the map, the crystal as plain glass,
  // one batch per material; centred and scaled like the old hero's
  const [built, setBuilt] = useState(null)
  const [broken, setBroken] = useState(null)
  useEffect(() => {
    let alive = true
    const build = async () => {
      await pause()
      const obj = scene.clone(true)
      // one copy per MATERIAL, shared by every mesh that shared it: the batches group by material, so a copy per mesh
      // made 273 one-part batches and 100–140 ms frames on a throttled phone (measured 2026-10-29)
      const copies = new Map()
      obj.traverse((o) => {
        if (!o.isMesh || !o.material || Array.isArray(o.material)) return
        if (!copies.has(o.material)) copies.set(o.material, o.material.clone())
        o.material = copies.get(o.material)
      })
      await pause()
      // phone: true → the crystal as plain transparency at opacity 0.05 (the old hero's choice, at the dial's opacity)
      const prepared = prepareWatch(obj, { phone: true })
      await pause()
      let drawn = null
      try {
        drawn = await buildBatchesInSteps(prepared, pause)
      } catch {
        drawn = null
      }
      const maxDim = Math.max(prepared.size.x, prepared.size.y, prepared.size.z) || 1
      if (alive) setBuilt({ drawn, prepared, scale: TARGET_SIZE / maxDim, centre: prepared.centre })
    }
    // a model that cannot be prepared ends at the poster, as it did when this ran inside the render
    build().catch((e) => alive && setBroken(e instanceof Error ? e : new Error('the model could not be prepared')))
    return () => {
      alive = false
    }
  }, [scene])

  // the old hero's ready signal: compile every shader the first frame needs, THEN show the canvas — with the studio
  // map already bound, so the first frame does not recompile (the map is filtered first: `light` is no longer
  // undefined)
  const readyRef = useRef(onLoaded)
  useEffect(() => {
    readyRef.current = onLoaded
  }, [onLoaded])
  useEffect(() => {
    if (light === undefined || !built) return undefined
    let alive = true
    const { prepared, drawn } = built
    bindEnvironment(prepared, light, 1)
    prepared.root.updateMatrixWorld(true)
    drawn?.sync()
    const ready = () => {
      if (alive) readyRef.current?.()
    }
    const warm = async () => {
      if (typeof gl.compileAsync === 'function') await gl.compileAsync(root, camera)
      // the model's pictures to the graphics card one per task: the first frame used to upload all of them at once
      if (typeof gl.initTexture !== 'function') return
      for (const texture of texturesOf(prepared.materials)) {
        await pause()
        if (!alive) return
        gl.initTexture(texture)
      }
    }
    warm().then(ready, ready)
    return () => {
      alive = false
    }
  }, [gl, camera, root, built, light])

  useFrame((state) => {
    if (!built) return
    // an HDR that lands late is bound on the next frame (a no-op once bound)
    bindEnvironment(built.prepared, state.scene.environment, 1)
    if (done.current || !group.current) return
    if (start.current === null) start.current = state.clock.elapsedTime
    const t = INTRO_DURATION > 0 ? Math.min((state.clock.elapsedTime - start.current) / INTRO_DURATION, 1) : 1 // [OLD]
    const e = easeOutCubic(t)
    group.current.rotation.x = FROM.x + (TO.x - FROM.x) * e
    group.current.rotation.y = FROM.y + (TO.y - FROM.y) * e
    if (t >= 1) {
      done.current = true
      onIntroDone()
    }
  })

  if (broken) throw broken
  if (!built) return null
  const { drawn, prepared, scale, centre } = built
  // [OLD] the group scales and turns around the origin, where the model's centre sits. Inside it: the quarter turn
  // that stands this model's dial up to face the camera, then the model moved so its centre is at the origin.
  return (
    <group ref={group} position={[modelX, 0, 0]} rotation={[FROM.x, FROM.y, 0]} scale={scale}>
      <group rotation={[Math.PI / 2, 0, 0]}>
        <group position={[-centre.x, -centre.y, -centre.z]}>
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
        </group>
      </group>
    </group>
  )
}

class ModelErrorBoundary extends Component {
  state = { hasError: false }
  static getDerivedStateFromError() {
    return { hasError: true }
  }
  componentDidCatch() {}
  render() {
    if (this.state.hasError) {
      this.props.onFail?.()
      return null // the poster under the canvas is the fallback
    }
    return this.props.children
  }
}

// The studio HDR: loaded (it suspends, beside the model), filtered off the page's critical moment, THEN made the
// scene's environment and handed to the model. [OLD] a map that lands late asks for one frame.
function StudioLight({ files, onLight }) {
  const equirect = useEnvironment({ files })
  const gl = useThree((state) => state.gl)
  const scene = useThree((state) => state.scene)
  const invalidate = useThree((state) => state.invalidate)
  useEffect(() => {
    let alive = true
    let target = null
    const give = (texture) => {
      scene.environment = texture
      onLight(texture)
      invalidate()
    }
    prefilterEnvironment(gl, equirect).then(
      (filtered) => {
        if (!alive) return filtered.dispose()
        target = filtered
        return give(filtered.texture)
      },
      // the filter could not be run ahead: three converts the map itself at the first compile, as it always did
      () => alive && give(equirect),
    )
    return () => {
      alive = false
      if (scene.environment === equirect || (target && scene.environment === target.texture)) scene.environment = null
      target?.dispose()
    }
  }, [gl, scene, equirect, onLight, invalidate])
  return null
}

// [OLD] a failed .hdr renders the watch without it rather than failing the canvas
class EnvBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  componentDidCatch() {
    this.props.onFail?.()
  }
  render() {
    if (this.state.failed) return null
    return this.props.children
  }
}

// [OLD] the canvas in a memoised component, so a parent re-render never remounts it (a remount leaks a context)
const Scene = memo(function Scene({ src, env, frameloop, introComplete, modelX, onIntroDone, onLoaded, onLost, onRestored }) {
  // the studio map for the model: undefined = on its way, null = not to be had, else the filtered map
  const [light, setLight] = useState(undefined)
  const noLight = useCallback(() => setLight(null), [])
  return (
    <Canvas
      frameloop={frameloop}
      dpr={[1, 1.5]}
      gl={{
        alpha: true,
        antialias: true,
        powerPreference: 'high-performance',
        failIfMajorPerformanceCaveat: false,
        preserveDrawingBuffer: false,
        toneMapping: NeutralToneMapping, // the dial's black (the old hero used ACES at 1 for its gold)
        toneMappingExposure: 0.92,
        outputColorSpace: SRGBColorSpace,
      }}
      camera={{ fov: 38, near: 0.1, far: 100, position: [0, 0.2, CAMERA_Z] }}
      onCreated={({ gl, invalidate }) => {
        // [OLD] a lost context is recoverable; the page shows its poster while it is away
        const el = gl.domElement
        el.addEventListener('webglcontextlost', (e) => { e.preventDefault(); onLost?.() }, false)
        el.addEventListener('webglcontextrestored', () => { invalidate(); onRestored?.() }, false)
      }}
    >
      {/* the experience page's light: soft, so the black dial stays black (the steel lives on the studio map) */}
      <ambientLight intensity={0.02} />
      <directionalLight position={[5, 5, 5]} intensity={0.3} color="#fff5e0" />
      <directionalLight position={[-5, 2, 2]} intensity={0.16} color="#dfe9ff" />
      <directionalLight position={[0, 3, -5]} intensity={0.12} color="#ffffff" />
      <Suspense fallback={null}>
        <EnvBoundary onFail={noLight}>
          <StudioLight files={env} onLight={setLight} />
        </EnvBoundary>
        <WatchModel src={src} light={light} onIntroDone={onIntroDone} onLoaded={onLoaded} modelX={modelX} />
      </Suspense>
      <OrbitControls
        makeDefault
        enabled={introComplete}
        enableRotate
        enableZoom
        enablePan={false}
        enableDamping
        dampingFactor={0.08}
        rotateSpeed={0.5}
        zoomSpeed={0.8}
        autoRotate={false}
        minDistance={MIN_DIST}
        maxDistance={MAX_DIST}
        minPolarAngle={Math.PI * 0.2}
        maxPolarAngle={Math.PI * 0.8}
      />
    </Canvas>
  )
})

/**
 * @param {{ src: string, env: string, isRTL: boolean, onReady?: () => void, onFail?: () => void, onLost?: () => void, onRestored?: () => void }} props
 */
export default function GrandPrixCanvas({ src, env, isRTL, onReady, onFail, onLost, onRestored }) {
  const [introComplete, setIntroComplete] = useState(false)
  const [modelLoaded, setModelLoaded] = useState(false)
  const modelX = modelOffsetX(isRTL)
  const webglOk = hasWebGL()

  // [OLD] off screen → no frames; loading → none (the compile must finish first); intro → every frame; then on demand
  const wrapRef = useRef(null)
  const [inView, setInView] = useState(true)
  useEffect(() => {
    const el = wrapRef.current
    if (!el || typeof IntersectionObserver === 'undefined') return undefined
    const obs = new IntersectionObserver(([entry]) => setInView(entry.isIntersecting), { threshold: 0 })
    obs.observe(el)
    return () => obs.disconnect()
  }, [])

  useEffect(() => {
    if (!webglOk) onFail?.()
  }, [webglOk, onFail])

  const handleIntroDone = useCallback(() => setIntroComplete(true), [])
  const handleLoaded = useCallback(() => {
    setModelLoaded(true)
    onReady?.()
  }, [onReady])

  if (!webglOk) return null

  return (
    <ModelErrorBoundary onFail={onFail}>
      <div ref={wrapRef} className="wz-gp__canvas-wrap">
        <Scene
          src={src}
          env={env}
          frameloop={!inView || !modelLoaded ? 'never' : !introComplete ? 'always' : 'demand'}
          introComplete={introComplete}
          modelX={modelX}
          onIntroDone={handleIntroDone}
          onLoaded={handleLoaded}
          onLost={onLost}
          onRestored={onRestored}
        />
      </div>
    </ModelErrorBoundary>
  )
}
