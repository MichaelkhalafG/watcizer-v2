import { headers } from 'next/headers'
import { redirect } from 'next/navigation'
import serverHttp from '@/src/lib/serverFetch'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { requestLang } from '@/src/lib/requestLang'
import ReviewForm from './ReviewForm'
import '@/src/Components/Reviews/VerifiedReviews.css'

// /review/<token> and /ar/review/<token> — the page a review link opens (SEO Phase 5, 2026-11-14). A TWIN. The products
// of the delivered order behind the link, one form each; never indexed, never cached (each link is one customer's).
// An unknown or expired link shows a short explanation instead (no 404 page: the customer did nothing wrong).
//
// A BRANCH'S IN-STORE ADDRESS (2026-11-17): /review/<branch> (a short key like `arkadia`, never 40 characters) is the
// address in the QR code at the counter and in the bag, and in the staff's WhatsApp message. core counts the scan and
// answers the branch's Google review link, and the visitor is sent there. A link preview (WhatsApp, Facebook, a
// crawler) is sent on without being counted.
export const dynamic = 'force-dynamic'

const PREVIEW_UA = /bot|crawl|spider|preview|facebookexternalhit|whatsapp|telegram|slack|discord|skype|linkedin|embed/i

async function branchLink(key) {
  const ua = (await headers()).get('user-agent') || ''
  try {
    const { data } = await serverHttp.get(`v2/${STOREFRONT_CODE}/review-link/${key}`, { params: { count: PREVIEW_UA.test(ua) ? 0 : 1 } })
    const url = data?.data?.url
    return typeof url === 'string' && /^https:\/\//.test(url) ? url : null
  } catch {
    return null
  }
}

export const metadata = { robots: { index: false, follow: false } }

async function requestOf(token) {
  if (!/^[A-Za-z0-9]{40}$/.test(String(token || ''))) return null
  try {
    const { data } = await serverHttp.get(`v2/${STOREFRONT_CODE}/reviews/request/${token}`)
    return Array.isArray(data?.data?.products) ? { products: data.data.products, google: Array.isArray(data.data.google) ? data.data.google : [] } : null
  } catch {
    return null
  }
}

export default async function ReviewPage({ params }) {
  const { token } = await params
  // a branch's in-store address: off to its Google review page (outside the try: redirect() throws by design)
  if (/^[a-z0-9-]{2,32}$/.test(String(token || ''))) {
    const url = await branchLink(token)
    if (url) redirect(url)
  }
  const { urlLang } = await requestLang()
  const ar = urlLang === 'ar'
  const request = await requestOf(token)
  const products = request?.products ?? null
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
              ? 'رأيك يساعد غيرك على الاختيار. يظهر تقييمك في صفحة المنتج فور إرساله.'
              : 'Your opinion helps other shoppers choose. Your review appears on the product page as soon as you send it.'}
          </p>
          {products.map((p) => (
            <ReviewForm key={p.id} token={token} product={p} lang={urlLang} google={request.google} />
          ))}
        </>
      )}
    </main>
  )
}
