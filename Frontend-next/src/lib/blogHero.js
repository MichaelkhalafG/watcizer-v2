// AN ARTICLE'S HERO PICTURE (2026-11-17). A TWIN.
//
// core stores one picture per article and sends it as `hero`: { src, srcset: [{src, width, format}], width, height,
// alt: {en, ar}, product: {slug, title: {en, ar}} | null } — a photo of an in-stock product of the shop that matches
// the article, never one kept from search (core's BlogHero), or the team's own upload. An older core sends only the
// `cover` address; that is drawn as it is, with the article's title as its alt text.
//
// One reading for the page (the <img>), its og:image and its Article markup, so the three never disagree.

// The hero is drawn inside a panel of at most 480 px tall (article.css): a portrait product photo is about 300 px wide
// there on a desktop, and about 60 % of a phone's width. 2x screens take the next rung up.
export const HERO_SIZES = '(max-width: 767px) 64vw, 320px'

const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

/**
 * @param {object} blog  core's article
 * @param {'en'|'ar'} lang  the language of the page's text
 * @returns {{src: string, srcset: Array, width: number|null, height: number|null, alt: string, product: {slug: string, title: string}|null}|null}
 */
export function heroOf(blog, lang) {
  const title = pick(blog?.title, lang)
  const h = blog?.hero
  if (h && typeof h.src === 'string' && h.src) {
    return {
      src: h.src,
      srcset: Array.isArray(h.srcset) ? h.srcset : [],
      width: Number(h.width) || null,
      height: Number(h.height) || null,
      alt: pick(h.alt, lang) || title,
      product: h.product?.slug ? { slug: h.product.slug, title: pick(h.product.title, lang) } : null,
    }
  }
  if (typeof blog?.cover === 'string' && blog.cover) {
    return { src: blog.cover, srcset: [], width: null, height: null, alt: title, product: null }
  }
  return null
}

/**
 * The picture as Open Graph and Article markup name it: the largest recorded WebP rendition (a share card is drawn
 * large), else the master. With its size when known, and its alt text.
 */
export function heroShareImage(blog, lang) {
  const h = heroOf(blog, lang)
  if (!h) return null
  const webp = h.srcset.filter((r) => r?.format === 'webp' && r?.src).sort((a, b) => Number(b.width) - Number(a.width))[0]
  // the master is the photo itself; a rendition only when the master's size is unknown (it may be very large)
  if (h.width && h.height) return { url: h.src, width: h.width, height: h.height, alt: h.alt }
  return webp ? { url: webp.src, width: Number(webp.width) || undefined, alt: h.alt } : { url: h.src, alt: h.alt }
}
