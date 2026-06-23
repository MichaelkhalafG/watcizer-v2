import { useContext, useEffect, useState, useCallback } from 'react'
import { Rating, Button, TextField, Typography, Alert, Snackbar, useMediaQuery } from '@mui/material'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { toSlug } from '../../utils/slugs'
import { getImageUrl, handleImgError } from '../../utils/imageUrl'
import { buildListingParams } from '../../utils/listingParams'
import useCart, { getItemKey } from '../../Hooks/useCart'
import InnerImageZoom from 'react-inner-image-zoom'
import 'react-inner-image-zoom/lib/InnerImageZoom/styles.css'
import './Product.css'
import PropTypes from 'prop-types'
import ProductSlider from './ProductSlider'
import DOMPurify from 'dompurify'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import http from '../../Context/api'
import TrustSignals from '../Merchandising/TrustSignals'

function ProductDisplay() {
  const [alertMessage, setAlertMessage] = useState('')
  const [alertType, setAlertType] = useState('info')
  const [isfashion, setisfashion] = useState(false)
  const [openAlert, setOpenAlert] = useState(false)
  const [type_stock, settype_stock] = useState('')
  const [price, setPrice] = useState(0)
  const [pricebefore, setPriceBefore] = useState(0)
  const { slug: routeParam } = useParams()
  const navigate = useNavigate()
  const { addItem, updateQuantity, cart } = useCart()
  const isDesktop = useMediaQuery('(min-width:768px)')
  const { handleAddTowishlist, Loader, products, tables } = useContext(MyContext)
  const { language } = useUIStore()
  const { userId: user_id } = useAuthStore()
  const [product, setProduct] = useState(null)
  const [related, setRelated] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // Resolve the URL param to the exact ENGLISH product_title the API expects.
  // The param may be: an English slug (rolex-submariner-date), a numeric id
  // (backward compat), or a legacy raw title. Slugs/ids are resolved against the
  // context product list (which carries `name` = English title + `id`); a raw
  // title is passed straight through (the API matches the English product_title).
  useEffect(() => {
    if (!routeParam) return
    const param = decodeURIComponent(routeParam)
    const isNumeric = /^\d+$/.test(param)
    const isSlug = /^[a-z0-9-]+$/.test(param)
    const all = products || []

    let titleToFetch = null
    if (isNumeric || isSlug) {
      // Need the context list to map slug/id → English title.
      if (!all.length) return // products not loaded yet — stay in loading state
      const match =
        (isNumeric && all.find((p) => p.id === Number(param))) ||
        all.find((p) => toSlug(p.name || '') === param) ||
        all.find((p) => toSlug(p.product_title || '') === param) ||
        null
      if (!match) {
        setError('not_found')
        setLoading(false)
        return
      }
      titleToFetch = match.name || match.product_title
    } else {
      // Legacy raw-title URL → fetch directly.
      titleToFetch = param
    }

    setLoading(true)
    setError(null)
    http
      .get(`products/by-name/${encodeURIComponent(titleToFetch)}`)
      .then(({ data }) => {
        setProduct(data.product)
        setRelated(data.related ?? [])
      })
      .catch((err) => {
        setError(err.response?.status === 404 ? 'not_found' : 'error')
      })
      .finally(() => setLoading(false))
  }, [routeParam, products])

  // Keep the document title in sync with the loaded product.
  useEffect(() => {
    if (product) {
      document.title = `${product.name_en || product.product_title || product.name || 'Product'} — Watchizer`
    }
    return () => {
      document.title = 'Watchizer'
    }
  }, [product])
  const DialColor = product?.dial_color?.[0]?.color_value
  const BandColor = product?.band_color?.[0]?.color_value
  const [selectedDialColor, setSelectedDialColor] = useState(DialColor || null)
  const [selectedBandColor, setSelectedBandColor] = useState(BandColor || null)
  const [selectedImage, setSelectedImage] = useState('')
  const [ratings, setRatings] = useState([])
  const [stock, setstock] = useState()
  const [quantity, setQuantity] = useState(1)
  const [totalRating, setTotalRating] = useState(5)
  const [ratingsOpen, setRatingsOpen] = useState(false)
  const [showFullDescription, setShowFullDescription] = useState(false)
  const [newRating, setNewRating] = useState({ value: 0, comment: '' })
  const handleRatingClick = () => setRatingsOpen((prev) => !prev)

  const handleQuantityChange = (change) => {
    setQuantity((prev) => Math.max(1, Math.min(prev + change, stock)))
  }

  const getShortDescription = (desc) => {
    if (!desc) return language === 'ar' ? 'لا يوجد وصف' : 'No description available'
    const words = desc.split(' ')
    if (words.length <= 10) return desc
    return words.slice(0, 10).join(' ') + '...'
  }

  // The PDP API (ProductResource) returns several fields as OBJECTS
  // ({ id, name_en, name_ar, ... }) or arrays of objects — rendering one of
  // those directly throws "Objects are not valid as a React child". safeStr
  // coerces any value into a display string, picking the active language.
  const safeStr = (val) => {
    if (val === null || val === undefined) return ''
    if (typeof val === 'string') return val
    if (typeof val === 'number') return String(val)
    if (Array.isArray(val)) return val.map(safeStr).filter(Boolean).join(', ')
    if (typeof val === 'object') {
      const localized =
        language === 'ar' ? val.name_ar || val.color_name_ar : val.name_en || val.color_name_en
      return (
        localized ||
        val.name_en ||
        val.name_ar ||
        val.brand_name ||
        val.sub_type_name ||
        val.category_type_name ||
        val.grade_name ||
        val.material_name ||
        val.color_name ||
        val.gender_name ||
        val.feature_name ||
        val.name ||
        ''
      )
    }
    return ''
  }

  // Product title is exposed both as flat strings and localized aliases.
  const getProductName = (p) => {
    if (!p) return ''
    if (language === 'ar')
      return p.name_ar || p.product_title_ar || p.product_title || p.name || ''
    return p.name_en || p.product_title || p.name || ''
  }

  const formatPrice = (val) => {
    const num = Number(val)
    if (isNaN(num)) return '0'
    return Math.round(num).toLocaleString(language === 'ar' ? 'ar-EG' : 'en-US')
  }

  const renderDetail = (labelEn, labelAr, value, fs, col) => (
    <div className={`${col} mb-2`}>
      <p
        className={`fw-bold text-secondary ${language === 'ar' ? 'text-end' : 'text-start'}`}
        style={{ fontSize: fs }}
      >
        <span className={`${language === 'ar' ? 'ms-2' : 'me-2'}`}>
          {language === 'ar' ? `${labelAr}:` : `${labelEn}:`}
        </span>
        {value || '-'}
      </p>
    </div>
  )
  const showAlert = useCallback((message, type) => {
    setAlertMessage(message)
    setAlertType(type)
    setOpenAlert(true)
  }, [])

  useEffect(() => {
    if (product) {
      product.category_type_name === 'Watches' ? setisfashion(false) : setisfashion(true)
      setPrice(parseFloat(product.sale_price_after_discount))
      setPriceBefore(parseFloat(product.selling_price))
    }
  }, [product])

  const handleAddToCart = useCallback(() => {
    const piecePrice = parseFloat(price)
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

    const identifier = `product_${product.id}`
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
        product_id: product.id,
        quantity: totalQty,
        piece_price: piecePrice,
        color_band: selectedBandColor,
        color_dial: selectedDialColor,
        type_stock: type_stock,
      })
    }

    showAlert(language === 'ar' ? 'تمت الإضافة إلى السلة!' : 'Added to cart!', 'success')
  }, [
    language,
    product?.id,
    price,
    quantity,
    selectedBandColor,
    selectedDialColor,
    type_stock,
    showAlert,
    addItem,
    updateQuantity,
    cart,
    stock,
  ])

  const renderColorDetail = (labelEn, labelAr, colors, fs, col, setColor) => (
    <div className={`${col} mb-2`}>
      <div
        className={`fw-bold text-secondary ${language === 'ar' ? 'text-end' : 'text-start'}`}
        style={{ fontSize: fs }}
      >
        <span className={`${language === 'ar' ? 'ms-2' : 'me-2'} pb-2`}>
          {language === 'ar' ? `${labelAr} :` : `${labelEn} :`}
        </span>
        <div className={`d-flex gap-2 ${language === 'ar' ? 'justify-content-end' : ''}`}>
          {colors &&
            colors.map((color, index) => (
              <div
                key={index}
                onClick={() => setColor(color.color_value)}
                style={{
                  backgroundColor: color.color_value || '#f0f0f0',
                  width: '30px',
                  height: '30px',
                  borderRadius: '50%',
                  border:
                    selectedDialColor === color.color_value ||
                    selectedBandColor === color.color_value
                      ? '2px solid #000'
                      : '1px solid #ddd',
                  cursor: 'pointer',
                }}
                title={language === 'ar' ? color.color_name_ar : color.color_name_en}
              />
            ))}
        </div>
      </div>
    </div>
  )

  const fetchRatings = useCallback(async () => {
    try {
      const response = await http.get('/all_product_rating')
      const productRatings = response.data.filter((r) => r.product_id === product?.id)
      setRatings(productRatings)
    } catch {
      // console.error("Error fetching ratings:", error);
    }
  }, [product])

  useEffect(() => {
    if (product?.image) {
      setSelectedImage(product.image)
    } else if (product?.images?.length) {
      setSelectedImage(product.images[0])
    }
    if (product) {
      fetchRatings()
      setSelectedDialColor(product.dial_color?.[0]?.color_value || null)
      setSelectedBandColor(product.band_color?.[0]?.color_value || null)
      if (product?.stock && product.stock > 0) {
        setstock(parseInt(product.stock))
        settype_stock('Express')
      } else if (product?.market_stock && product.market_stock > 0) {
        setstock(parseInt(product.market_stock))
        settype_stock('Market')
      } else {
        setstock(0)
      }
    }
  }, [product, fetchRatings])

  useEffect(() => {
    if (ratings.length > 0) {
      const total = ratings.reduce((acc, r) => acc + r.rating, 0)
      setTotalRating(total / ratings.length)
    } else {
      setTotalRating(5)
    }
  }, [ratings])

  const handleRatingSubmit = async (value, comment) => {
    if (!user_id) {
      showAlert(language === 'ar' ? 'يجب تسجيل الدخول أولاً!' : 'You must login first!', 'warning')
      return
    }
    const sanitizedComment = DOMPurify.sanitize(comment)
    if (value && sanitizedComment.trim()) {
      try {
        await http.post('/add_product_rating', null, {
          params: {
            product_id: product.id,
            rating: value,
            comment: sanitizedComment,
            user_id: user_id,
          },
        })

        await fetchRatings()
        setNewRating({ value: 0, comment: '' })
        showAlert(
          language === 'ar' ? 'تم إرسال التقييم بنجاح!' : 'Rating submitted successfully!',
          'success',
        )
      } catch {
        // console.error("Error submitting rating:", error);
        showAlert(
          language === 'ar'
            ? 'حدث خطأ أثناء إرسال التقييم. يرجى المحاولة مرة أخرى.'
            : 'An error occurred while submitting the rating. Please try again.',
          'error',
        )
      }
    } else {
      showAlert(
        language === 'ar'
          ? 'يرجى إدخال تقييم وتعليق صحيح'
          : 'Please enter a valid rating and comment',
        'warning',
      )
    }
  }

  if (loading || !product) {
    if (error === 'not_found') {
      return (
        <div className="container text-center py-5">
          <h3 className="fw-bold">{language === 'ar' ? 'المنتج غير موجود' : 'Product not found'}</h3>
          <p className="text-secondary">
            {language === 'ar'
              ? 'المنتج الذي تبحث عنه غير موجود.'
              : "The product you're looking for doesn't exist."}
          </p>
          <button className="btn btn-dark rounded-pill px-4 mt-2" onClick={() => navigate('/listing')}>
            {language === 'ar' ? 'تصفح كل المنتجات' : 'Browse all products'}
          </button>
        </div>
      )
    }
    if (error) {
      return (
        <div className="container text-center py-5">
          <h3 className="fw-bold">{language === 'ar' ? 'حدث خطأ ما' : 'Something went wrong'}</h3>
        </div>
      )
    }
    return <Loader />
  } else {
    return (
      <div className="container">
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

        {/* Breadcrumb */}
        <nav
          className="d-flex flex-wrap align-items-center gap-1 px-2 py-3"
          style={{ fontSize: '12px', color: 'rgba(0,0,0,0.55)' }}
          dir={language === 'ar' ? 'rtl' : 'ltr'}
        >
          <Link to="/" className="text-decoration-none text-secondary">
            {language === 'ar' ? 'الرئيسية' : 'Home'}
          </Link>
          <span>/</span>
          <Link to="/listing" className="text-decoration-none text-secondary">
            {language === 'ar' ? 'كل المنتجات' : 'All Products'}
          </Link>
          {product?.category_type &&
            (() => {
              const catObj = (tables?.categoryTypes || []).find((c) =>
                (c.translations || []).some((t) => t.category_type_name === product.category_type),
              )
              const catHref = catObj
                ? `/listing?${buildListingParams({ categories: [catObj.id] }, {}, tables).toString()}`
                : null
              return (
                <>
                  <span>/</span>
                  {catHref ? (
                    <Link to={catHref} className="text-decoration-none text-secondary">
                      {safeStr(product.category_type)}
                    </Link>
                  ) : (
                    <span>{safeStr(product.category_type)}</span>
                  )}
                </>
              )
            })()}
          <span>/</span>
          <span className="fw-semibold" style={{ color: '#262626' }}>
            {getProductName(product)}
          </span>
        </nav>

        <div
          className={`row ${isDesktop ? 'border-bottom' : ''}  border-2 ps-1 p-4 pb-2 product-header mb-3`}
        >
          <div className={`col-12 ${language === 'ar' ? 'text-end' : 'text-start'}`}>
            <h3 className="fw-bold">{getProductName(product) || '-'}</h3>
          </div>
          <div className="col-4">
            {renderDetail(
              'Brand',
              'البراند',
              safeStr(product?.brand),
              isDesktop ? 'Medium' : 'small',
              'col-12',
            )}
          </div>
          <div className="col-4">
            {renderDetail(
              'Type',
              'النوع',
              safeStr(product?.category_type),
              isDesktop ? 'Medium' : 'small',
              'col-12',
            )}
          </div>
          <div className="col-4">
            <Rating name="read-only" value={totalRating} size="small" readOnly />
          </div>
        </div>

        <div className="row product-details">
          <div className="col-md-4 product-images">
            <div className="selected-image mb-3 d-flex justify-content-center">
              {selectedImage && (
                <InnerImageZoom
                  src={getImageUrl(selectedImage)}
                  zoomSrc={getImageUrl(selectedImage)}
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
                  src={getImageUrl(product.image)}
                  alt="Main Thumbnail"
                  onClick={() => setSelectedImage(product.image)}
                  onError={handleImgError}
                  style={{
                    width: '60px',
                    height: '60px',
                    objectFit: 'cover',
                    borderRadius: '4px',
                    border:
                      product.image === selectedImage ? '2px solid #262626' : '1px solid #ddd',
                    cursor: 'pointer',
                    boxShadow:
                      product.image === selectedImage ? '0px 4px 10px rgba(0, 0, 0, 0.2)' : 'none',
                  }}
                  className="thumbnail"
                />
              )}
              {product?.images?.map((image, index) => (
                <img
                  key={index}
                  src={getImageUrl(image)}
                  alt={`Thumbnail ${index + 1}`}
                  onClick={() => setSelectedImage(image)}
                  onError={handleImgError}
                  style={{
                    width: '60px',
                    height: '60px',
                    objectFit: 'cover',
                    borderRadius: '4px',
                    border: image === selectedImage ? '2px solid #262626' : '1px solid #ddd',
                    cursor: 'pointer',
                    boxShadow: image === selectedImage ? '0px 4px 10px rgba(0, 0, 0, 0.2)' : 'none',
                  }}
                  className="thumbnail"
                />
              ))}
            </div>
          </div>

          <div className="col-md-8 product-info">
            <h5 className={`my-3 ${language === 'ar' ? 'text-end' : 'text-start'}`}>
              {language === 'ar' ? 'التفاصيل' : 'Details'}
            </h5>
            <p
              className={`text-secondary fw-bold  ${language === 'ar' ? 'text-end' : 'text-start'}`}
              style={{ fontSize: 'large' }}
            >
              {showFullDescription
                ? product?.long_description ||
                  (language === 'ar' ? 'لا يوجد وصف' : 'No description available')
                : getShortDescription(product?.long_description)}
              {product?.long_description && product.long_description.split(' ').length > 10 && (
                <button
                  className="btn btn-link p-0 ms-2"
                  style={{ fontSize: 'small' }}
                  onClick={() => setShowFullDescription((prev) => !prev)}
                >
                  {showFullDescription
                    ? language === 'ar'
                      ? 'عرض أقل'
                      : 'Read Less'
                    : language === 'ar'
                      ? 'اقرأ المزيد'
                      : 'Read More'}
                </button>
              )}
            </p>
            <div className="d-flex col-12 my-3 align-items-center">
              <span className="color-most-used fw-bold me-2 fs-large" style={{ fontSize: 'large' }}>
                {formatPrice(price)} {language === 'ar' ? 'ج.م' : 'EGP'}
              </span>
              <span
                className="text-muted text-decoration-line-through fs-large"
                style={{ fontSize: 'large' }}
              >
                {formatPrice(pricebefore)} {language === 'ar' ? 'ج.م' : 'EGP'}
              </span>
            </div>
            <div className="row">
              {product?.grade &&
                renderDetail('Grade', 'التصنيف', safeStr(product.grade), 'small', 'col-md-4 col-6')}
              {product?.sub_type &&
                renderDetail(
                  'Sub Type',
                  'النوع الفرعي',
                  safeStr(product.sub_type),
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.band_closure &&
                renderDetail(
                  'Band Closure',
                  'إغلاق السوار',
                  product.band_closure,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.dial_display_type &&
                renderDetail(
                  'Dial Display',
                  'نوع عرض وجة الساعة',
                  product.dial_display_type,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.case_shape &&
                renderDetail(
                  'Case Shape',
                  'شكل العلبة',
                  product.case_shape,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.band_material && isfashion
                ? renderDetail(
                    'Material',
                    'مادة الصنع',
                    product.band_material,
                    'small',
                    'col-md-4 col-6',
                  )
                : renderDetail(
                    'Band Material',
                    'مادة السوار',
                    product.band_material,
                    'small',
                    'col-md-4 col-6',
                  )}
              {product?.watch_movement &&
                renderDetail(
                  'Watch Movement',
                  'حركة الساعة',
                  product.watch_movement,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.water_resistance &&
                renderDetail(
                  'Water Resistance',
                  'مقاومة الماء',
                  `${product.water_resistance} ${product.water_resistance_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.case_thickness &&
                renderDetail(
                  'Case Size',
                  'حجم العلبة',
                  `${product.case_thickness} ${product.case_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.band_length &&
                renderDetail(
                  'Band Length',
                  'طول السوار',
                  `${product.band_length} ${product.band_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.band_width &&
                renderDetail(
                  'Band Width',
                  'عرض السوار',
                  `${product.band_width} ${product.band_width_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.case_thickness &&
                renderDetail(
                  'Case Thickness',
                  'سمك العلبة',
                  `${product.case_thickness} ${product.case_thickness_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.watch_height &&
                renderDetail(
                  'Watch Height',
                  'ارتفاع الساعة',
                  `${product.watch_height} ${product.watch_height_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.watch_width &&
                renderDetail(
                  'Watch Width',
                  'عرض الساعة',
                  `${product.watch_width} ${product.watch_width_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.watch_length &&
                renderDetail(
                  'Watch Length',
                  'طول الساعة',
                  `${product.watch_length} ${product.watch_length_size_type}`,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.dial_glass_material &&
                renderDetail(
                  'Dial Glass Material',
                  'مادة زجاج الوجة',
                  product.dial_glass_material,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.dial_case_material &&
                renderDetail(
                  'Dial Case Material',
                  'مادة اطار الوجة',
                  product.dial_case_material,
                  'small',
                  'col-md-4 col-6</div>',
                )}
              {product?.country &&
                renderDetail(
                  'Country of Origin',
                  'بلد الصنع',
                  product.country,
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.stone &&
                renderDetail('Stone', 'الحجر', product.stone, 'small', 'col-md-4 col-6')}
              {product?.features?.length > 0 &&
                renderDetail(
                  'Features',
                  'الميزات',
                  safeStr(product.features),
                  'small',
                  'col-md-4 col-6',
                )}
              {product?.gender?.length > 0 &&
                renderDetail(
                  'Gender',
                  'الجنس',
                  safeStr(product.gender),
                  'small',
                  'col-md-4 col-6',
                )}
              <div
                className={`fw-bold text-secondary mb-1 col-12 ${language === 'ar' ? 'text-end' : 'text-start'}`}
                style={{ fontSize: 'medium' }}
              >
                {language === 'ar' ? 'اختر اللون' : 'Chosse colors'}
              </div>
              {product?.dial_color?.length > 0 &&
                renderColorDetail(
                  'Dial Color',
                  'لون وجة الساعة',
                  product.dial_color,
                  'small',
                  'col-md-4 col-6',
                  setSelectedDialColor,
                )}
              {product?.band_color?.length > 0 && isfashion
                ? renderColorDetail(
                    'Color',
                    'الون',
                    product.band_color,
                    'small',
                    'col-md-4 col-6',
                    setSelectedBandColor,
                  )
                : renderColorDetail(
                    'Band Color',
                    'لون السوار',
                    product.band_color,
                    'small',
                    'col-md-4 col-6',
                    setSelectedBandColor,
                  )}
              <div
                className={`quantity-control col-6 d-flex align-items-center ${language === 'ar' ? 'justify-content-end' : ''}`}
              >
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
              <div
                className={`col-6 d-flex align-items-center ${language === 'ar' ? 'justify-content-end' : ''}`}
              >
                {stock && (
                  <span
                    className={`badge ${parseInt(product.stock) > 0 ? 'bg-black' : parseInt(product.market_stock) > 0 ? 'bg-success' : 'bg-danger'} col-md-8 col-12 p-2`}
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
                )}
              </div>
            </div>

            <div className="mt-3 col-12 d-flex justify-content-between action-buttons">
              <button
                className={`col-6 btn btn-dark`}
                onClick={handleAddToCart}
                disabled={stock <= 0}
              >
                {language === 'ar' ? 'أضف إلى السلة' : 'Add to Cart'}
              </button>

              <button
                className="btn btn-outline-danger col-5"
                onClick={() => handleAddTowishlist(product.id, 'p')}
              >
                {language === 'ar' ? 'أضف إلى قائمة الرغبات' : 'Add to Wish List'}
              </button>
            </div>
            <TrustSignals variant="pdp" />
          </div>
        </div>

        <div className="ratings-section row align-items-center rounded-5 border border-2 p-md-5 p-3 mx-md-0 mx-2 mt-4">
          <Typography variant="h5" className="col-md-10 col-6">
            {language === 'ar' ? 'التقييمات' : 'Ratings'}
          </Typography>
          <button
            onClick={handleRatingClick}
            className={`mt-3 col-md-2 col-6 btn ${ratingsOpen ? 'btn-danger' : 'btn-dark'} `}
          >
            {ratingsOpen
              ? language === 'ar'
                ? 'اخفاء التقييمات'
                : 'Close Ratings'
              : language === 'ar'
                ? 'عرض التقييمات'
                : 'View Ratings'}
          </button>
          <div className={`rating-list col-12 ${ratingsOpen ? '' : 'd-none'} row mt-3`}>
            {ratings.length > 0 ? (
              ratings.map((rating) => (
                <div key={rating.id} className="rating-item col-md-6 col-12 mb-3">
                  <Rating name="read-only" value={rating.rating} readOnly size="small" />
                  <p>{rating.comment}</p>
                  <small className="me-3">
                    by : {[].find((u) => u.id === rating.user_id)?.first_name}
                  </small>
                  <small>{new Date(rating.created_at).toLocaleDateString()}</small>
                </div>
              ))
            ) : (
              <p>{language === 'ar' ? 'لا توجد تقييمات بعد' : 'No ratings yet'}</p>
            )}
          </div>
          <div className="add-rating mt-4">
            <Typography variant="h6">
              {language === 'ar' ? 'إضافة تقييم' : 'Add a Rating'}
            </Typography>
            <div className="mt-2">
              <Rating
                name="new-rating"
                value={newRating.value}
                onChange={(e, value) => setNewRating((prev) => ({ ...prev, value }))}
              />
            </div>
            <TextField
              fullWidth
              multiline
              rows={3}
              placeholder={language === 'ar' ? 'أضف تعليقك' : 'Add your comment'}
              value={newRating.comment}
              onChange={(e) => setNewRating((prev) => ({ ...prev, comment: e.target.value }))}
              variant="outlined"
              className="mt-3"
            />
            <button
              className="mt-2 btn btn-dark"
              onClick={() => handleRatingSubmit(newRating.value, newRating.comment)}
            >
              {language === 'ar' ? 'إرسال التقييم' : 'Submit Rating'}
            </button>
          </div>
        </div>
        <div className="row justify-content-center">
          <div className="related-products col-md-11 lato-regular mt-4">
            {related && (
              <ProductSlider
                text={{
                  title: { en: 'Related Product', ar: 'المنتجات ذات الصلة' },
                  description: {
                    en: 'Products similar to the product you chose',
                    ar: 'منتجات مشابهة للمنتج الذي اخترته',
                  },
                }}
                gradeproducts={related}
              />
            )}
            {/* {console.log(realetedProducts)} */}
          </div>
        </div>
      </div>
    )
  }
}

ProductDisplay.propTypes = {
  products: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.number.isRequired,
      name: PropTypes.string.isRequired,
      product_title: PropTypes.string.isRequired,
      model_name: PropTypes.string,
      long_description: PropTypes.string.isRequired,
      short_description: PropTypes.string.isRequired,
      selling_price: PropTypes.string.isRequired,
      sale_price_after_discount: PropTypes.string.isRequired,
      percentage_discount: PropTypes.string.isRequired,
      stock: PropTypes.number.isRequired,
      category_type_name: PropTypes.string.isRequired,
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
      water_resistance: PropTypes.string,
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
  ),
}

export default ProductDisplay
