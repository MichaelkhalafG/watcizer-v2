import { useRef, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import ProductCard from './ProductCard'
import './ProductSlider.css'

const OfferSlider = ({ products = [], text = {} }) => {
  const navigate = useNavigate()
  const { language } = useUIStore()
  const trackRef = useRef(null)
  const [canLeft, setCanLeft] = useState(false)
  const [canRight, setCanRight] = useState(true)

  const title =
    language === 'ar' ? text.title?.ar || text.title?.en || '' : text.title?.en || text.title?.ar || ''

  const updateArrows = useCallback(() => {
    const el = trackRef.current
    if (!el) return
    setCanLeft(el.scrollLeft > 8)
    setCanRight(el.scrollLeft < el.scrollWidth - el.clientWidth - 8)
  }, [])

  const scroll = (dir) => {
    const el = trackRef.current
    if (!el) return
    const card = el.querySelector('.wz-card')
    const step = ((card?.offsetWidth || 240) + 14) * 2
    el.scrollBy({
      left: dir === 'left' ? -step : step,
      behavior: 'smooth',
    })
    setTimeout(updateArrows, 420)
  }

  if (!products.length) return null

  return (
    <div className="wz-slider wz-slider-offers">
      <div className="wz-slider-header">
        <div className="wz-slider-header-left">
          <span className="wz-section-label wz-label-red">
            {language === 'ar' ? 'عروض محدودة' : 'Limited Offers'}
          </span>
          <h2 className="wz-section-title">{title}</h2>
        </div>
        <button
          className="wz-section-action wz-action-red"
          onClick={() => navigate('/listing?offers=true')}
        >
          {language === 'ar' ? 'كل العروض ←' : 'All Offers →'}
        </button>
      </div>

      <div className="wz-slider-wrap">
        <button
          className={`wz-slider-arrow wz-slider-arrow-l ${!canLeft ? 'wz-slider-arrow-hidden' : ''}`}
          onClick={() => scroll('left')}
          aria-label="Previous"
        >
          ‹
        </button>

        <div className="wz-slider-track" ref={trackRef} onScroll={updateArrows}>
          {products.map((p, i) => (
            <div key={p.id || i} className="wz-slider-item">
              <ProductCard product={p} />
            </div>
          ))}
        </div>

        <button
          className={`wz-slider-arrow wz-slider-arrow-r ${!canRight ? 'wz-slider-arrow-hidden' : ''}`}
          onClick={() => scroll('right')}
          aria-label="Next"
        >
          ›
        </button>
      </div>
    </div>
  )
}

export default OfferSlider
