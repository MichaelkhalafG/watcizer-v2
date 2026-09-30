import { cache } from 'react'
import serverHttp from './serverFetch'
import { coreRead } from './coreRead'

// SERVER-ONLY article access for the /blogs list and /blog/[name] detail routes (2026-10-01).
// React-cached, so generateMetadata() and the page body share ONE upstream fetch per request.
//
// Articles are written on the dashboard, per storefront, and served by core: `catalog/blogs` (this
// storefront's PUBLISHED articles, newest first) and `catalog/blog?slug=` (one). A draft never
// reaches the storefront. The URL of an article is /blog/{slug}.

// [{ slug, cover, published_at, title: {en, ar}, excerpt: {en, ar} }]. THROWS when core could not
// answer and no last good copy exists (2026-09-30): an empty list would mark /blogs noindex.
export const getServerBlogs = cache(async () => {
  const data = await coreRead('blogs', async () => (await serverHttp.get('catalog/blogs')).data)
  return Array.isArray(data?.blogs) ? data.blogs : []
})

// { slug, cover, published_at, title, body, meta_title, meta_description } (each {en, ar}), null when
// core says there is no such published article, and THROWS when core could not answer.
export const getServerBlog = cache(async (slug) => {
  if (!slug) return null
  let decoded = slug
  try {
    decoded = decodeURIComponent(slug)
  } catch {
    // a malformed escape — look it up as typed
  }
  const data = await coreRead(`blog:${decoded}`, async () => (await serverHttp.get(`catalog/blog?slug=${encodeURIComponent(decoded)}`)).data)
  return data?.blog ?? null
})

// A text field in `lang`, the other language when that one is empty.
export const inLang = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()
