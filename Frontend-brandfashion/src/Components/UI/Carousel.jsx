'use client'
import { memo, useCallback, useEffect, useState } from 'react'
import useEmblaCarousel from 'embla-carousel-react'
import { FiChevronLeft, FiChevronRight } from 'react-icons/fi'
import { useUIStore } from '../../Store/uiStore'
import './Carousel.css'

// Reusable Embla-powered slider with Watchizer styling. Embla handles touch +
// mouse drag, momentum, snap points, RTL and click-suppression-during-drag
// internally — so consumers only supply slides and a slide-width class.
function Carousel({
  children,
  gap = 16,
  showArrows = true,
  showDots = false,
  loop = false,
  align = 'start',
  className = '',
  autoplay = false,
  autoplayDelay = 3000,
}) {
  const { language } = useUIStore()
  const isRTL = language === 'ar'

  const [emblaRef, emblaApi] = useEmblaCarousel({
    loop,
    align,
    direction: isRTL ? 'rtl' : 'ltr',
    dragFree: true,
    containScroll: 'trimSnaps',
    skipSnaps: false,
  })

  const [canScrollPrev, setCanScrollPrev] = useState(false)
  const [canScrollNext, setCanScrollNext] = useState(false)
  const [selectedIndex, setSelectedIndex] = useState(0)
  const [scrollSnaps, setScrollSnaps] = useState([])

  const scrollPrev = useCallback(() => emblaApi?.scrollPrev(), [emblaApi])
  const scrollNext = useCallback(() => emblaApi?.scrollNext(), [emblaApi])
  const scrollTo = useCallback((index) => emblaApi?.scrollTo(index), [emblaApi])

  const onSelect = useCallback(() => {
    if (!emblaApi) return
    setSelectedIndex(emblaApi.selectedScrollSnap())
    setCanScrollPrev(emblaApi.canScrollPrev())
    setCanScrollNext(emblaApi.canScrollNext())
  }, [emblaApi])

  useEffect(() => {
    if (!emblaApi) return
    // Sync carousel state from the Embla instance on init — external-system sync.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    onSelect()
    setScrollSnaps(emblaApi.scrollSnapList())
    emblaApi.on('select', onSelect)
    emblaApi.on('reInit', onSelect)
    return () => {
      emblaApi.off('select', onSelect)
      emblaApi.off('reInit', onSelect)
    }
  }, [emblaApi, onSelect])

  // Re-init when the reading direction changes (language switch).
  useEffect(() => {
    if (emblaApi) emblaApi.reInit()
  }, [isRTL, emblaApi])

  // Opt-in autoplay (used by the hero). Advances on an interval and wraps to the
  // start; pauses while the user is pointing at / dragging the carousel. Default
  // off, so every other consumer (product/offer/category sliders) is unaffected.
  useEffect(() => {
    if (!emblaApi || !autoplay) return
    let paused = false
    const root = emblaApi.rootNode()
    const pause = () => (paused = true)
    const resume = () => (paused = false)
    root.addEventListener('pointerenter', pause)
    root.addEventListener('pointerleave', resume)
    root.addEventListener('pointerdown', pause)
    const id = setInterval(() => {
      if (paused) return
      if (emblaApi.canScrollNext()) emblaApi.scrollNext()
      else emblaApi.scrollTo(0)
    }, autoplayDelay)
    return () => {
      clearInterval(id)
      root.removeEventListener('pointerenter', pause)
      root.removeEventListener('pointerleave', resume)
      root.removeEventListener('pointerdown', pause)
    }
  }, [emblaApi, autoplay, autoplayDelay])

  return (
    <div className={`wz-carousel ${className}`}>
      <div className="wz-carousel-viewport" ref={emblaRef}>
        <div className="wz-carousel-track" style={{ gap: `${gap}px` }}>
          {children}
        </div>
      </div>

      {showArrows && (
        <>
          <button
            className={`wz-carousel-arrow wz-carousel-prev${!canScrollPrev ? ' is-disabled' : ''}`}
            onClick={scrollPrev}
            disabled={!canScrollPrev}
            aria-label="Previous"
            type="button"
          >
            {isRTL ? <FiChevronRight /> : <FiChevronLeft />}
          </button>
          <button
            className={`wz-carousel-arrow wz-carousel-next${!canScrollNext ? ' is-disabled' : ''}`}
            onClick={scrollNext}
            disabled={!canScrollNext}
            aria-label="Next"
            type="button"
          >
            {isRTL ? <FiChevronLeft /> : <FiChevronRight />}
          </button>
        </>
      )}

      {showDots && scrollSnaps.length > 1 && (
        <div className="wz-carousel-dots">
          {scrollSnaps.map((_, i) => (
            <button
              key={i}
              className={`wz-carousel-dot${i === selectedIndex ? ' is-active' : ''}`}
              onClick={() => scrollTo(i)}
              aria-label={`Go to slide ${i + 1}`}
              type="button"
            />
          ))}
        </div>
      )}
    </div>
  )
}

function CarouselSlide({ children, className = '' }) {
  return <div className={`wz-carousel-slide ${className}`}>{children}</div>
}

const MemoCarousel = memo(Carousel)

export { MemoCarousel as Carousel, CarouselSlide }
export default MemoCarousel