'use client'
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react'
import { useFrame, useThree } from '@react-three/fiber'
import { AdditiveBlending, CanvasTexture, Color, DoubleSide, LinearFilter, Mesh, OrthographicCamera, PlaneGeometry, Scene, ShaderMaterial, Vector2, Vector3, WebGLRenderTarget } from 'three'

/*
 * THE ATMOSPHERE — the environment behind and around the watch, inside the same WebGL canvas (2026-10-28,
 * Watchizer only; Michael's design pass: no black, a cinematic, scroll-tied environment, one render pass).
 *
 *   Backdrop   one full-screen quad drawn first (depth off): a two-tone gradient that breathes, depth haze from a
 *              few octaves of value noise, volumetric beams fanning out of a light point, a flare at the moments
 *              the timeline marks (the crystal lifting, the reassembly's reveal), a vignette and film grain. Every
 *              parameter is a uniform the stage sets from timeline.js `sceneAt(p)` each frame, so the environment
 *              changes character section by section and moves with the scroll.
 *   Particles  dust in the light: one Points draw, positions fixed in a volume around the watch, drift and
 *              twinkle computed in the vertex shader from time, occluded by the watch (depth test on, no write).
 *   Words      the oversized ghost words that sit BEHIND the watch with real depth: canvas-rendered text on a
 *              plane parented to the camera 0.6 m ahead — farther than any camera stands from the watch, so the
 *              case occludes them, and nearer than the backdrop. Parallax from the scroll.
 *
 * `tier` 'lite' (phones): the SAME look at lower cost — the backdrop shader drawn straight into the main pass (the
 *              separate render-target pass cost the throttled main thread ~8 ms a frame, 2026-10-28), two noise
 *              octaves, 120 particles instead of 480, no ghost-word planes.
 */

const BACKDROP_VERT = /* glsl */ `
varying vec2 vUv;
void main() { vUv = uv; gl_Position = vec4(position.xy, 0.99999, 1.0); }
`

// the composite: the low-resolution backdrop stretched over the screen, with grain added at full resolution
const COMPOSITE_FRAG = /* glsl */ `
precision mediump float;
varying vec2 vUv;
uniform sampler2D uMap; uniform vec2 uRes; uniform float uTime;
float hash(vec2 p) { vec3 p3 = fract(vec3(p.xyx) * 0.1031); p3 += dot(p3, p3.yzx + 33.33); return fract((p3.x + p3.y) * p3.z); }
void main() {
  vec3 col = texture2D(uMap, vUv).rgb;
  col += (hash(vUv * uRes + fract(uTime)) - 0.5) * 0.02;
  gl_FragColor = vec4(col, 1.0);
}
`

const BACKDROP_FRAG = /* glsl */ `
precision highp float;
varying vec2 vUv;
uniform vec3 uTop; uniform vec3 uBottom; uniform vec3 uLight;
uniform float uTime; uniform float uBeam; uniform float uBeamAngle; uniform float uHaze; uniform float uFlare; uniform float uVignette; uniform float uGlow;
uniform vec2 uLightPos; uniform vec2 uRes; uniform float uTier;

float hash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
}
float noise(vec2 p) {
  vec2 i = floor(p); vec2 f = fract(p); f = f * f * (3.0 - 2.0 * f);
  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), f.x), mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), f.x), f.y);
}
float fbm(vec2 p) {
  float v = 0.0; float a = 0.5;
  v += a * noise(p); p *= 2.03; a *= 0.5;
  v += a * noise(p); p *= 2.01; a *= 0.5;
  if (uTier > 0.5) v += a * noise(p);
  return v;
}
void main() {
  vec2 uv = vUv;
  float aspect = uRes.x / max(1.0, uRes.y);
  vec2 q = vec2((uv.x - 0.5) * aspect, uv.y - 0.5);
  // the gradient breathes: its boundary wanders with slow noise
  // one field of noise serves the gradient's wander and the haze (two would double the cost)
  float h = fbm(uv * vec2(2.2, 1.7) + vec2(uTime * 0.025, uTime * 0.01));
  float band = uv.y + 0.2 * (h - 0.5);
  vec3 col = mix(uBottom, uTop, smoothstep(-0.1, 1.1, band));
  // depth haze in the light's colour, drifting
  col += uLight * (h * h) * uHaze * 0.55;
  // volumetric beams fanning from the light point
  vec2 lp = vec2((uLightPos.x - 0.5) * aspect, uLightPos.y - 0.5);
  vec2 d = q - lp;
  float dist = length(d);
  float ang = atan(d.y, d.x) + uBeamAngle;
  // soft, uneven shafts: a few wide ones modulated by noise, never a clean sunburst
  float n1 = noise(vec2(ang * 2.2, uTime * 0.03)) * 0.6 + 0.2 * h;
  float rays = pow(0.5 + 0.5 * sin(ang * 5.0 + n1 * 4.0 + uTime * 0.05), 3.0) * (0.4 + 0.6 * n1);
  if (uTier > 0.5) rays = 0.7 * rays + 0.3 * pow(0.5 + 0.5 * sin(ang * 11.0 - uTime * 0.03 + n1 * 3.0), 4.0);
  float fall = exp(-dist * 1.6) * smoothstep(0.0, 0.25, dist);
  col += uLight * rays * fall * uBeam * 0.42;
  // the light's own glow and the flare
  float g = exp(-dist * dist * 9.0);
  col += uLight * g * uGlow * 0.7;
  col += (uLight * 0.6 + vec3(0.4)) * exp(-dist * dist * 22.0) * uFlare * 1.6;
  col += uLight * exp(-dist * 3.0) * uFlare * 0.5;
  // vignette and grain
  float v = smoothstep(0.35, 1.05, length(q) * 1.25);
  col *= 1.0 - uVignette * v;
  gl_FragColor = vec4(col, 1.0);
}
`

const PARTICLE_VERT = /* glsl */ `
attribute float aSeed;
uniform float uTime; uniform float uSize; uniform float uDpr;
varying float vAlpha;
void main() {
  vec3 p = position;
  p.x += sin(uTime * 0.21 + aSeed * 6.28) * 0.012;
  p.y += sin(uTime * 0.17 + aSeed * 9.1) * 0.01 + fract(aSeed * 7.3) * 0.004 * sin(uTime * 0.05);
  p.z += cos(uTime * 0.19 + aSeed * 4.4) * 0.012;
  vec4 mv = modelViewMatrix * vec4(p, 1.0);
  gl_Position = projectionMatrix * mv;
  float tw = 0.55 + 0.45 * sin(uTime * (0.8 + aSeed) + aSeed * 40.0);
  vAlpha = tw;
  gl_PointSize = uSize * uDpr * (0.6 + aSeed * 0.8) / max(0.05, -mv.z) * 0.02;
}
`

const PARTICLE_FRAG = /* glsl */ `
precision mediump float;
uniform vec3 uColor; uniform float uOpacity;
varying float vAlpha;
void main() {
  vec2 c = gl_PointCoord - 0.5;
  float d = length(c);
  float a = smoothstep(0.5, 0.05, d) * vAlpha * uOpacity;
  gl_FragColor = vec4(uColor, a);
}
`

const col = (hex) => new Color(hex)

/** Draws one word onto a canvas for a ghost plane. */
function wordTexture(text, { font, color, outline }) {
  if (typeof document === 'undefined') return null
  const c = document.createElement('canvas')
  c.width = 1536
  c.height = 384
  const ctx = c.getContext('2d')
  ctx.clearRect(0, 0, c.width, c.height)
  ctx.font = font
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  let size = 290
  ctx.font = font.replace(/\d+px/, `${size}px`)
  while (size > 60 && ctx.measureText(text).width > c.width * 0.96) {
    size -= 10
    ctx.font = font.replace(/\d+px/, `${size}px`)
  }
  if (outline) {
    ctx.lineWidth = 3
    ctx.strokeStyle = color
    ctx.strokeText(text, c.width / 2, c.height / 2)
  } else {
    ctx.fillStyle = color
    ctx.fillText(text, c.width / 2, c.height / 2)
  }
  const tex = new CanvasTexture(c)
  tex.anisotropy = 4
  return tex
}

/**
 * @param {{ tier: 'full'|'lite', words: Array<{ id: string, text: string, outline?: boolean }>, font: string }} props
 * ref: { set(scene, { p, time, light }) } — called by the stage every frame with timeline `sceneAt(p)`.
 */
const Atmosphere = forwardRef(function Atmosphere({ tier = 'full', words = [], font = '300 380px Georgia, serif', wordColor = 'rgba(242,237,227,0.14)' }, ref) {
  const size = useThree((s) => s.size)
  const viewport = useThree((s) => s.viewport)
  const camera = useThree((s) => s.camera)
  const backdrop = useRef()
  const points = useRef()
  const wordRefs = useRef([])
  const lite = tier === 'lite'

  const uniforms = useMemo(
    () => ({
      uTop: { value: col('#2c1c11') },
      uBottom: { value: col('#140d08') },
      uLight: { value: col('#e8a552') },
      uTime: { value: 0 },
      uBeam: { value: 0.3 },
      uBeamAngle: { value: 0 },
      uHaze: { value: 0.5 },
      uFlare: { value: 0 },
      uVignette: { value: 0.5 },
      uGlow: { value: 0.3 },
      uLightPos: { value: new Vector2(0.7, 0.75) },
      uRes: { value: new Vector2(1, 1) },
      uTier: { value: lite ? 0 : 1 },
    }),
    [lite],
  )

  const particles = useMemo(() => {
    const n = lite ? 120 : 480
    const pos = new Float32Array(n * 3)
    const seed = new Float32Array(n)
    for (let i = 0; i < n; i++) {
      pos[i * 3] = (Math.random() - 0.5) * 0.36
      pos[i * 3 + 1] = (Math.random() - 0.5) * 0.3
      pos[i * 3 + 2] = (Math.random() - 0.5) * 0.36
      seed[i] = Math.random()
    }
    return { n, pos, seed }
  }, [lite])
  // the backdrop's own little scene: one quad rendered into a render target at a fraction of the screen
  const pass = useMemo(() => {
    const scene = new Scene()
    const cam = new OrthographicCamera(-1, 1, 1, -1, 0, 1)
    const mat = new ShaderMaterial({ vertexShader: BACKDROP_VERT, fragmentShader: BACKDROP_FRAG, uniforms, depthTest: false, depthWrite: false })
    const quad = new Mesh(new PlaneGeometry(2, 2), mat)
    quad.frustumCulled = false
    scene.add(quad)
    const rt = new WebGLRenderTarget(2, 2, { minFilter: LinearFilter, magFilter: LinearFilter, depthBuffer: false, stencilBuffer: false })
    return { scene, cam, mat, quad, rt }
  }, [uniforms])
  const compositeUniforms = useMemo(() => ({ uMap: { value: pass.rt.texture }, uRes: { value: new Vector2(1, 1) }, uTime: { value: 0 } }), [pass])
  useEffect(
    () => () => {
      pass.rt.dispose()
      pass.mat.dispose()
      pass.quad.geometry.dispose()
    },
    [pass],
  )
  useFrame((state) => {
    if (lite) {
      // the phone tier draws the backdrop straight into the main pass (one render, two noise octaves)
      uniforms.uRes.value.set(state.size.width * state.viewport.dpr, state.size.height * state.viewport.dpr)
      return
    }
    // shade the backdrop at 1/2 of the screen, before the main render
    const scale = 1 / 2
    const w = Math.max(2, Math.round(state.size.width * state.viewport.dpr * scale))
    const h = Math.max(2, Math.round(state.size.height * state.viewport.dpr * scale))
    if (pass.rt.width !== w || pass.rt.height !== h) pass.rt.setSize(w, h)
    uniforms.uRes.value.set(w, h)
    compositeUniforms.uRes.value.set(state.size.width * state.viewport.dpr, state.size.height * state.viewport.dpr)
    compositeUniforms.uTime.value = uniforms.uTime.value
    const prev = state.gl.getRenderTarget()
    state.gl.setRenderTarget(pass.rt)
    state.gl.render(pass.scene, pass.cam)
    state.gl.setRenderTarget(prev)
  })

  const particleUniforms = useMemo(() => ({ uTime: { value: 0 }, uSize: { value: 90 }, uDpr: { value: 1 }, uColor: { value: col('#e8a552') }, uOpacity: { value: 0.5 } }), [])

  // the ghost words: one plane each, parented to the camera 0.6 m ahead
  const textures = useRef([])
  const textureFor = (i) => {
    const w = words[i]
    if (!w) return null
    if (!textures.current[i]) textures.current[i] = wordTexture(w.text, { font, color: wordColor, outline: !!w.outline })
    return textures.current[i]
  }
  useEffect(() => {
    const list = textures.current
    return () => list.forEach((t) => t?.dispose())
  }, [])

  useEffect(() => {
    particleUniforms.uDpr.value = Math.min(2, viewport.dpr || 1)
  }, [viewport.dpr, particleUniforms])

  const tmp = useRef(new Vector3())
  useImperativeHandle(
    ref,
    () => ({
      set(scene, { p, time, lightPos }) {
        const u = uniforms
        u.uTop.value.set(scene.top)
        u.uBottom.value.set(scene.bottom)
        u.uLight.value.set(scene.light)
        u.uTime.value = time
        u.uBeam.value = scene.beam
        u.uBeamAngle.value = scene.beamAngle
        u.uHaze.value = scene.haze
        u.uFlare.value = scene.flare
        u.uVignette.value = scene.vignette
        u.uGlow.value = scene.glow
        if (lightPos) u.uLightPos.value.set(lightPos[0], lightPos[1])
        particleUniforms.uTime.value = time
        particleUniforms.uColor.value.set(scene.light)
        particleUniforms.uOpacity.value = scene.particles * (lite ? 0.7 : 1)
        // the words: the current section's word ahead of the camera, sliding with the scroll
        const vis = Math.max(0, Math.min(1, scene.word))
        wordRefs.current.forEach((m, i) => {
          if (!m) return
          const w = words[i]
          const on = w && w.id === scene.wordId
          m.visible = !!on && vis > 0.01
          if (!on) return
          if (!m.material.map) {
            m.material.map = textureFor(i)
            m.material.needsUpdate = true
          }
          m.material.opacity = vis
          // at 0.6 m the view is 0.6 * 2 * tan(fov/2) tall; keep the plane that wide
          const h = 0.6 * 2 * Math.tan((camera.fov * Math.PI) / 360)
          const aspect = size.width / Math.max(1, size.height)
          const wide = h * aspect * 1.25
          m.scale.set(wide, wide / 4, 1)
          m.position.set((scene.wordX + (0.5 - p) * 0.6) * wide * 0.5, scene.wordY * h * 0.5, -0.6)
          tmp.current.set(0, 0, 0)
        })
      },
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [uniforms, particleUniforms, words, lite, camera, size],
  )

  return (
    <>
      <mesh ref={backdrop} frustumCulled={false} renderOrder={-10}>
        <planeGeometry args={[2, 2]} />
        {lite ? (
          <shaderMaterial vertexShader={BACKDROP_VERT} fragmentShader={BACKDROP_FRAG} uniforms={uniforms} depthTest={false} depthWrite={false} />
        ) : (
          <shaderMaterial vertexShader={BACKDROP_VERT} fragmentShader={COMPOSITE_FRAG} uniforms={compositeUniforms} depthTest={false} depthWrite={false} />
        )}
      </mesh>
      <points ref={points} frustumCulled={false} renderOrder={-5}>
        <bufferGeometry>
          <bufferAttribute attach="attributes-position" args={[particles.pos, 3]} />
          <bufferAttribute attach="attributes-aSeed" args={[particles.seed, 1]} />
        </bufferGeometry>
        <shaderMaterial vertexShader={PARTICLE_VERT} fragmentShader={PARTICLE_FRAG} uniforms={particleUniforms} transparent depthWrite={false} blending={AdditiveBlending} />
      </points>
      <primitive object={camera}>
        {words.map((w, i) => (
            <mesh
              key={w.id}
              ref={(el) => {
                wordRefs.current[i] = el
              }}
              position={[0, 0, -0.6]}
              renderOrder={-4}
              visible={false}
            >
              <planeGeometry args={[1, 0.25]} />
              <meshBasicMaterial transparent opacity={0} depthWrite={false} side={DoubleSide} toneMapped={false} />
            </mesh>
        ))}
      </primitive>
    </>
  )
})

export default Atmosphere
