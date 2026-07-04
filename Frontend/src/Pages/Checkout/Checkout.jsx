import { memo, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FiCheck,
  FiChevronDown,
  FiCreditCard,
  FiLock,
  FiPhone,
  FiRefreshCw,
  FiShield,
  FiUser,
} from 'react-icons/fi'
import { MyContext } from '../../Context/Context'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useOffers } from '../../Hooks/queries/useOffers'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import http from '../../Context/api'
import useCart, { getGuestToken } from '../../Hooks/useCart'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import './Checkout.css'

// Card logos / Paymob availability. Vite exposes import.meta.env at build time;
// VITE_PAYMOB_ENABLED lets us disable the online-payment option cleanly.
const PAYMOB_ENABLED = import.meta.env.VITE_PAYMOB_ENABLED !== 'false'

const isValidEgPhone = (v) => /^01\d{9}$/.test(String(v || '').trim())

// ── Order summary (memoized — re-renders only when the cart/shipping change,
//    not on every keystroke in the form) ────────────────────────────────────
const OrderSummary = memo(function OrderSummary({
  items,
  products,
  offers,
  language,
  shippingCost,
  shippingName,
  subtotal,
  savings,
  total,
  canSubmit,
  processing,
  onPlace,
  isRTL,
}) {
  const t = (en, ar) => (isRTL ? ar : en)
  const currency = isRTL ? 'ج.م' : 'EGP'
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')

  const resolve = (item) => {
    if (item.product_id) {
      const p = (products || []).find((x) => x.id === item.product_id)
      return { name: p?.name || p?.product_title || '—', image: getImageUrl(p?.image) || PLACEHOLDER_IMG }
    }
    const o = (offers || []).find((x) => String(x.id) === String(item.offer_id))
    return {
      name: o ? (isRTL ? o.offer_name_ar : o.offer_name_en) : '—',
      image: getImageUrl(o?.image) || PLACEHOLDER_IMG,
    }
  }

  return (
    <aside className="wz-checkout-summary">
      <h2 className="wz-checkout-summary-title">{t('Order Summary', 'ملخص الطلب')}</h2>

      <div className="wz-checkout-items">
        {items.map((item) => {
          const d = resolve(item)
          return (
            <div className="wz-checkout-item" key={`${item.product_id || 'o'}-${item.offer_id || ''}-${item.id}`}>
              <img className="wz-checkout-item-img" src={d.image} alt={d.name} width="48" height="48" loading="lazy" onError={handleImgError} />
              <div className="wz-checkout-item-info">
                <p className="wz-checkout-item-name">{d.name}</p>
                <span className="wz-checkout-item-meta">
                  {item.quantity} × {fmt(item.piece_price)} {currency}
                </span>
              </div>
              <span className="wz-checkout-item-price">
                {fmt(Number(item.piece_price) * item.quantity)} {currency}
              </span>
            </div>
          )
        })}
      </div>

      <div className="wz-checkout-line">
        <span>{t('Subtotal', 'المجموع الفرعي')}</span>
        <span className="v">{fmt(subtotal)} {currency}</span>
      </div>

      <div className="wz-checkout-line">
        <span>
          {t('Shipping', 'الشحن')}
          {shippingName ? ` · ${shippingName}` : ''}
        </span>
        <span className="v">
          {shippingCost > 0 ? `${fmt(shippingCost)} ${currency}` : '—'}
        </span>
      </div>

      {savings > 0 && (
        <div className="wz-checkout-line is-save">
          <span>{t('You save', 'وفّرت')}</span>
          <span className="v">−{fmt(savings)} {currency}</span>
        </div>
      )}

      <div className="wz-checkout-divider" />

      <div className="wz-checkout-total">
        <span className="wz-checkout-total-label">{t('Total', 'الإجمالي')}</span>
        <span className="wz-checkout-total-val">
          <small>{currency}</small>
          {fmt(total)}
        </span>
      </div>

      <button
        type="button"
        className="wz-checkout-place"
        disabled={!canSubmit || processing}
        onClick={onPlace}
      >
        {processing ? (
          <>
            <span className="wz-checkout-spinner" />
            {t('PROCESSING…', 'جاري المعالجة…')}
          </>
        ) : (
          t('PLACE ORDER', 'إتمام الطلب')
        )}
      </button>

      <ul className="wz-checkout-trust">
        <li><FiLock size={14} /> {t('Secure checkout', 'دفع آمن')}</li>
        <li><FiRefreshCw size={14} /> {t('30-day returns', 'إرجاع 30 يوم')}</li>
        <li><FiShield size={14} /> {t('100% authentic', 'أصلي 100%')}</li>
        <li><FiPhone size={14} /> {t('WhatsApp support', 'دعم واتساب')}</li>
      </ul>
    </aside>
  )
})

function Checkout() {
  // Server data from TanStack Query; shipping state stays in context.
  const {
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

  const { userId, user, logout } = useAuthStore()
  const isLoggedIn = !!sessionStorage.getItem('token')
  const isGuest = !isLoggedIn

  const { cart, clearCart } = useCart()
  const items = cart?.cart_item || []

  // ── Form state ───────────────────────────────────────────────────────────
  const [email, setEmail] = useState(user?.email || '')
  const [firstName, setFirstName] = useState(user?.first_name || '')
  const [lastName, setLastName] = useState(user?.last_name || '')
  const [street, setStreet] = useState('')
  const [apartment, setApartment] = useState('')
  const [phone, setPhone] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [notes, setNotes] = useState('')

  const [addresses, setAddresses] = useState([])
  const [selectedAddressId, setSelectedAddressId] = useState(null)
  const [useNewAddress, setUseNewAddress] = useState(false)

  const [errors, setErrors] = useState({})
  const [banner, setBanner] = useState('')
  const [processing, setProcessing] = useState(false)
  const submitting = useRef(false)

  // ── Load saved addresses (logged-in only) ────────────────────────────────
  useEffect(() => {
    if (!isLoggedIn) return
    let alive = true
    http
      .get('me/addresses')
      .then((res) => {
        if (!alive) return
        const list = Array.isArray(res.data) ? res.data : []
        setAddresses(list)
        if (list.length > 0) {
          setSelectedAddressId(list[0].id)
          setUseNewAddress(false)
        } else {
          setUseNewAddress(true)
        }
      })
      .catch(() => alive && setUseNewAddress(true))
    return () => {
      alive = false
    }
  }, [isLoggedIn])

  // Whether the address form (inline fields) is active.
  const useInline = isGuest || useNewAddress || (isLoggedIn && addresses.length === 0)

  // ── Governorate dropdown ─────────────────────────────────────────────────
  const onGovChange = useCallback(
    (e) => {
      const id = e.target.value
      setShippingid(id)
      const city = shippingPrices.find((c) => String(c.id) === String(id))
      if (city) {
        setShipping(String(city.Price))
        setShippingName(isRTL ? city.GovernorateAr : city.GovernorateEn)
      }
      setErrors((p) => ({ ...p, shipping_city_id: undefined }))
    },
    [shippingPrices, isRTL, setShippingid, setShipping, setShippingName],
  )

  // ── Active shipping city (saved address city OR selected governorate) ─────
  const activeCity = useMemo(() => {
    if (!useInline && selectedAddressId) {
      const addr = addresses.find((a) => a.id === selectedAddressId)
      if (addr) return shippingPrices.find((c) => String(c.id) === String(addr.shipping_city_id)) || null
    }
    return shippingPrices.find((c) => String(c.id) === String(shippingid)) || null
  }, [useInline, selectedAddressId, addresses, shippingPrices, shippingid])

  // ── Totals (mirror the backend: items + governorate shipping cost) ────────
  const subtotal = useMemo(
    () => items.reduce((s, i) => s + Number(i.piece_price) * i.quantity, 0),
    [items],
  )
  const savings = useMemo(() => {
    let s = 0
    for (const it of items) {
      const entity = it.product_id
        ? (products || []).find((p) => p.id === it.product_id)
        : (offers || []).find((o) => String(o.id) === String(it.offer_id))
      if (entity) {
        const orig = Number(entity.selling_price) || 0
        const sale = Number(entity.sale_price_after_discount) || 0
        if (sale > 0 && sale < orig) s += (orig - sale) * it.quantity
      }
    }
    return s
  }, [items, products, offers])

  const shippingCost = activeCity ? Number(activeCity.Price) || 0 : 0
  const shippingName = activeCity ? (isRTL ? activeCity.GovernorateAr : activeCity.GovernorateEn) : ''
  const total = subtotal + shippingCost

  // ── Validation gate ──────────────────────────────────────────────────────
  const canSubmit = useMemo(() => {
    if (items.length === 0) return false
    if (!paymentMethod) return false
    if (useInline) {
      if (!shippingid) return false
      if (!street.trim()) return false
      if (!isValidEgPhone(phone)) return false
    } else if (!selectedAddressId) {
      return false
    }
    if (isGuest) {
      if (!/^\S+@\S+\.\S+$/.test(email)) return false
      if (!firstName.trim() || !lastName.trim()) return false
    }
    return true
  }, [
    items.length,
    paymentMethod,
    useInline,
    shippingid,
    street,
    phone,
    selectedAddressId,
    isGuest,
    email,
    firstName,
    lastName,
  ])

  // ── Cleanup (replaces the old localStorage.clear() bug) ──────────────────
  const cleanupAfterOrder = useCallback(() => {
    clearCart()
    if (isGuest) localStorage.removeItem('wz_guest_token')
    // Language, shipping cache, banners etc. are deliberately preserved.
  }, [clearCart, isGuest])

  // ── Submit ───────────────────────────────────────────────────────────────
  const placeOrder = useCallback(async () => {
    if (submitting.current || !canSubmit) return
    submitting.current = true
    setProcessing(true)
    setBanner('')
    setErrors({})

    const orderItems = items.map((i) => ({
      product_id: i.product_id ?? null,
      offer_id: i.offer_id ?? null,
      quantity: i.quantity,
      piece_price: i.piece_price,
      total_price: i.total_price ?? Number(i.piece_price) * i.quantity,
      type_stock: i.type_stock ?? 'Market',
      color_band: i.color_band ?? null,
      color_dial: i.color_dial ?? null,
    }))

    const body = {
      payment_method: paymentMethod, // 'cash' | 'card' (backend maps card→paymob)
      note: notes,
      total_price_for_order: total,
      items: orderItems,
    }
    if (isLoggedIn) body.user_id = userId

    if (useInline) {
      body.address_line = street.trim() + (apartment.trim() ? `, ${apartment.trim()}` : '')
      body.shipping_city_id = shippingid
      body.phone = phone.trim()
    } else {
      body.address_id = selectedAddressId
    }

    if (isGuest) {
      body.guest_name = `${firstName.trim()} ${lastName.trim()}`.trim()
      body.guest_email = email.trim()
      body.guest_phone = phone.trim()
      body.guest_token = getGuestToken()
    }

    try {
      const { data } = await http.post('/add_order', body)
      if (data?.success) {
        // Online payment → hand off to Paymob (cart cleared by the callback).
        if (data.redirect_url) {
          window.location.href = data.redirect_url
          return
        }
        // Cash → confirm locally.
        const snapshot = items.map((i) => {
          const entity = i.product_id
            ? (products || []).find((p) => p.id === i.product_id)
            : (offers || []).find((o) => String(o.id) === String(i.offer_id))
          const name = i.product_id
            ? entity?.name || entity?.product_title || '—'
            : entity
              ? isRTL
                ? entity.offer_name_ar
                : entity.offer_name_en
              : '—'
          return { name, qty: i.quantity, lineTotal: Number(i.piece_price) * i.quantity }
        })
        cleanupAfterOrder()
        navigate('/order-confirmation', {
          replace: true,
          state: {
            orderNumber: data.order_number,
            email: isGuest ? email.trim() : user?.email || '',
            firstName: firstName.trim(),
            lastName: lastName.trim(),
            isGuest,
            items: snapshot,
            total,
            shippingName,
            shippingPrice: shippingCost,
            paymentMethod,
          },
        })
        return
      }
      setBanner(data?.message || t('Failed to place order. Please try again.', 'فشل إتمام الطلب. حاول مرة أخرى.'))
    } catch (err) {
      const status = err.response?.status
      if (status === 422 && err.response.data?.errors) {
        setErrors(err.response.data.errors)
        setBanner(t('Please check the highlighted fields.', 'يرجى مراجعة الحقول المظللة.'))
      } else if (status === 422 && err.response.data?.message) {
        setBanner(err.response.data.message)
      } else {
        setBanner(t('Something went wrong. Please try again.', 'حدث خطأ ما. حاول مرة أخرى.'))
      }
    } finally {
      submitting.current = false
      setProcessing(false)
    }
  }, [
    canSubmit, items, paymentMethod, notes, total, isLoggedIn, userId, useInline, street,
    apartment, shippingid, phone, selectedAddressId, isGuest, firstName, lastName, email,
    products, offers, isRTL, cleanupAfterOrder, navigate, shippingName, shippingCost, user, t,
  ])

  const handleSignOut = useCallback(() => {
    logout()
    navigate('/login')
  }, [logout, navigate])

  const err = (k) => errors[k]?.[0]

  // ── Empty cart guard ─────────────────────────────────────────────────────
  if (items.length === 0) {
    return (
      <div className="wz-checkout" dir={isRTL ? 'rtl' : 'ltr'}>
        <div className="wz-checkout-inner" style={{ textAlign: 'center', paddingTop: 40 }}>
          <h1 className="wz-checkout-title">{t('Your cart is empty', 'سلة التسوق فارغة')}</h1>
          <p className="wz-checkout-sub">{t('Add items before checking out.', 'أضف منتجات قبل إتمام الطلب.')}</p>
          <button className="wz-checkout-place" style={{ maxWidth: 280, margin: '0 auto' }} onClick={() => navigate('/listing')}>
            {t('EXPLORE COLLECTION', 'تصفّح المجموعة')}
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="wz-checkout" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-checkout-inner">
        <h1 className="wz-checkout-title">{t('Checkout', 'إتمام الطلب')}</h1>
        <p className="wz-checkout-sub">{t('Complete your order below', 'أكمل طلبك بالأسفل')}</p>

        <div className="wz-checkout-grid">
          {/* ─── LEFT: form ─────────────────────────────────────────────── */}
          <div className="wz-checkout-form">
            {banner && <div className="wz-checkout-banner">{banner}</div>}

            {/* SECTION 1 — CONTACT */}
            <section className="wz-checkout-section">
              <div className="wz-checkout-sechead">
                <span className="wz-checkout-sectitle">{t('Contact', 'معلومات التواصل')}</span>
                <span className="wz-checkout-secnum">01</span>
              </div>

              {isLoggedIn ? (
                <div className="wz-checkout-signed">
                  <span className="wz-checkout-signed-ico"><FiUser size={18} /></span>
                  <div className="wz-checkout-signed-txt">
                    {t('Signed in as', 'تم تسجيل الدخول كـ')}
                    <b>{user?.email || `${user?.first_name || ''} ${user?.last_name || ''}`}</b>
                  </div>
                  <button type="button" className="wz-checkout-linkbtn" onClick={handleSignOut}>
                    {t('Sign out', 'تسجيل الخروج')}
                  </button>
                </div>
              ) : (
                <>
                  <div className="wz-checkout-field">
                    <label className="wz-checkout-label">
                      {t('Email', 'البريد الإلكتروني')}<span className="req">*</span>
                    </label>
                    <input
                      type="email"
                      className={`wz-checkout-input${err('guest_email') ? ' is-error' : ''}`}
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="you@example.com"
                      autoComplete="email"
                    />
                    {err('guest_email') && <span className="wz-checkout-err">{err('guest_email')}</span>}
                  </div>
                  <div className="wz-checkout-row2">
                    <div className="wz-checkout-field">
                      <label className="wz-checkout-label">
                        {t('First name', 'الاسم الأول')}<span className="req">*</span>
                      </label>
                      <input
                        className="wz-checkout-input"
                        value={firstName}
                        onChange={(e) => setFirstName(e.target.value)}
                        autoComplete="given-name"
                      />
                    </div>
                    <div className="wz-checkout-field">
                      <label className="wz-checkout-label">
                        {t('Last name', 'الاسم الأخير')}<span className="req">*</span>
                      </label>
                      <input
                        className="wz-checkout-input"
                        value={lastName}
                        onChange={(e) => setLastName(e.target.value)}
                        autoComplete="family-name"
                      />
                    </div>
                  </div>
                  <p className="wz-checkout-hint">
                    {t('Already have an account?', 'لديك حساب بالفعل؟')}{' '}
                    <a href="/login">{t('Sign in', 'تسجيل الدخول')}</a>
                  </p>
                </>
              )}
            </section>

            {/* SECTION 2 — SHIPPING */}
            <section className="wz-checkout-section">
              <div className="wz-checkout-sechead">
                <span className="wz-checkout-sectitle">{t('Shipping', 'عنوان الشحن')}</span>
                <span className="wz-checkout-secnum">02</span>
              </div>

              {/* Saved addresses (logged-in) */}
              {isLoggedIn && addresses.length > 0 && !useNewAddress && (
                <>
                  {addresses.map((a) => (
                    <label
                      key={a.id}
                      className={`wz-checkout-saved${selectedAddressId === a.id ? ' is-active' : ''}`}
                    >
                      <input
                        type="radio"
                        name="saved-address"
                        style={{ display: 'none' }}
                        checked={selectedAddressId === a.id}
                        onChange={() => setSelectedAddressId(a.id)}
                      />
                      <span className="wz-checkout-dot" />
                      <div className="wz-checkout-saved-body">
                        {a.address_line}
                        <br />
                        <span>{a.phone_number_one}</span>
                      </div>
                    </label>
                  ))}
                  <button type="button" className="wz-checkout-linkbtn" onClick={() => setUseNewAddress(true)}>
                    + {t('Use a new address', 'استخدام عنوان جديد')}
                  </button>
                </>
              )}

              {/* Address form (guest / new address) */}
              {useInline && (
                <>
                  <div className="wz-checkout-field">
                    <label className="wz-checkout-label">
                      {t('Governorate', 'المحافظة')}<span className="req">*</span>
                    </label>
                    <div className="wz-checkout-selwrap">
                      <select
                        className={`wz-checkout-select${err('shipping_city_id') ? ' is-error' : ''}`}
                        value={shippingid || ''}
                        onChange={onGovChange}
                      >
                        <option value="" disabled>
                          {t('Select governorate', 'اختر المحافظة')}
                        </option>
                        {shippingPrices.map((c) => (
                          <option key={c.id} value={c.id}>
                            {(isRTL ? c.GovernorateAr : c.GovernorateEn)} — {Math.round(c.Price)} {t('EGP', 'ج.م')}
                          </option>
                        ))}
                      </select>
                      <FiChevronDown className="wz-checkout-selicon" size={16} />
                    </div>
                    {err('shipping_city_id') && <span className="wz-checkout-err">{err('shipping_city_id')}</span>}
                  </div>

                  <div className="wz-checkout-field">
                    <label className="wz-checkout-label">
                      {t('Street address', 'العنوان بالتفصيل')}<span className="req">*</span>
                    </label>
                    <input
                      className={`wz-checkout-input${err('address_line') ? ' is-error' : ''}`}
                      value={street}
                      onChange={(e) => setStreet(e.target.value)}
                      placeholder={t('Street name, building number', 'اسم الشارع، رقم المبنى')}
                      autoComplete="street-address"
                    />
                    {err('address_line') && <span className="wz-checkout-err">{err('address_line')}</span>}
                  </div>

                  <div className="wz-checkout-field">
                    <label className="wz-checkout-label">{t('Apartment / Floor', 'الشقة / الدور')}</label>
                    <input
                      className="wz-checkout-input"
                      value={apartment}
                      onChange={(e) => setApartment(e.target.value)}
                      placeholder={t('Apartment, floor (optional)', 'الشقة، الدور (اختياري)')}
                    />
                  </div>

                  <div className="wz-checkout-field">
                    <label className="wz-checkout-label">
                      {t('Phone', 'رقم الهاتف')}<span className="req">*</span>
                    </label>
                    <div className="wz-checkout-phone">
                      <span className="wz-checkout-flag">🇪🇬</span>
                      <input
                        className={`wz-checkout-input${err('phone') || (phone && !isValidEgPhone(phone)) ? ' is-error' : ''}`}
                        value={phone}
                        onChange={(e) => setPhone(e.target.value.replace(/[^\d]/g, '').slice(0, 11))}
                        placeholder="01XXXXXXXXX"
                        inputMode="numeric"
                        autoComplete="tel"
                      />
                    </div>
                    {phone && !isValidEgPhone(phone) && (
                      <span className="wz-checkout-err">
                        {t('Enter a valid 11-digit number starting with 01', 'أدخل رقمًا صحيحًا من 11 رقمًا يبدأ بـ 01')}
                      </span>
                    )}
                    {err('phone') && <span className="wz-checkout-err">{err('phone')}</span>}
                  </div>

                  {isLoggedIn && addresses.length > 0 && (
                    <button type="button" className="wz-checkout-linkbtn" onClick={() => setUseNewAddress(false)}>
                      {t('Use a saved address instead', 'استخدام عنوان محفوظ بدلاً من ذلك')}
                    </button>
                  )}
                </>
              )}
            </section>

            {/* SECTION 3 — PAYMENT */}
            <section className="wz-checkout-section">
              <div className="wz-checkout-sechead">
                <span className="wz-checkout-sectitle">{t('Payment', 'طريقة الدفع')}</span>
                <span className="wz-checkout-secnum">03</span>
              </div>

              <div className="wz-checkout-pay">
                <label className={`wz-checkout-payopt${paymentMethod === 'cash' ? ' is-active' : ''}`}>
                  <input
                    type="radio"
                    name="payment"
                    value="cash"
                    style={{ display: 'none' }}
                    checked={paymentMethod === 'cash'}
                    onChange={() => setPaymentMethod('cash')}
                  />
                  <span className="wz-checkout-dot" />
                  <div className="wz-checkout-payopt-main">
                    <div className="wz-checkout-payopt-title">{t('Cash on Delivery', 'الدفع عند الاستلام')}</div>
                    <div className="wz-checkout-payopt-note">
                      {t('Pay when you receive your order', 'ادفع عند استلام طلبك')}
                    </div>
                  </div>
                </label>

                <label
                  className={`wz-checkout-payopt${paymentMethod === 'card' ? ' is-active' : ''}${
                    PAYMOB_ENABLED ? '' : ' is-disabled'
                  }`}
                >
                  <input
                    type="radio"
                    name="payment"
                    value="card"
                    style={{ display: 'none' }}
                    disabled={!PAYMOB_ENABLED}
                    checked={paymentMethod === 'card'}
                    onChange={() => PAYMOB_ENABLED && setPaymentMethod('card')}
                  />
                  <span className="wz-checkout-dot" />
                  <div className="wz-checkout-payopt-main">
                    <div className="wz-checkout-payopt-title">
                      {t('Pay Online', 'دفع إلكتروني')}
                      {PAYMOB_ENABLED ? (
                        <span className="wz-checkout-cards"><FiCreditCard /></span>
                      ) : (
                        <span className="wz-checkout-soon">{t('Coming soon', 'قريباً')}</span>
                      )}
                    </div>
                    <div className="wz-checkout-payopt-note">
                      {t('Secure payment via Paymob · Visa / Mastercard', 'دفع آمن عبر Paymob · فيزا / ماستركارد')}
                    </div>
                  </div>
                </label>
              </div>
            </section>

            {/* SECTION 4 — NOTES */}
            <section className="wz-checkout-section">
              <div className="wz-checkout-sechead">
                <span className="wz-checkout-sectitle">{t('Order notes', 'ملاحظات على الطلب')}</span>
                <span className="wz-checkout-secnum">04</span>
              </div>
              <textarea
                className="wz-checkout-textarea"
                value={notes}
                maxLength={500}
                onChange={(e) => setNotes(e.target.value)}
                placeholder={t('Anything we should know? (optional)', 'أي ملاحظات إضافية؟ (اختياري)')}
              />
            </section>
          </div>

          {/* ─── RIGHT: summary ─────────────────────────────────────────── */}
          <OrderSummary
            items={items}
            products={products}
            offers={offers}
            language={language}
            isRTL={isRTL}
            subtotal={subtotal}
            savings={savings}
            shippingCost={shippingCost}
            shippingName={shippingName}
            total={total}
            canSubmit={canSubmit}
            processing={processing}
            onPlace={placeOrder}
          />
        </div>
      </div>
    </div>
  )
}

export default Checkout
