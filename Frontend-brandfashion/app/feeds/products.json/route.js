import serverHttp from '@/src/lib/serverFetch'
import { STOREFRONT_CODE } from '@/src/lib/env'

// THE PUBLIC PRODUCT FEED (2026-11-17), at /feeds/products.json on the shop. A TWIN. The file core's `feeds:public` writes
// every hour (GET /api/v2/{shop}/feed), passed on as it is: every product this shop shows to search engines, with its
// titles, brand, page, price and stock — read-only data for assistants, linked from /llms.txt. Never a product kept from
// search engines nor a restricted one (core leaves both out). 404 until core has written it; 503 when core cannot answer.
export const revalidate = 600

export async function GET() {
  try {
    const res = await serverHttp.get(`v2/${STOREFRONT_CODE}/feed`, { responseType: 'text', timeout: 20000, transformResponse: (d) => d })
    return new Response(typeof res.data === 'string' ? res.data : JSON.stringify(res.data), {
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'public, max-age=600', 'X-Robots-Tag': 'noindex' },
    })
  } catch (e) {
    const status = e?.response?.status === 404 ? 404 : 503
    return new Response(JSON.stringify({ error: status === 404 ? 'no feed yet' : 'unavailable' }), {
      status,
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' },
    })
  }
}
