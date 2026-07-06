import { cache } from 'react'
import serverHttp from './serverFetch'

// SERVER-ONLY blog access for the /blogs list + /blog/[name] detail routes.
// React-cached so generateMetadata() and the page body share ONE upstream fetch
// per request (same pattern as serverCatalog.js).

// Raw /all_blog array (each: { id, image, images:[{image}], translations:[{locale,title,text}] }).
export const getServerBlogs = cache(async () => {
  const { data } = await serverHttp.get('/all_blog')
  return Array.isArray(data) ? data : []
})

// english title of a blog (the URL key + SEO title source)
export const blogTitleEn = (blog) =>
  blog?.translations?.find((t) => t.locale === 'en')?.title || ''

// Resolve a blog from the list by its URL param — the english title, matched raw
// or url-decoded (titles carry spaces/punctuation → the segment is encoded).
export const findBlogByName = (blogs, name) => {
  if (!name) return null
  let dec = name
  try {
    dec = decodeURIComponent(name)
  } catch {
    // malformed escape → keep raw
  }
  return (blogs || []).find((b) => {
    const en = blogTitleEn(b)
    return en && (en === name || en === dec)
  }) || null
}
