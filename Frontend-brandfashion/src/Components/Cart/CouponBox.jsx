'use client'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { cartStore } from '../../Store/cartStore'
import { couponNotice, setCouponNotice, setStoredCoupon, storedCoupon, subscribeCoupon } from '../../lib/coupon'
import usePromotion from '../../Hooks/usePromotion'
import './CouponBox.css'

/*
 * The coupon field of the cart and the checkout (M1u, 2026-10-30). A TWIN.
 *
 * Apply keeps the code and re-reads the cart; core answers with the code's status and what it takes off, in both
 * languages. A code core refuses is forgotten at once (so it never rides along to the order) and its sentence stays
 * on screen; "remove" forgets it and re-reads. Nothing here computes money. The kept code and the refusal live in
 * the small store in src/lib/coupon.js, read with useSyncExternalStore — the server render has no code.
 */
const noCode = () => null

export default function CouponBox({ isRTL }) {
  const promotion = usePromotion()
  const kept = useSyncExternalStore(subscribeCoupon, storedCoupon, noCode)
  const notice = useSyncExternalStore(subscribeCoupon, couponNotice, noCode)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)

  const coupon = promotion.coupon
  const answered = Boolean(kept && coupon && coupon.code.toLowerCase() === kept.toLowerCase())
  const applied = answered && coupon.status === 'applied'
  const refused = answered && coupon.status !== 'applied'

  // a refused code is dropped from the kept store (an external store, not React state) and its reason kept
  useEffect(() => {
    if (!refused) return
    setCouponNotice({ messages: coupon.messages })
    setStoredCoupon('')
  }, [refused, coupon])

  const apply = async (event) => {
    event.preventDefault()
    const value = code.trim()
    if (value === '' || busy) return
    setBusy(true)
    setCouponNotice(null)
    setStoredCoupon(value)
    try {
      await cartStore.fetchCart()
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    setStoredCoupon('')
    setCouponNotice(null)
    setCode('')
    await cartStore.fetchCart()
  }

  // no field until core answers with a promotion block: an older core cannot price a code (src/lib/coupon.js)
  if (!promotion.available) return null

  const text = notice ? (isRTL ? notice.messages.ar : notice.messages.en) || '' : ''

  return (
    <div className="wz-coupon" dir={isRTL ? 'rtl' : 'ltr'}>
      {applied ? (
        <div className="wz-coupon__applied" role="status">
          <span>
            {isRTL ? 'تم تطبيق الكود' : 'Code applied'} <bdi dir="ltr">{kept}</bdi>
          </span>
          <button type="button" className="wz-coupon__remove" onClick={remove}>
            {isRTL ? 'إزالة' : 'Remove'}
          </button>
        </div>
      ) : (
        <form className="wz-coupon__form" onSubmit={apply}>
          <label htmlFor="wz-coupon-code" className="wz-coupon__label">
            {isRTL ? 'كود الخصم' : 'Discount code'}
          </label>
          <div className="wz-coupon__row">
            <input
              id="wz-coupon-code"
              className="wz-coupon__input"
              dir="ltr"
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              maxLength={40}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder={isRTL ? 'اكتب الكود' : 'Enter code'}
            />
            <button type="submit" className="wz-coupon__apply" disabled={busy || code.trim() === ''}>
              {busy ? (isRTL ? 'جارٍ التحقق…' : 'Checking…') : isRTL ? 'تطبيق' : 'Apply'}
            </button>
          </div>
        </form>
      )}
      {!applied && text ? (
        <p className="wz-coupon__message wz-coupon__message--error" role="alert">
          {text}
        </p>
      ) : null}
    </div>
  )
}
