import { memo, useState, useEffect, useMemo, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useUIStore } from '../../Store/uiStore'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl } from '../../utils/productUrl'

// Title can live in a translations[] array or be pre-localized on the catalog
// object (product_title / name) — cover both.
const getName = (p, lang) => {
  if (p.translations?.length) {
    const t =
      p.translations.find((x) => x.locale === lang) || p.translations.find((x) => x.locale === 'en')
    if (t?.product_title) return t.product_title
  }
  return lang === 'ar'
    ? p.product_title_ar || p.name_ar || p.product_title || p.name || p.name_en || ''
    : p.product_title || p.name || p.name_en || p.product_title_ar || p.name_ar || ''
}

// Rotating hero strip below WatchHero — 5 random (preferably on-sale) products.
// Extracted to its own module so it isn't redefined on every Home render, and
// memoized so it only re-renders when the products reference actually changes.
function FeaturedBanner({ products, loading = false }) {
  const [current, setCurrent] = useState(0)
  const [visible, setVisible] = useState(true)
  const { language } = useUIStore()
  const navigate = useNavigate()
  const isRTL = language === 'ar'

  const featured = useMemo(() => {
    const list = Array.isArray(products) ? products : []
    const withSale = list.filter(
      (p) =>
        Number(p.sale_price_after_discount) > 0 &&
        Number(p.sale_price_after_discount) < Number(p.selling_price) &&
        p.image,
    )
    const pool = withSale.length >= 3 ? withSale : list.filter((p) => p.image)
    return [...pool].sort(() => Math.random() - 0.5).slice(0, 5)
  }, [products])

  useEffect(() => {
    if (featured.length <= 1) return
    const id = setInterval(() => {
      setVisible(false)
      setTimeout(() => {
        setCurrent((c) => (c + 1) % featured.length)
        setVisible(true)
      }, 350)
    }, 5000)
    return () => clearInterval(id)
  }, [featured.length])

  const jumpTo = useCallback((i) => {
    setVisible(false)
    setTimeout(() => {
      setCurrent(i)
      setVisible(true)
    }, 350)
  }, [])

  if (!featured.length) {
    // Show a placeholder banner while the catalog is still loading; once loaded
    // with no eligible product, render nothing.
    if (!loading) return null
    return (
      <div className="wz-featured wz-featured-skeleton" dir={isRTL ? 'rtl' : 'ltr'}>
        <div className="wz-skel-img" />
      </div>
    )
  }

  const p = featured[current]
  const name = getName(p, language)
  const brand = typeof p.brand === 'string' ? p.brand : p.brand?.name_en || p.brand_name || ''
  const price = Number(p.selling_price || 0)
  const sale = Number(p.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < price
  const pct = hasSale ? Math.round((1 - sale / price) * 100) : 0
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')
  const currency = isRTL ? 'ج.م' : 'EGP'

  return (
    <div className="wz-featured" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-featured-bg-grid" />
      <div className={`wz-featured-inner ${visible ? 'wz-featured-show' : ''}`}>
        <div className="wz-featured-text">
          <span className="wz-featured-eyebrow">
            {hasSale
              ? isRTL
                ? `لفترة محدودة — خصم ${pct}%`
                : `Limited Time — ${pct}% OFF`
              : isRTL
                ? 'تشكيلة مميزة'
                : 'Featured Collection'}
          </span>
          {brand && <p className="wz-featured-brand">{brand}</p>}
          <h2 className="wz-featured-name">{name}</h2>
          <div className="wz-featured-prices">
            {hasSale ? (
              <>
                <span className="wz-fp-sale">
                  {fmt(sale)} {currency}
                </span>
                <span className="wz-fp-orig">
                  {fmt(price)} {currency}
                </span>
              </>
            ) : (
              <span className="wz-fp-price">
                {fmt(price)} {currency}
              </span>
            )}
          </div>
          <button className="wz-featured-btn" onClick={() => navigate(productUrl(p))}>
            {isRTL ? 'تسوق الآن' : 'Shop Now'}
            <span>{isRTL ? '←' : '→'}</span>
          </button>
        </div>

        <div className="wz-featured-img-box">
          <img
            src={getImageUrl(p.image) || PLACEHOLDER_IMG}
            alt={name}
            className="wz-featured-img"
            width="320"
            height="320"
            loading="lazy"
            onError={handleImgError}
          />
        </div>
      </div>

      <div className="wz-featured-dots">
        {featured.map((_, i) => (
          <button
            key={i}
            className={`wz-fdot ${i === current ? 'wz-fdot-on' : ''}`}
            onClick={() => jumpTo(i)}
            aria-label={`slide ${i + 1}`}
          />
        ))}
      </div>
    </div>
  )
}

export default memo(FeaturedBanner)
