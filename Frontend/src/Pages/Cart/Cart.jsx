import { memo, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import {
  FiX,
  FiMinus,
  FiPlus,
  FiShoppingBag,
  FiLock,
  FiRefreshCw,
  FiCheck,
  FiChevronDown,
} from 'react-icons/fi'
import { MyContext } from '../../Context/Context'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useOffers } from '../../Hooks/queries/useOffers'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import useCart, { getItemKey } from '../../Hooks/useCart'
import ProductSlider from '../../Components/Product/ProductSlider'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl, offerUrl } from '../../utils/productUrl'
import './Cart.css'

// ── Cart item ──────────────────────────────────────────────────────────────
const CartItem = memo(function CartItem({
  item,
  d,
  isRTL,
  t,
  fmt,
  currency,
  onQty,
  onRemove,
  onWishlist,
  canWishlist,
  navigate,
}) {
  const key = getItemKey(item)
  const [qty, setQty] = useState(item.quantity)
  useEffect(() => setQty(item.quantity), [item.quantity])

  // Debounce the store/network sync; the local number updates instantly.
  useEffect(() => {
    if (qty === item.quantity) return
    const id = setTimeout(() => onQty(key, qty), 300)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qty])

  const unit = Number(item.piece_price)
  const orig = d.selling
  const hasSale = orig > unit + 0.01
  const max = d.stock > 0 ? d.stock : d.entity ? 0 : 99
  const unavailable = !!d.entity && d.stock <= 0
  const lowStock = !!d.entity && d.stock > 0 && d.stock < qty
  const currentPrice = d.sale > 0 && d.sale < d.selling ? d.sale : d.selling
  const priceChanged = !!d.entity && currentPrice > 0 && Math.abs(currentPrice - unit) > 0.01
  const lineTotal = unit * qty

  return (
    <div className={`wz-cart-item${unavailable ? ' is-unavailable' : ''}`}>
      <button
        className="wz-cart-item-remove"
        onClick={() => onRemove(item)}
        aria-label={t('Remove', 'حذف')}
        title={t('Remove', 'حذف')}
      >
        <FiX />
      </button>

      <button className="wz-cart-item-img" onClick={() => navigate(d.url)} aria-label={d.name}>
        <img src={d.image} alt={d.name} loading="lazy" onError={handleImgError} />
      </button>

      <div className="wz-cart-item-info">
        {d.brand && <p className="wz-cart-item-brand">{d.brand}</p>}
        <button className="wz-cart-item-name" onClick={() => navigate(d.url)}>
          {d.name}
        </button>

        <div className="wz-cart-item-variant">
          {item.type_stock && (
            <span className={`wz-cart-stock wz-cart-stock--${unavailable ? 'out' : item.type_stock === 'Express' ? 'express' : 'market'}`}>
              <span className="wz-cart-stock-dot" />
              {unavailable
                ? t('Unavailable', 'غير متوفر')
                : item.type_stock === 'Express'
                  ? t('Express', 'إكسبريس')
                  : t('Market', 'ماركت')}
            </span>
          )}
          {item.color_band && (
            <span className="wz-cart-swatch" style={{ background: item.color_band }} title={t('Band', 'السوار')} />
          )}
          {item.color_dial && (
            <span className="wz-cart-swatch" style={{ background: item.color_dial }} title={t('Dial', 'الميناء')} />
          )}
        </div>

        <div className="wz-cart-item-price">
          <span className="wz-cart-unit">
            {fmt(unit)} {currency}
          </span>
          {hasSale && (
            <span className="wz-cart-unit-orig">
              {fmt(orig)} {currency}
            </span>
          )}
        </div>

        {lowStock && <p className="wz-cart-warn-text">{isRTL ? `باقي ${d.stock} فقط` : `Only ${d.stock} left`}</p>}
        {priceChanged && !unavailable && (
          <p className="wz-cart-warn-text">{t('Price updated', 'تم تحديث السعر')}</p>
        )}
        {unavailable && <p className="wz-cart-warn-text">{t('This item is unavailable', 'هذا المنتج غير متوفر')}</p>}

        {canWishlist && (
          <button className="wz-cart-item-wish" onClick={() => onWishlist(item)}>
            {t('Move to wishlist', 'نقل للمفضلة')}
          </button>
        )}
      </div>

      <div className="wz-cart-item-side">
        <div className="wz-cart-qty">
          <button onClick={() => setQty((q) => Math.max(1, q - 1))} disabled={qty <= 1 || unavailable} aria-label={t('Decrease', 'تقليل')}>
            <FiMinus />
          </button>
          <span>{qty}</span>
          <button
            onClick={() => setQty((q) => Math.min(max, q + 1))}
            disabled={unavailable || qty >= max}
            aria-label={t('Increase', 'زيادة')}
          >
            <FiPlus />
          </button>
        </div>
        <div className="wz-cart-line-total">
          {fmt(lineTotal)} {currency}
        </div>
      </div>
    </div>
  )
})

// ── Page ───────────────────────────────────────────────────────────────────
function Cart() {
  // Server data from TanStack Query; wishlist toggle + shipping state stay in context.
  const {
    handleAddTowishlist,
    shippingPrices,
    shippingid,
    setShippingid,
    setShipping,
    setShippingName,
  } = useContext(MyContext)
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const navigate = useNavigate()
  const { userId } = useAuthStore()
  const { cart, updateQuantity, removeItem, undoRemove, validateCart, lastRemoved } = useCart()

  const [processing, setProcessing] = useState(false)
  const [warnDismissed, setWarnDismissed] = useState(false)

  const items = cart?.cart_item || []
  const list = products || []
  const offerList = offers || []
  const loading = items.length > 0 && list.length === 0

  // Validate against live catalog when the cart opens (authoritative gate).
  useEffect(() => {
    if (items.length) validateCart()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const resolve = useCallback(
    (item) => {
      if (item.product_id) {
        const p = list.find((x) => x.id === item.product_id)
        return {
          entity: p,
          name: p?.name || p?.product_title || '',
          brand: typeof p?.brand === 'string' ? p.brand : p?.brand_name || '',
          image: getImageUrl(p?.image) || PLACEHOLDER_IMG,
          url: p ? productUrl(p) : '/listing',
          stock: item.type_stock === 'Express' ? Number(p?.stock || 0) : Number(p?.market_stock || 0),
          selling: Number(p?.selling_price || 0),
          sale: Number(p?.sale_price_after_discount || 0),
        }
      }
      const o = offerList.find((x) => String(x.id) === String(item.offer_id))
      return {
        entity: o,
        name: o ? (isRTL ? o.offer_name_ar : o.offer_name_en) : '',
        brand: '',
        image: getImageUrl(o?.image) || PLACEHOLDER_IMG,
        url: o ? offerUrl(o) : '/offers',
        stock: Number(o?.stock || 0),
        selling: Number(o?.selling_price || 0),
        sale: Number(o?.sale_price_after_discount || 0),
      }
    },
    [list, offerList, isRTL],
  )

  const { subtotal, savings, anyUnavailable, itemCount } = useMemo(() => {
    let sub = 0
    let sav = 0
    let bad = false
    let count = 0
    for (const it of items) {
      const unit = Number(it.piece_price)
      sub += unit * it.quantity
      count += it.quantity
      const d = resolve(it)
      if (d.selling > unit) sav += (d.selling - unit) * it.quantity
      if (d.entity && d.stock <= 0) bad = true
    }
    return { subtotal: sub, savings: sav, anyUnavailable: bad, itemCount: count }
  }, [items, resolve])

  // ── Shipping (always the selected governorate cost — no free shipping) ──
  const cities = shippingPrices || []
  const selectedCity = cities.find((c) => String(c.id) === String(shippingid)) || null
  const shippingCost = selectedCity ? Number(selectedCity.Price) : 0
  const citySelected = !!selectedCity
  const total = subtotal + shippingCost
  const fmt = useCallback((v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US'), [isRTL])
  const currency = isRTL ? 'ج.م' : 'EGP'

  const onCityChange = useCallback(
    (e) => {
      const id = e.target.value
      setShippingid(id)
      const c = cities.find((x) => String(x.id) === String(id))
      if (c) {
        setShipping(String(c.Price))
        setShippingName(isRTL ? c.GovernorateAr : c.GovernorateEn)
      }
    },
    [cities, setShippingid, setShipping, setShippingName, isRTL],
  )

  // ── Smart suggestions from the whole cart (lazy-rendered) ──
  const cartSuggestions = useMemo(() => {
    if (!items.length || !list.length) return []
    const cartIds = new Set(items.map((i) => i.product_id).filter(Boolean))
    return list
      .filter((p) => !cartIds.has(p.id))
      .map((p) => {
        let score = 0
        items.forEach((item) => {
          const cp = list.find((x) => x.id === item.product_id)
          if (!cp) return
          if (p.brand_id === cp.brand_id) score += 2
          if (p.sub_type_id === cp.sub_type_id) score += 2
          if (p.category_type_id === cp.category_type_id) score += 1
          const pg = Array.isArray(p.genders_en) ? p.genders_en : []
          const cg = Array.isArray(cp.genders_en) ? cp.genders_en : []
          if (pg.some((g) => cg.includes(g))) score += 1
          if (p.sub_type_id !== cp.sub_type_id && p.category_type_id === cp.category_type_id) score += 1
          const pp = Number(p.selling_price || 0)
          const cpp = Number(cp.selling_price || 0)
          if (pp > 0 && cpp > 0) {
            const ratio = pp / cpp
            if (ratio >= 0.5 && ratio <= 1.5) score += 1
          }
        })
        return { product: p, score }
      })
      .filter((x) => x.score >= 2)
      .sort((a, b) => b.score - a.score)
      .slice(0, 12)
      .map((x) => x.product)
  }, [items, list])

  const [showSuggest, setShowSuggest] = useState(false)
  const suggestRef = useRef(null)
  useEffect(() => {
    const el = suggestRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setShowSuggest(true)
          obs.disconnect()
        }
      },
      { rootMargin: '200px' },
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [items.length])

  const hasWarnings = useMemo(
    () =>
      items.some((it) => {
        const d = resolve(it)
        if (!d.entity) return false
        if (d.stock <= 0 || d.stock < it.quantity) return true
        const cur = d.sale > 0 && d.sale < d.selling ? d.sale : d.selling
        return cur > 0 && Math.abs(cur - Number(it.piece_price)) > 0.01
      }),
    [items, resolve],
  )

  const onQty = useCallback((key, q) => updateQuantity(key, q), [updateQuantity])
  const onRemove = useCallback((item) => removeItem(getItemKey(item)), [removeItem])
  const onWishlist = useCallback(
    (item) => {
      handleAddTowishlist(item.product_id || item.offer_id, item.product_id ? 'p' : 'o')
      removeItem(getItemKey(item))
    },
    [handleAddTowishlist, removeItem],
  )

  const goToCheckout = useCallback(async () => {
    if (processing) return
    setProcessing(true)
    const res = await validateCart()
    setProcessing(false)
    if (res && res.valid === false) {
      setWarnDismissed(false)
      window.scrollTo({ top: 0, behavior: 'smooth' })
      return
    }
    navigate('/checkout')
  }, [processing, validateCart, navigate])

  // ── Empty ──
  if (!loading && items.length === 0) {
    return (
      <div className="wz-cartpage" dir={isRTL ? 'rtl' : 'ltr'}>
        <div className="wz-cart-empty">
          <FiShoppingBag className="wz-cart-empty-icon" />
          <h2>{t('Your cart is empty', 'سلة التسوق فارغة')}</h2>
          <p>{t('Discover luxury timepieces', 'اكتشف الساعات الفاخرة')}</p>
          <button className="wz-cart-checkout" onClick={() => navigate('/listing')}>
            {t('EXPLORE COLLECTION', 'تصفّح المجموعة')}
          </button>
          <Link to="/" className="wz-cart-continue">
            {t('Continue shopping', 'متابعة التسوق')}
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="wz-cartpage" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-cart-inner">
        {/* Warnings banner */}
        {hasWarnings && !warnDismissed && (
          <div className="wz-cart-banner">
            <span>⚠ {t('Some items in your cart need attention', 'بعض المنتجات في سلتك تحتاج انتباهك')}</span>
            <button onClick={() => setWarnDismissed(true)} aria-label={t('Dismiss', 'إغلاق')}>
              <FiX />
            </button>
          </div>
        )}

        <div className="wz-cart-grid">
          {/* Items */}
          <div className="wz-cart-main">
            <div className="wz-cart-head">
              <h1 className="wz-cart-title">{t('Your Cart', 'سلة التسوق')}</h1>
              <span className="wz-cart-count">
                ({itemCount} {itemCount === 1 ? t('item', 'منتج') : t('items', 'منتجات')})
              </span>
            </div>

            {loading ? (
              <div className="wz-cart-skel-list">
                {Array.from({ length: 2 }).map((_, i) => (
                  <div className="wz-cart-skel-item" key={i}>
                    <div className="wz-cart-skel-img" />
                    <div className="wz-cart-skel-lines">
                      <div className="wz-cart-skel-line w40" />
                      <div className="wz-cart-skel-line w70" />
                      <div className="wz-cart-skel-line w30" />
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              items.map((item) => (
                <CartItem
                  key={getItemKey(item)}
                  item={item}
                  d={resolve(item)}
                  isRTL={isRTL}
                  t={t}
                  fmt={fmt}
                  currency={currency}
                  onQty={onQty}
                  onRemove={onRemove}
                  onWishlist={onWishlist}
                  canWishlist={!!userId}
                  navigate={navigate}
                />
              ))
            )}

            <Link to="/listing" className="wz-cart-continue wz-cart-continue--inline">
              ← {t('Continue Shopping', 'متابعة التسوق')}
            </Link>
          </div>

          {/* Summary */}
          <aside className="wz-cart-summary-wrap">
            <div className="wz-cart-summary">
              <h2 className="wz-cart-summary-title">{t('Order Summary', 'ملخص الطلب')}</h2>

              {/* Ship-to governorate */}
              <div className="wz-cart-shipto">
                <label className="wz-cart-shipto-label" htmlFor="wz-ship-city">
                  {t('Ship to:', 'الشحن إلى:')}
                </label>
                <div className="wz-cart-select">
                  <select id="wz-ship-city" value={shippingid || ''} onChange={onCityChange}>
                    <option value="" disabled>
                      {t('Select governorate', 'اختر المحافظة')}
                    </option>
                    {cities.map((c) => (
                      <option key={c.id} value={c.id}>
                        {isRTL
                          ? `${c.GovernorateAr} — ${fmt(c.Price)} ج.م`
                          : `${c.GovernorateEn} — ${fmt(c.Price)} EGP`}
                      </option>
                    ))}
                  </select>
                  <FiChevronDown className="wz-cart-select-icon" />
                </div>
              </div>

              <div className="wz-cart-row">
                <span>{t('Subtotal', 'المجموع الفرعي')}</span>
                <span>{fmt(subtotal)} {currency}</span>
              </div>
              <div className="wz-cart-row">
                <span>{t('Shipping', 'الشحن')}</span>
                {citySelected ? (
                  <span>{fmt(shippingCost)} {currency}</span>
                ) : (
                  <span>—</span>
                )}
              </div>

              {savings > 0 && (
                <div className="wz-cart-row wz-cart-savings">
                  <span>{t('You save', 'وفرت')}</span>
                  <span>
                    {fmt(savings)} {currency}
                  </span>
                </div>
              )}

              <div className="wz-cart-divider" />

              <div className="wz-cart-row wz-cart-total">
                <span>{t('Total', 'الإجمالي')}</span>
                <span>{fmt(total)} {currency}</span>
              </div>

              <button
                className="wz-cart-checkout"
                onClick={goToCheckout}
                disabled={processing || anyUnavailable || !citySelected}
              >
                {processing ? (
                  <>
                    <span className="wz-cart-spinner" />
                    {t('Processing...', 'جارٍ المعالجة...')}
                  </>
                ) : (
                  t('CHECKOUT', 'إتمام الشراء')
                )}
              </button>
              {!citySelected && (
                <p className="wz-cart-select-hint">{t('Please select your city', 'يرجى اختيار مدينتك')}</p>
              )}

              <ul className="wz-cart-trust">
                <li><FiLock /> {t('Secure checkout', 'دفع آمن')}</li>
                <li><FiRefreshCw /> {t('Free returns within 30 days', 'إرجاع مجاني خلال 30 يوماً')}</li>
                <li><FiCheck /> {t('100% authentic guarantee', 'أصالة مضمونة 100%')}</li>
              </ul>
            </div>
          </aside>
        </div>
      </div>

      {/* Smart suggestions (lazy, full-width band) */}
      <div ref={suggestRef} className="wz-cart-suggest-anchor">
        {showSuggest && cartSuggestions.length > 0 && (
          <section className="wz-cart-suggest">
            <div className="wz-cart-suggest-inner">
              <ProductSlider
                gradeproducts={cartSuggestions}
                text={{
                  title: { en: 'Complete Your Collection', ar: 'أكمل مجموعتك' },
                  description: { en: 'Based on your cart', ar: 'بناءً على سلتك' },
                }}
              />
            </div>
          </section>
        )}
      </div>

      {/* Undo toast */}
      {lastRemoved && (
        <div className="wz-cart-toast">
          <span>{t('Item removed.', 'تم حذف المنتج.')}</span>
          <button onClick={undoRemove}>{t('Undo', 'تراجع')}</button>
        </div>
      )}
    </div>
  )
}

export default memo(Cart)
