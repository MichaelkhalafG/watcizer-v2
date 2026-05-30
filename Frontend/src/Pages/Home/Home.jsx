import { useContext, useState, useEffect } from 'react'
import HomeSlider from './HomeSlider'
import { LazyLoadImage } from 'react-lazy-load-image-component'
import 'react-lazy-load-image-component/src/effects/blur.css'
import './home.css'
import ProductSlider from '../../Components/Product/ProductSlider'
import OfferSlider from '../../Components/Product/OfferSlider'
import { MyContext } from '../../Context/Context'
import CategoryNavPhone from '../../Components/Header/Nav/CategoryNavPhone'

function Home() {
  const {
    products,
    tables,
    language,
    windowWidth,
    offers,
    sideBanners,
    bottomBanners,
    HomeBannersPc,
    HomeBannersMob,
  } = useContext(MyContext)
  const [grades, setGrades] = useState([])
  const [homeBanners, sethomeBanners] = useState([])
  const [filteredProducts, setFilteredProducts] = useState({})
  const [gradeText, setGradeText] = useState({})
  const [filteredOffers, setfilteredOffers] = useState([])

  useEffect(() => {
    if (windowWidth >= 768) {
      sethomeBanners(HomeBannersPc)
    } else {
      sethomeBanners(HomeBannersMob)
    }
  }, [HomeBannersPc, HomeBannersMob, windowWidth])

  useEffect(() => {
    setfilteredOffers(offers.filter((o) => o.in_season === 'yes'))
  }, [offers])

  useEffect(() => {
    if (tables && tables.grades) {
      setGrades(tables.grades)
    }
  }, [tables])

  useEffect(() => {}, [])

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

  return (
    <div className="home px-md-5 pb-md-0 pb-5 container-fluid">
      {windowWidth <= 768 ? (
        <div className="py-2">
          <CategoryNavPhone />
        </div>
      ) : null}
      <div className={`home-slider ${windowWidth > 768 ? 'py-5' : 'pb-3'} `}>
        <HomeSlider banners={homeBanners} />
      </div>
      <div className="row position-relative">
        <div
          className="col-md-3 d-md-block d-none side-banners-container"
          style={{ overflow: 'hidden' }}
        >
          {sideBanners.map((banner, index) => (
            // <img key={index} loading="lazy" src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Side/${banner.image}`} alt={`sidebanner${index + 1}`} className="col-12 mb-2 rounded-3" />
            <LazyLoadImage
              src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Side/${banner.image}`}
              key={index}
              alt={`sidebanner${index + 1}`}
              style={{ width: '100%', height: 'auto', aspectRatio: '1/1' }}
              effect="blur"
              width="100%"
              height="auto"
              className="col-12 mb-2 rounded-3"
            />
          ))}
        </div>
        <div className="col-md-9 col-12 lato-regular home-proud">
          {grades?.map((grade) => {
            const gradeProducts = filteredProducts?.[grade.id] ?? []
            const gradeLocalization = gradeText?.[grade.id]
            if (gradeProducts.length > 0) {
              return (
                <ProductSlider
                  key={grade.id}
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
              )
            }
            return null
          })}
          {filteredOffers.length !== 0 && (
            <OfferSlider
              key={0}
              text={{
                title: { en: 'Season Offers.', ar: 'عروض الموسم.' },
                description: { en: 'Season Offers.', ar: 'عروض الموسم.' },
              }}
              products={filteredOffers}
              to={`/offers`}
            />
          )}
        </div>
      </div>
      <div className="row flex-wrap pb-md-0 pb-5 bottom-banners-container">
        {bottomBanners.map((banner, index) => (
          // <LazyLoadImage
          //     key={index}
          //     src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Bottom/${banner.image}`}
          //     alt={`bottombanner${index + 1}`}
          //     className="col-md-6 col-6 mb-2 rounded-3 img-fluid"
          //     style={{ maxHeight: "300px" }}
          //     effect="blur"
          // />
          <img
            key={index}
            loading="lazy"
            src={`${import.meta.env.VITE_ASSET_BASE}/Uploads_Images/Banner_Bottom/${banner.image}`}
            alt={`bottombanner${index + 1}`}
            className="col-6 mb-2 rounded-3 img-fluid"
            style={{
              maxHeight: '300px',
              minHeight: '150px',
              aspectRatio: '4/3',
            }}
          />
        ))}
      </div>
    </div>
  )
}

export default Home
