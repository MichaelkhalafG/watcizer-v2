'use client'
import { useState, useRef, useCallback } from 'react'
import './ImageZoom.css'

// Custom hover-to-zoom (no external library, ~0 KB). Zoom happens IN PLACE inside
// the image container — no lightbox overlay — like Rolex / SSENSE / Farfetch.
// Desktop: hover tracks the cursor; mobile: touch-and-drag tracks the finger.
export default function ImageZoom({
  src,
  alt = '',
  width = 600,
  height = 600,
  zoomScale = 2.5,
  className = '',
  onError,
}) {
  const containerRef = useRef(null)
  const [zooming, setZooming] = useState(false)
  const [position, setPosition] = useState({ x: 50, y: 50 })

  const handleMouseMove = useCallback((e) => {
    const rect = containerRef.current?.getBoundingClientRect()
    if (!rect) return
    const x = ((e.clientX - rect.left) / rect.width) * 100
    const y = ((e.clientY - rect.top) / rect.height) * 100
    setPosition({ x, y })
  }, [])

  const handleMouseEnter = useCallback(() => setZooming(true), [])
  const handleMouseLeave = useCallback(() => {
    setZooming(false)
    setPosition({ x: 50, y: 50 })
  }, [])

  const handleTouchMove = useCallback((e) => {
    const touch = e.touches[0]
    const rect = containerRef.current?.getBoundingClientRect()
    if (!rect) return
    const x = ((touch.clientX - rect.left) / rect.width) * 100
    const y = ((touch.clientY - rect.top) / rect.height) * 100
    setPosition({ x, y })
  }, [])

  const handleTouchStart = useCallback(
    (e) => {
      setZooming(true)
      handleTouchMove(e)
    },
    [handleTouchMove],
  )

  const handleTouchEnd = useCallback(() => {
    setZooming(false)
    setPosition({ x: 50, y: 50 })
  }, [])

  return (
    <div
      ref={containerRef}
      className={`wz-img-zoom ${zooming ? 'is-zooming' : ''} ${className}`}
      onMouseMove={handleMouseMove}
      onMouseEnter={handleMouseEnter}
      onMouseLeave={handleMouseLeave}
      onTouchStart={handleTouchStart}
      onTouchMove={handleTouchMove}
      onTouchEnd={handleTouchEnd}
    >
      <img
        src={src}
        alt={alt}
        width={width}
        height={height}
        draggable={false}
        onError={onError}
        className="wz-img-zoom__img"
        style={
          zooming
            ? {
                transformOrigin: `${position.x}% ${position.y}%`,
                transform: `scale(${zoomScale})`,
              }
            : {
                transformOrigin: 'center center',
                transform: 'scale(1)',
              }
        }
      />
    </div>
  )
}
