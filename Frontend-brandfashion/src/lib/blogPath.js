// An article's path in a language, WITHOUT the /ar prefix (LocaleLink and localePath add it) — SEO Phase 3, 2026-11-14,
// a TWIN. English: /blog/<ascii slug>. Arabic: /blog/<Arabic slug> when the article has one (BUSINESS FACTS 9), else the
// English slug as before. `encoded` gives the percent-encoded form canonical, hreflang and the feeds carry.
export function blogPath(article, lang, { encoded = false } = {}) {
  const ar = lang === 'ar' && article?.slug_ar
  const slug = ar ? article.slug_ar : article?.slug || ''
  return `/blog/${encoded ? encodeURIComponent(slug) : slug}`
}

// The slug a request named, decoded (a malformed escape is taken as typed).
export function decodedSlug(raw) {
  try {
    return decodeURIComponent(String(raw || ''))
  } catch {
    return String(raw || '')
  }
}
