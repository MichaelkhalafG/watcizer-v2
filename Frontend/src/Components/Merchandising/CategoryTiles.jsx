import { memo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useUIStore } from '../../Store/uiStore'
import { getImageUrl } from '../../utils/imageUrl'
import { buildListingParams } from '../../utils/listingParams'
import { Carousel, CarouselSlide } from '../UI/Carousel'
import './CategoryTiles.css'

const CategoryTiles = () => {
  const { tables, isFetching } = useCatalog()
  const { language } = useUIStore()
  const navigate = useNavigate()

  const subTypes = tables?.subTypes || []

  // While the catalog tables are still loading, show placeholder chips so the
  // section fills in progressively instead of popping in. Once loaded, an empty
  // list genuinely means "no categories" → render nothing.
  if (!subTypes.length) {
    if (!isFetching) return null
    return (
      <div className="wz-cats" dir={language === 'ar' ? 'rtl' : 'ltr'}>
        <div className="wz-cats-row wz-cats-skeleton">
          {Array.from({ length: 8 }).map((_, i) => (
            <div className="wz-skel-chip" key={i}>
              <div className="wz-skel-chip-circle" />
              <div className="wz-skel-chip-line" />
            </div>
          ))}
        </div>
      </div>
    )
  }

  // Names live in a translations[] array (current language → English fallback).
  const getName = (sub) => {
    const tr = (loc) => sub.translations?.find((t) => t.locale === loc)?.sub_type_name
    return (language === 'ar' ? tr('ar') || tr('en') : tr('en') || tr('ar')) || ''
  }

  // Readable slug URL the Listing page reads back (keeps the filter working).
  const handleClick = (sub) => {
    const qs = buildListingParams({ subTypes: [sub.id] }, {}, tables).toString()
    navigate(qs ? `/listing?${qs}` : '/listing')
  }

  return (
    <div className="wz-cats" dir={language === 'ar' ? 'rtl' : 'ltr'}>
      {/* Header */}
      <div className="wz-cats-header">
        <div className="wz-cats-title-wrap">
          <span className="wz-section-label">{language === 'ar' ? 'تصفح حسب' : 'Shop by'}</span>
          <h2 className="wz-cats-title">{language === 'ar' ? 'الفئة' : 'Category'}</h2>
        </div>
        <button className="wz-section-action" onClick={() => navigate('/listing')}>
          {language === 'ar' ? 'عرض الكل ←' : 'See All →'}
        </button>
      </div>

      {/* Scrollable row (Embla) */}
      <Carousel gap={10} showArrows showDots={false}>
        {subTypes.map((sub) => {
          const name = getName(sub)
          const img = getImageUrl(sub.image)

          return (
            <CarouselSlide key={sub.id} className="wz-cat-slide">
              <button className="wz-cat-chip" onClick={() => handleClick(sub)} title={name}>
                <div className={`wz-cat-chip-img${img ? '' : ' wz-cat-chip-img-fallback'}`}>
                  {img ? (
                    <img
                      src={img}
                      alt={name}
                      loading="lazy"
                      onError={(e) => {
                        e.target.onerror = null
                        e.target.style.display = 'none'
                        e.target.parentElement.classList.add('wz-cat-chip-img-fallback')
                        const fb = e.target.parentElement.querySelector('.wz-cat-chip-initial')
                        if (fb) fb.style.display = 'flex'
                      }}
                    />
                  ) : null}
                  <span className="wz-cat-chip-initial" style={{ display: img ? 'none' : 'flex' }}>
                    {name.charAt(0).toUpperCase()}
                  </span>
                </div>

                <span className="wz-cat-chip-name">{name}</span>
              </button>
            </CarouselSlide>
          )
        })}
      </Carousel>
    </div>
  )
}

export default memo(CategoryTiles)
