'use client'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { cartStore } from '../../Store/cartStore'
import { couponNotice, isHeldRefusal, setCouponNotice, setStoredCoupon, storedCoupon, subscribeCoupon, wheelNote } from '../../lib/coupon'
import usePromotion from '../../Hooks/usePromotion'
import './CouponBox.css'

/*
 * The coupon field of the cart and the checkout (M1u, 2026-10-30). A TWIN.
 *
 * Apply keeps the code and re-reads the cart; core answers with the code's status and what it takes off, in both
 * languages. A code core refuses is forgotten at once (so it never rides along to the order) and its sentence stays
 * on screen; "remove" forgets it and re-reads. Nothing here computes money. The kept code and the refusal live in
 * the small store in src/lib/coupon.js, read with useSyncExternalStore — the server render has no code.
 *
 * A WHEEL CODE (2026-11-07) is bound to the phone it was won with: under an applied one the field says so, with the
 * number masked; when the checkout's typed phone is another number core answers `phone_mismatch` and the field keeps
 * the code and shows that sentence (the shopper fixes the number, or removes the code) — a code is forgotten only
 * for the refusals that no change on this page can cure. The same for an invite used with the winner's own number or
 * a number that ordered before, and for a scoped code with nothing eligible in the cart (src/lib/coupon.js
 * isHeldRefusal, 2026-11-09): the shopper fixes the number or the cart, and the next cart read applies the code.
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
  // the phone or the cart can still be put right on this page: the code stays, the sentence shows
  const mismatch = answered && isHeldRefusal(coupon)
  const refused = answered && coupon.status !== 'applied' && !mismatch
  const wheel = answered ? wheelNote(coupon, isRTL) : ''

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
  const mismatchText = mismatch ? (isRTL ? coupon.messages?.ar : coupon.messages?.en) || '' : ''

  return (
    <div className="wz-coupon" dir={isRTL ? 'rtl' : 'ltr'}>
      {applied || mismatch ? (
        <div className={`wz-coupon__applied${mismatch ? ' wz-coupon__applied--held' : ''}`} role="status">
          <span>
            {applied ? (isRTL ? 'تم تطبيق الكود' : 'Code applied') : isRTL ? 'الكود' : 'Code'} <bdi dir="ltr">{kept}</bdi>
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
      {mismatch && mismatchText ? (
        <p className="wz-coupon__message wz-coupon__message--error" role="alert">
          {mismatchText}
        </p>
      ) : null}
      {applied && wheel ? (
        <p className="wz-coupon__message wz-coupon__message--note" role="note">
          {wheel}
        </p>
      ) : null}
      {!applied && !mismatch && text ? (
        <p className="wz-coupon__message wz-coupon__message--error" role="alert">
          {text}
        </p>
      ) : null}
    </div>
  )
}
