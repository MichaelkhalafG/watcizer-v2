import { memo, useEffect } from 'react'
import { Carousel, CarouselSlide } from '../../Components/UI/Carousel'

function HomeSlider({ banners }) {
  // Preload the first banner image for LCP as soon as banner data arrives.
  useEffect(() => {
    if (banners?.length > 0) {
      const firstBanner = banners[0].image
      if (!document.querySelector(`link[href*="${firstBanner}"]`)) {
        const link = document.createElement('link')
        link.rel = 'preload'
        link.as = 'image'
        link.href = `${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_home/${firstBanner}?format=webp`
        document.head.appendChild(link)
      }
    }
  }, [banners])

  return (
    <div className="wz-hero-carousel-wrap">
      {/* Embla wrapper: loop + autoplay preserve the old react-slick behaviour;
          drag/RTL are handled internally. */}
      <Carousel loop autoplay showArrows showDots={false} gap={0} className="wz-hero-carousel">
        {(banners || []).map((item, index) => (
          <CarouselSlide key={index} className="wz-hero-slide">
            <img
              src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_home/${item.image}?format=webp`}
              alt={`banner${index + 1}`}
              loading={index === 0 ? 'eager' : 'lazy'}
              decoding="async"
              // Only the first (LCP) slide gets high fetch priority.
              ref={index === 0 ? (img) => img && img.setAttribute('fetchpriority', 'high') : undefined}
              width="100%"
              className="wz-hero-img"
            />
          </CarouselSlide>
        ))}
      </Carousel>
    </div>
  )
}

export default memo(HomeSlider)
