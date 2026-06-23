import { useContext, useState, useEffect, useMemo } from 'react'
import useMediaQuery from '@mui/material/useMediaQuery'
import { useNavigate } from 'react-router-dom'
import WatchHero from '../../Components/Hero/WatchHero'
import './home.css'
import ProductSlider from '../../Components/Product/ProductSlider'
import OfferSlider from '../../Components/Product/OfferSlider'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import CategoryNavPhone from '../../Components/Header/Nav/CategoryNavPhone'
import CategoryTiles from '../../Components/Merchandising/CategoryTiles'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl } from '../../utils/productUrl'
import { buildListingParams } from '../../utils/listingParams'

// Title can live in a translations[] array or be pre-localized on the catalog
// object (product_title / name) — cover both.
const getName = (p, lang) => {
  if (p.translations?.length) {
    const t =
      p.translations.find((x) => x.locale === lang) || p.translations.find((x) => x.locale === 'en')
    if (t?.product_title) return t.product_title
  }
  return lang === 'ar'
    ? p.product_title_ar || p.name_ar || p.product_title || p.name || p.name_en || ''
    : p.product_title || p.name || p.name_en || p.product_title_ar || p.name_ar || ''
}

// Rotating hero strip below WatchHero — 5 random (preferably on-sale) products.
function FeaturedBanner({ products }) {
  const [current, setCurrent] = useState(0)
  const [visible, setVisible] = useState(true)
  const { language } = useUIStore()
  const navigate = useNavigate()
  const isRTL = language === 'ar'

  const featured = useMemo(() => {
    const list = Array.isArray(products) ? products : []
    const withSale = list.filter(
      (p) =>
        Number(p.sale_price_after_discount) > 0 &&
        Number(p.sale_price_after_discount) < Number(p.selling_price) &&
        p.image,
    )
    const pool = withSale.length >= 3 ? withSale : list.filter((p) => p.image)
    return [...pool].sort(() => Math.random() - 0.5).slice(0, 5)
  }, [products])

  useEffect(() => {
    if (featured.length <= 1) return
    const id = setInterval(() => {
      setVisible(false)
      setTimeout(() => {
        setCurrent((c) => (c + 1) % featured.length)
        setVisible(true)
      }, 350)
    }, 5000)
    return () => clearInterval(id)
  }, [featured.length])

  if (!featured.length) return null

  const p = featured[current]
  const name = getName(p, language)
  const brand = typeof p.brand === 'string' ? p.brand : p.brand?.name_en || p.brand_name || ''
  const price = Number(p.selling_price || 0)
  const sale = Number(p.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < price
  const pct = hasSale ? Math.round((1 - sale / price) * 100) : 0
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')
  const currency = isRTL ? 'ج.م' : 'EGP'

  const jumpTo = (i) => {
    setVisible(false)
    setTimeout(() => {
      setCurrent(i)
      setVisible(true)
    }, 350)
  }

  return (
    <div className="wz-featured" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-featured-bg-grid" />
      <div className={`wz-featured-inner ${visible ? 'wz-featured-show' : ''}`}>
        <div className="wz-featured-text">
          <span className="wz-featured-eyebrow">
            {hasSale
              ? isRTL
                ? `لفترة محدودة — خصم ${pct}%`
                : `Limited Time — ${pct}% OFF`
              : isRTL
                ? 'تشكيلة مميزة'
                : 'Featured Collection'}
          </span>
          {brand && <p className="wz-featured-brand">{brand}</p>}
          <h2 className="wz-featured-name">{name}</h2>
          <div className="wz-featured-prices">
            {hasSale ? (
              <>
                <span className="wz-fp-sale">
                  {fmt(sale)} {currency}
                </span>
                <span className="wz-fp-orig">
                  {fmt(price)} {currency}
                </span>
              </>
            ) : (
              <span className="wz-fp-price">
                {fmt(price)} {currency}
              </span>
            )}
          </div>
          <button className="wz-featured-btn" onClick={() => navigate(productUrl(p))}>
            {isRTL ? 'تسوق الآن' : 'Shop Now'}
            <span>{isRTL ? '←' : '→'}</span>
          </button>
        </div>

        <div className="wz-featured-img-box">
          <img
            src={getImageUrl(p.image) || PLACEHOLDER_IMG}
            alt={name}
            className="wz-featured-img"
            onError={handleImgError}
          />
        </div>
      </div>

      <div className="wz-featured-dots">
        {featured.map((_, i) => (
          <button
            key={i}
            className={`wz-fdot ${i === current ? 'wz-fdot-on' : ''}`}
            onClick={() => jumpTo(i)}
            aria-label={`slide ${i + 1}`}
          />
        ))}
      </div>
    </div>
  )
}

function Home() {
  const { products, tables } = useContext(MyContext)
  const { language } = useUIStore()
  const navigate = useNavigate()
  const isDesktop = useMediaQuery('(min-width:768px)')
  const [grades, setGrades] = useState([])
  const [filteredProducts, setFilteredProducts] = useState({})
  const [gradeText, setGradeText] = useState({})

  // "Season Offers" now surfaces discounted PRODUCTS so the unified ProductCard
  // renders correctly and matches the slider's "All Offers →" → /listing?offers=true.
  const offerProducts = useMemo(
    () => (products || []).filter((p) => Number(p.percentage_discount) > 0).slice(0, 15),
    [products],
  )

  useEffect(() => {
    if (tables && tables.grades) {
      setGrades(tables.grades)
    }
  }, [tables])

  useEffect(() => {
    if (!products || !grades?.length) return

    const productsByGrade = grades.reduce((acc, grade) => {
      const filtered = products.filter((product) => product.grade_id === grade.id)
      if (filtered.length > 0) acc[grade.id] = filtered
      return acc
    }, {})

    setFilteredProducts(productsByGrade)

    const gradeTextObj = Object.fromEntries(
      grades.map((grade) => [
        grade.id,
        {
          title:
            grade.translations?.find((t) => t.locale === language)?.grade_name ?? grade.grade_name,
          description:
            grade.translations?.find((t) => t.locale === language)?.description ??
            grade.description,
        },
      ]),
    )

    setGradeText(gradeTextObj)
  }, [products, grades, language])

  const brands = tables?.brands || []
  const brandName = (b) =>
    b.translations?.find((t) => t.locale === language)?.brand_name ||
    b.translations?.find((t) => t.locale === 'en')?.brand_name ||
    b.brand_name ||
    ''

  return (
    <div className="wz-home">
      {!isDesktop && (
        <div className="wz-home-phonenav">
          <CategoryNavPhone />
        </div>
      )}

      <WatchHero />

      {brands.length > 0 && (
        <div className="wz-brand-strip">
          <div className="wz-brand-strip-track">
            {[...brands, ...brands].map((b, i) => {
              const img = getImageUrl(b.image, 'Brand')
              const name = brandName(b)
              return (
                <button
                  key={`${b.id}-${i}`}
                  className="wz-brand-strip-item"
                  onClick={() =>
                    navigate(
                      `/listing?${buildListingParams({ brands: [b.id] }, {}, tables).toString()}`,
                    )
                  }
                  title={name}
                  type="button"
                >
                  {img ? (
                    <img
                      src={img}
                      alt={name}
                      className="wz-brand-strip-img"
                      loading="lazy"
                      onError={(e) => {
                        e.target.style.display = 'none'
                        if (e.target.nextSibling) e.target.nextSibling.style.display = 'block'
                      }}
                    />
                  ) : null}
                  <span className="wz-brand-strip-name" style={{ display: img ? 'none' : 'block' }}>
                    {name}
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      )}

      <section className="wz-home-section">
        <div className="wz-container">
          <CategoryTiles />
        </div>
      </section>

      <FeaturedBanner products={products || []} />

      {grades?.map((grade) => {
        const gradeProducts = filteredProducts?.[grade.id] ?? []
        const gradeLocalization = gradeText?.[grade.id]
        if (gradeProducts.length === 0) return null
        return (
          <section className="wz-home-section" key={grade.id}>
            <div className="wz-container">
              <ProductSlider
                text={{
                  title: {
                    en: gradeLocalization?.title ?? grade.grade_name,
                    ar: gradeLocalization?.title ?? grade.grade_name,
                  },
                  description: {
                    en: gradeLocalization?.description ?? '',
                    ar: gradeLocalization?.description ?? '',
                  },
                }}
                gradeproducts={gradeProducts}
                to={`/grade/${grade?.translations?.find((t) => t.locale === 'en')?.grade_name}`}
                moreid={grade.id}
              />
            </div>
          </section>
        )
      })}

      {offerProducts.length !== 0 && (
        <section className="wz-home-section">
          <div className="wz-container">
            <OfferSlider
              text={{
                title: { en: 'Season Offers', ar: 'عروض الموسم' },
                description: { en: 'Season Offers', ar: 'عروض الموسم' },
              }}
              products={offerProducts}
            />
          </div>
        </section>
      )}
    </div>
  )
}

export default Home
