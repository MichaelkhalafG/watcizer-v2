import { createElement } from 'react'
import Markdown from 'react-markdown'

// An article's Markdown → React ELEMENTS, safe by construction (2026-10-02, the blog editor).
//
// No sanitiser is involved, because nothing here ever becomes an HTML string:
//  - raw HTML in the text (`<script>`, `<img onerror>`, `<iframe>`…) is NOT interpreted — react-markdown
//    shows it as text (no rehype-raw, ever);
//  - only the elements in ALLOWED exist; anything else is unwrapped to its text (an `# h1` becomes an
//    h2, so the page keeps one h1 — the article title);
//  - a link keeps its address only for https:, http:, mailto:, tel: and a path on this site ("/…");
//    javascript:, data:, vbscript:, file: and protocol-relative "//host" addresses are dropped, and the
//    words stay as plain text;
//  - an image is shown only when it is one of OUR uploads ("/Uploads_Images/…"), resolved against
//    this site's image host; any other image (another site, data:, a tracking pixel) is dropped.
//
// THE SAME RULES live in core/resources/js/lib/markdown.ts (the dashboard's preview), so what the
// team previews is what the shop shows. BlogMarkdownSafeTest renders a hostile corpus through BOTH
// and requires identical, harmless output — change one, change the other.

export const ALLOWED = ['p', 'br', 'strong', 'em', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'hr', 'code', 'pre']
const LINK_SCHEMES = /^(https?:|mailto:|tel:)/i
const UPLOADS = /^\/Uploads_Images\/[A-Za-z0-9._\-/]+$/

/** A link address we keep, or '' (the link becomes plain text). */
export function safeHref(url) {
  const u = String(url || '').trim()
  if (u.startsWith('/') && !u.startsWith('//')) return u
  if (u.startsWith('#')) return u
  return LINK_SCHEMES.test(u) ? u : ''
}

/** An image source we show (on `assetBase`), or '' (the image is dropped). */
export function safeImg(url, assetBase) {
  const u = String(url || '').trim()
  return UPLOADS.test(u) && !u.includes('..') ? `${String(assetBase || '').replace(/\/$/, '')}${u}` : ''
}

/** The plain text of an article (for an excerpt or a meta description): Markdown marks removed. */
export function markdownToPlain(text) {
  return String(text || '')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/gm, '')
    .replace(/(\*\*|__|\*|_|`)/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

// `internalHref` lets the page localise its own links (D7: an internal link keeps the page's language);
// the default leaves them as written, which is what the dashboard's preview does.
export function MarkdownBody({ text, assetBase, className = 'wz-article-body', internalHref = (h) => h }) {
  const components = {
    h1: ({ children }) => createElement('h2', null, children),
    a: ({ href, children }) => {
      const safe = safeHref(href)
      if (!safe) return createElement('span', null, children)
      const external = /^https?:/i.test(safe)
      return createElement('a', external ? { href: safe, target: '_blank', rel: 'noopener noreferrer nofollow' } : { href: safe.startsWith('/') ? internalHref(safe) : safe }, children)
    },
    img: ({ src, alt }) => {
      const safe = safeImg(src, assetBase)
      return safe ? createElement('img', { src: safe, alt: alt || '', loading: 'lazy', decoding: 'async' }) : null
    },
  }
  return createElement(
    'div',
    { className },
    createElement(Markdown, {
      allowedElements: [...ALLOWED, 'h1'],
      unwrapDisallowed: true,
      // Addresses are judged in the components above; react-markdown's own filter would blank them
      // before we see them, so let them through unchanged here and decide there.
      urlTransform: (url) => url,
      components,
    }, String(text || '')),
  )
}
