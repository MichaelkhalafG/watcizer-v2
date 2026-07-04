import { Component, Suspense, useEffect, useMemo, useRef, useState } from 'react'
import { Canvas, useFrame } from '@react-three/fiber'
import { OrbitControls, Environment, useGLTF } from '@react-three/drei'
import * as THREE from 'three'
import { useUIStore } from '../../Store/uiStore'
import logo from '../../assets/images/logo.webp'

// Draco-compressed model. drei's useGLTF attaches the Draco decoder by default,
// so the compressed .glb decodes with no extra wiring.
const MODEL_URL = '/Watchizer_Gold.glb'
useGLTF.preload(MODEL_URL)

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
const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3)

function WatchModel({ onIntroDone, onLoaded, modelX }) {
  const { scene } = useGLTF(MODEL_URL)
  const group = useRef()
  const start = useRef(null)
  const done = useRef(false)

  // This component only mounts once useGLTF has resolved (it's inside Suspense),
  // so this effect signals "model ready" → triggers the canvas fade-in.
  useEffect(() => {
    onLoaded?.()
  }, [onLoaded])

  // Centre the model at the origin (NO scale on the object — scale lives on the
  // group below, so centring is preserved and rotation is around the centre).
  const { obj, scale } = useMemo(() => {
    const obj = scene.clone(true)
    const box = new THREE.Box3().setFromObject(obj)
    const size = box.getSize(new THREE.Vector3())
    const center = box.getCenter(new THREE.Vector3())
    obj.position.sub(center) // geometric centre → world origin
    const maxDim = Math.max(size.x, size.y, size.z) || 1
    obj.traverse((o) => {
      if (o.isMesh && o.material) {
        o.material.envMapIntensity = 1.2
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
  state = { hasError: false }
  static getDerivedStateFromError() {
    return { hasError: true }
  }
  render() {
    if (this.state.hasError) return this.props.fallback
    return this.props.children
  }
}

export default function WatchCanvas() {
  // OrbitControls stay disabled until the intro animation finishes.
  const [introComplete, setIntroComplete] = useState(false)
  const { language } = useUIStore()
  const modelX = modelOffsetX(language === 'ar')
  // Canvas stays transparent (dark hero shows through) until the model is ready,
  // then fades in — so loading time never shows a spinner or affects LCP/CLS.
  const [modelLoaded, setModelLoaded] = useState(false)

  // ── GPU throttle ──────────────────────────────────────────────────────────
  // The Canvas defaults to frameloop="always" (renders 60fps forever). Left on,
  // it saturates the GPU even after the intro, starving CSS hover transitions on
  // the cards below → the "freeze on hover" lag. Render only while the hero is
  // actually on screen; stop completely once the user scrolls to the sliders.
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

  return (
    <ModelErrorBoundary
      fallback={
        <div className="wz-hero-fallback">
          <img src={logo} alt="Watchizer" />
        </div>
      }
    >
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
        <Canvas
          frameloop={inView ? 'always' : 'never'}
          dpr={[1, 1.5]}
          gl={{ alpha: true, antialias: true }}
          camera={{ fov: 38, near: 0.1, far: 100, position: [0, 0.2, CAMERA_Z] }}
        >
        {/* Lighting — env does most of the gold reflection work */}
        <ambientLight intensity={0.3} />
        <directionalLight position={[5, 5, 5]} intensity={1.5} color="#fff5e0" />
        <directionalLight position={[-5, 2, 2]} intensity={0.5} color="#dfe9ff" />
        <directionalLight position={[0, 3, -5]} intensity={0.9} color="#ffffff" />

        {/* HDR environment for realistic gold reflections (own Suspense so a
            slow/failed env load never blocks the model) */}
        <Suspense fallback={null}>
          <Environment preset="studio" />
        </Suspense>

        <Suspense fallback={null}>
          <WatchModel
            onIntroDone={() => setIntroComplete(true)}
            onLoaded={() => setModelLoaded(true)}
            modelX={modelX}
          />
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
      </div>
    </ModelErrorBoundary>
  )
}
