'use client'
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import Link from '@/src/Components/LocaleLink'
import {
  FiCalendar,
  FiCheck,
  FiChevronDown,
  FiCreditCard,
  FiLock,
  FiPhone,
  FiRefreshCw,
  FiShield,
  FiSmartphone,
  FiUser,
} from 'react-icons/fi'
import { usePaymentMethods } from '../../Hooks/queries/usePaymentMethods'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useShippingStore } from '../../Store/shippingStore'
import { useShippingPrices } from '../../Hooks/useShippingPrices'
import http from '../../Context/api'
import useCart from '../../Hooks/useCart'
import { cartStore } from '../../Store/cartStore'
import { txt, money } from '../../lib/cardData'
import { STOREFRONT_CODE } from '../../lib/env'
import { trackInitiateCheckout, savePendingPurchase } from '../../scripts/pixels'
import { useOrderConfirmStore } from '../../Store/orderConfirmStore'
import './Checkout.css'

/*
 * Brand Fashion's checkout (DIVERGED 2026-10-19, B7) — Watchizer's page and flow, on core's v2 routes:
 *  • the order is POSTed to /api/v2/{code}/orders, which builds it from the v2 cart (re-checked, re-priced),
 *    tags it with this storefront and refuses a total that is not the server's — never compat's add_order;
 *  • the lines' names, photos and list prices come from the cart's own v2 answer (no compat cards by id);
 *  • the payment rows are EXACTLY core's v2 list for this storefront (spec §10.5) — no cash row of the page's
 *    own and no "Pay Online" fallback while the list loads or fails; each row posts its own id, which core checks;
 *  • core's refusal CODES are said in the shopper's language, and a refused cart sends them back to the cart;
 *  • Brand Fashion's skin: prices as the card prints them (§5), the total inside the button, and on a phone
 *    the summary collapsible at the top with the item count and the total.
 * Everything else — contact, saved addresses, the recovery prefill, the governorate, the phone rule, the
 * notes, InitiateCheckout once, the confirmation hand-over — is Watchizer's, line for line.
 */

const isValidEgPhone = (v) => /^01\d{9}$/.test(String(v || '').trim())

// ── Online payment methods (batch 1, 2026-09-26) ──────────────────────────────
// The list comes from core (usePaymentMethods). Cash is always offered and is not driven by it;
// offline keys are skipped if a row for them exists. The icon and the one-line note are the
// storefront's, by method KEY — the label is the merchant's, from the dashboard.
const KIND = { cod: 'cash', whatsapp: 'whatsapp' }   // what core's `payment_method` calls an offline row; anything else is 'card'
const METHOD_ICON = {
  card: FiCreditCard,
  bank_installment: FiCalendar,
  cagg: FiCalendar,
  valu: FiCalendar,
  wallet: FiSmartphone,
  apple_pay: FiSmartphone,
}
const METHOD_NOTE = {
  cod: ['Pay when you receive your order', 'ادفع عند استلام طلبك'],
  whatsapp: ['We confirm the order and the payment with you on WhatsApp', 'نؤكد معك الطلب والدفع على واتساب'],
  card: ['Visa / Mastercard / Meeza', 'فيزا / ماستركارد / ميزة'],
  wallet: ['Vodafone Cash, Orange, Etisalat and other mobile wallets', 'فودافون كاش وأورانج واتصالات وباقي المحافظ'],
  bank_installment: ['Split the amount on your bank credit card', 'قسّط المبلغ على بطاقة البنك الائتمانية'],
  cagg: ['Pay in instalments through a finance provider', 'قسّط المبلغ عبر إحدى شركات التمويل'],
  valu: ['Pay in instalments with valU', 'قسّط المبلغ مع valU'],
  apple_pay: ['Pay with Face ID or Touch ID', 'ادفع ببصمة الوجه أو الإصبع'],
}
const FIELD_TEXT = {
  guest_email: ['Enter a valid email address', 'أدخل بريداً إلكترونياً صحيحاً'],
  guest_name: ['Enter your name', 'أدخل اسمك'],
  phone: ['Enter an Egyptian mobile number (01xxxxxxxxx)', 'أدخل رقم موبايل مصري (01xxxxxxxxx)'],
  address_line: ['Enter your street address', 'أدخل عنوانك بالتفصيل'],
  shipping_city_id: ['Choose your governorate', 'اختر المحافظة'],
  address_id: ['Choose one of your addresses', 'اختر أحد عناوينك'],
}
const egp = (n) => Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 })

// Why a method cannot take THIS order, in the shopper's language — or null when it can.
const limitReason = (m, total, t) => {
  if (m.min_total != null && total < m.min_total)
    return t(`For orders of EGP ${egp(m.min_total)} or more`, `للطلبات من ${egp(m.min_total)} ج.م فأكثر`)
  if (m.max_total != null && total > m.max_total)
    return t(`For orders of up to EGP ${egp(m.max_total)}`, `للطلبات حتى ${egp(m.max_total)} ج.م`)
  return null
}

// ── Order summary (memoized — re-renders only when the cart/shipping change,
//    not on every keystroke in the form) ────────────────────────────────────
const OrderSummary = memo(function OrderSummary({
  items,
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
  const fmt = (v) => money(Math.round(Number(v) || 0), isRTL ? 'ar' : 'en')
  const resolve = (item) => ({
    name: txt(item.product?.title, language === 'ar' ? 'ar' : 'en') || '—',
    image: item.product?.image?.src || null,
    label: item.variant?.label || '',
  })

  return (
    <aside className="wz-checkout-summary">
      <h2 className="wz-checkout-summary-title">{t('Order Summary', 'ملخص الطلب')}</h2>

      <div className="wz-checkout-items">
        {items.map((item) => {
          const d = resolve(item)
          return (
            <div className="wz-checkout-item" key={`${item.product_id || 'o'}-${item.offer_id || ''}-${item.id}`}>
              {d.image ? (
                // eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL
                <img className="wz-checkout-item-img" src={d.image} alt="" width="48" height="48" loading="lazy" />
              ) : (
                <span className="wz-checkout-item-img" aria-hidden="true" />
              )}
              <div className="wz-checkout-item-info">
                <p className="wz-checkout-item-name">{d.name}</p>
                <span className="wz-checkout-item-meta">
                  {d.label ? `${d.label} · ` : ''}{item.quantity} × {fmt(item.piece_price)}
                </span>
              </div>
              <span className="wz-checkout-item-price">
                {fmt(Number(item.piece_price) * item.quantity)}
              </span>
            </div>
          )
        })}
      </div>

      <div className="wz-checkout-line">
        <span>{t('Subtotal', 'المجموع الفرعي')}</span>
        <span className="v">{fmt(subtotal)}</span>
      </div>

      <div className="wz-checkout-line">
        <span>
          {t('Shipping', 'الشحن')}
          {shippingName ? ` · ${shippingName}` : ''}
        </span>
        <span className="v">
          {shippingCost > 0 ? fmt(shippingCost) : t('Choose a governorate', 'اختر المحافظة')}
        </span>
      </div>

      {savings > 0 && (
        <div className="wz-checkout-line is-save">
          <span>{t('You save', 'وفّرت')}</span>
          <span className="v">−{fmt(savings)}</span>
        </div>
      )}

      <div className="wz-checkout-divider" />

      <div className="wz-checkout-total">
        <span className="wz-checkout-total-label">{t('Total', 'الإجمالي')}</span>
        <span className="wz-checkout-total-val">
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
            {t('Placing your order…', 'جارٍ إتمام طلبك…')}
          </>
        ) : (
          <>
            <span>{t('Place order', 'إتمام الطلب')}</span>
            <span className="bf-co-place-total">{fmt(total)}</span>
          </>
        )}
      </button>

      <ul className="wz-checkout-trust">
        <li><FiLock size={14} /> {t('Secure checkout', 'دفع آمن')}</li>
        <li><FiRefreshCw size={14} /> {t('Returns within 4 days of delivery', 'الإرجاع خلال 4 أيام من الاستلام')}</li>
        <li><FiShield size={14} /> {t('100% authentic', 'أصلي 100%')}</li>
        <li><FiPhone size={14} /> {t('WhatsApp support', 'دعم واتساب')}</li>
      </ul>
    </aside>
  )
})

function Checkout() {
  // Server data from TanStack Query; shipping selection from Zustand, the derived
  // city-price list from the shared shipping query.
  const shippingPrices = useShippingPrices()
  const shippingid = useShippingStore((s) => s.shippingid)
  const setShippingid = useShippingStore((s) => s.setShippingid)
  const setShipping = useShippingStore((s) => s.setShipping)
  const setShippingName = useShippingStore((s) => s.setShippingName)
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  // stable, because the method rows' memo (and the effect that picks the first row) depend on it
  const t = useCallback((en, ar) => (isRTL ? ar : en), [isRTL])
  const router = useRouter()
  const setOrderData = useOrderConfirmStore((s) => s.setOrderData)

  const { user, logout } = useAuthStore()
  // sessionStorage isn't available during SSR, so read it after mount. Server +
  // first client render both see `false` (no hydration mismatch); the real value
  // lands post-mount and re-runs the address fetch below.
  const [isLoggedIn, setIsLoggedIn] = useState(false)
  useEffect(() => {
    // Read auth from sessionStorage after mount (unavailable during SSR) — intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setIsLoggedIn(!!sessionStorage.getItem('token'))
  }, [])
  const isGuest = !isLoggedIn

  const { cart, clearCart } = useCart()
  const items = useMemo(() => cart?.cart_item || [], [cart])
  const lang = isRTL ? 'ar' : 'en'
  const fmt = useCallback((v) => money(Math.round(Number(v) || 0), lang), [lang])

  // ── Form state ───────────────────────────────────────────────────────────
  const [email, setEmail] = useState(user?.email || '')
  const [firstName, setFirstName] = useState(user?.first_name || '')
  const [lastName, setLastName] = useState(user?.last_name || '')
  // The session arrives after mount (the auth store starts logged-out so server and
  // client render alike), so fill what the shopper has not typed once it does.
  useEffect(() => {
    if (!user) return
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the session is only readable after mount
    setEmail((v) => v || user.email || '')
    setFirstName((v) => v || user.first_name || '')
    setLastName((v) => v || user.last_name || '')
  }, [user])
  const [street, setStreet] = useState('')
  const [apartment, setApartment] = useState('')
  const [phone, setPhone] = useState('')
  // The chosen row of core's v2 list (posted as `payment_method_id`) and what core calls its kind
  // (`payment_method`: cash for cod, whatsapp, card for every online row). Nothing is chosen until the
  // list has arrived; then the first usable row is.
  const [paymentMethod, setPaymentMethod] = useState(null)
  const [methodId, setMethodId] = useState(null)
  const { data: onlineList, isError: onlineListFailed, isLoading: methodsLoading, refetch: refetchMethods } = usePaymentMethods()
  // Apple Pay is offered only where it can work: Safari on an Apple device with a card in the
  // wallet. Read after mount — `window` does not exist on the server render.
  const [canApplePay, setCanApplePay] = useState(false)
  useEffect(() => {
    let supported = false
    try {
      supported = Boolean(window.ApplePaySession?.canMakePayments?.())
    } catch {
      supported = false
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- a browser capability, known only after mount
    setCanApplePay(supported)
  }, [])
  const [notes, setNotes] = useState('')

  const [addresses, setAddresses] = useState([])
  const [selectedAddressId, setSelectedAddressId] = useState(null)
  const [useNewAddress, setUseNewAddress] = useState(false)

  const [errors, setErrors] = useState({})
  const [banner, setBanner] = useState('')
  // A refused CART (a line gone or short, an empty cart): the banner also links back to the cart.
  const [bannerToCart, setBannerToCart] = useState(false)
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

  // ── Details brought back by a recovery link (/cart/recover, 2026-10-05) ──────
  // The page hands over what the shopper typed on the order that expired unpaid — name, e-mail,
  // phone, address line, governorate — in this tab's sessionStorage, read ONCE and removed. Only
  // empty fields are filled: anything already typed or known from the account wins.
  const [prefillCity, setPrefillCity] = useState(null)
  useEffect(() => {
    let prefill = null
    try {
      prefill = JSON.parse(sessionStorage.getItem('wz_checkout_prefill') || 'null')
      sessionStorage.removeItem('wz_checkout_prefill')
    } catch {
      prefill = null
    }
    if (!prefill || typeof prefill !== 'object') return
    const [first = '', ...rest] = String(prefill.name || '').trim().split(/\s+/)
    // eslint-disable-next-line react-hooks/set-state-in-effect -- sessionStorage is only readable after mount
    setFirstName((v) => v || first)
    setLastName((v) => v || rest.join(' '))
    setEmail((v) => v || String(prefill.email || ''))
    setPhone((v) => v || String(prefill.phone || ''))
    setStreet((v) => v || String(prefill.address_line || ''))
    if (prefill.shipping_city_id) setPrefillCity(String(prefill.shipping_city_id))
  }, [])
  // The governorate needs the price list, which may arrive after mount.
  useEffect(() => {
    if (!prefillCity || !shippingPrices.length) return
    const city = shippingPrices.find((c) => String(c.id) === prefillCity)
    if (city && !shippingid) {
      setShippingid(prefillCity)
      setShipping(String(city.Price))
      setShippingName(isRTL ? city.GovernorateAr : city.GovernorateEn)
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot hand-over
    setPrefillCity(null)
  }, [prefillCity, shippingPrices, shippingid, isRTL, setShippingid, setShipping, setShippingName])

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
      const p = it.product || {}
      const v = it.variant_id ? (p.variants || []).find((x) => x.id === it.variant_id) : null
      const list = Number((v?.price || p.price || {}).amount) || 0
      const unit = Number(it.piece_price) || 0
      if (list > unit + 0.01) s += (list - unit) * it.quantity
    }
    return s
  }, [items])

  /*
   * ── Analytics: InitiateCheckout ─────────────────────────────────────────
   *
   * Once per visit to this page with something in the cart. The ref is what makes it once: the
   * cart is a live store, so this component re-renders on every quantity change, and an
   * unguarded effect would report a fresh checkout each time someone adjusts a line.
   *
   * The value is the MERCHANDISE subtotal, not `total`. Two reasons, and the second is the one
   * that was briefly wrong here: shipping is not known until a governorate is picked, and it is
   * not our revenue in any case — the courier's share is not a conversion value.
   *
   * `Purchase` reports the SAME figure, for the same reason. This comment used to claim that as an
   * accomplished fact while `OrderConfirmation` was still sending `total`, so the two events
   * really did disagree by the shipping cost on every order (review 🟠 minor). The subtotal is now
   * handed to the confirmation page explicitly, below, rather than re-derived there — two places
   * subtracting shipping is two places to drift.
   */
  const initiateFired = useRef(false)
  useEffect(() => {
    if (initiateFired.current) return
    if (items.length === 0 || subtotal <= 0) return
    initiateFired.current = true
    trackInitiateCheckout({
      value: subtotal,
      contents: items.map((i) => ({
        id: i.product_id ?? i.offer_id ?? null,
        quantity: i.quantity,
        price: Number(i.piece_price),
      })),
    })
  }, [items, subtotal])

  const shippingCost = activeCity ? Number(activeCity.Price) || 0 : 0
  const shippingName = activeCity ? (isRTL ? activeCity.GovernorateAr : activeCity.GovernorateEn) : ''
  const total = subtotal + shippingCost

  const offered = useMemo(
    () =>
      (onlineList || [])
        .filter((m) => m.method !== 'apple_pay' || canApplePay)
        .map((m) => ({ ...m, kind: KIND[m.method] || 'card', reason: limitReason(m, total, t) })),
    [onlineList, canApplePay, total, t],
  )
  // The first usable row is chosen once the list arrives (and again if the chosen one disappears).
  useEffect(() => {
    if (methodId != null && offered.some((m) => m.id === methodId)) return
    const first = offered.find((m) => !m.reason)
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the list is only known after it loads
    setMethodId(first ? first.id : null)
    setPaymentMethod(first ? first.kind : null)
  }, [offered, methodId])
  // A chosen method the order has since outgrown (or that disappeared): nothing counts as
  // selected, so the shopper picks again instead of meeting a refusal after pressing the button.
  const chosenOnline = methodId == null ? null : offered.find((m) => m.id === methodId)
  const choiceLost = methodId == null || !chosenOnline || Boolean(chosenOnline.reason)

  // ── Validation gate ──────────────────────────────────────────────────────
  const canSubmit = useMemo(() => {
    if (items.length === 0) return false
    if (!paymentMethod || choiceLost) return false
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
    choiceLost,
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

    setBannerToCart(false)
    // v2 builds the order from the v2 CART (no lines in the body); the client's total is checked against
    // the server's, and the chosen row's id is checked (this shop's, switched on, inside its limits)
    // before anything is written.
    const body = {
      payment_method: paymentMethod,
      payment_method_id: methodId,
      note: notes,
      total,
    }

    if (useInline) {
      body.address_line = street.trim() + (apartment.trim() ? `, ${apartment.trim()}` : '')
      body.shipping_city_id = Number(shippingid)
      body.phone = phone.trim()
    } else {
      body.address_id = selectedAddressId
    }

    if (isGuest) {
      body.guest_name = `${firstName.trim()} ${lastName.trim()}`.trim()
      body.guest_email = email.trim()
      body.guest_phone = phone.trim()
    }

    try {
      const res = await http.post(`v2/${STOREFRONT_CODE}/orders`, body, { headers: { Accept: 'application/json' } })
      const data = res.status === 201 ? res.data?.data : null
      if (data?.order_number) {
        // Online payment → hand off to Paymob. The cart is already gone: core destroys it inside
        // the order transaction when `add_order` is accepted (CheckoutCompatController, before
        // this response is even sent), so by the time the shopper reaches Paymob the cart is
        // empty whatever happens next. The callback never touches the cart (corrected 2026-10-12;
        // this line used to say the callback cleared it).
        //
        // The shopper comes back to /order-confirmation?order=…&status=paid|failed|pending,
        // sent there by the payment callback (OrderConfirmation.jsx). The CLIENT cart (this
        // tab's sessionStorage) is deliberately NOT cleared here, unlike the cash path below:
        // the landing clears it by outcome — paid and pending yes, failed no, because on a
        // failure the kept cart is how the shopper retries (the Cart page rebuilds the server
        // cart from it). See CardLanding in OrderConfirmation.jsx (2026-10-12).
        //
        // NO Purchase event here, deliberately. The shopper has been sent to a payment page,
        // not paid: firing Purchase at this line would count every abandoned and every declined
        // card payment as a sale. What the Purchase needs is SAVED here instead (2026-10-22), and
        // the landing the payment callback sends the browser to fires it for a PAID order only —
        // see the card note at the top of src/scripts/pixels.js.
        if (data.redirect_url) {
          savePendingPurchase({
            orderNumber: data.order_number,
            value: subtotal,
            shipping: shippingCost,
            contents: items.map((i) => ({ id: i.product_id ?? null, name: txt(i.product?.title, 'en') || txt(i.product?.title, lang), quantity: i.quantity, price: Number(i.piece_price) })),
          })
          window.location.href = data.redirect_url
          return
        }
        // Cash → confirm locally.
        const snapshot = items.map((i) => {
          const name = txt(i.product?.title, lang) || '—'
          return {
            name,
            qty: i.quantity,
            lineTotal: Number(i.piece_price) * i.quantity,
            // Carried for the Purchase pixel event on the confirmation page.
            id: i.product_id ?? null,
            price: Number(i.piece_price),
          }
        })
        cleanupAfterOrder()
        // Next's router.push can't carry navigation state, so hand the order to
        // the confirmation page via the Zustand store (see orderConfirmStore).
        setOrderData({
          orderNumber: data.order_number,
          email: isGuest ? email.trim() : user?.email || '',
          firstName: firstName.trim(),
          lastName: lastName.trim(),
          isGuest,
          items: snapshot,
          total,
          // The MERCHANDISE value, carried separately from `total` for the Purchase pixel event:
          // it must equal what InitiateCheckout reported, and `total` includes shipping.
          subtotal,
          shippingName,
          shippingPrice: shippingCost,
          paymentMethod,
        })
        router.push('/order-confirmation')
        return
      }
      setBanner(t('Failed to place order. Please try again.', 'فشل إتمام الطلب. حاول مرة أخرى.'))
    } catch (err) {
      const status = err.response?.status
      const b = err.response?.data || {}
      if (status === 422 && b.error === 'invalid') {
        // core names the fields it refused; each gets its own sentence under the field
        const map = {}
        for (const f of b.fields || []) {
          const k = f === 'guest_phone' ? 'phone' : f
          map[k] = [FIELD_TEXT[k] ? t(FIELD_TEXT[k][0], FIELD_TEXT[k][1]) : t('Check this field', 'راجع هذا الحقل')]
        }
        setErrors(map)
        setBanner(t('Please check the highlighted fields.', 'يرجى مراجعة الحقول المظللة.'))
      } else if (status === 422 && b.error === 'payment_method_unavailable') {
        // A payment-method refusal: nothing was written. Both languages come back from core.
        setBanner(b.messages?.[lang] || t('That payment method is not available for this order.', 'طريقة الدفع هذه غير متاحة لهذا الطلب.'))
        refetchMethods()
      } else if (status === 422 && b.error === 'total_mismatch') {
        // a price or a fee moved since the page drew it: the cart is re-read, the new total is shown
        await cartStore.fetchCart()
        setBanner(
          isRTL
            ? `تغيّر الإجمالي إلى ${fmt(b.server_total)}. راجع طلبك ثم أتممه مرة أخرى.`
            : `The total changed to ${fmt(b.server_total)}. Check your order, then place it again.`,
        )
      } else if (status === 422 && ['cart_empty', 'line_unavailable', 'insufficient_stock'].includes(b.error)) {
        await cartStore.fetchCart()
        setBannerToCart(true)
        setBanner(
          b.error === 'cart_empty'
            ? t('Your cart is empty — it may have been ordered from another tab.', 'سلتك فارغة — ربما طُلبت من نافذة أخرى.')
            : t('An item in your cart is no longer available in that quantity.', 'أحد منتجات سلتك لم يعد متوفراً بهذه الكمية.'),
        )
      } else if (status === 429) {
        setBanner(t('Too many attempts. Please wait a minute, then try again.', 'محاولات كثيرة. انتظر دقيقة ثم حاول مرة أخرى.'))
      } else if (b.error === 'server_error' && b.ref) {
        setBanner(
          isRTL
            ? `حدث خطأ من جهتنا ولم يُسجَّل الطلب. حاول مرة أخرى، أو راسلنا بالرقم ${String(b.ref).slice(0, 8)}.`
            : `Something went wrong on our side and no order was placed. Try again, or contact us with reference ${String(b.ref).slice(0, 8)}.`,
        )
      } else {
        setBanner(t('Something went wrong. Please try again.', 'حدث خطأ ما. حاول مرة أخرى.'))
      }
    } finally {
      submitting.current = false
      setProcessing(false)
    }
  }, [
    canSubmit, items, paymentMethod, methodId, refetchMethods, notes, total, useInline, street,
    apartment, shippingid, phone, selectedAddressId, isGuest, firstName, lastName, email,
    isRTL, lang, fmt, cleanupAfterOrder, router, setOrderData, shippingName, shippingCost, user, t,
    subtotal,
  ])

  const handleSignOut = useCallback(() => {
    logout()
    router.push('/login')
  }, [logout, router])

  const err = (k) => errors[k]?.[0]

  // ── Empty cart guard ─────────────────────────────────────────────────────
  if (items.length === 0) {
    return (
      <div className="wz-checkout" dir={isRTL ? 'rtl' : 'ltr'}>
        <div className="wz-checkout-inner" style={{ textAlign: 'center', paddingTop: 40 }}>
          <h1 className="wz-checkout-title">{t('Your cart is empty', 'سلة التسوق فارغة')}</h1>
          <p className="wz-checkout-sub">{t('Add items before checking out.', 'أضف منتجات قبل إتمام الطلب.')}</p>
          <button className="wz-checkout-place" style={{ maxWidth: 280, margin: '0 auto' }} onClick={() => router.push('/listing')}>
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
            {banner && (
              <div className="wz-checkout-banner" role="alert">
                {banner}
                {bannerToCart && (
                  <>
                    {' '}
                    <Link href="/cart" className="bf-co-banner-link">{t('Review your cart', 'راجع سلتك')}</Link>
                  </>
                )}
              </div>
            )}
            {/* the phone's summary, collapsible at the top (§10.5): the item count and the total */}
            <details className="bf-co-mini">
              <summary>
                <span>
                  {t('Order summary', 'ملخص الطلب')} ({items.reduce((n, i) => n + i.quantity, 0)})
                </span>
                <strong>{fmt(total)}</strong>
              </summary>
              <ul className="bf-co-mini-list">
                {items.map((i) => (
                  <li key={i.id}>
                    <span>
                      {txt(i.product?.title, lang) || '—'}
                      {i.variant?.label ? ` · ${i.variant.label}` : ''} × {i.quantity}
                    </span>
                    <span>{fmt(Number(i.piece_price) * i.quantity)}</span>
                  </li>
                ))}
              </ul>
            </details>

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
                    <Link href="/login">{t('Sign in', 'تسجيل الدخول')}</Link>
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

              <div className="wz-checkout-pay" role="radiogroup" aria-label={t('Payment', 'طريقة الدفع')}>
                {methodsLoading ? (
                  <div className="wz-checkout-payopt is-loading" aria-busy="true">
                    <div className="wz-checkout-payopt-main">
                      <div className="wz-checkout-payopt-note">{t('Loading payment methods…', 'جارٍ تحميل طرق الدفع…')}</div>
                    </div>
                  </div>
                ) : onlineListFailed || offered.length === 0 ? (
                  <div className="wz-checkout-payfail" role="alert">
                    <p>
                      {onlineListFailed
                        ? t("We couldn't load the payment methods.", 'تعذّر تحميل طرق الدفع.')
                        : t('No payment method is available right now.', 'لا توجد طريقة دفع متاحة الآن.')}
                    </p>
                    <button type="button" className="wz-checkout-link" onClick={() => refetchMethods()}>
                      {t('Try again', 'حاول مرة أخرى')}
                    </button>
                  </div>
                ) : (
                  offered.map((m) => {
                    const Icon = METHOD_ICON[m.method] || null
                    const note = METHOD_NOTE[m.method]
                    const active = methodId === m.id
                    return (
                      <label key={m.id} className={`wz-checkout-payopt${active ? ' is-active' : ''}${m.reason ? ' is-disabled' : ''}`}>
                        <input
                          type="radio"
                          name="payment"
                          value={`method-${m.id}`}
                          className="bf-co-radio"
                          disabled={Boolean(m.reason)}
                          checked={active}
                          onChange={() => {
                            if (m.reason) return
                            setPaymentMethod(m.kind)
                            setMethodId(m.id)
                          }}
                        />
                        <span className="wz-checkout-dot" aria-hidden="true" />
                        <div className="wz-checkout-payopt-main">
                          <div className="wz-checkout-payopt-title">
                            {(isRTL ? m.label?.ar : m.label?.en) || m.label?.ar || m.label?.en}
                            {Icon ? (
                              <span className="wz-checkout-cards" aria-hidden="true">
                                <Icon />
                              </span>
                            ) : null}
                          </div>
                          <div className="wz-checkout-payopt-note">
                            {m.reason || (note ? t(note[0], note[1]) : t('Secure online payment', 'دفع إلكتروني آمن'))}
                          </div>
                        </div>
                      </label>
                    )
                  })
                )}
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
