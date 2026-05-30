import { memo, useContext, useEffect, lazy } from 'react'
import { MyContext } from '../../Context/Context'
import { IoIosArrowDroprightCircle, IoIosArrowDropleftCircle } from 'react-icons/io'
const Slider = lazy(() => import('react-slick'))

function HomeSlider({ banners }) {
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

  const { language } = useContext(MyContext)

  function NextArrow({ onClick }) {
    return (
      <IoIosArrowDroprightCircle
        style={{
          fontSize: '40px',
          color: '#fff',
          position: 'absolute',
          top: '50%',
          transform: 'translateY(-50%)',
          right: '10px',
          zIndex: 10,
          cursor: 'pointer',
        }}
        onClick={onClick}
      />
    )
  }

  function PrevArrow({ onClick }) {
    return (
      <IoIosArrowDropleftCircle
        style={{
          fontSize: '40px',
          color: '#fff',
          position: 'absolute',
          top: '50%',
          transform: 'translateY(-50%)',
          left: '10px',
          zIndex: 10,
          cursor: 'pointer',
        }}
        onClick={onClick}
      />
    )
  }

  const settings = {
    dots: false,
    infinite: true,
    speed: 500,
    autoplay: true,
    autoplaySpeed: 3000,
    slidesToShow: 1,
    slidesToScroll: 1,
    lazyLoad: 'anticipated',
    rtl: language === 'ar',
    nextArrow: <NextArrow />,
    prevArrow: <PrevArrow />,
  }

  return (
    <div style={{ position: 'relative', ariahidden: 'true' }}>
      <Slider {...settings}>
        {banners.map((item, index) => (
          <div
            key={index}
            className="col-12 home-slider-image"
            style={{
              width: '100%',
            }}
          >
            <img
              src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_home/${item.image}?format=webp`}
              alt={`banner${index + 1}`}
              loading={index === 0 ? 'eager' : 'lazy'}
              decoding="async"
              ref={(img) => img && img.setAttribute('fetchpriority', 'high')}
              width="100%"
              style={{
                objectFit: 'cover',
                width: '100%',
              }}
            />
          </div>
        ))}
      </Slider>
    </div>
  )
}

export default memo(HomeSlider)
