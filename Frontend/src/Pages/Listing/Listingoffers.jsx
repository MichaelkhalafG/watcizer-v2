import OffersSideBar from '../../Components/SideBar/OffersSideBar'
import './Listing.css'
import { MyContext } from '../../Context/Context'
import { Link } from 'react-router-dom'
import { LazyLoadImage } from 'react-lazy-load-image-component'
import 'react-lazy-load-image-component/src/effects/blur.css'
import { FormControl, Drawer, InputLabel, MenuItem, Select, Button } from '@mui/material'
import { useContext, useState, useEffect } from 'react'
import { BsFillGrid3X3GapFill } from 'react-icons/bs'
import { IoGrid } from 'react-icons/io5'
import { FaRegHeart } from 'react-icons/fa'
import { SlSizeFullscreen } from 'react-icons/sl'
import { Rating } from '@mui/material'
import OfferModel from '../../Components/Product/OfferModel'
import Pagination from '@mui/material/Pagination'

function Listingoffers() {
  const {
    language,
    offers,
    windowWidth,
    currentPage,
    setCurrentPage,
    offersfilters,
    setOffersFilters,
    handleAddTowishlist,
  } = useContext(MyContext)

  const [filteredProducts, setFilteredProducts] = useState([])
  const [shownum, setShownum] = useState(10)
  const [selectedProduct, setSelectedProduct] = useState(null)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [colselected, setColselected] = useState('col-md-3 col-6')
  const [open, setOpen] = useState(false)

  const toggleDrawer = (newOpen) => {
    return () => {
      setOpen(newOpen)
    }
  }

  const isRTL = language === 'ar'

  useEffect(() => {
    let filtered = offers.map((offer) => {
      return {
        ...offer,
        gift_product_ids: offer.gift_product_ids.map((id) => parseInt(id)),
        price: parseFloat(offer.price),
        stock: offer.stock || 0,
        category_type_id: offer.category_type_id,
        average_rate: offer.average_rate ? parseFloat(offer.average_rate) : 5,
        offer_name: isRTL ? offer.offer_name_ar : offer.offer_name_en,
      }
    })
    if (offersfilters.categories.length > 0) {
      filtered = filtered.filter((offer) =>
        offersfilters.categories.includes(offer.category_type_id),
      )
    }
    if (offersfilters.price[0] !== 0 || offersfilters.price[1] !== 6000) {
      filtered = filtered.filter(
        (offer) => offer.price >= offersfilters.price[0] && offer.price <= offersfilters.price[1],
      )
    }
    setFilteredProducts(filtered)
  }, [offersfilters, offers, isRTL])

  const handleChange = (event) => {
    setShownum(event.target.value)
    setCurrentPage(1)
  }
  const handleProductClick = (product) => {
    setSelectedProduct(product)
    setIsModalOpen(true)
  }
  const handleModalClose = () => {
    setIsModalOpen(false)
    setSelectedProduct(null)
  }
  const handlePageChange = (event, value) => {
    setCurrentPage(value)
    window.scrollTo(0, 0)
  }
  const totalPages = Math.ceil(filteredProducts.length / shownum)
  const displayedProducts = filteredProducts.slice(
    (currentPage - 1) * shownum,
    currentPage * shownum,
  )

  return (
    <div className={`container product-listing ${isRTL ? 'rtl' : 'ltr'}`}>
      <div className="row">
        {windowWidth <= 768 ? (
          <Drawer open={open} onClose={toggleDrawer(false)}>
            <button className="btn btn-dark rounded-0" onClick={toggleDrawer(false)}>
              {language === 'ar' ? 'اغلاق الفلاتر' : 'Close Filters'}
            </button>
            <OffersSideBar setFilters={setOffersFilters} />
          </Drawer>
        ) : (
          <div className="col-md-3">
            <OffersSideBar setFilters={setOffersFilters} />
          </div>
        )}
        <div className="col-md-9 pb-md-1 pb-5 col-12">
          {filteredProducts.length === 0 ? (
            <div className="row pt-4">
              <div
                className="row justify-content-center align-items-center p-5 text-center"
                style={{ minHeight: '50vh' }}
              >
                <h2 className="text-danger fw-bold col-12">
                  {isRTL ? 'لا توجد عروض' : 'No Offers Found'}
                </h2>
                <Button
                  variant="contained"
                  className="rounded-pill bg-most-used text-light col-12 col-md-4 py-3 fw-bold"
                  onClick={() =>
                    setOffersFilters({ categories: [], subTypes: [], price: [0, 6000], rating: [] })
                  }
                >
                  {isRTL ? 'إعادة تعيين الفلاتر' : 'Reset Filters'}
                </Button>
              </div>
            </div>
          ) : (
            <div className={`row ${windowWidth >= 768 ? 'pt-4' : ''}`}>
              <div className="col-12 px-4 bg-2 rounded-3 p-2">
                <div className="row">
                  <div className="col-md-10 col-8 d-flex align-items-center">
                    <button
                      className="color-most-used btn px-1"
                      onClick={() => {
                        windowWidth >= 768 ? setColselected('col-3') : setColselected('col-6')
                      }}
                    >
                      <BsFillGrid3X3GapFill className="fs-3" />
                    </button>
                    <button
                      className="color-most-used btn px-1"
                      onClick={() => {
                        windowWidth >= 768 ? setColselected('col-4') : setColselected('col-12')
                      }}
                    >
                      <IoGrid className="fs-3" />
                    </button>
                  </div>
                  <div className="col-md-2 col-4 d-flex justify-content-end">
                    <FormControl size="small" className="text-light">
                      <InputLabel id="select-label" className="text-light">
                        {isRTL ? 'عرض' : 'Show'}
                      </InputLabel>
                      <Select
                        labelId="select-label"
                        id="simple-select"
                        value={shownum}
                        onChange={handleChange}
                        className="text-light"
                        variant="outlined"
                      >
                        {[10, 20, 30, 40].map((num) => (
                          <MenuItem key={num} value={num}>
                            {num}
                          </MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  </div>
                </div>
              </div>
              <div className="col-12 py-2">
                {windowWidth <= 768 ? (
                  <div className="row justify-content-center">
                    <button
                      className="btn btn-dark col-10"
                      onClick={() => {
                        toggleDrawer(true)()
                      }}
                    >
                      {language === 'ar' ? 'تخصيص فلاتر' : 'Set Filters'}
                    </button>
                  </div>
                ) : null}
                <div className="row">
                  {displayedProducts.map((product) => (
                    <div
                      key={product.id}
                      className={`p-2 ${colselected}`}
                      style={{ height: '100%' }}
                    >
                      <div className="card product-card border-0 rounded-3 shadow-sm d-flex flex-column position-relative">
                        <div className="action-menu position-absolute" style={{ zIndex: 1000 }}>
                          {windowWidth >= 768 ? (
                            <button
                              className="btn btn-dark rounded-circle"
                              onClick={() => handleProductClick(product)}
                            >
                              <SlSizeFullscreen />
                            </button>
                          ) : (
                            <Link
                              to={`/offer/${product.id}`}
                              className="btn btn-dark rounded-circle"
                            >
                              <SlSizeFullscreen />
                            </Link>
                          )}
                          <button
                            className="btn mt-2 btn-danger rounded-circle"
                            onClick={() => handleAddTowishlist(product.id, 'o')}
                          >
                            <FaRegHeart />
                          </button>
                        </div>
                        <div className="product-img-container">
                          <Link to={`/offer/${product.id}`}>
                            {/* <img
                                                            src={product.image || "/placeholder.png"}
                                                            alt={product.offer_name || "Product"}
                                                            className="img-fluid rounded-top"
                                                            loading="lazy"
                                                        /> */}
                            <LazyLoadImage
                              src={product.image || '/placeholder.png'}
                              alt={product.wa_code || 'Product'}
                              srcSet={`${product.image}?w=400 400w, ${product.image}?w=800 800w`}
                              effect="blur"
                              width="100%"
                              height="auto"
                              className="img-fluid rounded-top"
                            />
                          </Link>
                        </div>
                        <div className="card-body d-flex flex-column justify-content-between p-3">
                          <h6
                            className={`card-title ${language === 'ar' ? 'text-end' : ''} fs-large fw-bold mb-2`}
                            style={{ fontSize: 'small' }}
                          >
                            {product.offer_name}
                          </h6>
                          <p
                            className={`card-text ${language === 'ar' ? 'text-end' : ''}  text-secondary mb-3`}
                            style={{ fontSize: '0.9rem' }}
                          >
                            {language === 'ar'
                              ? product?.short_description_ar?.length > 100
                                ? `${product?.short_description_ar?.slice(0, 100)}...`
                                : product?.short_description_ar
                              : product?.short_description_en?.length > 100
                                ? `${product?.short_description_en?.slice(0, 100)}...`
                                : product?.short_description_en}
                          </p>
                          <div className="d-flex justify-content-center align-items-center mb-2">
                            <span
                              className="color-most-used fw-bold me-2 fs-large"
                              style={{ fontSize: 'small' }}
                            >
                              {Math.round(product.sale_price_after_discount)}{' '}
                              {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                            <span
                              className="text-muted text-decoration-line-through fs-large"
                              style={{ fontSize: 'small' }}
                            >
                              {Math.round(product.selling_price)}{' '}
                              {language === 'ar' ? 'ج.م' : 'EGP'}
                            </span>
                          </div>
                          <div className="d-md-flex  justify-content-between align-items-center">
                            <div className="col-md-5 col-12 p-1">
                              <span
                                className={`badge ${parseInt(product.stock) > 0 ? 'bg-success' : 'bg-danger'} col-12`}
                              >
                                {language === 'ar'
                                  ? parseInt(product.stock) > 0
                                    ? 'متوفر'
                                    : 'غير متوفر'
                                  : parseInt(product.stock) > 0
                                    ? 'In Stock'
                                    : 'Out of Stock'}
                              </span>
                            </div>
                            <div className="d-flex col-md-7 p-1 justify-content-center col-12 align-items-center">
                              <Rating
                                name="read-only"
                                className={`${windowWidth <= 768 ? 'col-12' : ''}`}
                                value={Math.round(
                                  product.average_rate === null ? 5 : product.average_rate,
                                )}
                                size="small"
                                readOnly
                              />
                              <span className={` mx-1 ${windowWidth <= 768 ? 'd-none' : ''}`}>
                                (
                                {Math.round(
                                  product.average_rate === null ? 5 : product.average_rate,
                                )}
                                )
                              </span>
                            </div>
                          </div>
                          <Link
                            className="btn btn-outline-dark rounded-4 mt-2"
                            to={`/offer/${product.id}`}
                          >
                            {isRTL ? 'أضف إلى السلة' : 'Add to Cart'}
                          </Link>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
                <div className="d-flex justify-content-center mb-md-0 mb-5 mt-4">
                  <Pagination
                    count={totalPages}
                    page={currentPage}
                    onChange={handlePageChange}
                    color="primary"
                  />
                </div>
                {selectedProduct && (
                  <OfferModel
                    open={isModalOpen}
                    onClose={handleModalClose}
                    product={selectedProduct}
                    language={language}
                  />
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default Listingoffers
