'use client'
import { useState } from 'react'
import http from '../../Context/api'
import { useAuthStore } from '../../Store/authStore'
import './StockAlertForm.css'

// "E-mail me when it's back" (2026-10-01) — shown under the disabled "Out of stock" button on an
// out-of-stock product page. A signed-in customer gets ONE button (core uses their account's
// address); a guest types an address. Afterwards it says plainly that an e-mail will come.
// Core: POST /api/stock-alerts → { status: subscribed | already | in_stock }.
export default function StockAlertForm({ productId, isRTL }) {
  const signedIn = useAuthStore((s) => s.isAuthenticated)
  const [email, setEmail] = useState('')
  const [state, setState] = useState('idle') // idle | sending | done | in_stock | error
  const [error, setError] = useState('')
  const t = (ar, en) => (isRTL ? ar : en)

  const submit = async (e) => {
    e?.preventDefault()
    if (state === 'sending') return
    if (!signedIn && !/^\S+@\S+\.\S+$/.test(email.trim())) {
      setError(t('اكتب بريداً إلكترونياً صحيحاً.', 'Enter a valid e-mail address.'))
      return
    }
    setError('')
    setState('sending')
    try {
      const { data } = await http.post('stock-alerts', {
        product_id: productId,
        ...(signedIn ? {} : { email: email.trim() }),
        locale: isRTL ? 'ar' : 'en',
      })
      setState(data?.status === 'in_stock' ? 'in_stock' : 'done')
    } catch (err) {
      const status = err?.response?.status
      setState('error')
      setError(
        status === 422
          ? t('اكتب بريداً إلكترونياً صحيحاً.', 'Enter a valid e-mail address.')
          : status === 429
            ? t('محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.', 'Too many tries. Wait a minute and try again.')
            : t('تعذّر الحفظ. حاول مرة أخرى.', "Couldn't save that. Please try again."),
      )
    }
  }

  if (state === 'done') {
    return (
      <div className="wz-stock-alert wz-stock-alert--done" role="status" dir={isRTL ? 'rtl' : 'ltr'}>
        <strong>{t('تم! سنراسلك عند توفره.', "Done — we'll e-mail you when it's back.")}</strong>
        <span>
          {t(
            'ستصلك رسالة واحدة على بريدك عندما يعود هذا المنتج للمخزون.',
            'You will get one e-mail when this product is back in stock.',
          )}
        </span>
      </div>
    )
  }

  if (state === 'in_stock') {
    return (
      <div className="wz-stock-alert wz-stock-alert--done" role="status" dir={isRTL ? 'rtl' : 'ltr'}>
        <strong>{t('عاد متوفراً للتو!', "It's just come back in stock!")}</strong>
        <button type="button" className="wz-stock-alert__link" onClick={() => window.location.reload()}>
          {t('حدّث الصفحة لإضافته إلى السلة', 'Reload the page to add it to your cart')}
        </button>
      </div>
    )
  }

  return (
    <form className="wz-stock-alert" onSubmit={submit} dir={isRTL ? 'rtl' : 'ltr'} noValidate>
      <p className="wz-stock-alert__title">
        {t('نفد هذا المنتج حالياً', 'This product is out of stock')}
      </p>
      <p className="wz-stock-alert__hint">
        {signedIn
          ? t('نراسلك على بريد حسابك عندما يعود.', "We'll e-mail your account address when it's back.")
          : t('اترك بريدك ونراسلك عندما يعود.', "Leave your e-mail and we'll tell you when it's back.")}
      </p>
      <div className="wz-stock-alert__row">
        {!signedIn && (
          <input
            type="email"
            inputMode="email"
            autoComplete="email"
            dir="ltr"
            className="wz-stock-alert__input"
            placeholder={t('بريدك الإلكتروني', 'Your e-mail')}
            aria-label={t('بريدك الإلكتروني', 'Your e-mail')}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        )}
        <button type="submit" className="wz-stock-alert__btn" disabled={state === 'sending'}>
          {state === 'sending'
            ? t('جارٍ الحفظ…', 'Saving…')
            : t('أعلمني عند التوفر', "Notify me when it's back")}
        </button>
      </div>
      {error && (
        <p className="wz-stock-alert__error" role="alert">
          {error}
        </p>
      )}
    </form>
  )
}
