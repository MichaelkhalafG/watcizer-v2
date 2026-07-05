'use client'
import { memo } from 'react'
import { useRouter } from 'next/navigation'
import { useUIStore } from '../../Store/uiStore'
import { Carousel, CarouselSlide } from '../UI/Carousel'
import ProductCard from './ProductCard'
import './ProductSlider.css'

const ProductSlider = ({ gradeproducts = [], text = {}, moreid, loading = false }) => {
  const router = useRouter()
  const { language } = useUIStore()

  const title =
    language === 'ar' ? text.title?.ar || text.title?.en || '' : text.title?.en || text.title?.ar || ''

  const description =
    language === 'ar'
      ? text.description?.ar || text.description?.en || ''
      : text.description?.en || text.description?.ar || ''

  const handleViewAll = () => {
    router.push(moreid ? `/listing?grade=${moreid}` : '/listing')
  }

  // Loading placeholder — parent owns the "still fetching" signal so a genuinely
  // empty grade (loaded, zero products) stays null instead of shimmering forever.
  if (loading) {
    return (
      <div className="wz-slider" dir={language === 'ar' ? 'rtl' : 'ltr'}>
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

  if (!gradeproducts.length) return null

  return (
    <div className="wz-slider" dir={language === 'ar' ? 'rtl' : 'ltr'}>
      {/* Header */}
      <div className="wz-slider-header">
        <div className="wz-slider-header-left">
          {description && <span className="wz-section-label">{description}</span>}
          <h2 className="wz-section-title">{title}</h2>
        </div>
        <button className="wz-section-action" onClick={handleViewAll}>
          {language === 'ar' ? 'عرض الكل ←' : 'View All →'}
        </button>
      </div>

      {/* Slider (Embla) */}
      <Carousel gap={16} showArrows showDots={false}>
        {gradeproducts.map((p, i) => (
          <CarouselSlide key={p.id || i} className="wz-slider-slide">
            <ProductCard product={p} />
          </CarouselSlide>
        ))}
      </Carousel>
    </div>
  )
}

export default memo(ProductSlider)