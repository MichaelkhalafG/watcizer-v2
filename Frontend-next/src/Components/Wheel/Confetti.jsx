'use client'
import { useEffect, useRef } from 'react'

// THE CELEBRATION (2026-11-07; over the whole screen since 2026-11-08; paced since 2026-11-09 evening, WATCHIZER ONLY):
// ONE big burst at the result — two hundred and forty flakes in the won segment's colour, the brand's golds, pink and
// white, thrown up from the centre and falling for three seconds — then a SMALL one (sixty flakes) every nine seconds,
// and nothing at all after half a minute (Michael: the repeats pulled the eye off the code). One canvas FIXED to the
// viewport, drawn BEHIND the card (wheel.css: the card's column sits above it), so no flake ever crosses the code; one
// requestAnimationFrame loop that runs only while flakes are in the air and sleeps between bursts. Nothing while the
// visitor asked for reduced motion (the parent does not mount it then), nothing for "better luck" (not mounted either).

const BASE = ['#F0C75E', '#FFF0B0', '#FF5FA2', '#FFFFFF', '#7DD3FC']
export const BIG = 240
export const SMALL = 60
export const GAP_MS = 9000 // between the small bursts
export const STOP_MS = 30000 // no burst starts after this
const FALL_S = 3.2

export default function Confetti({ onDone, color = null }) {
  const ref = useRef(null)
  useEffect(() => {
    const canvas = ref.current
    if (!canvas) return undefined
    const ctx = canvas.getContext('2d')
    const dpr = Math.min(2, window.devicePixelRatio || 1)
    let w = window.innerWidth
    let h = window.innerHeight
    canvas.width = Math.floor(w * dpr)
    canvas.height = Math.floor(h * dpr)
    ctx.scale(dpr, dpr)
    const palette = color ? [color, color, color, ...BASE] : BASE
    const started = performance.now()
    let flakes = []
    let frame = 0
    let timer = 0
    let ended = false

    const burst = (count) => {
      const born = performance.now()
      for (let i = 0; i < count; i++) {
        flakes.push({
          born,
          x: w / 2 + (Math.random() - 0.5) * w * 0.3,
          y: h * 0.58 + (Math.random() - 0.5) * 30,
          vx: (Math.random() - 0.5) * (count === BIG ? 16 : 10),
          vy: -Math.random() * (count === BIG ? 14 : 9) - 6,
          size: 5 + Math.random() * 7,
          rot: Math.random() * Math.PI,
          vr: (Math.random() - 0.5) * 0.35,
          color: palette[Math.floor(Math.random() * palette.length)],
          shape: Math.random() < 0.3 ? 'dot' : 'flake',
        })
      }
      if (!frame) frame = requestAnimationFrame(draw)
    }
    const draw = (t) => {
      frame = 0
      ctx.clearRect(0, 0, w, h)
      flakes = flakes.filter((f) => (t - f.born) / 1000 < FALL_S)
      for (const f of flakes) {
        const age = (t - f.born) / 1000
        f.vy += 0.24
        f.vx *= 0.99
        f.x += f.vx
        f.y += f.vy
        f.rot += f.vr
        ctx.save()
        ctx.globalAlpha = Math.max(0, Math.min(1, FALL_S - age))
        ctx.translate(f.x, f.y)
        ctx.rotate(f.rot)
        ctx.fillStyle = f.color
        if (f.shape === 'dot') {
          ctx.beginPath()
          ctx.arc(0, 0, f.size / 2.4, 0, Math.PI * 2)
          ctx.fill()
        } else {
          ctx.fillRect(-f.size / 2, -f.size / 4, f.size, f.size / 2)
        }
        ctx.restore()
      }
      if (flakes.length > 0) frame = requestAnimationFrame(draw)
      else if (ended) onDone?.()
    }
    // the schedule: the big one now, a small one every GAP_MS while the half minute lasts, then done once the air is clear
    burst(BIG)
    const next = () => {
      const elapsed = performance.now() - started
      if (elapsed + GAP_MS > STOP_MS) {
        ended = true
        if (!frame && flakes.length === 0) onDone?.()
        return
      }
      timer = window.setTimeout(() => {
        if (document.hidden) {
          next()
          return
        }
        burst(SMALL)
        next()
      }, GAP_MS)
    }
    next()
    const onResize = () => {
      w = window.innerWidth
      h = window.innerHeight
      canvas.width = Math.floor(w * dpr)
      canvas.height = Math.floor(h * dpr)
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    }
    window.addEventListener('resize', onResize)
    return () => {
      cancelAnimationFrame(frame)
      clearTimeout(timer)
      window.removeEventListener('resize', onResize)
    }
  }, [onDone, color])
  return <canvas ref={ref} className="sp-confetti" aria-hidden="true" />
}
