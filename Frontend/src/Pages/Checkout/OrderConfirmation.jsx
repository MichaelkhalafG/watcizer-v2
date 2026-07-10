import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import DOMPurify from 'dompurify'
import { trackPurchase } from '../../scripts/pixels'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useToastStore } from '../../Store/toastStore'
import http from '../../Context/api'
import './Checkout.css'

// Delivery window from the governorate shipping tier (cost ≈ distance).
const deliveryWindow = (price) => {
  const p = Number(price) || 0
  if (p <= 100) return [1, 2]
  if (p <= 150) return [2, 3]
  if (p <= 200) return [3, 4]
  if (p <= 250) return [4, 5]
  return [5, 7]
}

function OrderConfirmation() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const navigate = useNavigate()
  const { state } = useLocation()
  const { login } = useAuthStore()
  const { showToast } = useToastStore()

  const currency = isRTL ? 'ج.م' : 'EGP'
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')

  const {
    orderNumber,
    email,
    firstName = '',
    lastName = '',
    isGuest = false,
    items = [],
    total = 0,
    shippingName = '',
    shippingPrice = 0,
  } = state || {}

  const [days] = useState(() => deliveryWindow(shippingPrice))

  // ── Guest account creation ───────────────────────────────────────────────
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [acctErr, setAcctErr] = useState('')
  const [creating, setCreating] = useState(false)
  const [created, setCreated] = useState(false)

  const createAccount = useCallback(async () => {
    setAcctErr('')
    if (password.length < 8) {
      setAcctErr(t('Password must be at least 8 characters.', 'كلمة المرور 8 أحرف على الأقل.'))
      return
    }
    if (password !== confirm) {
      setAcctErr(t('Passwords do not match.', 'كلمتا المرور غير متطابقتين.'))
      return
    }
    setCreating(true)
    try {
      const { data } = await http.post('/register', {
        first_name: DOMPurify.sanitize(firstName) || 'Guest',
        last_name: DOMPurify.sanitize(lastName) || 'Customer',
        email: DOMPurify.sanitize((email || '').trim()),
        password,
        password_confirmation: confirm,
      })
      login(data)
      setCreated(true)
      showToast(t('Account created! Your order is now linked.', 'تم إنشاء الحساب! تم ربط طلبك.'), 'success')
    } catch (err) {
      const a = err.response?.data?.errors
      setAcctErr(
        a?.email?.[0] ||
          a?.password?.[0] ||
          t('Could not create account. Please try again.', 'تعذّر إنشاء الحساب. حاول مرة أخرى.'),
      )
    } finally {
      setCreating(false)
    }
  }, [password, confirm, firstName, lastName, email, login, showToast, t])

  // No state (direct hit / refresh) → graceful fallback.
  const hasOrder = useMemo(() => Boolean(orderNumber), [orderNumber])

  // ── Analytics: Purchase (FB) / CompletePayment (TikTok). Deduped per order
  //    inside trackPurchase, so a remount / back-forward never double-counts. ──
  useEffect(() => {
    if (!orderNumber) return
    trackPurchase({
      orderNumber,
      value: total,
      contents: items.map((it) => ({
        id: it.id,
        name: it.name,
        quantity: it.qty,
        price: it.price ?? (it.qty ? Number(it.lineTotal) / it.qty : 0),
      })),
    })
  }, [orderNumber, total, items])

  return (
    <div className="wz-oc" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-oc-card">
        <svg className="wz-oc-check" viewBox="0 0 80 80" aria-hidden="true">
          <circle cx="40" cy="40" r="36" />
          <path d="M24 41 L36 53 L57 30" />
        </svg>

        <h1 className="wz-oc-title">{t('Order Confirmed!', 'تم تأكيد الطلب!')}</h1>
        <p className="wz-oc-thanks">{t('Thank you for your purchase', 'شكرًا لشرائك')}</p>

        {hasOrder ? (
          <>
            <div className="wz-oc-number">
              <span>{t('Order number', 'رقم الطلب')}</span>
              <b>#{orderNumber}</b>
            </div>

            {items.length > 0 && (
              <div className="wz-oc-items">
                {items.map((it, i) => (
                  <div className="wz-oc-item" key={i}>
                    <span>
                      {it.name} <b>×{it.qty}</b>
                    </span>
                    <b>{fmt(it.lineTotal)} {currency}</b>
                  </div>
                ))}
                <div className="wz-oc-item" style={{ marginTop: 4 }}>
                  <b>{t('Total', 'الإجمالي')}</b>
                  <b>{fmt(total)} {currency}</b>
                </div>
              </div>
            )}

            <p className="wz-oc-meta">
              {email && (
                <>
                  {t('Confirmation email sent to', 'تم إرسال تأكيد إلى')} <b>{email}</b>
                  <br />
                </>
              )}
              {shippingName && (
                <>
                  {t('Shipping to', 'الشحن إلى')} <b>{shippingName}</b>
                  <br />
                </>
              )}
              {t('Estimated delivery', 'التوصيل المتوقع')}:{' '}
              <b>
                {days[0]}–{days[1]} {t('business days', 'أيام عمل')}
              </b>
            </p>
          </>
        ) : (
          <p className="wz-oc-meta">
            {t('Your order has been placed successfully.', 'تم تقديم طلبك بنجاح.')}
          </p>
        )}

        <div className="wz-oc-actions">
          <button className="wz-oc-btn wz-oc-btn--dark" onClick={() => navigate('/account?tab=orders')}>
            {t('View Orders', 'عرض الطلبات')}
          </button>
          <button className="wz-oc-btn wz-oc-btn--ghost" onClick={() => navigate('/listing')}>
            {t('Continue Shopping', 'متابعة التسوق')}
          </button>
        </div>

        {/* Guest account creation */}
        {isGuest && hasOrder && !created && (
          <div className="wz-oc-acct">
            <h3 className="wz-oc-acct-title">{t('Create an account', 'أنشئ حسابًا')}</h3>
            <p className="wz-oc-acct-sub">
              {t('Track this order and check out faster next time.', 'تتبّع هذا الطلب وأكمل الشراء أسرع في المرة القادمة.')}
            </p>
            {acctErr && <div className="wz-checkout-banner">{acctErr}</div>}
            <div className="wz-checkout-field">
              <label className="wz-checkout-label">{t('Password', 'كلمة المرور')}<span className="req">*</span></label>
              <input
                type="password"
                className="wz-checkout-input"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                autoComplete="new-password"
              />
            </div>
            <div className="wz-checkout-field">
              <label className="wz-checkout-label">{t('Confirm password', 'تأكيد كلمة المرور')}<span className="req">*</span></label>
              <input
                type="password"
                className="wz-checkout-input"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                placeholder="••••••••"
                autoComplete="new-password"
              />
            </div>
            <button
              className="wz-checkout-place"
              style={{ height: 50 }}
              disabled={creating}
              onClick={createAccount}
            >
              {creating ? (
                <>
                  <span className="wz-checkout-spinner" />
                  {t('CREATING…', 'جاري الإنشاء…')}
                </>
              ) : (
                t('CREATE ACCOUNT', 'إنشاء حساب')
              )}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export default OrderConfirmation
