'use client'
import { Component, Suspense, memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Canvas, useFrame, useThree } from '@react-three/fiber'
import { OrbitControls, Environment, useGLTF } from '@react-three/drei'
import { Box3, Vector3, ACESFilmicToneMapping, SRGBColorSpace } from 'three'
import { useUIStore } from '../../Store/uiStore'
import { hasWebGL } from '../../utils/hasWebGL'

// Logo served from /public (Vite imported from src/assets; Next uses a URL string).
const logo = '/logo.webp'

// Draco-compressed model. drei's useGLTF attaches the Draco decoder by default,
// so the compressed .glb decodes with no extra wiring.
const MODEL_URL = '/Watchizer_Gold.glb'
useGLTF.preload(MODEL_URL)

// The studio lighting for the gold reflections, at 512×256 (2026-09-27). The original 1024×512
// file was 1.6 MB — the heaviest download on the home page; this one is 411 KB and was compared
// side by side with it at rest and turned: indistinguishable. 256×128 was tried and rejected (the
// bracelet goes greyer), so was a 1024 base-colour texture on the model (the hands' centre cap
// turns from black to gold). Made with a box-filter downsample of the same HDR.
const ENV_URL = '/studio_small_03_512.hdr'

const isMobile = typeof window !== 'undefined' && window.innerWidth <= 768

// Normalised world size of the watch's largest dimension (smaller = more
// breathing room). Scale is applied to the wrapping GROUP, not the model, so
// the model's geometric centre stays exactly at the origin (= rotation centre).
const TARGET_SIZE = isMobile ? 2.8 : 2.2
// Desktop default sits at the old min-zoom distance (the size users liked).
const CAMERA_Z = isMobile ? 5.0 : 4.0
const MIN_DIST = isMobile ? 4 : 3.5 // allow a touch closer than default
const MAX_DIST = isMobile ? 9 : 5.8 // out to the old default distance
// Nudge the watch toward the hero centre to fill the middle gap. On desktop the
// columns flip in RTL (watch moves to the left), so the offset flips sign too.
const modelOffsetX = (isRTL) => (isMobile ? 0.4 : isRTL ? 0.3 : -0.3)

// ── Intro animation angles (radians) ──────────────────────────────────────
// FROM = dramatic side/strap angle · TO = front-facing dial, slight tilt (like
// a product photo). Tweak TO if the model's natural front isn't facing camera.
const FROM = { x: 0.45, y: -1.6 }
const TO = { x: 0.2, y: -0.38 }
const INTRO_DURATION = 2.5 // seconds
// Opacity of the crystal once it is plain transparent glass (see WatchModel). Low: the dial must
// read through it; the clearcoat reflections are what make it look like glass.
const CRYSTAL_OPACITY = 0.12
const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3)

function WatchModel({ onIntroDone, onLoaded, modelX }) {
  const { scene } = useGLTF(MODEL_URL)
  const group = useRef()
  const start = useRef(null)
  const done = useRef(false)
  const gl = useThree((state) => state.gl)
  const camera = useThree((state) => state.camera)
  const root = useThree((state) => state.scene)

  // This component only mounts once the model AND the environment have resolved (one Suspense),
  // so every shader variant the first frame needs is known here. Compile them BEFORE any frame is
  // drawn (the canvas renders nothing until onLoaded — see the frameloop in WatchCanvas): with
  // compileAsync the browser can compile in parallel instead of freezing the page on the first
  // frame (2026-09-27). Then signal "model ready" → the canvas fades in and the intro starts.
  useEffect(() => {
    let alive = true
    const ready = () => {
      if (alive) onLoaded?.()
    }
    if (typeof gl.compileAsync === 'function') gl.compileAsync(root, camera).then(ready, ready)
    else ready()
    return () => {
      alive = false
    }
  }, [gl, camera, root, onLoaded])

  // Centre the model at the origin (NO scale on the object — scale lives on the
  // group below, so centring is preserved and rotation is around the centre).
  const { obj, scale } = useMemo(() => {
    const obj = scene.clone(true)
    const box = new Box3().setFromObject(obj)
    const size = box.getSize(new Vector3())
    const center = box.getCenter(new Vector3())
    obj.position.sub(center) // geometric centre → world origin
    const maxDim = Math.max(size.x, size.y, size.z) || 1
    obj.traverse((o) => {
      if (o.isMesh && o.material) {
        o.material.envMapIntensity = 1.2
        // Most metal parts ship white-based (color=#fff) with the gold in the
        // texture map; under three 0.185 that reads as neutral silver. Warm the
        // white metallic F0 to the model's own gold so it reads gold again.
        if (o.material.metalness >= 0.9 && o.material.color?.getHexString?.() === 'ffffff') {
          o.material.color.setHex(0xf0dda7)
        }
        // The crystal is modelled as physically accurate glass (transmission + volume). For that,
        // three.js renders the whole scene a SECOND time into an off-screen buffer on every frame,
        // and compiles its most expensive shader — measured as the largest single block of main-
        // thread time the hero costs on a throttled phone (2026-09-27). Plain transparency with the
        // same clearcoat keeps the look of glass over the dial: reflections, no second pass.
        if (o.material.transmission > 0) {
          o.material.transmission = 0
          o.material.thickness = 0
          o.material.transparent = true
          o.material.opacity = CRYSTAL_OPACITY
          o.material.depthWrite = false
          o.material.needsUpdate = true
        }
        o.castShadow = false
        o.receiveShadow = false
      }
    })
    return { obj, scale: TARGET_SIZE / maxDim }
  }, [scene])

  useFrame((state) => {
    if (done.current || !group.current) return
    if (start.current === null) start.current = state.clock.elapsedTime
    const t = Math.min((state.clock.elapsedTime - start.current) / INTRO_DURATION, 1)
    const e = easeOutCubic(t)
    group.current.rotation.x = FROM.x + (TO.x - FROM.x) * e
    group.current.rotation.y = FROM.y + (TO.y - FROM.y) * e
    if (t >= 1) {
      done.current = true
      onIntroDone()
    }
  })

  // Group scales + rotates around the origin; the model's centre sits there.
  return (
    <group ref={group} position={[modelX, 0, 0]} rotation={[FROM.x, FROM.y, 0]} scale={scale}>
      <primitive object={obj} />
    </group>
  )
}

// If the model (or WebGL) fails, fall back to a clean branded panel instead of
// a broken/empty canvas.
class ModelErrorBoundary extends Component {
  state = { hasError: false, hasWebGLError: false }
  static getDerivedStateFromError() {
    return { hasError: true }
  }
  componentDidCatch(error) {
    // WebGL / context-creation failures are expected on some devices and during
    // rapid HMR — flag them and keep showing the static fallback instead of
    // letting the error propagate and crash the tree. (Defining this method at
    // all stops React from re-throwing; getDerivedStateFromError already swaps in
    // the fallback for any error, WebGL-related or not.)
    const msg = String(error?.message || error || '')
    if (/webgl|context/i.test(msg)) {
      this.setState({ hasError: true, hasWebGLError: true })
    }
  }
  render() {
    if (this.state.hasError) return this.props.fallback
    return this.props.children
  }
}

// In `demand` mode nothing redraws on its own, and the environment is set in an effect that does not
// ask for a frame — so an HDR that finishes downloading AFTER the intro would leave the watch without
// its reflections until the shopper touched it. This asks for one frame when it lands.
function InvalidateOnMount() {
  const invalidate = useThree((state) => state.invalidate)
  useEffect(() => {
    invalidate()
  }, [invalidate])
  return null
}

// Isolates the HDR environment. If the .hdr fails to load (missing file, offline,
// decode error), render the scene WITHOUT it — the watch just loses the env-map
// reflections and looks a touch darker, rather than the failure bubbling up to
// ModelErrorBoundary and replacing the whole canvas with the static fallback.
class EnvBoundary extends Component {
  state = { failed: false }
  static getDerivedStateFromError() {
    return { failed: true }
  }
  // Defining this (even empty) stops React from re-throwing the caught error.
  componentDidCatch() {}
  render() {
    if (this.state.failed) return null
    return this.props.children
  }
}

// The <Canvas> + scene, isolated in a MEMOIZED component so unrelated parent
// re-renders (e.g. the modelLoaded/opacity fade, language state) never remount
// the canvas — a remount leaks the old WebGL context and, across HMR/renders,
// walks into the browser's context cap ("context creation blocked"). Only the
// props below can change it, and R3F applies those in place without recreating
// the renderer.
const Scene = memo(function Scene({ frameloop, introComplete, modelX, onIntroDone, onLoaded }) {
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
        // Restore the R3F v8 / three 0.160 defaults — v9/three-0.185 changed how
        // metallic IBL reflections are tone-mapped, washing the gold to silver.
        toneMapping: ACESFilmicToneMapping,
        toneMappingExposure: 1,
        outputColorSpace: SRGBColorSpace,
      }}
      camera={{ fov: 38, near: 0.1, far: 100, position: [0, 0.2, CAMERA_Z] }}
      onCreated={({ gl, invalidate }) => {
        // Make a lost context recoverable instead of fatal: preventDefault lets
        // the browser restore it, and we repaint once it's back.
        const el = gl.domElement
        el.addEventListener('webglcontextlost', (e) => e.preventDefault(), false)
        el.addEventListener('webglcontextrestored', () => invalidate(), false)
      }}
    >
      {/* Lighting — env does most of the gold reflection work */}
      <ambientLight intensity={0.3} />
      <directionalLight position={[5, 5, 5]} intensity={1.5} color="#fff5e0" />
      <directionalLight position={[-5, 2, 2]} intensity={0.5} color="#dfe9ff" />
      <directionalLight position={[0, 3, -5]} intensity={0.9} color="#ffffff" />

      {/* HDR environment for realistic gold reflections. Loaded from a LOCAL
          copy in /public (no raw.githubusercontent.com runtime dependency, so it
          works offline / on slow networks). EnvBoundary + own Suspense mean a
          slow OR failed env load never blocks or crashes the model. */}
      {/* ONE Suspense for the environment and the model, so the model mounts with its lighting
          already in place and compiles its shaders once (WatchModel). EnvBoundary still isolates
          a failed .hdr: the watch then shows without env reflections instead of not at all. */}
      <Suspense fallback={null}>
        <EnvBoundary>
          <Environment files={ENV_URL} />
          <InvalidateOnMount />
        </EnvBoundary>
        <WatchModel onIntroDone={onIntroDone} onLoaded={onLoaded} modelX={modelX} />
      </Suspense>

      {/* target defaults to the origin — which is the model's centre, so the
          watch spins around itself like a turntable. */}
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
        minPolarAngle={Math.PI * 0.2} /* ~36° — no flipping over the top */
        maxPolarAngle={Math.PI * 0.8} /* ~144° — no flipping under */
      />
    </Canvas>
  )
})

// Static branded fallback — used when WebGL is unavailable AND when the renderer
// throws (via ModelErrorBoundary). Design unchanged; the panel is opaque so it
// fully covers the hero poster behind it.
const HeroFallback = (
  <div className="wz-hero-fallback">
    <img src={logo} alt="Watchizer" width="180" height="55" loading="lazy" />
  </div>
)

export default function WatchCanvas({ onReady }) {
  // OrbitControls stay disabled until the intro animation finishes.
  const [introComplete, setIntroComplete] = useState(false)
  const { language } = useUIStore()
  const modelX = modelOffsetX(language === 'ar')
  // Canvas stays transparent (dark hero shows through) until the model is ready,
  // then fades in — so loading time never shows a spinner or affects LCP/CLS.
  const [modelLoaded, setModelLoaded] = useState(false)

  // Probe WebGL up front. If the browser can't grant a context (blocked, cap
  // reached, unsupported), show the static fallback and NEVER mount <Canvas> — so
  // the WebGLRenderer that would throw during commit is never constructed.
  const webglOk = hasWebGL()

  // ── Render only when something moves (2026-09-27) ─────────────────────────
  // frameloop="always" redrew the watch 60 times a second for as long as the hero
  // was on screen — measured at ~9 s of blocked main thread on a throttled mid-range
  // phone profile, and the page never went idle. Now:
  //   • off screen            → 'never' (as before);
  //   • loading               → 'never': the canvas is still invisible, and a frame drawn
  //     before WatchModel's compileAsync finishes would compile the shaders synchronously;
  //   • after the intro        → 'demand': a frame only when asked. OrbitControls asks
  //     on every drag and while its damping settles, so rotating feels the same;
  //     the environment asks once when it lands (InvalidateOnMount);
  //   • the 2.5 s intro        → 'always', because it is driven frame by frame.
  const wrapRef = useRef(null)
  const [inView, setInView] = useState(true)
  useEffect(() => {
    const el = wrapRef.current
    if (!el || typeof IntersectionObserver === 'undefined') return
    const obs = new IntersectionObserver(([entry]) => setInView(entry.isIntersecting), {
      threshold: 0,
    })
    obs.observe(el)
    return () => obs.disconnect()
  }, [])

  // No WebGL → the static fallback is the final state; tell the parent so the
  // hero poster crossfades out (the opaque fallback panel covers it regardless).
  useEffect(() => {
    if (!webglOk) onReady?.()
  }, [webglOk, onReady])

  // Stable callbacks so the memoized <Scene> only re-renders on real prop changes
  // (not on the modelLoaded opacity flip), which keeps the canvas from remounting.
  const handleIntroDone = useCallback(() => setIntroComplete(true), [])
  const handleLoaded = useCallback(() => {
    setModelLoaded(true)
    onReady?.()
  }, [onReady])

  if (!webglOk) return HeroFallback

  return (
    <ModelErrorBoundary fallback={HeroFallback}>
      <div
        ref={wrapRef}
        className="wz-hero-canvas-wrap"
        style={{
          width: '100%',
          height: '100%',
          opacity: modelLoaded ? 1 : 0,
          transition: 'opacity 0.6s ease',
        }}
      >
        <Scene
          frameloop={!inView || !modelLoaded ? 'never' : !introComplete ? 'always' : 'demand'}
          introComplete={introComplete}
          modelX={modelX}
          onIntroDone={handleIntroDone}
          onLoaded={handleLoaded}
        />
      </div>
    </ModelErrorBoundary>
  )
}