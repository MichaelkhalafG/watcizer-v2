// Per-language site paths (S-AR stage 1). English keeps the bare URLs; Arabic lives under /ar/.
// Plain functions — imported by the server (src/lib/requestLang.js) and the browser alike.

// A site path in a language. `path` starts with '/'; a query string may follow.
export const localePath = (path, lang) => {
  if (lang !== 'ar') return path
  if (path === '/' || path.startsWith('/?')) return `/ar${path.slice(1)}`
  return `/ar${path}`
}

// The English (bare) form of a path that may carry the /ar prefix.
export const barePath = (pathname) =>
  pathname === '/ar' ? '/' : pathname.startsWith('/ar/') ? pathname.slice(3) : pathname

// Not pages: Next's own files, the API and image proxies, and anything with a file extension.
const NOT_A_PAGE = /^\/(_next|api|Uploads_Images)(\/|$)|\.[a-z0-9]+$/i

// A link target in the page's language (D7, 2026-09-30): every internal link keeps the language of the
// page it is on — /ar/… on an Arabic page, bare on an English one — so neither a shopper nor a crawler
// (who carries no cookie) ever crosses languages by following a link. Idempotent: a path that already
// carries /ar is re-based, never doubled. External URLs, anchors and files pass through untouched.
// Accepts a string or a next/link object ({ pathname, query, hash }).
export const localizeHref = (href, lang) => {
  if (href && typeof href === 'object') {
    return typeof href.pathname === 'string' ? { ...href, pathname: localizeHref(href.pathname, lang) } : href
  }
  if (typeof href !== 'string' || !href.startsWith('/') || href.startsWith('//')) return href
  const cut = href.search(/[?#]/)
  const path = cut === -1 ? href : href.slice(0, cut)
  const tail = cut === -1 ? '' : href.slice(cut)
  if (NOT_A_PAGE.test(path)) return href
  return localePath(barePath(path), lang) + tail
}
