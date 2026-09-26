import { cache } from 'react'

// SERVER-ONLY blog access for the /blogs list + /blog/[name] detail routes.
// React-cached so generateMetadata() and the page body share ONE upstream fetch
// per request (same pattern as serverCatalog.js).

// Raw /all_blog array (each: { id, image, images:[{image}], translations:[{locale,title,text}] }).
// `/all_blog` no longer exists (core forwards it to the retired legacy host), so the blog pages
// asked for it on every render and got an error (batch 1, 2026-09-26). Blogs come back per
// storefront later; until then the list is empty WITHOUT a request, and both pages keep showing
// their existing empty state.
export const getServerBlogs = cache(async () => [])

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
