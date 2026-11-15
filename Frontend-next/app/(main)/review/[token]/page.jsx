import serverHttp from '@/src/lib/serverFetch'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { requestLang } from '@/src/lib/requestLang'
import ReviewForm from './ReviewForm'
import '@/src/Components/Reviews/VerifiedReviews.css'

// /review/<token> and /ar/review/<token> — the page a review link opens (SEO Phase 5, 2026-11-14). A TWIN. The products
// of the delivered order behind the link, one form each; never indexed, never cached (each link is one customer's).
// An unknown or expired link shows a short explanation instead (no 404 page: the customer did nothing wrong).
export const dynamic = 'force-dynamic'

export const metadata = { robots: { index: false, follow: false } }

async function productsOf(token) {
  if (!/^[A-Za-z0-9]{40}$/.test(String(token || ''))) return null
  try {
    const { data } = await serverHttp.get(`v2/${STOREFRONT_CODE}/reviews/request/${token}`)
    return Array.isArray(data?.data?.products) ? data.data.products : null
  } catch {
    return null
  }
}

export default async function ReviewPage({ params }) {
  const { token } = await params
  const { urlLang } = await requestLang()
  const ar = urlLang === 'ar'
  const products = await productsOf(token)
  return (
    <main className="wz-review-page" dir={ar ? 'rtl' : 'ltr'}>
      <h1>{ar ? 'اكتب رأيك في طلبك' : 'Review your order'}</h1>
      {products === null ? (
        <p>
          {ar
            ? 'هذا الرابط غير صالح أو انتهت مدته. راسلنا على واتساب وسنرسل لك رابطاً جديداً.'
            : 'This link is not valid or has expired. Message us on WhatsApp and we will send you a new one.'}
        </p>
      ) : (
        <>
          <p>
            {ar
              ? 'رأيك يساعد غيرك على الاختيار. يظهر تقييمك في صفحة المنتج بعد مراجعته من فريقنا.'
              : 'Your opinion helps other shoppers choose. Your review appears on the product page once our team has approved it.'}
          </p>
          {products.map((p) => (
            <ReviewForm key={p.id} token={token} product={p} lang={urlLang} />
          ))}
        </>
      )}
    </main>
  )
}
