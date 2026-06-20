import { useState, useContext } from 'react'
import { Button } from '@mui/material'
import { IoIosMenu } from 'react-icons/io'
import { FaAngleDown, FaAngleRight } from 'react-icons/fa'
import { Link } from 'react-router-dom'
import { MyContext } from '../../../Context/Context'
import { useUIStore } from '../../../Store/uiStore'

const CategoryNav = () => {
  const { products, tables } = useContext(MyContext)
  const { language, setCurrentPage, setFilters } = useUIStore()
  const [isHovered, setIsHovered] = useState(false)
  const [hoveredSubtype, setHoveredSubtype] = useState(null)

  return (
    <div
      className="cat-nav col-12"
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      <Button
        className="all-cat-tap col-8 py-2 rounded-5 text-light"
        title="All Categories"
        style={{ background: '#262626E0', transition: 'background 0.3s ease-in-out' }}
      >
        <span>
          <IoIosMenu style={{ fontSize: '25px' }} />
        </span>
        <span className="text-uppercase dosis-regular mx-2">
          {language === 'ar' ? 'جميع الفئات' : 'All Categories'}
        </span>
        <span>
          <FaAngleDown style={{ fontSize: '25px' }} />
        </span>
      </Button>
      {isHovered && (
        <div
          className={`side-menu p-3 col-8 d-flex flex-column rounded-bottom-3 border border-1 ${language === 'ar' ? 'side-menu-ar' : ''}`}
        >
          {tables.subTypes &&
            tables.subTypes
              .filter((subtype) => products.some((product) => product.sub_type_id === subtype.id))
              .map((subtype) => (
                <div
                  key={subtype.id}
                  className="position-relative"
                  onMouseEnter={() => setHoveredSubtype(subtype.id)}
                  onMouseLeave={() => setHoveredSubtype(null)}
                >
                  <Link
                    to={`/subtypes/${subtype.translations.find((sup) => sup.locale === 'en').sub_type_name}`}
                    className="text-decoration-none col-12 d-flex text-start px-2 py-1 color-most-used"
                    onClick={() => {
                      setFilters({
                        categories: [],
                        brands: [],
                        subTypes: [subtype.id],
                        price: [0, 6000],
                      })
                      setCurrentPage(1)
                    }}
                  >
                    <Button className="col-12 color-most-used justify-content-start">
                      {subtype.translations.map((translation) =>
                        translation.locale === language ? translation.sub_type_name : null,
                      )}
                      <FaAngleRight className="ms-auto" />
                    </Button>
                  </Link>
                  {hoveredSubtype === subtype.id && (
                    <div
                      className="sub-menu position-absolute start-100 top-0 p-3 border rounded bg-white"
                      style={{ width: '300px' }}
                    >
                      {tables.brands &&
                        tables.brands
                          .filter((brand) =>
                            products.some(
                              (product) =>
                                product.brand_id === brand.id && product.sub_type_id === subtype.id,
                            ),
                          )
                          .map((brand) => (
                            <Link
                              key={brand.id}
                              to={`/${subtype.translations.find((sup) => sup.locale === 'en').sub_type_name}/${brand.translations.find((tr) => tr.locale === 'en').brand_name}`}
                              className="text-decoration-none col-12 d-block px-2 py-1"
                              onClick={() => {
                                setFilters({
                                  categories: [],
                                  brands: [brand.id],
                                  subTypes: [subtype.id],
                                  price: [0, 6000],
                                })
                                setCurrentPage(1)
                              }}
                            >
                              <Button className="color-most-used col-12 text-start">
                                {brand.translations.map((translation) =>
                                  translation.locale === language ? translation.brand_name : null,
                                )}
                              </Button>
                            </Link>
                          ))}
                    </div>
                  )}
                </div>
              ))}
        </div>
      )}
    </div>
  )
}

export default CategoryNav
