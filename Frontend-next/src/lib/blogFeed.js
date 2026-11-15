import { blogPath } from './blogPath'
import { SITE, SHOP_NAME } from './siteAddress'
import { localePath } from '../utils/localePath'
import { TRUST_PAGES } from '../content/trustPages'

// THE FEEDS' XML (app/(main)/blog/feed/route.js) — /blog/feed and /ar/blog/feed, the articles as RSS 2.0, one feed per language (SEO Phase 3, 2026-11-14). A TWIN.
// The newest 30 published articles; each item at its own address in that language (the Arabic slug under /ar), the
// team as the author, the publication date. Core unreachable with no last good copy: 503, never an empty feed.
const LIMIT = 30
const inLang = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

const esc = (s) =>
  String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')

const rfc822 = (iso) => {
  const d = new Date(String(iso || '').replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '' : d.toUTCString()
}

export function feedXml(articles, lang) {
  const ar = lang === 'ar'
  const home = `${SITE}${localePath('/blogs', lang)}`
  const team = TRUST_PAGES.team[lang].title
  const items = articles.slice(0, LIMIT).map((a) => {
    const link = `${SITE}${localePath(blogPath(a, lang, { encoded: true }), lang)}`
    return [
      '    <item>',
      `      <title>${esc(inLang(a.title, lang))}</title>`,
      `      <link>${esc(link)}</link>`,
      `      <guid isPermaLink="true">${esc(link)}</guid>`,
      a.published_at ? `      <pubDate>${rfc822(a.published_at)}</pubDate>` : '',
      `      <dc:creator>${esc(team)}</dc:creator>`,
      `      <description>${esc(inLang(a.excerpt, lang))}</description>`,
      '    </item>',
    ]
      .filter(Boolean)
      .join('\n')
  })
  return [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">',
    '  <channel>',
    `    <title>${esc(ar ? `مقالات ${SHOP_NAME}` : `${SHOP_NAME} articles`)}</title>`,
    `    <link>${esc(home)}</link>`,
    `    <atom:link href="${esc(`${SITE}${localePath('/blog/feed', lang)}`)}" rel="self" type="application/rss+xml"/>`,
    `    <description>${esc(ar ? `أدلة الشراء والمقالات من ${team}` : `Buying guides and articles from the ${team}`)}</description>`,
    `    <language>${ar ? 'ar' : 'en'}</language>`,
    ...items,
    '  </channel>',
    '</rss>',
    '',
  ].join('\n')
}

