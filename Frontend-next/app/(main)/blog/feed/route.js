import { headers } from 'next/headers'
import { getServerBlogs } from '@/src/lib/serverBlogs'
import { feedXml } from '@/src/lib/blogFeed'

// /blog/feed and /ar/blog/feed (SEO Phase 3, 2026-11-14) — a TWIN; the XML is src/lib/blogFeed.js. The language is the
// address's (middleware.js sets x-wz-url-lang).
export async function GET() {
  const lang = (await headers()).get('x-wz-url-lang') === 'ar' ? 'ar' : 'en'
  let articles
  try {
    articles = await getServerBlogs()
  } catch {
    return new Response('', { status: 503, headers: { 'Retry-After': '300' } })
  }
  return new Response(feedXml(articles, lang), {
    headers: { 'Content-Type': 'application/rss+xml; charset=utf-8', 'Cache-Control': 'public, max-age=600' },
  })
}
