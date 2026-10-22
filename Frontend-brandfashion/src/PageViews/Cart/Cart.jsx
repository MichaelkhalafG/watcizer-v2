'use client'
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Link from '@/src/Components/LocaleLink'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { useQuery } from '@tanstack/react-query'
import { FiShoppingBag, FiLock, FiRefreshCw, FiCheck, FiChevronDown } from 'react-icons/fi'
import publicHttp from '../../Context/publicApi'
import { useUIStore } from '../../Store/uiStore'
import { useShippingStore } from '../../Store/shippingStore'
import { useShippingPrices } from '../../Hooks/useShippingPrices'
import useCart, { getItemKey } from '../../Hooks/useCart'
import { cartStore } from '../../Store/cartStore'
import BfRail from '../../Components/Listing/BfRail'
import { txt, money } from '../../lib/cardData'
import { trackViewCart } from '../../scripts/pixels'
import { localizeHref } from '../../utils/localePath'
import { STOREFRONT_CODE } from '../../lib/env'
import './Cart.css'

/*
 * Brand Fashion's cart page (DIVERGED 2026-10-19, B7) — on core's v2 cart. Watchizer's twin reads compat:
 * the cards by id, a reconcile pass that heals a drifted server cart, then cart/validate. Here core's v2
 * answer already carries every line's product card, its current price, its stock and its problem, so the
 * page draws that answer and nothing else. The BEHAVIOUR is Watchizer's: the stepper debounced 300 ms,
 * remove with a 5 s undo, the governorate chosen here, the checkout gate that says WHY it did not proceed,
 * the same sentences. Brand Fashion's own (spec §10.4): the variant on the line, a sold-out line greyed
 * with a "Remove" action, "Price updated" on the line, the delivery row always showing an amount, one rail
 * on the leather tint, a rail on the empty cart, and the phone's sticky bar with the total and Checkout.
 */

const OUT = new Set(['unavailable', 'variant_unavailable', 'variant_required'])

const lineView = (item, lang) => {
  const p = item.product || {}
  const unit = Number(item.piece_price)
  const variant = item.variant_id ? (p.variants || []).find((v) => v.id === item.variant_id) : null
  const list = Number((variant?.price || p.price || {}).amount || 0)
  const label = variant
    ? [txt(variant.color?.name, lang), txt(variant.size?.name, lang)].filter(Boolean).join(' / ')
    : item.variant?.label || ''
  const soldOut = OUT.has(item.problem) || (item.server && item.available <= 0)
  return {
    name: txt(p.title, lang),
    brand: txt(p.brand?.name, lang),
    image: p.image?.src || null,
    url: p.slug ? `/product/${p.slug}` : '/listing',
    label,
    unit,
    list,
    onSale: list > unit + 0.01,
    soldOut,
    short: !soldOut && item.problem === 'insufficient_stock',
    available: item.available,
    max: item.available > 0 ? Math.min(99, item.available) : 99,
    priceChanged: !soldOut && item.seen_price != null && Math.abs(Number(item.seen_price) - unit) > 0.01,
    was: item.seen_price,
  }
}

// ── Cart line ─────────────────────────────────────────────────────────────
const CartItem = memo(function CartItem({ item, d, isRTL, t, fmt, onQty, onRemove, navigate }) {
  const key = getItemKey(item)
  const [qty, setQty] = useState(item.quantity)
  // eslint-disable-next-line react-hooks/set-state-in-effect -- keep the local number in step with the store
  useEffect(() => setQty(item.quantity), [item.quantity])

  // Debounce the network write; the number on screen moves at once (Watchizer's 300 ms).
  useEffect(() => {
    if (qty === item.quantity) return
    const id = setTimeout(() => onQty(key, qty), 300)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qty])

  return (
    <div className={`wz-cart-item bf-cart-line${d.soldOut ? ' is-unavailable' : ''}`}>
      <button className="wz-cart-item-img" onClick={() => navigate(d.url)} aria-label={d.name} tabIndex={-1}>
        {d.image ? (
          // eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL
          <img src={d.image} alt="" width="80" height="80" loading="lazy" />
        ) : (
          <span className="bf-cart-noimg" aria-hidden="true" />
        )}
      </button>

      <div className="wz-cart-item-info">
        {d.brand && <p className="wz-cart-item-brand">{d.brand}</p>}
        <button className="wz-cart-item-name" onClick={() => navigate(d.url)}>
          {d.name || t('Product', 'منتج')}
        </button>
        {d.label && <p className="bf-cart-variant">{d.label}</p>}

        <div className="wz-cart-item-price">
          {d.soldOut ? (
            <span className="bf-cart-pill">{t('Sold out', 'نفد')}</span>
          ) : (
            <>
              <span className="wz-cart-unit">{fmt(d.unit)}</span>
              {d.onSale && <span className="wz-cart-unit-orig">{fmt(d.list)}</span>}
            </>
          )}
        </div>

        {d.short && (
          <p className="wz-cart-warn-text" role="status">
            {d.available > 0
              ? isRTL ? `المتاح ${d.available} فقط` : `Only ${d.available} left`
              : t('Not enough stock for this quantity', 'الكمية المطلوبة غير متوفرة في المخزون')}
          </p>
        )}
        {d.priceChanged && (
          <p className="bf-cart-alert" role="status">
            {isRTL ? `تم تحديث السعر: كان ${fmt(d.was)}` : `Price updated: was ${fmt(d.was)}`}
          </p>
        )}
        {d.soldOut ? (
          <button type="button" className="bf-cart-remove-primary" onClick={() => onRemove(item)}>
            {t('Remove', 'حذف')}
          </button>
        ) : (
          <button type="button" className="bf-cart-remove" onClick={() => onRemove(item)}>
            {t('Remove', 'حذف')}
          </button>
        )}
      </div>

      {!d.soldOut && (
        <div className="wz-cart-item-side">
          <div className="wz-cart-qty" role="group" aria-label={t('Quantity', 'الكمية')}>
            <button onClick={() => setQty((q) => Math.max(1, q - 1))} disabled={qty <= 1} aria-label={t('Decrease', 'تقليل')}>
              −
            </button>
            <span aria-live="polite">{qty}</span>
            <button onClick={() => setQty((q) => Math.min(d.max, q + 1))} disabled={qty >= d.max} aria-label={t('Increase', 'زيادة')}>
              +
            </button>
          </div>
          <div className="wz-cart-line-total">{fmt(d.unit * qty)}</div>
        </div>
      )}
    </div>
  )
})

// The rail under the items: what goes with the last thing added (v2 related → addons, else similar).
const useCartRail = (slug, exclude) =>
  useQuery({
    queryKey: ['bf-cart-rail', slug],
    queryFn: async () => (await publicHttp.get(`v2/${STOREFRONT_CODE}/products/${encodeURIComponent(slug)}/related`)).data,
    enabled: Boolean(slug),
    staleTime: 5 * 60 * 1000,
    select: (data) => {
      const pool = (data?.addons?.length ? data.addons : data?.similar) || []
      return pool.filter((c) => !exclude.has(c.id))
    },
  })

// The empty cart's rail (§10.4: a rail, never a blank page): the newest products in stock.
const useNewIn = (enabled) =>
  useQuery({
    queryKey: ['bf-cart-newin'],
    queryFn: async () => (await publicHttp.get(`v2/${STOREFRONT_CODE}/products?sort=newest&in_stock=1&per_page=12`)).data,
    enabled,
    staleTime: 5 * 60 * 1000,
    select: (data) => data?.data || [],
  })

// ── Page ──────────────────────────────────────────────────────────────────
function Cart() {
  const shippingPrices = useShippingPrices()
  const shippingid = useShippingStore((s) => s.shippingid)
  const setShippingid = useShippingStore((s) => s.setShippingid)
  const setShipping = useShippingStore((s) => s.setShipping)
  const setShippingName = useShippingStore((s) => s.setShippingName)
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const lang = isRTL ? 'ar' : 'en'
  const t = useCallback((en, ar) => (isRTL ? ar : en), [isRTL])
  const router = useRouter()
  const navigate = useCallback((to) => router.push(to), [router])
  const { cart, updateQuantity, removeItem, undoRemove, validateCart, lastRemoved } = useCart()

  const [checked, setChecked] = useState(false)
  const [processing, setProcessing] = useState(false)
  // Why the last checkout tap did not proceed — shown right above the button (Watchizer, 2026-09-28).
  // null | { kind: 'invalid', lines: [{ name, reason }] } | { kind: 'error' }
  const [checkoutBlock, setCheckoutBlock] = useState(null)

  const items = useMemo(() => cart?.cart_item || [], [cart])
  // Core's verdict when the cart opens: every line re-checked and re-priced (the authoritative read).
  useEffect(() => {
    let live = true
    validateCart().finally(() => live && setChecked(true))
    return () => {
      live = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  const loading = !checked && items.some((i) => !i.product)

  const fmt = useCallback((v) => money(Math.round(Number(v) || 0), lang), [lang])
  const views = useMemo(() => new Map(items.map((i) => [getItemKey(i), lineView(i, lang)])), [items, lang])

  const { subtotal, savings, anyUnavailable, itemCount } = useMemo(() => {
    let sub = 0
    let sav = 0
    let bad = false
    let count = 0
    for (const it of items) {
      const d = views.get(getItemKey(it))
      if (d.soldOut) {
        bad = true
        continue
      }
      sub += d.unit * it.quantity
      count += it.quantity
      if (d.onSale) sav += (d.list - d.unit) * it.quantity
    }
    return { subtotal: sub, savings: sav, anyUnavailable: bad, itemCount: count }
  }, [items, views])

  // GA4 view_cart (2026-10-22): once per visit, when core's verdict is in and the money is on screen
  const viewCartFired = useRef(false)
  useEffect(() => {
    if (viewCartFired.current || loading || !items.length) return
    viewCartFired.current = true
    trackViewCart({
      value: subtotal,
      contents: items.map((i) => ({ id: i.product_id ?? null, name: txt(i.product?.title, 'en') || views.get(getItemKey(i))?.name, quantity: i.quantity, price: Number(i.piece_price) })),
    })
  }, [loading, items, subtotal, views])

  // every unit in the cart, sold-out lines included — the header's badge counts the same way
  const lineUnits = items.reduce((n, i) => n + i.quantity, 0)

  // ── Delivery: the governorate's fee; before one is chosen, the range (the row always shows an amount)
  const cities = useMemo(() => shippingPrices || [], [shippingPrices])
  const selectedCity = cities.find((c) => String(c.id) === String(shippingid)) || null
  const shippingCost = selectedCity ? Number(selectedCity.Price) : 0
  const citySelected = Boolean(selectedCity)
  const fees = cities.map((c) => Number(c.Price)).filter((n) => Number.isFinite(n))
  const feeRange = fees.length
    ? isRTL
      ? `${Math.min(...fees).toLocaleString('en-EG')}–${Math.max(...fees).toLocaleString('en-EG')} ج.م`
      : `EGP ${Math.min(...fees).toLocaleString('en-EG')}–${Math.max(...fees).toLocaleString('en-EG')}`
    : '—'
  const total = subtotal + shippingCost

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

  // Core's reasons in the shopper's language (Watchizer's sentences).
  const reasonText = useCallback(
    (line) => {
      if (line.problem === 'insufficient_stock')
        return line.available > 0
          ? isRTL ? `المتاح ${line.available} فقط` : `Only ${line.available} available`
          : t('Not enough stock for this quantity', 'الكمية المطلوبة غير متوفرة في المخزون')
      if (OUT.has(line.problem)) return t('No longer available', 'لم يعد متوفراً')
      return t("This item couldn't be updated", 'تعذّر تحديث هذا المنتج')
    },
    [isRTL, t],
  )

  const onRemove = useCallback(
    (item) => {
      setCheckoutBlock(null)
      removeItem(getItemKey(item))
    },
    [removeItem],
  )

  const onQty = useCallback(
    async (key, q) => {
      setCheckoutBlock(null)
      // The server's answer wins: a refused quantity is rolled back by the store, and the shopper is told why.
      const r = await updateQuantity(key, q)
      if (r && r.ok === false) {
        const line = cartStore.getSnapshot().cart_item.find((i) => getItemKey(i) === key)
        setCheckoutBlock({
          kind: 'invalid',
          lines: [{ name: line ? lineView(line, lang).name : '', reason: line ? reasonText({ ...line, problem: r.code }) : '' }],
        })
      }
    },
    [updateQuantity, reasonText, lang],
  )

  // The checkout step never fails silently (Watchizer, 2026-09-28): core judges the cart as it stands, and
  // any line it refuses is named next to the button.
  const goToCheckout = useCallback(async () => {
    if (processing) return
    setProcessing(true)
    setCheckoutBlock(null)
    const res = await validateCart()
    setProcessing(false)
    if (res?.failed) {
      setCheckoutBlock({ kind: 'error' })
      return
    }
    if (res && res.valid === false) {
      const lines = cartStore
        .getSnapshot()
        .cart_item.filter((l) => l.problem)
        .map((l) => ({ name: lineView(l, lang).name, reason: reasonText(l) }))
      setCheckoutBlock({ kind: 'invalid', lines })
      return
    }
    cartStore.acknowledgePrices()
    navigate('/checkout')
  }, [processing, validateCart, navigate, reasonText, lang])

  const inCart = useMemo(() => new Set(items.map((i) => i.product_id)), [items])
  const lastSlug = [...items].reverse().find((i) => i.product?.slug)?.product?.slug || null
  const { data: railCards = [] } = useCartRail(lastSlug, inCart)
  const empty = checked && items.length === 0
  const { data: newIn = [] } = useNewIn(empty)

  const chooseCity = useCallback(() => {
    const el = document.getElementById('wz-ship-city')
    if (!el) return
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
    el.focus({ preventScroll: true })
  }, [])
  const checkoutDisabled = processing || anyUnavailable || !citySelected || itemCount === 0
  const checkoutLabel = processing ? t('Processing...', 'جارٍ المعالجة...') : t('Checkout', 'إتمام الشراء')

  // ── Empty ──
  if (!loading && items.length === 0) {
    return (
      <div className="wz-cartpage bf-cartpage" dir={isRTL ? 'rtl' : 'ltr'}>
        <div className="wz-cart-empty">
          <FiShoppingBag className="wz-cart-empty-icon" aria-hidden="true" />
          <h1 className="bf-cart-empty-title">{t('Your cart is empty', 'سلة التسوق فارغة')}</h1>
          <p>{t('Start with what is new this week.', 'ابدأ بما وصل هذا الأسبوع.')}</p>
          <button className="wz-cart-checkout" onClick={() => navigate('/listing')}>
            {t('Shop all products', 'تسوّق كل المنتجات')}
          </button>
        </div>
        {newIn.length > 1 && (
          <div className="bf-cart-rail-wrap">
            <BfRail title={t('New in', 'وصل حديثاً')} cards={newIn} lang={lang} seeAllHref="/listing" tint="none" className="bf-cart-rail" />
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="wz-cartpage bf-cartpage" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-cart-inner">
        <div className="wz-cart-grid">
          {/* Items */}
          <div className="wz-cart-main">
            <div className="wz-cart-head">
              <h1 className="wz-cart-title">{t('Your Cart', 'سلة التسوق')}</h1>
              <span className="wz-cart-count">
                ({lineUnits} {lineUnits === 1 ? t('item', 'منتج') : t('items', 'منتجات')})
              </span>
            </div>

            {loading ? (
              <div className="wz-cart-skel-list" aria-busy="true">
                {Array.from({ length: Math.min(3, items.length || 2) }).map((_, i) => (
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
                  d={views.get(getItemKey(item))}
                  isRTL={isRTL}
                  t={t}
                  fmt={fmt}
                  onQty={onQty}
                  onRemove={onRemove}
                  navigate={navigate}
                />
              ))
            )}

            <Link href="/listing" className="wz-cart-continue wz-cart-continue--inline">
              {t('Continue shopping', 'متابعة التسوق')}
            </Link>
          </div>

          {/* Summary */}
          <aside className="wz-cart-summary-wrap" aria-label={t('Order summary', 'ملخص الطلب')}>
            <div className="wz-cart-summary">
              <h2 className="wz-cart-summary-title">{t('Order summary', 'ملخص الطلب')}</h2>

              <div className="wz-cart-shipto">
                <label className="wz-cart-shipto-label" htmlFor="wz-ship-city">
                  {t('Ship to', 'الشحن إلى')}
                </label>
                <div className="wz-cart-select">
                  <select id="wz-ship-city" value={shippingid || ''} onChange={onCityChange}>
                    <option value="" disabled>
                      {t('Select governorate', 'اختر المحافظة')}
                    </option>
                    {cities.map((c) => (
                      <option key={c.id} value={c.id}>
                        {isRTL ? `${c.GovernorateAr} — ${fmt(c.Price)}` : `${c.GovernorateEn} — ${fmt(c.Price)}`}
                      </option>
                    ))}
                  </select>
                  <FiChevronDown className="wz-cart-select-icon" aria-hidden="true" />
                </div>
              </div>

              <div className="wz-cart-row">
                <span>{t('Subtotal', 'المجموع الفرعي')}</span>
                <span>{fmt(subtotal)}</span>
              </div>
              <div className="wz-cart-row">
                <span>{t('Delivery', 'التوصيل')}</span>
                <span>{citySelected ? fmt(shippingCost) : feeRange}</span>
              </div>
              {savings > 0 && (
                <div className="wz-cart-row wz-cart-savings">
                  <span>{t('You save', 'وفرت')}</span>
                  <span>{fmt(savings)}</span>
                </div>
              )}

              <div className="wz-cart-divider" />

              <div className="wz-cart-row wz-cart-total">
                <span>{t('Total', 'الإجمالي')}</span>
                <span>{fmt(total)}</span>
              </div>
              {!citySelected && <p className="bf-cart-total-note">{t('Plus delivery to your governorate', 'يُضاف التوصيل حسب المحافظة')}</p>}

              {checkoutBlock && (
                <div className="wz-cart-block" role="alert">
                  {checkoutBlock.kind === 'error' ? (
                    <p className="wz-cart-block-title">
                      {t("We couldn't check your cart just now. Please try again.", 'تعذّر التحقق من سلتك الآن. حاول مرة أخرى.')}
                    </p>
                  ) : (
                    <>
                      <p className="wz-cart-block-title">
                        {t('Your cart changed since you added these items:', 'تغيّرت سلتك منذ أضفت هذه المنتجات:')}
                      </p>
                      <ul className="wz-cart-block-list">
                        {checkoutBlock.lines.map((l, i) => (
                          <li key={i}>
                            {l.name ? <strong>{l.name}</strong> : null}
                            {l.name ? ' — ' : ''}
                            {l.reason}
                          </li>
                        ))}
                      </ul>
                      <p className="wz-cart-block-hint">
                        {t(
                          'Your cart now shows what is available. Check it, then tap Checkout again.',
                          'سلتك الآن تعرض المتاح فقط. راجعها ثم اضغط إتمام الشراء مرة أخرى.',
                        )}
                      </p>
                    </>
                  )}
                </div>
              )}
              {anyUnavailable && (
                <p className="wz-cart-select-hint">{t('Remove the sold-out items to continue', 'احذف المنتجات النافدة للمتابعة')}</p>
              )}
              <button className="wz-cart-checkout" onClick={goToCheckout} disabled={checkoutDisabled}>
                {processing && <span className="wz-cart-spinner" aria-hidden="true" />}
                {checkoutLabel}
              </button>
              {!citySelected && !anyUnavailable && (
                <p className="wz-cart-select-hint">{t('Choose your governorate to continue', 'اختر محافظتك للمتابعة')}</p>
              )}

              <ul className="wz-cart-trust">
                <li><FiLock aria-hidden="true" /> {t('Secure checkout', 'دفع آمن')}</li>
                <li><FiRefreshCw aria-hidden="true" /> {t('Returns within 4 days of delivery', 'الإرجاع خلال 4 أيام من الاستلام')}</li>
                <li><FiCheck aria-hidden="true" /> {t('100% authentic guarantee', 'أصالة مضمونة 100%')}</li>
              </ul>
            </div>
          </aside>
        </div>
      </div>

      {railCards.length > 1 && (
        <div className="bf-cart-rail-wrap">
          <BfRail title={t('Goes with your cart', 'يناسب سلتك')} cards={railCards} lang={lang} tint="none" className="bf-cart-rail" />
        </div>
      )}

      {/* The phone's sticky bar (§10.4): the total and Checkout, always in reach */}
      <div className="bf-cart-bar">
        <div className="bf-cart-bar-total">
          <span>{t('Total', 'الإجمالي')}</span>
          <strong>{fmt(total)}</strong>
        </div>
        {/* before a governorate is chosen the bar's button takes the shopper to the choice, not a dead end */}
        <button
          className="bf-cart-bar-cta"
          onClick={citySelected ? goToCheckout : chooseCity}
          disabled={processing || anyUnavailable || itemCount === 0}
        >
          {citySelected ? checkoutLabel : t('Choose governorate', 'اختر المحافظة')}
        </button>
      </div>

      {lastRemoved && (
        <div className="wz-cart-toast" role="status">
          <span>{t('Item removed.', 'تم حذف المنتج.')}</span>
          <button onClick={undoRemove}>{t('Undo', 'تراجع')}</button>
        </div>
      )}
    </div>
  )
}

export default memo(Cart)
