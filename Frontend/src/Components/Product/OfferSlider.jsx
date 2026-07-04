import { memo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import { Carousel, CarouselSlide } from '../UI/Carousel'
import ProductCard from './ProductCard'
import './ProductSlider.css'

const OfferSlider = ({ products = [], text = {}, loading = false }) => {
  const navigate = useNavigate()
  const { language } = useUIStore()

  const title =
    language === 'ar' ? text.title?.ar || text.title?.en || '' : text.title?.en || text.title?.ar || ''

  // Loading placeholder — parent owns the "still fetching" signal so a genuinely
  // empty offers list (loaded, zero on-sale products) stays null.
  if (loading) {
    return (
      <div className="wz-slider wz-slider-offers" dir={language === 'ar' ? 'rtl' : 'ltr'}>
        <div className="wz-slider-header">
          <div className="wz-slider-header-left">
            <div className="wz-skel-line wz-skel-title" />
          </div>
        </div>
        <div className="wz-slider-skeleton">
          {Array.from({ length: 5 }).map((_, i) => (
            <div className="wz-slider-item" key={i}>
              <div className="wz-skel-card">
                <div className="wz-skel-img" />
                <div className="wz-skel-line wz-skel-brand" />
                <div className="wz-skel-line wz-skel-name" />
                <div className="wz-skel-line wz-skel-price" />
              </div>
            </div>
          ))}
        </div>
      </div>
    )
  }

  if (!products.length) return null

  return (
    <div className="wz-slider wz-slider-offers" dir={language === 'ar' ? 'rtl' : 'ltr'}>
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

      <Carousel gap={16} showArrows showDots={false}>
        {products.map((p, i) => (
          <CarouselSlide key={p.id || i} className="wz-slider-slide">
            <ProductCard product={p} />
          </CarouselSlide>
        ))}
      </Carousel>
    </div>
  )
}

export default memo(OfferSlider)
