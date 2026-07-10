import { memo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import { getResponsiveImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl } from '../../utils/productUrl'
import useCart from '../../Hooks/useCart'
import './ProductCard.css'

const NEW_WINDOW_MS = 30 * 24 * 60 * 60 * 1000

const ProductCard = ({ product, showBrand = true, showRating = true }) => {
  const navigate = useNavigate()
  const { language } = useUIStore()
  const { addItem } = useCart()
  const [added, setAdded] = useState(false)
  const [pending, setPending] = useState(false)
  const isRTL = language === 'ar'

  if (!product) return null

  /* ── Localized name ── */
  const name = (() => {
    const t =
      product.translations?.find((x) => x.locale === language) ||
      product.translations?.find((x) => x.locale === 'en')
    if (t?.product_title) return t.product_title
    return isRTL
      ? product.product_title_ar || product.name_ar || product.product_title || product.name_en || ''
      : product.product_title || product.name_en || product.product_title_ar || product.name_ar || ''
  })()

  /* ── Brand ── */
  const brand = (() => {
    const t =
      product.brand?.translations?.find((x) => x.locale === language) ||
      product.brand?.translations?.find((x) => x.locale === 'en')
    if (t?.brand_name) return t.brand_name
    return typeof product.brand === 'string'
      ? product.brand
      : product.brand?.brand_name || product.brand?.name_en || product.brand_name || ''
  })()

  /* ── Prices ── */
  const price = Number(product.selling_price || 0)
  const salePrice = Number(product.sale_price_after_discount || 0)
  const hasSale = salePrice > 0 && salePrice < price
  const discount = hasSale ? Math.round((1 - salePrice / price) * 100) : 0
  const currency = isRTL ? 'ج.م' : 'EGP'
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')

  /* ── Stock ── */
  const expressStock = Number(product.stock || 0)
  const marketStock = Number(product.market_stock || 0)
  const inStock = expressStock > 0 || marketStock > 0
  const isExpress = expressStock > 0

  /* ── Flags ── */
  const isNew = product.created_at
    ? Date.now() - new Date(product.created_at).getTime() < NEW_WINDOW_MS
    : false

  /* ── Rating ── */
  const rating = Number(product.average_rating || product.ratings_avg || product.rating || 0)
  const reviews = Number(product.ratings_count || product.reviews_count || 0)

  /* ── Image (srcset-ready via the CDN builder; passthrough until a CDN is set) ── */
  const imgData = getResponsiveImageUrl(product.image, 'Product')
  const imgSrc = imgData.src || PLACEHOLDER_IMG

  /* ── Stock status descriptor ── */
  const stock = isExpress
    ? {
        tone: 'express',
        label: isRTL ? `إكسبريس · ${expressStock} متاح` : `Express · ${expressStock} in stock`,
      }
    : marketStock > 0
      ? {
          tone: 'market',
          label: isRTL ? `ماركت · ${marketStock} متاح` : `Market · ${marketStock} available`,
        }
      : { tone: 'out', label: isRTL ? 'غير متوفر' : 'Out of stock' }

  /* ── Handlers ── */
  const goToProduct = () => navigate(productUrl(product))

  const handleAdd = async (e) => {
    e.stopPropagation()
    if (!inStock || added || pending) return
    setPending(true)
    try {
      await addItem({
        product_id: product.id,
        quantity: 1,
        piece_price: hasSale ? salePrice : price,
        type_stock: isExpress ? 'Express' : 'Market',
      })
    } finally {
      setPending(false)
    }
    setAdded(true)
    setTimeout(() => setAdded(false), 1400)
  }

  return (
    <article
      className={`wz-pc ${!inStock ? 'wz-pc--out' : ''}`}
      onClick={goToProduct}
      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && goToProduct()}
      role="button"
      tabIndex={0}
      dir={isRTL ? 'rtl' : 'ltr'}
      aria-label={name}
    >
      {/* ── Media ── */}
      <div className="wz-pc__media">
        <img
          src={imgSrc}
          srcSet={imgData.srcSet || undefined}
          sizes={imgData.sizes || undefined}
          alt={name}
          className="wz-pc__img"
          width="300"
          height="300"
          loading="lazy"
          onError={handleImgError}
        />

        {/* Badges — independent small pills, can coexist */}
        {isNew && <span className="wz-pc__badge wz-pc__badge--new">{isRTL ? 'جديد' : 'New'}</span>}
        {hasSale && (
          <span className="wz-pc__badge wz-pc__badge--sale">−{discount}%</span>
        )}

        {/* Sold out — frosted center overlay */}
        {!inStock && (
          <div className="wz-pc__soldout">
            <span>{isRTL ? 'نفذ المخزون' : 'Sold Out'}</span>
          </div>
        )}
      </div>

      {/* ── Body ── */}
      <div className="wz-pc__body">
        {showBrand && brand && <p className="wz-pc__brand">{brand}</p>}

        <h3 className="wz-pc__title" title={name}>
          {name}
        </h3>

        {showRating && rating > 0 && (
          <div className="wz-pc__rating" aria-label={`${rating.toFixed(1)} / 5`}>
            <span className="wz-pc__stars" style={{ '--rating': Math.max(0, Math.min(5, rating)) }}>
              ★★★★★
            </span>
            {reviews > 0 && <span className="wz-pc__reviews">({reviews})</span>}
          </div>
        )}

        {/* Price block — always two rows tall (placeholder when no sale) so the
            block below stays aligned across cards */}
        <div className="wz-pc__prices">
          <div className="wz-pc__price-main">
            {hasSale ? (
              <span className="wz-pc__price wz-pc__price--sale">
                {fmt(salePrice)} {currency}
              </span>
            ) : (
              <span className="wz-pc__price">
                {fmt(price)} {currency}
              </span>
            )}
          </div>
          <div className="wz-pc__price-orig-row">
            {hasSale ? (
              <span className="wz-pc__price--orig">
                {fmt(price)} {currency}
              </span>
            ) : (
              <span className="wz-pc__price-ph">&nbsp;</span>
            )}
          </div>
        </div>

        {/* Bottom block — stock + button, always pinned to the card bottom */}
        <div className="wz-pc__bottom">
          <div className={`wz-pc__stock wz-pc__stock--${stock.tone}`}>
            <span className="wz-pc__dot" />
            <span className="wz-pc__stock-text">{stock.label}</span>
          </div>

          <button
            type="button"
            className={`wz-pc__add ${added ? 'is-added' : ''}`}
            onClick={handleAdd}
            disabled={!inStock || pending}
            aria-busy={pending}
            aria-label={isRTL ? 'أضف إلى السلة' : 'Add to cart'}
          >
            {!inStock
              ? isRTL
                ? 'غير متوفر'
                : 'Unavailable'
              : pending
                ? isRTL
                  ? 'جارٍ الإضافة…'
                  : 'Adding…'
                : added
                  ? isRTL
                    ? '✓ تمت الإضافة'
                    : '✓ Added'
                  : isRTL
                    ? 'أضف إلى السلة'
                    : 'Add to Cart'}
          </button>
        </div>
      </div>
    </article>
  )
}

// Custom comparison: skip re-render when the parent re-renders but this card's
// meaningful data is unchanged. product_title/brand are included so a language
// switch (which swaps the localized product object) still re-renders correctly.
const areEqual = (prev, next) => {
  const a = prev.product
  const b = next.product
  return (
    a?.id === b?.id &&
    a?.product_title === b?.product_title &&
    a?.brand === b?.brand &&
    a?.selling_price === b?.selling_price &&
    a?.sale_price_after_discount === b?.sale_price_after_discount &&
    a?.stock === b?.stock &&
    a?.market_stock === b?.market_stock &&
    prev.showBrand === next.showBrand &&
    prev.showRating === next.showRating
  )
}

export default memo(ProductCard, areEqual)
