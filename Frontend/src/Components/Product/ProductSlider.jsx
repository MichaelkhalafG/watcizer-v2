import React, { useContext, lazy, Suspense, useCallback, useState, useEffect } from 'react'
import PropTypes from 'prop-types'
import { Link } from 'react-router-dom'
import { Button, Alert, Snackbar } from '@mui/material'
import { LazyLoadImage } from 'react-lazy-load-image-component'
import 'react-lazy-load-image-component/src/effects/blur.css'
import useCart from '../../Hooks/useCart'
// import axios from 'axios';
import {
  IoIosArrowForward,
  IoIosArrowBack,
  IoIosArrowDropleftCircle,
  IoIosArrowDroprightCircle,
} from 'react-icons/io'
import { FaRegHeart } from 'react-icons/fa'
import { SlSizeFullscreen } from 'react-icons/sl'
import { MyContext } from '../../Context/Context'
import './Product.css'
// import { use } from 'react';
const ProductModel = lazy(() => import('./ProductModel'))
const Slider = lazy(() => import('react-slick'))
// const Rating = lazy(() => import('@mui/material/Rating'));

const NextArrow = React.memo(({ onClick }) => (
  <IoIosArrowDroprightCircle
    style={{
      fontSize: '40px',
      color: '#26262696',
      position: 'absolute',
      top: '50%',
      transform: 'translateY(-50%)',
      right: '10px',
      zIndex: 10,
      cursor: 'pointer',
    }}
    onClick={onClick}
  />
))
NextArrow.displayName = 'NextArrow'

const PrevArrow = React.memo(({ onClick }) => (
  <IoIosArrowDropleftCircle
    style={{
      fontSize: '40px',
      color: '#26262696',
      position: 'absolute',
      top: '50%',
      transform: 'translateY(-50%)',
      left: '10px',
      zIndex: 10,
      cursor: 'pointer',
    }}
    onClick={onClick}
  />
))
PrevArrow.displayName = 'PrevArrow'

function ProductSlider({ text, gradeproducts, to, moreid }) {
  const {
    language,
    Loader,
    // fetchCart, products,user_id,
    setCurrentPage,
    setgradesfilters,
    windowWidth,
    // offers, setCart,
    handleAddTowishlist,
  } = useContext(MyContext)
  const { addItem } = useCart()
  const [selectedProduct, setSelectedProduct] = useState(null)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [filteredProducts, setFilteredProducts] = useState([])
  const [resetKey, setResetKey] = useState(0)
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [openAlert, setOpenAlert] = useState(false)
  const visibleItems = 15

  useEffect(() => {
    setResetKey((prevKey) => prevKey + 1)
  }, [])
  useEffect(() => {
    if (!Array.isArray(gradeproducts)) return
    setFilteredProducts(gradeproducts.filter((product) => product?.active === 1))
  }, [gradeproducts])
  const handleProductClick = (product) => {
    setSelectedProduct(product)

    setIsModalOpen(true)
  }

  const handleModalClose = () => {
    setIsModalOpen(false)
    setSelectedProduct(null)
  }

  const sliderSettings = {
    dots: false,
    infinite: false,
    speed: 300,
    slidesToShow: 4,
    slidesToScroll: 2,
    initialSlide: 0,
    nextArrow: <NextArrow />,
    prevArrow: <PrevArrow />,
    rtl: language === 'ar',
    responsive: [
      { breakpoint: 1024, settings: { slidesToShow: 3, slidesToScroll: 2 } },
      { breakpoint: 768, settings: { slidesToShow: 2, slidesToScroll: 1 } },
      { breakpoint: 480, settings: { slidesToShow: 2, slidesToScroll: 1 } },
    ],
  }
  const handleAddToCart = useCallback(
    (product, type_stock) => {
      const piecePrice = parseFloat(product.sale_price_after_discount || product.selling_price)

      if (isNaN(piecePrice) || piecePrice <= 0) {
        setAlertMessage(
          language === 'ar'
            ? 'حدث خطأ في حساب السعر.'
            : 'There was an error calculating the price.',
        )
        setAlertType('error')
        setOpenAlert(true)
        return
      }
      addItem({
        product_id: product.id,
        quantity: 1,
        piece_price: piecePrice,
        type_stock: type_stock,
      })

      setAlertMessage(language === 'ar' ? 'تمت الإضافة إلى السلة!' : 'Added to the cart!')
      setAlertType('success')
      setOpenAlert(true)
    },
    [language, setAlertMessage, setAlertType, setOpenAlert, addItem],
  )

  // const handleAddToCart = (product, type_stock) => {
  //     if (!user_id) {
  //         setAlertMessage(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
  //         setAlertType("warning");
  //         setOpenAlert(true);
  //     } else {
  //         const piecePrice = parseInt(product.sale_price_after_discount, 10);
  //         const totalPrice = piecePrice * 1;

  //         if (isNaN(totalPrice) || totalPrice <= 0) {
  //             setAlertMessage(language === "ar" ? "حدث خطأ في حساب السعر الإجمالي." : "There was an error calculating the total price.");
  //             setAlertType("error");
  //             setOpenAlert(true);
  //             return;
  //         }

  //         const payload = {
  //             user_id: user_id,
  //             product_id: product.id,
  //             quantity: 1,
  //             piece_price: piecePrice,
  //             type_stock: type_stock,
  //             total_price: totalPrice,
  //         };
  //         // console.log(payload);

  //         http.post("/add_to_cart", payload)
  //             .then(() => {
  //                 setAlertMessage(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to the cart!");
  //                 setAlertType("success");
  //                 setOpenAlert(true);
  //                 fetchCart(user_id, products, offers, language, setCart);
  //             })
  //             .catch(() => {
  //                 // console.error("Error adding to cart:", error);
  //                 setAlertMessage(language === "ar" ? "حدث خطأ أثناء الإضافة إلى السلة." : "An error occurred while adding to the cart.");
  //                 setAlertType("error");
  //                 setOpenAlert(true);
  //             });
  //     }
  // };
  const isMobile = window.innerWidth < 768

  const ActionMenu = ({ product }) => (
    <div className="action-menu">
      {isMobile ? (
        <Link
          to={`/product/${product.product_title}`}
          className="btn btn-dark rounded-circle"
          title="display product"
        >
          <SlSizeFullscreen />
        </Link>
      ) : (
        <button
          className="btn btn-dark rounded-circle"
          onClick={() => handleProductClick(product)}
          title="display product"
        >
          <SlSizeFullscreen />
        </button>
      )}
      <button
        className="btn mt-2 btn-danger rounded-circle"
        onClick={() => handleAddTowishlist(product.id, 'p')}
        title="add to wishlist"
      >
        <FaRegHeart />
      </button>
    </div>
  )

  if (!gradeproducts) {
    return <Loader />
  } else {
    return (
      <>
        <Snackbar
          open={openAlert}
          autoHideDuration={3000}
          onClose={() => setOpenAlert(false)}
          anchorOrigin={{
            vertical: windowWidth >= 768 ? 'bottom' : 'top',
            horizontal: windowWidth >= 768 ? 'right' : 'left',
          }}
        >
          <Alert
            severity={alertType}
            onClose={() => setOpenAlert(false)}
            sx={{
              fontSize: '1.25rem',
              padding: '16px 24px',
              width: '100%',
              maxWidth: '400px',
            }}
          >
            {alertMessage}
          </Alert>
        </Snackbar>

        <div className="col-12 d-flex px-3 info">
          <div className="col-md-10 col-8">
            <h4 className="color-most-used fw-bold">
              {language === 'ar' ? text.title.ar : text.title.en}
            </h4>
            <p className="text-secondary fs-large" style={{ fontSize: 'small' }}>
              {language === 'ar' ? text.description.ar : text.description.en}
            </p>
          </div>
          <div className="col-md-2 col-4">
            {to && (
              <Link to={to}>
                <Button
                  className="color-most-used rounded-4 px-3 border border-1"
                  title="View More"
                  onClick={() => {
                    setgradesfilters({
                      categories: [],
                      brands: [],
                      subTypes: [],
                      grades: [moreid],
                      price: [0, 6000],
                    })
                    setCurrentPage(1)
                  }}
                >
                  {language === 'ar' ? 'مشاهدة المزيد' : 'View More'}
                  {language === 'ar' ? (
                    <IoIosArrowBack className="me-2" />
                  ) : (
                    <IoIosArrowForward className="ms-2" />
                  )}
                </Button>
              </Link>
            )}
          </div>
        </div>

        <div className="row product-slider pb-5">
          <Suspense fallback={<Loader />}>
            <Slider key={resetKey} {...sliderSettings}>
              {filteredProducts.slice(0, visibleItems).map((product) => (
                <div key={product.product_title} className="p-2" style={{ height: '100%' }}>
                  <div className="card product-card border-0 rounded-3 shadow-sm d-flex flex-column position-relative">
                    <ActionMenu product={product} />
                    <Link
                      to={`/product/${product.product_title}`}
                      className="product-img-container"
                    >
                      <LazyLoadImage
                        src={product.image}
                        alt={product.wa_code}
                        srcSet={`${product.image}?w=400 400w, ${product.image}?w=800 800w`}
                        effect="blur"
                        width="100%"
                        height="auto"
                        className="img-fluid rounded-top"
                      />
                      {/* <img
                                            src={product.image}
                                            alt={product.wa_code}
                                            srcSet={`${product.image}?w=400 400w, ${product.image}?w=800 800w`}
                                            className="img-fluid rounded-top"
                                            loading="lazy"
                                        /> */}
                    </Link>

                    <div className="card-body d-flex flex-column justify-content-between p-3">
                      <h6
                        className={`card-title ${language === 'ar' ? 'text-end' : ''} fs-large fw-bold mb-2`}
                        style={{ fontSize: 'small' }}
                      >
                        {product.product_title.length > 40 ? (
                          <>{product.product_title.slice(0, 50)}...</>
                        ) : product.product_title.length <= 30 ? (
                          <>
                            {product.product_title}
                            <br />
                            <br />
                          </>
                        ) : (
                          product.product_title
                        )}
                      </h6>
                      <p
                        className={`card-text ${language === 'ar' ? 'text-end' : ''}  text-secondary mb-3`}
                        style={{ fontSize: '0.9rem' }}
                      >
                        {product.short_description.length > 100 ? (
                          <>{product.short_description.slice(0, 100)}...</>
                        ) : product.short_description.length <= 50 ? (
                          <>
                            {product.short_description}
                            <br />
                            <br />
                          </>
                        ) : (
                          product.short_description
                        )}
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
                          {Math.round(product.selling_price)} {language === 'ar' ? 'ج.م' : 'EGP'}
                        </span>
                      </div>

                      <div className="d-md-flex  justify-content-between align-items-center">
                        <div className="col-12 p-1">
                          <span
                            className={`badge ${parseInt(product.stock) > 0 ? 'bg-black' : parseInt(product.market_stock) > 0 ? 'bg-success' : 'bg-danger'} col-12`}
                          >
                            {language === 'ar'
                              ? parseInt(product.stock) > 0
                                ? 'اكسبريس'
                                : parseInt(product.market_stock) > 0
                                  ? 'ماركت'
                                  : 'غير متوفر'
                              : parseInt(product.stock) > 0
                                ? 'Express'
                                : parseInt(product.market_stock) > 0
                                  ? 'Market Place'
                                  : 'Out of Stock'}
                          </span>
                        </div>
                        {/* <div className="d-flex col-md-6 p-1 justify-content-center col-12 align-items-center">
                                                <Rating name="read-only" className={`${windowWidth <= 768 ? "col-12" : ""}`} value={Math.round(product.rating === null ? 5 : product.rating)} size="small" readOnly />
                                                <span className={` ms-2 ${windowWidth <= 768 ? "d-none" : ""}`}>
                                                    ({Math.round(product.rating === null ? 5 : product.rating)})</span>
                                            </div> */}
                      </div>
                      {/* {user_id && user_id !== null ? */}
                      <button
                        onClick={() =>
                          handleAddToCart(
                            product,
                            parseInt(product.stock) > 0 ? 'Express' : 'Market',
                          )
                        }
                        className="btn btn-outline-dark rounded-4 mt-2"
                        disabled={
                          parseInt(product.stock) <= 0 && parseInt(product.market_stock) <= 0
                        }
                      >
                        {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                      </button>
                      {/* :
                                                <Link to={`/login`}
                                                    className="btn btn-outline-dark rounded-4 mt-2"
                                                    disabled={(parseInt(product.stock) <= 0 || parseInt(product.market_stock) <= 0)}
                                                >
                                                    {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                                                </Link>
                                            } */}
                    </div>
                  </div>
                </div>
              ))}
            </Slider>
          </Suspense>
        </div>

        {selectedProduct && (
          <Suspense fallback={<Loader />}>
            <ProductModel
              open={isModalOpen}
              onClose={handleModalClose}
              product={selectedProduct}
              language={language}
            />
          </Suspense>
        )}
      </>
    )
  }
}

ProductSlider.propTypes = {
  text: PropTypes.shape({
    title: PropTypes.shape({
      en: PropTypes.string.isRequired,
      ar: PropTypes.string.isRequired,
    }).isRequired,
    description: PropTypes.shape({
      en: PropTypes.string.isRequired,
      ar: PropTypes.string.isRequired,
    }).isRequired,
  }).isRequired,
  gradeproducts: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.number.isRequired,
      product_title: PropTypes.string.isRequired,
      model_name: PropTypes.string,
      long_description: PropTypes.string.isRequired,
      short_description: PropTypes.string.isRequired,
      selling_price: PropTypes.string.isRequired,
      sale_price_after_discount: PropTypes.string.isRequired,
      percentage_discount: PropTypes.string.isRequired,
      stock: PropTypes.number.isRequired,
      rate: PropTypes.number,
      image: PropTypes.string,
      images: PropTypes.arrayOf(PropTypes.string),
      category_type: PropTypes.string.isRequired,
      brand: PropTypes.string.isRequired,
      grade: PropTypes.string,
      sub_type: PropTypes.string.isRequired,
      market_stock: PropTypes.number,
      dial_color: PropTypes.arrayOf(
        PropTypes.shape({
          color_id: PropTypes.number,
          color_value: PropTypes.string,
          color_name_ar: PropTypes.string,
          color_name_en: PropTypes.string,
        }),
      ),
      band_color: PropTypes.arrayOf(
        PropTypes.shape({
          color_id: PropTypes.number,
          color_value: PropTypes.string,
          color_name_ar: PropTypes.string,
          color_name_en: PropTypes.string,
        }),
      ),
      band_closure: PropTypes.string,
      dial_display_type: PropTypes.string,
      case_shape: PropTypes.string,
      band_material: PropTypes.string,
      watch_movement: PropTypes.string,
      water_resistance_size_type: PropTypes.string,
      water_resistance: PropTypes.number,
      case_size_type: PropTypes.string,
      case: PropTypes.string,
      band_size_type: PropTypes.string,
      band_length: PropTypes.string,
      band_width_size_type: PropTypes.string,
      band_width: PropTypes.string,
      case_thickness_size_type: PropTypes.string,
      case_thickness: PropTypes.string,
      watch_height_size_type: PropTypes.string,
      watch_width_size_type: PropTypes.string,
      watch_length_size_type: PropTypes.string,
      dial_glass_material: PropTypes.string,
      watch_height: PropTypes.string,
      watch_width: PropTypes.string,
      watch_length: PropTypes.string,
      dial_case_material: PropTypes.string,
      country: PropTypes.string,
      stone: PropTypes.string,
      features: PropTypes.arrayOf(PropTypes.string),
      gender: PropTypes.arrayOf(PropTypes.string),
    }),
  ).isRequired,
}

export default ProductSlider
