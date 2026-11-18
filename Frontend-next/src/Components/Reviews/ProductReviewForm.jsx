'use client'
import { useRef, useState } from 'react'
import { API_BASE, STOREFRONT_CODE } from '../../lib/env'
import { deviceId, loadTurnstile, turnstileToken } from '../../lib/turnstile'
import GoogleReviewButtons from './GoogleReviewButtons'

// WRITE A REVIEW, FROM THE PRODUCT PAGE (Michael's decisions, 2026-11-17). A TWIN, under the product's reviews.
//
// No login: a name, an e-mail, stars and words. The e-mail is never shown and the answer is the same whether or not it
// belongs to an account (core attaches the review to one silently). The review is LIVE at once — on the page at its
// next refresh — unless it carries a link or a phone number, when the team checks it first; never held for its stars.
// Protected in the background by Cloudflare Turnstile (invisible: loaded only when the form is opened) and by limits
// per e-mail, device and address. After sending, everyone sees the shop's Google review buttons.
const STARS = [1, 2, 3, 4, 5]
const BODY_MIN = 10

const T = {
  en: {
    open: 'Write a review',
    title: 'Write a review',
    stars: 'Your rating',
    star: (n) => `${n} out of 5`,
    body: 'Your review',
    name: 'Name to show',
    email: 'E-mail',
    emailNote: 'Not shown with your review, and never shared.',
    send: 'Send review',
    sending: 'Sending…',
    needStars: 'Choose a star rating.',
    needBody: 'Write at least a sentence.',
    needName: 'Write the name to show with your review.',
    needEmail: 'Write a valid e-mail.',
    live: 'Thank you! Your review is published and will show on this page in a few minutes.',
    held: 'Thank you! We received your review. It will show on this page after a quick check by our team.',
    already: 'There is already a review from this e-mail for this product.',
    limited: 'Too many reviews for now. Please try again later.',
    check: 'We could not check the form. Please try again.',
    failed: 'We could not send your review just now. Please try again shortly.',
  },
  ar: {
    open: 'اكتب تقييمك',
    title: 'اكتب تقييمك',
    stars: 'تقييمك',
    star: (n) => `${n} من 5`,
    body: 'رأيك',
    name: 'الاسم الذي يظهر',
    email: 'البريد الإلكتروني',
    emailNote: 'لا يظهر مع تقييمك، ولا نشاركه مع أحد.',
    send: 'أرسل التقييم',
    sending: 'جارٍ الإرسال…',
    needStars: 'اختر عدد النجوم.',
    needBody: 'اكتب رأيك في جملة على الأقل.',
    needName: 'اكتب الاسم الذي سيظهر مع تقييمك.',
    needEmail: 'اكتب بريدًا إلكترونيًا صحيحًا.',
    live: 'شكرًا! تم نشر تقييمك، ويظهر في هذه الصفحة خلال دقائق.',
    held: 'شكرًا! وصلنا تقييمك، ويظهر في هذه الصفحة بعد مراجعة سريعة من فريقنا.',
    already: 'يوجد تقييم بهذا البريد لهذا المنتج بالفعل.',
    limited: 'تقييمات كثيرة الآن. حاول مرة أخرى لاحقًا.',
    check: 'تعذر التحقق من النموذج. حاول مرة أخرى.',
    failed: 'تعذر إرسال التقييم الآن. حاول مرة أخرى بعد قليل.',
  },
}

export default function ProductReviewForm({ productId, lang = 'en', form = null }) {
  const t = T[lang === 'ar' ? 'ar' : 'en']
  const siteKey = form?.turnstile_site_key || ''
  const [open, setOpen] = useState(false)
  const [rating, setRating] = useState(0)
  const [body, setBody] = useState('')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [state, setState] = useState('idle') // idle | sending | live | held | already
  const [error, setError] = useState('')
  const [google, setGoogle] = useState(form?.google || [])
  const box = useRef(null)
  if (!productId) return null

  const openForm = () => {
    setOpen(true)
    // the check's script, fetched now that somebody is writing (never on page load)
    if (siteKey) loadTurnstile().catch(() => {})
  }

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    if (rating < 1) return setError(t.needStars)
    if (body.trim().length < BODY_MIN) return setError(t.needBody)
    if (name.trim().length < 2) return setError(t.needName)
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return setError(t.needEmail)
    setState('sending')
    let token = ''
    try {
      token = await turnstileToken(box.current, siteKey, lang)
    } catch {
      setState('idle')
      return setError(t.check)
    }
    try {
      const res = await fetch(`${API_BASE.replace(/\/$/, '')}/v2/${STOREFRONT_CODE}/reviews/guest`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({
          product_id: productId,
          rating,
          body: body.trim(),
          name: name.trim(),
          email: email.trim(),
          locale: lang === 'ar' ? 'ar' : 'en',
          turnstile: token,
          device: deviceId(),
        }),
      })
      if (res.status === 201) {
        const data = await res.json().catch(() => ({}))
        if (Array.isArray(data?.google)) setGoogle(data.google)
        return setState(data?.status === 'held' ? 'held' : 'live')
      }
      setState(res.status === 409 ? 'already' : 'idle')
      if (res.status === 409) return
      setError(res.status === 429 ? t.limited : res.status === 422 ? t.check : t.failed)
    } catch {
      setState('idle')
      setError(t.failed)
    }
  }

  if (state === 'live' || state === 'held' || state === 'already') {
    return (
      <section className="wz-reviews wz-reviews--write" dir={lang === 'ar' ? 'rtl' : 'ltr'} aria-live="polite">
        <p className="wz-review-form__done">{t[state]}</p>
        {state !== 'already' ? <GoogleReviewButtons google={google} lang={lang} /> : null}
      </section>
    )
  }

  return (
    <section className="wz-reviews wz-reviews--write" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      {!open ? (
        <button type="button" className="wz-review-form__open" onClick={openForm} aria-expanded="false">
          {t.open}
        </button>
      ) : (
        <form className="wz-review-form" onSubmit={submit} noValidate>
          <h2 className="wz-reviews__title">{t.title}</h2>
          <fieldset className="wz-review-form__stars">
            <legend>{t.stars}</legend>
            {STARS.map((n) => (
              <button key={n} type="button" className={n <= rating ? 'is-on' : ''} aria-pressed={n <= rating} aria-label={t.star(n)} onClick={() => setRating(n)}>
                ★
              </button>
            ))}
          </fieldset>
          <label>
            {t.body}
            <textarea rows={4} maxLength={2000} value={body} onChange={(e) => setBody(e.target.value)} dir="auto" required />
          </label>
          <label>
            {t.name}
            <input type="text" maxLength={60} autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} dir="auto" required />
          </label>
          <label>
            {t.email}
            <input type="email" maxLength={191} autoComplete="email" inputMode="email" value={email} onChange={(e) => setEmail(e.target.value)} dir="ltr" required />
            <span className="wz-review-form__hint">{t.emailNote}</span>
          </label>
          {/* the invisible check renders here (nothing is drawn unless Cloudflare needs to ask) */}
          <div ref={box} className="wz-review-form__check" />
          {error ? (
            <p className="wz-review-form__error" role="alert">
              {error}
            </p>
          ) : null}
          <button className="wz-review-form__submit" type="submit" disabled={state === 'sending'}>
            {state === 'sending' ? t.sending : t.send}
          </button>
        </form>
      )}
    </section>
  )
}
