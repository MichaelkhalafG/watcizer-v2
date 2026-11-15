'use client'
import { useState } from 'react'
import { API_BASE, STOREFRONT_CODE } from '@/src/lib/env'

// ONE product's review form on the review page (SEO Phase 5, 2026-11-14) — a TWIN. Stars, words, the name to show and
// up to three photos, sent as one multipart POST to core (POST /api/v2/{shop}/reviews), which saves it PENDING for the
// team's approval. A product already reviewed from this order shows as done.
const MAX_PHOTOS = 3

export default function ReviewForm({ token, product, lang = 'en' }) {
  const ar = lang === 'ar'
  const [rating, setRating] = useState(0)
  const [body, setBody] = useState('')
  const [name, setName] = useState('')
  const [photos, setPhotos] = useState([])
  const [state, setState] = useState(product.reviewed ? 'done' : 'idle')
  const [error, setError] = useState('')
  const title = (ar ? product.title?.ar : product.title?.en) || product.title?.en || product.title?.ar || ''

  if (state === 'done') {
    return (
      <div className="wz-review-form">
        <div className="wz-review-form__product">
          {product.image ? <img src={product.image} alt="" /> : null}
          <strong>{title}</strong>
        </div>
        <p className="wz-review-form__done">{ar ? 'شكراً! وصلنا تقييمك لهذا المنتج.' : 'Thank you! We have your review of this product.'}</p>
      </div>
    )
  }

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    if (rating < 1) return setError(ar ? 'اختر عدد النجوم.' : 'Choose a star rating.')
    if (body.trim().length < 10) return setError(ar ? 'اكتب رأيك في جملة على الأقل.' : 'Write at least a sentence.')
    if (!name.trim()) return setError(ar ? 'اكتب الاسم الذي سيظهر مع تقييمك.' : 'Write the name to show with your review.')
    const form = new FormData()
    form.append('token', token)
    form.append('product_id', String(product.id))
    form.append('rating', String(rating))
    form.append('body', body.trim())
    form.append('name', name.trim())
    form.append('locale', ar ? 'ar' : 'en')
    photos.slice(0, MAX_PHOTOS).forEach((f) => form.append('photos[]', f))
    setState('sending')
    try {
      const res = await fetch(`${API_BASE.replace(/\/$/, '')}/v2/${STOREFRONT_CODE}/reviews`, { method: 'POST', body: form, headers: { Accept: 'application/json' } })
      if (res.status === 201 || res.status === 409) return setState('done')
      setState('idle')
      setError(
        res.status === 422
          ? ar
            ? 'راجع البيانات: الصور حتى 3 صور JPG أو PNG أو WebP، والنص حتى 2000 حرف.'
            : 'Please check the form: up to 3 JPG, PNG or WebP photos, and up to 2,000 characters.'
          : ar
            ? 'تعذر إرسال التقييم الآن. حاول مرة أخرى بعد قليل.'
            : 'We could not send your review just now. Please try again shortly.',
      )
    } catch {
      setState('idle')
      setError(ar ? 'تعذر الاتصال. حاول مرة أخرى.' : 'Could not connect. Please try again.')
    }
  }

  return (
    <form className="wz-review-form" onSubmit={submit}>
      <div className="wz-review-form__product">
        {product.image ? <img src={product.image} alt="" /> : null}
        <strong>{title}</strong>
      </div>
      <fieldset className="wz-review-form__stars" aria-label={ar ? 'التقييم بالنجوم' : 'Star rating'}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            type="button"
            className={n <= rating ? 'is-on' : ''}
            aria-pressed={n <= rating}
            aria-label={ar ? `${n} من 5` : `${n} out of 5`}
            onClick={() => setRating(n)}
          >
            ★
          </button>
        ))}
      </fieldset>
      <label>
        {ar ? 'رأيك' : 'Your review'}
        <textarea rows={4} maxLength={2000} value={body} onChange={(e) => setBody(e.target.value)} dir="auto" />
      </label>
      <label>
        {ar ? 'الاسم الذي يظهر' : 'Name to show'}
        <input type="text" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} dir="auto" />
      </label>
      <label>
        {ar ? 'صور (اختياري، حتى 3)' : 'Photos (optional, up to 3)'}
        <input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e) => setPhotos(Array.from(e.target.files || []).slice(0, MAX_PHOTOS))} />
      </label>
      {error ? <p className="wz-review-form__error">{error}</p> : null}
      <button className="wz-review-form__submit" type="submit" disabled={state === 'sending'}>
        {state === 'sending' ? (ar ? 'جارٍ الإرسال…' : 'Sending…') : ar ? 'أرسل التقييم' : 'Send review'}
      </button>
    </form>
  )
}
