import { useEffect, useRef, useState } from 'react'
import './WatchHero.css'

const WatchHero = () => {
  const containerRef = useRef(null)
  const [isVisible, setIsVisible] = useState(false)

  useEffect(() => {
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsVisible(true)
          observer.disconnect()
        }
      },
      { threshold: 0.1 },
    )
    if (containerRef.current) observer.observe(containerRef.current)
    return () => observer.disconnect()
  }, [])

  return (
    <div className="wz-hero">
      {/* Left — Text content */}
      <div className="wz-hero-text">
        {/* Top label */}
        <div className="wz-hero-label">
          <span className="wz-hero-label-line" />
          <span className="wz-hero-label-text">Swiss Mastery · Est. 2020</span>
        </div>

        {/* Main headline */}
        <h1 className="wz-hero-headline">
          The Art of
          <span className="wz-hero-headline-accent"> Timeless</span>
          <br />
          Precision
        </h1>

        {/* Body copy */}
        <p className="wz-hero-body">
          Discover our curated collection of authentic luxury timepieces — certified, guaranteed,
          and delivered to your door.
        </p>

        {/* CTA buttons */}
        <div className="wz-hero-cta">
          <button
            className="wz-hero-cta-primary"
            onClick={() => (window.location.href = '/listing')}
          >
            Explore Collection
            <span className="wz-hero-cta-arrow">→</span>
          </button>
          <button
            className="wz-hero-cta-secondary"
            onClick={() => (window.location.href = '/listing')}
          >
            View Brands
          </button>
        </div>
      </div>

      {/* Right — 3D Watch */}
      <div className="wz-hero-watch" ref={containerRef}>
        {isVisible ? (
          <iframe
            title="Watch 3D"
            frameBorder="0"
            allowFullScreen
            loading="lazy"
            allow="autoplay; fullscreen; xr-spatial-tracking"
            src="https://sketchfab.com/models/006fcbf9afca4ce690a742d655445881/embed?autostart=1&preload=1&transparent=1&ui_controls=0&ui_infos=0&ui_inspector=0&ui_watermark=0&ui_watermark_link=0&ui_help=0&ui_settings=0&ui_vr=0&ui_fullscreen=0&ui_annotations=0&ui_stop=0&ui_theatre=0&ui_ar=0&ui_sound=0&dnt=1"
          />
        ) : (
          <div className="wz-hero-spinner">
            <div className="wz-hero-spin-ring" />
          </div>
        )}

        {/* Overlays to hide Sketchfab UI */}
        <div className="wz-hero-overlay-top" />
        <div className="wz-hero-overlay-bottom" />
        <div className="wz-hero-overlay-left" />
      </div>

      {/* Corner brackets */}
      <div className="wz-hero-corner wz-hero-corner-tl" />
      <div className="wz-hero-corner wz-hero-corner-tr" />
      <div className="wz-hero-corner wz-hero-corner-bl" />
      <div className="wz-hero-corner wz-hero-corner-br" />
    </div>
  )
}

export default WatchHero
