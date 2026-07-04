import { useMemo, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Helmet } from 'react-helmet-async'
import WatchHero from '../../Components/Hero/WatchHero'
import './home.css'
import ProductSlider from '../../Components/Product/ProductSlider'
import OfferSlider from '../../Components/Product/OfferSlider'
import FeaturedBanner from '../../Components/Home/FeaturedBanner'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useUIStore } from '../../Store/uiStore'
import CategoryTiles from '../../Components/Merchandising/CategoryTiles'
import { getImageUrl } from '../../utils/imageUrl'
import { buildListingParams } from '../../utils/listingParams'

// Module-level handler (stable identity, never recreated): hide a broken brand
// logo and reveal its text fallback.
const onBrandImgError = (e) => {
  e.target.style.display = 'none'
  if (e.target.nextSibling) e.target.nextSibling.style.display = 'block'
}

function Home() {
  // Server data straight from TanStack Query (shared/cached) — Home no longer
  // depends on MyContext at all.
  const { products, tables, isFetching } = useCatalog()
  const { language } = useUIStore()
  const navigate = useNavigate()

  // Single source of truth for "still loading" on the home page. Once the fetch
  // settles, empty derived lists mean genuinely-empty (render nothing), not
  // loading. Each section below shows its own skeleton while this is true.
  const loading = isFetching && (!products || products.length === 0)

  // "Season Offers" surfaces discounted PRODUCTS so the unified ProductCard
  // renders correctly and matches "All Offers →" → /listing?offers=true.
  const offerProducts = useMemo(
    () => (products || []).filter((p) => Number(p.percentage_discount) > 0).slice(0, 15),
    [products],
  )

  // Derived directly from tables — no state/effect needed.
  const grades = useMemo(() => tables?.grades || [], [tables])

  // Products grouped by grade. Pure derivation of products + grades.
  const filteredProducts = useMemo(() => {
    if (!products || !grades.length) return {}
    return grades.reduce((acc, grade) => {
      const filtered = products.filter((product) => product.grade_id === grade.id)
      if (filtered.length > 0) acc[grade.id] = filtered
      return acc
    }, {})
  }, [products, grades])

  // Localized grade title/description map. Pure derivation of grades + language.
  const gradeText = useMemo(() => {
    if (!grades.length) return {}
    return Object.fromEntries(
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
  }, [grades, language])

  const brands = tables?.brands || []
  const brandName = (b) =>
    b.translations?.find((t) => t.locale === language)?.brand_name ||
    b.translations?.find((t) => t.locale === 'en')?.brand_name ||
    b.brand_name ||
    ''

  const handleBrandClick = useCallback(
    (brandId) => {
      navigate(`/listing?${buildListingParams({ brands: [brandId] }, {}, tables).toString()}`)
    },
    [navigate, tables],
  )

  return (
    <div className="wz-home" dir={language === 'ar' ? 'rtl' : 'ltr'}>
      <Helmet>
        <title>Watchizer | Luxury Watches & Accessories in Egypt</title>
        <meta
          name="description"
          content="Shop luxury watches and accessories at Watchizer — premium timepieces, elegant designs and unbeatable prices across Egypt."
        />
        <link rel="canonical" href="https://watchizereg.com/" />
      </Helmet>
      <WatchHero />

      <section className="wz-home-section">
        <div className="wz-container">
          <CategoryTiles />
        </div>
      </section>

      {offerProducts.length !== 0 ? (
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
      ) : loading ? (
        <section className="wz-home-section">
          <div className="wz-container">
            <OfferSlider loading products={[]} text={{}} />
          </div>
        </section>
      ) : null}

      {brands.length === 0 && loading && (
        <div className="wz-brand-strip-skeleton">
          {Array.from({ length: 8 }).map((_, i) => (
            <div className="wz-skel-brand-item" key={i} />
          ))}
        </div>
      )}

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
                  onClick={() => handleBrandClick(b.id)}
                  title={name}
                  type="button"
                >
                  {img ? (
                    <img
                      src={img}
                      alt={name}
                      className="wz-brand-strip-img"
                      width="90"
                      height="32"
                      loading="lazy"
                      onError={onBrandImgError}
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

      <FeaturedBanner products={products || []} loading={loading} />

      {loading &&
        [0, 1].map((i) => (
          <section className="wz-home-section" key={`grade-skel-${i}`}>
            <div className="wz-container">
              <ProductSlider loading />
            </div>
          </section>
        ))}

      {grades.map((grade) => {
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
    </div>
  )
}

export default Home
