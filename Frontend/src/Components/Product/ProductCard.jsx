import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl } from '../../utils/productUrl'
import useCart from '../../Hooks/useCart'
import './ProductCard.css'

const ProductCard = ({ product, showBrand = true, showRating = true }) => {
  const navigate = useNavigate()
  const { language } = useUIStore()
  const { addItem } = useCart()
  const [adding, setAdding] = useState(false)
  const isRTL = language === 'ar'

  if (!product) return null

  /* ── Name ── */
  const name = (() => {
    if (product.translations?.length) {
      const t =
        product.translations.find((x) => x.locale === language) ||
        product.translations.find((x) => x.locale === 'en')
      if (t?.product_title) return t.product_title
    }
    return isRTL
      ? product.product_title_ar || product.name_ar || product.product_title || product.name_en || ''
      : product.product_title || product.name_en || product.product_title_ar || product.name_ar || ''
  })()

  /* ── Brand ── */
  const brand = (() => {
    if (product.brand?.translations?.length) {
      const t =
        product.brand.translations.find((x) => x.locale === language) ||
        product.brand.translations.find((x) => x.locale === 'en')
      if (t?.brand_name) return t.brand_name
    }
    return typeof product.brand === 'string'
      ? product.brand
      : product.brand?.brand_name || product.brand?.name_en || product.brand_name || ''
  })()

  /* ── Prices ── */
  const price = Number(product.selling_price || 0)
  const salePrice = Number(product.sale_price_after_discount || 0)
  const hasSale = salePrice > 0 && salePrice < price
  const discount = hasSale ? Math.round((1 - salePrice / price) * 100) : 0

  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')
  const currency = isRTL ? 'ج.م' : 'EGP'

  /* ── Stock ── */
  const inStock = Number(product.stock || 0) > 0 || Number(product.market_stock || 0) > 0

  /* ── New badge (last 30 days) ── */
  const isNew = product.created_at
    ? Date.now() - new Date(product.created_at).getTime() < 30 * 24 * 60 * 60 * 1000
    : false

  /* ── Rating ── */
  const rating = Number(product.average_rating || product.ratings_avg || product.rating || 0)
  const reviews = Number(product.ratings_count || product.reviews_count || 0)

  /* ── Image ── */
  const imgSrc = getImageUrl(product.image) || PLACEHOLDER_IMG

  /* ── Handlers ── */
  const handleClick = () => navigate(productUrl(product))

  const handleAdd = (e) => {
    e.stopPropagation()
    if (!inStock || adding) return
    setAdding(true)
    addItem({
      product_id: product.id,
      quantity: 1,
      piece_price: hasSale ? salePrice : price,
      type_stock: Number(product.stock || 0) > 0 ? 'Express' : 'Market',
    })
    setTimeout(() => setAdding(false), 800)
  }

  return (
    <article
      className={`wz-card ${!inStock ? 'wz-card-soldout' : ''}`}
      onClick={handleClick}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => e.key === 'Enter' && handleClick()}
      dir={isRTL ? 'rtl' : 'ltr'}
    >
      {/* ── Image section ── */}
      <div className="wz-card-img-wrap">
        <img
          src={imgSrc}
          alt={name}
          className="wz-card-img"
          loading="lazy"
          onError={handleImgError}
        />

        {/* Badges */}
        <div className="wz-card-badges">
          {hasSale && <span className="wz-badge wz-badge-sale">-{discount}%</span>}
          {isNew && !hasSale && (
            <span className="wz-badge wz-badge-new">{isRTL ? 'جديد' : 'NEW'}</span>
          )}
          {!inStock && (
            <span className="wz-badge wz-badge-sold">{isRTL ? 'نفذ' : 'SOLD OUT'}</span>
          )}
        </div>

        {/* Quick add — appears on hover */}
        {inStock && (
          <div className="wz-card-quick">
            <button
              className={`wz-card-quick-btn ${adding ? 'wz-card-quick-adding' : ''}`}
              onClick={handleAdd}
              aria-label={isRTL ? 'أضف للسلة' : 'Add to cart'}
            >
              {adding
                ? isRTL
                  ? '✓ تمت الإضافة'
                  : '✓ Added'
                : isRTL
                  ? 'أضف للسلة'
                  : 'Add to Cart'}
            </button>
          </div>
        )}
      </div>

      {/* ── Info section ── */}
      <div className="wz-card-info">
        {/* Brand */}
        {showBrand && brand && <p className="wz-card-brand">{brand}</p>}

        {/* Name */}
        <h3 className="wz-card-name" title={name}>
          {name}
        </h3>

        {/* Rating */}
        {showRating && rating > 0 && (
          <div className="wz-card-rating">
            <div className="wz-card-stars">
              {[1, 2, 3, 4, 5].map((star) => (
                <span
                  key={star}
                  className={`wz-star ${star <= Math.round(rating) ? 'wz-star-on' : ''}`}
                >
                  ★
                </span>
              ))}
            </div>
            {reviews > 0 && <span className="wz-card-reviews">({reviews})</span>}
          </div>
        )}

        {/* Price */}
        <div className="wz-card-price-row">
          {hasSale ? (
            <>
              <span className="wz-card-price-sale">
                {fmt(salePrice)} {currency}
              </span>
              <span className="wz-card-price-orig">
                {fmt(price)} {currency}
              </span>
            </>
          ) : (
            <span className="wz-card-price">
              {fmt(price)} {currency}
            </span>
          )}
        </div>

        {/* Stock warning */}
        {inStock && Number(product.stock || 0) > 0 && Number(product.stock) <= 5 && (
          <p className="wz-card-low-stock">
            {isRTL ? `باقي ${product.stock} فقط` : `Only ${product.stock} left`}
          </p>
        )}
      </div>
    </article>
  )
}

export default ProductCard
