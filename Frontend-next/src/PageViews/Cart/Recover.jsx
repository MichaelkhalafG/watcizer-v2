'use client'
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import Link from '@/src/Components/LocaleLink'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { useUIStore } from '@/src/Store/uiStore'
import useCart from '@/src/Hooks/useCart'
import http from '@/src/Context/api'
import { trustSignals } from '@/src/config/trust.config'
import './Recover.css'

// /cart/recover?t=… — bring back a card order that expired unpaid (2026-10-05, version B).
//
// The link in the "your order wasn't completed" e-mail lands here. Core answers with the order's lines
// at TODAY's price and stock and the details the shopper typed (OrderRecovery). This page shows what
// will come back and what changed — sold out, fewer left, a new price, slower delivery — BEFORE
// anything is added, so nothing is discovered at payment. "Continue" adds the lines through the
// ordinary add-to-cart route (stock and price re-checked like any add), hands the details to checkout
// for this tab only, and opens checkout. A line already in the cart is left as it is.

const money = (n) => `EGP ${Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 })}`

export default function Recover() {
  const params = useSearchParams()
  const token = params.get('t') || ''
  const router = useRouter()
  const { language } = useUIStore()
  const ar = language === 'ar'
  const t = (en, arText) => (ar ? arText : en)
  const { cart, addItem } = useCart()
  const [state, setState] = useState({ status: token ? 'loading' : 'invalid' })
  const [busy, setBusy] = useState(false)
  const [problems, setProblems] = useState([])

  useEffect(() => {
    if (!token) return
    let live = true
    http
      .post('cart/recover', { token })
      .then(({ data }) => live && setState({ status: 'ready', recovery: data.recovery }))
      .catch((e) => {
        if (!live) return
        const reason = e?.response?.data?.refused
        setState({ status: ['expired', 'invalid', 'reordered'].includes(reason) ? reason : 'error' })
      })
    return () => {
      live = false
    }
  }, [token])

  const inCart = useMemo(() => new Set((cart?.cart_item || []).map((i) => String(i.product_id))), [cart])
  const lines = state.recovery?.lines || []
  const addable = lines.filter((l) => !l.state.includes('sold_out') && !l.state.includes('gone'))
  const nameOf = (l) => (ar ? l.name?.ar || l.name?.en : l.name?.en || l.name?.ar) || t('A product', 'منتج')

  const notes = (l) => {
    const out = []
    if (l.state.includes('gone')) out.push(t('No longer available — not added.', 'لم يعد متاحاً — لن يُضاف.'))
    if (l.state.includes('sold_out')) out.push(t('Sold out — not added.', 'نفد من المخزون — لن يُضاف.'))
    if (l.state.includes('reduced'))
      out.push(t(`Only ${l.available} left — ${l.available} will be added.`, `المتاح ${l.available} فقط — سيُضاف ${l.available}.`))
    if (l.state.includes('price_changed'))
      out.push(t(`The price has changed: it was ${money(l.quoted_price)}, it is now ${money(l.price)}.`, `تغيّر السعر: كان ${money(l.quoted_price)} وأصبح ${money(l.price)}.`))
    // A deliberate exception to "never show the shopper the stock type" (developer, 2026-10-05): this
    // item changed AFTER they ordered it, and they are entitled to know before they pay again. Keep it.
    if (l.state.includes('slower_delivery'))
      out.push(t('Now ships as Market: 4–7 business days.', 'يُشحن الآن كمنتج ماركت: ٤–٧ أيام عمل.'))
    if (!l.state.includes('gone') && !l.state.includes('sold_out') && inCart.has(String(l.product_id)))
      out.push(t('Already in your cart — left as it is.', 'موجود في سلتك بالفعل — سيبقى كما هو.'))
    return out
  }

  const proceed = async () => {
    setBusy(true)
    setProblems([])
    const failed = []
    for (const l of addable) {
      if (inCart.has(String(l.product_id))) continue
      const result = await addItem({
        product_id: l.product_id,
        quantity: Math.min(l.quantity, l.available),
        piece_price: l.price,
        type_stock: l.type_stock,
        color_band: l.color_band,
        color_dial: l.color_dial,
        name: l.name?.en || undefined,
      })
      if (result && result.ok === false) failed.push(`${nameOf(l)}: ${result.message || t('could not be added.', 'تعذّرت إضافته.')}`)
    }
    try {
      sessionStorage.setItem('wz_checkout_prefill', JSON.stringify(state.recovery.details))
    } catch {
      // A browser that refuses storage only loses the prefill; the cart is already filled.
    }
    setBusy(false)
    if (failed.length) {
      setProblems(failed)
      return
    }
    router.push('/checkout')
  }

  return (
    <main className="wz-recover" dir={ar ? 'rtl' : 'ltr'}>
      {state.status === 'loading' ? <p className="wz-recover__muted">{t('Loading your order…', 'جاري تحميل طلبك…')}</p> : null}

      {state.status === 'expired' || state.status === 'invalid' || state.status === 'error' ? (
        <section className="wz-recover__card">
          <h1>{state.status === 'expired' ? t('This link has expired', 'انتهت صلاحية هذا الرابط') : t('This link does not work', 'هذا الرابط لا يعمل')}</h1>
          <p>
            {state.status === 'expired'
              ? t('Recovery links work for 7 days. The products may still be in the shop.', 'روابط الاستعادة صالحة ٧ أيام. قد تكون المنتجات لا تزال في المتجر.')
              : state.status === 'invalid'
                ? t('It may be incomplete, or the order can no longer be brought back.', 'ربما الرابط غير مكتمل، أو لم يعد ممكناً استعادة الطلب.')
                : t('We could not load your order just now. Please try again in a moment.', 'تعذّر تحميل طلبك الآن. حاول مرة أخرى بعد قليل.')}
          </p>
          <Link href="/listing" className="wz-recover__btn wz-recover__btn--ghost">
            {t('Browse the shop', 'تصفح المتجر')}
          </Link>
        </section>
      ) : null}

      {/* The same shopper ordered again since the link was sent, and that order went through
          (core: OrderRecovery::reorderedSince). Rebuilding the cart here is how the same thing gets
          ordered twice, so the page says what happened and where to ask instead. */}
      {state.status === 'reordered' ? (
        <section className="wz-recover__card">
          <h1>{t('Your order has already gone through', 'تم تنفيذ طلبك بالفعل')}</h1>
          <p>
            {t(
              "We received an order from you after this link was sent, and it went through — so there's nothing to bring back here. If you think that isn't right, contact customer service on WhatsApp.",
              'استلمنا منك طلباً بعد إرسال هذا الرابط وتم تنفيذه، لذلك لا يوجد ما يمكن استرجاعه هنا. إن كنت ترى أن في ذلك خطأ، فتواصل مع خدمة العملاء عبر واتساب.',
            )}
          </p>
          <div className="wz-recover__actions">
            <a href={trustSignals.whatsapp.href} target="_blank" rel="noopener noreferrer" className="wz-recover__btn">
              {t('Contact customer service', 'تواصل مع خدمة العملاء')}
            </a>
            <Link href="/listing" className="wz-recover__btn wz-recover__btn--ghost">
              {t('Browse the shop', 'تصفح المتجر')}
            </Link>
          </div>
        </section>
      ) : null}

      {state.status === 'ready' ? (
        <section className="wz-recover__card">
          <span className="wz-recover__label">{t(`Order #${state.recovery.order_number}`, `طلب رقم #${state.recovery.order_number}`)}</span>
          <h1>{t('Bring your order back', 'استرجع طلبك')}</h1>
          <p className="wz-recover__muted">
            {t(
              "These are the items from your order, at today's prices. Anything that changed is marked below.",
              'هذه منتجات طلبك بأسعار اليوم. ما تغيّر منها موضح أدناه.',
            )}
          </p>
          <ul className="wz-recover__lines">
            {lines.map((l, i) => {
              const n = notes(l)
              const off = l.state.includes('gone') || l.state.includes('sold_out')
              return (
                <li key={i} className={off ? 'is-off' : ''}>
                  <div className="wz-recover__line">
                    <span className="wz-recover__name">{nameOf(l)}</span>
                    <span className="wz-recover__qty">× {off ? l.quantity : Math.min(l.quantity, l.available)}</span>
                    <span className="wz-recover__price">{l.price == null ? '—' : money(l.price)}</span>
                  </div>
                  {n.map((text) => (
                    <p key={text} className={off ? 'wz-recover__note is-bad' : 'wz-recover__note'}>
                      {text}
                    </p>
                  ))}
                </li>
              )
            })}
          </ul>
          {problems.length ? (
            <div className="wz-recover__problems" role="alert">
              {problems.map((p) => (
                <p key={p}>{p}</p>
              ))}
              <button type="button" className="wz-recover__btn" onClick={() => router.push('/checkout')}>
                {t('Continue to checkout', 'متابعة إتمام الطلب')}
              </button>
            </div>
          ) : addable.length ? (
            <button type="button" className="wz-recover__btn" onClick={proceed} disabled={busy}>
              {busy ? t('Adding…', 'جاري الإضافة…') : t('Add to cart and continue to checkout', 'أضف إلى السلة وتابع إتمام الطلب')}
            </button>
          ) : (
            <>
              <p className="wz-recover__muted">{t('Nothing from this order is available now.', 'لا يتوفر الآن أي منتج من هذا الطلب.')}</p>
              <Link href="/listing" className="wz-recover__btn wz-recover__btn--ghost">
                {t('Browse the shop', 'تصفح المتجر')}
              </Link>
            </>
          )}
          {addable.length && !problems.length ? (
            <p className="wz-recover__small">
              {t('Your details from the order will be filled in at checkout.', 'ستجد بياناتك من الطلب مكتوبة في صفحة إتمام الطلب.')}
            </p>
          ) : null}
        </section>
      ) : null}
    </main>
  )
}
