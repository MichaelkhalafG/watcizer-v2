'use client'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import DOMPurify from 'dompurify'
import { trackPurchase } from '../../scripts/pixels'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useToastStore } from '../../Store/toastStore'
import { useOrderConfirmStore } from '../../Store/orderConfirmStore'
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

/*
 * ── Two ways in (2026-10-12) ──────────────────────────────────────────────────────────────────
 *
 * CASH: Checkout places the order and pushes here with the full order in the Zustand store —
 * items, totals, the shopper's e-mail — and the page renders a receipt-like summary.
 *
 * CARD: the shopper went to Paymob with a full page navigation, so the store is gone. The payment
 * callback sends their browser back here with `?order=<number>&status=<paid|failed|pending>`, and
 * that query is ALL the page knows. Before this the callback sent them to the site root — with
 * `?payment_error=1` on failure, which nothing read — or, on the normal path, answered the browser
 * with raw JSON on a blank page. The three statuses are decided by core's payment POLICY, never by
 * the provider's own claim, and they mean:
 *
 *   paid     the order is settled; the confirmation e-mail is queued.
 *   pending  money may have moved but the order was not settled by this callback — the amount
 *            disagreed, the order was already closed, the attempt could not be written, or the
 *            provider itself reports the payment as still pending. The page says "being confirmed"
 *            and that they will hear by e-mail. It must never read as "failed" (a card may have
 *            been charged) and never invite a second payment.
 *   failed   the payment did not go through, or the callback was refused before any order could
 *            be proven (then there is no number). The order is not confirmed. This is the one
 *            outcome with NO e-mail behind it, so this page is the only feedback that exists.
 *
 * What this page is NOT: it renders whatever the query says. It is a confirmation MESSAGE, not a
 * receipt and not proof of payment — it fetches nothing and verifies nothing, so a hand-typed URL
 * fools only its author. Support reads the ORDER, never this page. And it fires NO Purchase event
 * on the card path: that needs an event id matching the one the callback already sends
 * server-side, or card sales count twice — a separate decision (developer, 2026-10-12).
 */
const CARD_STATUSES = ['paid', 'failed', 'pending']

function OrderConfirmation() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const router = useRouter()
  const params = useSearchParams()
  // The placed order is handed over via the Zustand store (Next router.push can't
  // carry navigation state). Clear it when leaving so a back-nav shows no stale order.
  const state = useOrderConfirmStore((s) => s.orderData)
  // The card landing: only when the store has nothing (a cash order never carries the query).
  const cardStatus = !state && CARD_STATUSES.includes(params.get('status')) ? params.get('status') : null
  const cardOrder = cardStatus ? (params.get('order') || '').trim() : ''
  const clearOrderData = useOrderConfirmStore((s) => s.clearOrderData)
  useEffect(() => () => clearOrderData(), [clearOrderData])
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
    // The MERCHANDISE value, handed over separately by Checkout for the Purchase pixel event.
    // Falls back to `total` only for an order placed before this field existed.
    subtotal = null,
    shippingName = '',
    shippingPrice = 0,
    paymentMethod = 'cash',
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

  /*
   * ── Analytics: Purchase (FB) / CompletePayment (TikTok) ───────────────────────
   *
   * CASH ON DELIVERY ONLY. A card order now DOES reach this component — the payment callback
   * lands the shopper here with `?order=…&status=…` (2026-10-12) — but it arrives with an empty
   * store, so `orderNumber` is unset and the first guard holds; the second is the stated rule. A
   * Purchase for a card sale is sent SERVER-SIDE by the callback (Meta CAPI); firing one here as
   * well would need a matching event id or every card sale counts twice. Not part of this page
   * (developer decision, 2026-10-12): do not fold it in without asking.
   *
   * Deduped per order number inside trackPurchase, and that record now survives a reload, so a
   * refresh of this page does not report the sale twice.
   */
  useEffect(() => {
    if (!orderNumber) return
    if (paymentMethod !== 'cash') return
    trackPurchase({
      orderNumber,
      /*
       * MERCHANDISE value, not `total` — `total` includes shipping, and shipping is the courier's
       * share rather than a conversion value. It also has to equal what `InitiateCheckout`
       * reported on the previous page, or the funnel shows every order growing by its delivery
       * cost between the two steps (review 🟠 minor: Checkout's comment claimed this was already
       * true while this line was still sending `total`).
       */
      value: subtotal ?? total,
      contents: items.map((it) => ({
        id: it.id,
        name: it.name,
        quantity: it.qty,
        price: it.price ?? (it.qty ? Number(it.lineTotal) / it.qty : 0),
      })),
    })
  }, [orderNumber, total, subtotal, items, paymentMethod])

  if (cardStatus) {
    return <CardLanding status={cardStatus} orderNumber={cardOrder} t={t} isRTL={isRTL} router={router} />
  }

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
          <button className="wz-oc-btn wz-oc-btn--dark" onClick={() => router.push('/account?tab=orders')}>
            {t('View Orders', 'عرض الطلبات')}
          </button>
          <button className="wz-oc-btn wz-oc-btn--ghost" onClick={() => router.push('/listing')}>
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

/*
 * The card landing. One sentence per status, both languages, no amounts (the page has none and
 * must not invent them), and the order number only when the callback proved which order it was.
 *
 * `failed` is weighted heaviest: on success the shopper also gets the confirmation e-mail, so this
 * page adds immediacy; on failure NOTHING else is sent, so this is all they will ever see. It says
 * the order is not confirmed, and — because a callback can be refused after a card was charged —
 * what to do if money left their account. `pending` never says "failed" and never suggests paying
 * again.
 */
function CardLanding({ status, orderNumber, t, isRTL, router }) {
  const copy = {
    paid: {
      icon: 'M24 41 L36 53 L57 30',
      title: t('Order Confirmed!', 'تم تأكيد الطلب!'),
      sub: t('Thank you for your purchase', 'شكرًا لشرائك'),
      body: t(
        'Your payment was received. A confirmation email is on its way to you.',
        'تم استلام الدفع. رسالة التأكيد في طريقها إليك.',
      ),
    },
    pending: {
      icon: 'M40 22 V40 L51 47',
      title: t('Payment being confirmed', 'جارٍ تأكيد الدفع'),
      sub: t('Thank you for your order', 'شكرًا لطلبك'),
      body: t(
        'Your payment is being confirmed. We will email you as soon as it is confirmed — you do not need to pay again.',
        'جارٍ تأكيد عملية الدفع. سنرسل إليك بريدًا إلكترونيًا فور تأكيدها — لا حاجة لإعادة الدفع.',
      ),
    },
    failed: {
      icon: 'M29 29 L51 51 M51 29 L29 51',
      title: t('Payment not completed', 'لم تكتمل عملية الدفع'),
      sub: t('Your order has not been confirmed', 'لم يتم تأكيد طلبك'),
      body: t(
        'Your payment did not go through, so the order was not confirmed. If your card was charged, contact us with your order number and we will sort it out.',
        'لم تكتمل عملية الدفع، لذلك لم يتم تأكيد الطلب. إذا تم خصم المبلغ من بطاقتك، فتواصل معنا مع ذكر رقم الطلب وسنتولى الأمر.',
      ),
    },
  }[status]

  return (
    <div className="wz-oc" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-oc-card">
        <svg className={`wz-oc-check wz-oc-check--${status}`} viewBox="0 0 80 80" aria-hidden="true">
          <circle cx="40" cy="40" r="36" />
          <path d={copy.icon} />
        </svg>

        <h1 className="wz-oc-title">{copy.title}</h1>
        <p className="wz-oc-thanks">{copy.sub}</p>

        {orderNumber && (
          <div className="wz-oc-number">
            <span>{t('Order number', 'رقم الطلب')}</span>
            <b>#{orderNumber}</b>
          </div>
        )}

        <p className="wz-oc-meta">{copy.body}</p>

        <div className="wz-oc-actions">
          {status === 'failed' ? (
            <button className="wz-oc-btn wz-oc-btn--dark" onClick={() => router.push('/contact-us')}>
              {t('Contact us', 'تواصل معنا')}
            </button>
          ) : (
            <button className="wz-oc-btn wz-oc-btn--dark" onClick={() => router.push('/account?tab=orders')}>
              {t('View Orders', 'عرض الطلبات')}
            </button>
          )}
          <button className="wz-oc-btn wz-oc-btn--ghost" onClick={() => router.push('/listing')}>
            {t('Continue Shopping', 'متابعة التسوق')}
          </button>
        </div>
      </div>
    </div>
  )
}

export default OrderConfirmation
