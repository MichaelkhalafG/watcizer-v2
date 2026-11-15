import { getServerV2Meta } from '@/src/lib/serverV2'

// /indexnow-key.txt (2026-11-14, SEO Phase 1): this shop's IndexNow key, the file IndexNow's `keyLocation` names. A TWIN.
// The key is core's (v2 meta `indexnow_key`, derived from its app key); public by the protocol's design. Core
// unreachable: 503, never a wrong key.
export const revalidate = 3600

export async function GET() {
  const meta = await getServerV2Meta().then((body) => body?.data ?? body ?? null, () => null)
  const key = typeof meta?.indexnow_key === 'string' ? meta.indexnow_key : ''
  if (!/^[a-f0-9]{32}$/.test(key)) return new Response('', { status: 503, headers: { 'Retry-After': '300' } })
  return new Response(key, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } })
}
