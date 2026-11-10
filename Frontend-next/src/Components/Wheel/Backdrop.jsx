'use client'
import { useEffect, useRef } from 'react'

// THE LIVING SKY OF /spin (2026-11-08, WATCHIZER ONLY; the carnival version). Fixed to the viewport, behind everything:
//
//   the sky        a violet-to-magenta gradient with two soft clouds of colour that drift on CSS transforms, and a
//                  sunburst of gold rays turning slowly behind the wheel (one conic gradient, one rotation)
//   the sparkles   a small canvas of gold and white motes that twinkle and rise; one requestAnimationFrame loop that
//                  starts once the page is idle and STOPS when the tab is hidden, device pixels capped at 1.5
//   the pointer    a warm glow follows the pointer and the stage tilts a few degrees towards it (parallax); on a phone
//                  the same from the device's tilt where the browser gives it (Android; iOS asks on the first touch)
//
// Everything is OFF under prefers-reduced-motion: the component then renders the still sky only (the CSS stops the
// drift and the rays) and binds nothing. Fixed, so a text re-wrap on the page never re-lays it out (layout shift 0).

const MOTES = 48

export default function Backdrop({ colors = [] }) {
  const rootRef = useRef(null)
  const canvasRef = useRef(null)
  const [c1 = '#7B3FE4', c2 = '#E0457B'] = colors

  useEffect(() => {
    const root = rootRef.current
    if (!root) return undefined
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reduced) return undefined
    const page = root.parentElement

    // ── the pointer: two CSS variables on the page, moved at most once a frame ──────────────────────
    let px = 0.5
    let py = 0.4
    let frame = 0
    const paint = () => {
      frame = 0
      page?.style.setProperty('--sp-mx', px.toFixed(3))
      page?.style.setProperty('--sp-my', py.toFixed(3))
    }
    const aim = (x, y) => {
      px = Math.min(1, Math.max(0, x))
      py = Math.min(1, Math.max(0, y))
      if (!frame) frame = requestAnimationFrame(paint)
    }
    const onMove = (e) => aim(e.clientX / window.innerWidth, e.clientY / window.innerHeight)
    const onTilt = (e) => {
      if (e.gamma == null || e.beta == null) return
      aim(0.5 + e.gamma / 60, 0.5 + (e.beta - 45) / 60)
    }
    const fine = window.matchMedia?.('(pointer: fine)').matches
    if (fine) window.addEventListener('pointermove', onMove, { passive: true })
    let tiltOn = false
    const startTilt = () => {
      if (tiltOn) return
      tiltOn = true
      window.addEventListener('deviceorientation', onTilt, { passive: true })
    }
    const askTilt = () => {
      window.removeEventListener('touchstart', askTilt)
      const ask = typeof DeviceOrientationEvent !== 'undefined' && DeviceOrientationEvent.requestPermission
      if (typeof ask === 'function') ask.call(DeviceOrientationEvent).then((r) => r === 'granted' && startTilt(), () => {})
      else startTilt()
    }
    if (!fine && 'ontouchstart' in window) {
      if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
        window.addEventListener('touchstart', askTilt, { passive: true })
      } else {
        startTilt()
      }
    }

    // ── the sparkles ──────────────────────────────────────────────────────────────────────────────
    const canvas = canvasRef.current
    const ctx = canvas?.getContext('2d')
    let motes = []
    let w = 0
    let h = 0
    let raf = 0
    let running = false
    const size = () => {
      if (!canvas || !ctx) return
      const dpr = Math.min(1.5, window.devicePixelRatio || 1)
      w = window.innerWidth
      h = window.innerHeight
      canvas.width = Math.floor(w * dpr)
      canvas.height = Math.floor(h * dpr)
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
      motes = Array.from({ length: MOTES }, () => mote(w, h, true))
    }
    const draw = (t) => {
      if (!running || !ctx) return
      ctx.clearRect(0, 0, w, h)
      for (const m of motes) {
        m.y -= m.vy
        m.x += Math.sin(t / 1800 + m.seed) * 0.14
        if (m.y < -6) Object.assign(m, mote(w, h, false))
        const twinkle = 0.55 + 0.45 * Math.sin(t / m.period + m.seed)
        ctx.globalAlpha = m.alpha * twinkle
        ctx.fillStyle = m.gold ? '#FFE9A8' : '#FFFFFF'
        ctx.beginPath()
        ctx.arc(m.x, m.y, m.r, 0, Math.PI * 2)
        ctx.fill()
      }
      ctx.globalAlpha = 1
      raf = requestAnimationFrame(draw)
    }
    const start = () => {
      if (running || document.hidden) return
      running = true
      raf = requestAnimationFrame(draw)
    }
    const stop = () => {
      running = false
      cancelAnimationFrame(raf)
    }
    const onVisibility = () => {
      page?.classList.toggle('is-paused', document.hidden)
      if (document.hidden) stop()
      else start()
    }
    const onResize = () => size()
    window.addEventListener('resize', onResize)
    document.addEventListener('visibilitychange', onVisibility)
    // the motes wait for the page to settle: the first seconds are the visitor's, not this
    const begin = () => {
      size()
      start()
    }
    const idle = typeof requestIdleCallback === 'function' ? requestIdleCallback(begin, { timeout: 2500 }) : setTimeout(begin, 1200)

    return () => {
      if (typeof cancelIdleCallback === 'function') cancelIdleCallback(idle)
      else clearTimeout(idle)
      stop()
      cancelAnimationFrame(frame)
      window.removeEventListener('resize', onResize)
      document.removeEventListener('visibilitychange', onVisibility)
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('deviceorientation', onTilt)
      window.removeEventListener('touchstart', askTilt)
      page?.classList.remove('is-paused')
    }
  }, [])

  return (
    <div className="sp-sky" ref={rootRef} aria-hidden="true">
      <div className="sp-sky__cloud sp-sky__cloud--1" style={{ '--c': c1 }} />
      <div className="sp-sky__cloud sp-sky__cloud--2" style={{ '--c': c2 }} />
      <div className="sp-sky__rays" />
      <div className="sp-sky__glow" />
      <canvas className="sp-sky__motes" ref={canvasRef} />
    </div>
  )
}

function mote(w, h, anywhere) {
  const gold = Math.random() < 0.5
  return {
    x: Math.random() * w,
    y: anywhere ? Math.random() * h : h + 6,
    r: gold ? 1 + Math.random() * 1.8 : 0.7 + Math.random() * 1.2,
    vy: 0.08 + Math.random() * 0.24,
    alpha: gold ? 0.55 + Math.random() * 0.4 : 0.3 + Math.random() * 0.4,
    period: 900 + Math.random() * 1600,
    seed: Math.random() * Math.PI * 2,
    gold,
  }
}
