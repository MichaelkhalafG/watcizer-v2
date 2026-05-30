import './Listing.css'
import { MyContext } from '../../Context/Context'
import { FormControl, InputLabel, MenuItem, Select, Snackbar, Alert } from '@mui/material'
import { useContext, useState, useEffect, useCallback } from 'react'
import { BsFillGrid3X3GapFill } from 'react-icons/bs'
import useCart from '../../Hooks/useCart'
import { IoGrid } from 'react-icons/io5'
import { FaRegHeart } from 'react-icons/fa'
import { SlSizeFullscreen } from 'react-icons/sl'
import { Link } from 'react-router-dom'
import { useLocation } from 'react-router-dom'
import { LazyLoadImage } from 'react-lazy-load-image-component'
// import axios from "axios";
import 'react-lazy-load-image-component/src/effects/blur.css'
// import { Rating } from "@mui/material";
import ProductModel from '../../Components/Product/ProductModel'
import Pagination from '@mui/material/Pagination'

function ListingSearch() {
  const {
    language,
    products,
    // fetchCart, user_id, offers, setCart,
    currentPage,
    setCurrentPage,
    windowWidth,
    handleAddTowishlist,
  } = useContext(MyContext)
  const { addItem } = useCart()
  const [filteredProducts, setFilteredProducts] = useState([])
  const location = useLocation()
  const searchParams = new URLSearchParams(location.search)
  const searchTerm = searchParams.get('query') || ''
  const [displayedProducts, setDisplayedProducts] = useState([])
  const [shownum, setShownum] = useState(10)
  const [selectedProduct, setSelectedProduct] = useState(null)
  const [isModalOpen, setIsModalOpen] = useState(false)
  const [colselected, setColselected] = useState('col-md-3 col-6')
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [openAlert, setOpenAlert] = useState(false)

  const isRTL = language === 'ar'

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
  useEffect(() => {
    if (searchTerm.trim()) {
      const filtered = products.filter(
        (product) =>
          product.product_title.toLowerCase().includes(searchTerm.toLowerCase()) ||
          product.short_description.toLowerCase().includes(searchTerm.toLowerCase()) ||
          product.brand.toLowerCase().includes(searchTerm.toLowerCase()),
      )
      setFilteredProducts(filtered)
    } else {
      setFilteredProducts(products)
    }
  }, [searchTerm, products])
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
  useEffect(() => {
    if (!filteredProducts) return
    setDisplayedProducts(filteredProducts.slice((currentPage - 1) * shownum, currentPage * shownum))
  }, [currentPage, filteredProducts, shownum])
  return (
    <div className={`container product-listing ${isRTL ? 'rtl' : 'ltr'}`}>
      <Snackbar
        open={openAlert}
        autoHideDuration={3000}
        onClose={() => setOpenAlert(false)}
        anchorOrigin={{
          vertical: windowWidth >= 768 ? 'bottom' : 'top',
          horizontal: windowWidth >= 768 ? 'right' : 'left',
        }}
      >
        <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
          {alertMessage}
        </Alert>
      </Snackbar>
      <h2 className="text-center my-4">
        {searchTerm ? `نتائج البحث عن: ${searchTerm}` : 'جميع المنتجات'}
      </h2>
      <div className="row justify-content-center">
        <div className="col-md-9 pb-md-1 pb-5 col-12">
          {filteredProducts.length === 0 ? (
            <div className="row pt-4">
              <div
                className="row justify-content-center align-items-center p-5 text-center"
                style={{ minHeight: '50vh' }}
              >
                <h2 className="text-danger fw-bold col-12">
                  {isRTL ? 'لا توجد منتجات' : 'No Products Found'}
                </h2>
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
                        setColselected('col-md-3 col-6')
                      }}
                    >
                      <BsFillGrid3X3GapFill className="fs-3" />
                    </button>
                    <button
                      className="color-most-used btn px-1"
                      onClick={() => {
                        setColselected('col-md-4 col-12')
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
                              to={`/product/${product.product_title}`}
                              className="btn btn-dark rounded-circle"
                            >
                              <SlSizeFullscreen />
                            </Link>
                          )}
                          <button
                            className="btn mt-2 btn-danger rounded-circle"
                            onClick={() => handleAddTowishlist(product.id, 'p')}
                          >
                            <FaRegHeart />
                          </button>
                        </div>
                        <Link
                          to={`/product/${product.product_title}`}
                          className="product-img-container"
                        >
                          {/* <img
                                                            src={product.image || "/placeholder.png"}
                                                            alt={product.wa_code || "Product"}
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
                        <div className="card-body d-flex flex-column justify-content-between p-3">
                          <h6
                            className={`card-title ${language === 'ar' ? 'text-end' : ''} fs-large fw-bold mb-2`}
                            style={{ fontSize: 'small' }}
                          >
                            {product.product_title.length > 30 ? (
                              <>{product.product_title.slice(0, 30)}...</>
                            ) : product.product_title.length <= 20 ? (
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
                              {Math.round(product.selling_price)}{' '}
                              {language === 'ar' ? 'ج.م' : 'EGP'}
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
                            {/* <div className="d-flex col-md-7 p-1 justify-content-center col-12 align-items-center">
                                                                <Rating name="read-only" className={`${windowWidth <= 768 ? "col-12" : ""}`} value={Math.round(product.rating === null ? 5 : product.rating)} size="small" readOnly />
                                                                <span className={` mx-1 ${windowWidth <= 768 ? "d-none" : ""}`}>({Math.round(product.rating === null ? 5 : product.rating)})</span>
                                                            </div> */}
                          </div>
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
                          {/* {user_id && user_id !== null ?
                                                            <Link onClick={() => handleAddToCart(product, (parseInt(product.stock) > 0 ? 'Express' : "Market"))}
                                                                className="btn btn-outline-dark rounded-4 mt-2"
                                                                disabled={parseInt(product.stock) <= 0}
                                                            >
                                                                {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                                                            </Link>
                                                            :
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
                  <ProductModel
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

export default ListingSearch
