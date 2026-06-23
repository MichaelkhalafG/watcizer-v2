import { useState, useEffect, useContext, useCallback } from 'react'
import useCart, { getItemKey } from '../../Hooks/useCart'
import PropTypes from 'prop-types'
import { Modal, Box, Button, Rating, Alert, Snackbar, useMediaQuery } from '@mui/material'
import { MdClose } from 'react-icons/md'
import InnerImageZoom from 'react-inner-image-zoom'
import defimg from '../../assets/images/offer.webp'
import 'react-inner-image-zoom/lib/InnerImageZoom/styles.css'
import { MyContext } from '../../Context/Context'
import { productUrl } from '../../utils/productUrl'
// import axios from 'axios';
import { Link } from 'react-router-dom'

function OfferModel({ open, onClose, product, language }) {
  const {
    // user_id, fetchCart, offers, setCart,
    tables,
    handleAddTowishlist,
    products,
  } = useContext(MyContext)
  const { cart, addItem, updateQuantity } = useCart()
  const isDesktop = useMediaQuery('(min-width:768px)')
  const [selectedImage, setSelectedImage] = useState('')
  const [quantity, setQuantity] = useState(1)
  const [stock, setstock] = useState()
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [openAlert, setOpenAlert] = useState(false)

  const showAlert = useCallback((message, type) => {
    setOpenAlert(true)
    setAlertMessage(message)
    setAlertType(type)
  }, [])

  useEffect(() => {
    if (product) {
      setSelectedImage(product.image || product.images?.[0] || '')
      setstock(parseInt(product.stock))
    }
  }, [product])

  const handleQuantityChange = (change) => {
    setQuantity((prev) => Math.max(1, Math.min(prev + change, stock)))
  }

  const renderDetail = (labelEn, labelAr, value, fs = '1rem', col = 'col-4') => (
    <div className={`${col}`}>
      <p className="fw-bold text-secondary" style={{ fontSize: fs }}>
        <span className={`${language === 'ar' ? 'ms-2' : 'me-2'}`}>
          {language === 'ar' ? `${labelAr} :` : `${labelEn} :`}
        </span>
        {value || '-'}
      </p>
    </div>
  )

  const render_product_ids = (labelEn, ids, labelAr, col = 'col-3') =>
    ids.map((id) => (
      <div className={`${col} p-1`} key={id}>
        <Link to={productUrl(product)}>
          <img
            className="col-12 border border-1 rounded-3"
            src={products.find((p) => p.id === id)?.image}
            loading="lazy"
            alt={`product ${id}`}
          />
        </Link>
      </div>
    ))

  const handleAddToCart = useCallback(() => {
    const piecePrice = parseFloat(product.sale_price_after_discount)
    const totalQty = quantity

    if (isNaN(piecePrice) || piecePrice <= 0) {
      showAlert(
        language === 'ar' ? 'حدث خطأ في السعر.' : 'There was an error with the price.',
        'warning',
      )
      return
    }

    if (stock <= 0) {
      showAlert(
        language === 'ar' ? 'المنتج غير متوفر حالياً.' : 'This product is currently out of stock.',
        'warning',
      )
      return
    }

    const identifier = `offer_${product.id}`

    const existingItem = cart.cart_item.find((item) => getItemKey(item) === identifier)

    if (existingItem) {
      const newQuantity = existingItem.quantity + totalQty
      if (newQuantity > stock) {
        showAlert(
          language === 'ar'
            ? 'الكمية المطلوبة أكبر من المتوفر.'
            : 'Requested quantity exceeds available stock.',
          'warning',
        )
        return
      }
      updateQuantity(identifier, newQuantity)
    } else {
      if (totalQty > stock) {
        showAlert(
          language === 'ar'
            ? 'الكمية المطلوبة أكبر من المتوفر.'
            : 'Requested quantity exceeds available stock.',
          'warning',
        )
        return
      }
      addItem({
        offer_id: product.id,
        quantity: totalQty,
        piece_price: piecePrice,
      })
    }

    showAlert(language === 'ar' ? 'تمت الإضافة إلى السلة!' : 'Added to cart!', 'success')
  }, [language, product, quantity, showAlert, addItem, updateQuantity, cart, stock])

  // const handleAddToCart = () => {
  //     if (!user_id) {
  //         alert(language === "ar" ? "يجب تسجيل الدخول أولاً!" : "You must login first!");
  //     } else {
  //         const piecePrice = parseInt(product.price, 10);
  //         const totalPrice = piecePrice * quantity;

  //         if (isNaN(totalPrice) || totalPrice <= 0) {
  //             // console.error("Invalid total price calculation.");
  //             alert(language === "ar" ? "حدث خطأ في حساب السعر الإجمالي." : "There was an error calculating the total price.");
  //             return;
  //         }

  //         const payload = {
  //             user_id: user_id,
  //             offer_id: product.id,
  //             quantity: quantity,
  //             piece_price: piecePrice,
  //             total_price: totalPrice,
  //         };

  //         http.post("/add_to_cart", payload)
  //             .then(() => {
  //                 alert(language === "ar" ? "تمت الإضافة إلى السلة!" : "Added to the cart!");
  //                 fetchCart(user_id, products, offers, language, setCart);
  //             })
  //             .catch(() => {
  //                 // console.error("Error adding to cart:", error);
  //                 alert(language === "ar" ? "حدث خطأ أثناء الإضافة إلى السلة." : "An error occurred while adding to the cart.");
  //             });
  //     }
  // };

  if (!product) return null

  return (
    <>
      <Snackbar
        open={openAlert}
        autoHideDuration={3000}
        onClose={() => setOpenAlert(false)}
        anchorOrigin={{
          vertical: isDesktop ? 'bottom' : 'top',
          horizontal: isDesktop ? 'right' : 'left',
        }}
      >
        <Alert severity={alertType} onClose={() => setOpenAlert(false)}>
          {alertMessage}
        </Alert>
      </Snackbar>
      <Modal open={open} onClose={onClose}>
        <Box
          sx={{
            position: 'absolute',
            top: '50%',
            left: language === 'ar' ? 'unset' : '50%',
            right: language === 'ar' ? '50%' : 'unset',
            transform: language === 'ar' ? 'translate(50%, -50%)' : 'translate(-50%, -50%)',
            width: '80%',
            maxWidth: '900px',
            bgcolor: 'background.paper',
            boxShadow: 24,
            p: 4,
            borderRadius: '10px',
            overflow: 'hidden',
          }}
          className={`p-5 ${language === 'ar' ? 'rtl' : 'ltr'}`}
          style={{
            position: 'relative',
            direction: language === 'ar' ? 'rtl' : 'ltr',
          }}
        >
          <div className="row border-bottom border-2 product-header mb-3">
            <div className="col-12">
              <h4 className="fw-bold">
                {language === 'ar' ? product.offer_name_ar : product.offer_name_en}
              </h4>
            </div>
            {renderDetail(
              'Category Type',
              'النوع',
              tables.categoryTypes.find((c) => c.id === product.category_type_id)
                ?.category_type_name,
            )}
            {renderDetail('Price', 'السعر', product.price)}
            <Rating
              name="read-only"
              value={Math.round(product.average_rate === null ? 5 : product.average_rate)}
              size="small"
              readOnly
            />
          </div>
          <div className="row product-details">
            <div className="col-md-5 product-images">
              <div className="selected-image mb-3 d-flex justify-content-center">
                {selectedImage && (
                  <InnerImageZoom
                    src={selectedImage}
                    zoomSrc={selectedImage || defimg}
                    alt="Selected Product"
                    style={{
                      width: '100%',
                      borderRadius: '8px',
                      objectFit: 'cover',
                      maxHeight: '300px',
                    }}
                    zoomType="hover"
                    zoomPreload={true}
                    zoomScale={2}
                  />
                )}
              </div>
              <div className="d-flex mt-3 gap-2 justify-content-center flex-wrap">
                {product?.image && (
                  <img
                    src={product.image}
                    alt="Main Thumbnail"
                    onClick={() => setSelectedImage(product.image)}
                    style={{
                      width: '60px',
                      height: '60px',
                      objectFit: 'cover',
                      borderRadius: '4px',
                      border:
                        product.image === selectedImage ? '2px solid #262626' : '1px solid #ddd',
                      cursor: 'pointer',
                      boxShadow:
                        product.image === selectedImage
                          ? '0px 4px 10px rgba(0, 0, 0, 0.2)'
                          : 'none',
                    }}
                    className="thumbnail"
                  />
                )}
                {product?.images?.map((image, index) => (
                  <img
                    key={image}
                    src={image}
                    alt={`Thumbnail ${index + 1}`}
                    onClick={() => setSelectedImage(image)}
                    style={{
                      width: '60px',
                      height: '60px',
                      objectFit: 'cover',
                      borderRadius: '4px',
                      border: image === selectedImage ? '2px solid #262626' : '1px solid #ddd',
                      cursor: 'pointer',
                      boxShadow:
                        image === selectedImage ? '0px 4px 10px rgba(0, 0, 0, 0.2)' : 'none',
                    }}
                    className="thumbnail"
                  />
                ))}
              </div>
            </div>
            <div className="col-7 row product-info">
              {/* <h5 className="">{language === 'ar' ? 'التفاصيل' : 'Details'}</h5>
                        <p className="text-secondary " style={{ fontSize: 'small' }}>
                            {product.long_description}
                        </p> */}
              <div className="col-12 p-1">
                <p className="fw-bold text-secondary" style={{ fontSize: 'large' }}>
                  {language === 'ar' ? `المنتج الاساسي :` : `Main Product :`}
                </p>
                <Link
                  to={`/product/${product.main_product_id}`}
                  className="col-4"
                  style={{
                    fontSize: 'small',
                  }}
                >
                  <img
                    className="col-4 border border-1 rounded-3"
                    src={products.find((p) => p.id === product.main_product_id)?.image}
                    loading="lazy"
                    alt={`product ${product.main_product_id}`}
                  />
                </Link>
              </div>
              <div className="row p-3">
                <p className="fw-bold text-secondary" style={{ fontSize: 'large' }}>
                  {language === 'ar' ? `منتجات العرض :` : `Offer Products :`}
                </p>
                {render_product_ids('Gift Products', product.gift_product_ids, 'منتجات العرض')}
              </div>
              <div className="quantity-control col-6 d-flex align-items-center">
                <Button
                  variant="outlined"
                  size="small"
                  onClick={() => handleQuantityChange(-1)}
                  disabled={quantity <= 1}
                  sx={{ minWidth: '30px', padding: '5px' }}
                >
                  -
                </Button>
                <input
                  type="text"
                  value={quantity}
                  readOnly
                  style={{
                    width: '40px',
                    textAlign: 'center',
                    margin: '0 10px',
                    border: '1px solid #ddd',
                    borderRadius: '4px',
                  }}
                />
                <Button
                  variant="outlined"
                  size="small"
                  onClick={() => handleQuantityChange(1)}
                  sx={{ minWidth: '30px', padding: '5px' }}
                >
                  +
                </Button>
              </div>
              <div className="col-6 d-flex align-items-center">
                {stock && parseInt(stock) > 0 ? (
                  <span className="badge bg-success" style={{ fontSize: '0.9rem' }}>
                    {language === 'ar' ? 'متوفر' : 'In Stock'}
                  </span>
                ) : (
                  <span className="badge bg-danger" style={{ fontSize: '0.9rem' }}>
                    {language === 'ar' ? 'غير متوفر' : 'Out of Stock'}
                  </span>
                )}
              </div>
              <div className="mt-3 row action-buttons">
                <div className="col-4 p-1">
                  <button
                    className={` btn btn-dark col-12`}
                    onClick={handleAddToCart}
                    disabled={stock <= 0}
                    style={{
                      fontSize: 'small',
                    }}
                  >
                    {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
                  </button>
                </div>
                <div className="col-4 p-1">
                  <button
                    className="btn btn-outline-danger col-12 "
                    style={{
                      fontSize: 'small',
                    }}
                    onClick={() => handleAddTowishlist(product.id, 'o')}
                  >
                    {language === 'ar' ? 'أضف إلى قائمة الرغبات' : 'Add to Wish List'}
                  </button>
                </div>
              </div>
            </div>
          </div>
          <Button
            className="close"
            style={{
              position: 'absolute',
              top: '10px',
              right: language === 'ar' ? 'unset' : '10px',
              left: language === 'ar' ? '10px' : 'unset',
              color: '#555',
            }}
            onClick={onClose}
          >
            <MdClose />
          </Button>
        </Box>
      </Modal>
    </>
  )
}

OfferModel.propTypes = {
  open: PropTypes.bool.isRequired,
  onClose: PropTypes.func.isRequired,
  product: PropTypes.shape({
    id: PropTypes.number.isRequired,
    main_product_id: PropTypes.number.isRequired,
    gift_product_ids: PropTypes.arrayOf(PropTypes.number).isRequired,
    price: PropTypes.oneOfType([PropTypes.number, PropTypes.string]).isRequired,
    category_type_id: PropTypes.number,
    stock: PropTypes.number.isRequired,
    image: PropTypes.string.isRequired,
    average_rate: PropTypes.oneOfType([PropTypes.number, PropTypes.oneOf([null])]),
    offer_name_en: PropTypes.string.isRequired,
    offer_name_ar: PropTypes.string.isRequired,
    long_description: PropTypes.string,
    rating: PropTypes.oneOfType([PropTypes.number, PropTypes.oneOf([null])]),
  }).isRequired,
  language: PropTypes.string.isRequired,
}

export default OfferModel
