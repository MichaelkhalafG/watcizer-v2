'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import ArticleBody from '@/src/Components/Blog/ArticleBody'
import DataBlock from '@/src/Components/Blog/DataBlock'
import { MarkdownBody } from '@/src/lib/markdown'
import { splitBody, tableOfContents, readingMinutes } from '@/src/lib/dataBlocks'
import { blogPath } from '@/src/lib/blogPath'
import { heroOf, HERO_SIZES } from '@/src/lib/blogHero'
import { pictureSources } from '@/src/utils/picture'
import { ASSET_BASE } from '@/src/lib/env'
import { localizeHref } from '@/src/utils/localePath'
import '@/src/Components/Blog/blog.css'
import '@/src/Components/Blog/article.css'

const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

// a stored time (ISO with its offset since 2026-11-14) as a Cairo date
const cairoDate = (iso, ar) =>
  iso
    ? new Date(String(iso).replace(' ', 'T')).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Cairo' })
    : ''
const sameDay = (a, b) => cairoDate(a, false) === cairoDate(b, false)

// The table of contents shows on an article of more than 1,200 words (the brief's writing rules).
const TOC_WORDS = 1200

// One article (2026-10-01), in the shopper's language — the other one when the article has only
// one. Neither renderer ever interprets the body as HTML: a 'markdown' article (written in the
// Markdown editor, 2026-10-02) goes through MarkdownBody, safe by construction; an older 'text'
// article keeps ArticleBody, so it looks exactly as it did.
//
// SEO Phase 3 (2026-11-14) — a TWIN: the team byline (linked to the team page), the Published and Last updated dates
// (Cairo), the reading time, the table of contents, the LIVE DATA BLOCKS in place (core resolved them: src/lib/
// dataBlocks.js places them), the related articles, and the editorial policy under every article. `team` is the shop's
// team name in the page's language (src/content/trustPages.js), passed by the page.
//
// THE MAGAZINE PAGE (Michael, 2026-11-17): the title in normal case at a calmer size, a clear hero — the article's
// stored picture (core's `hero`: a photo of an in-stock product that matches it, with its renditions, its alt text in
// each language and the product it shows, credited under it), a tidier contents box, a reading column of comfortable
// length and spacing, and right-to-left that reads as Arabic. Every SEO part above is kept as it was, markup included.
export default function BlogClient({ blog, team }) {
  const language = useUIStore((s) => s.language)
  const ar = language === 'ar'
  // The language the TEXT is actually in (an Arabic-only article reads right-to-left on /en too).
  const bodyLang = blog.body?.[language] ? language : ar ? 'en' : 'ar'
  const text = pick(blog.body, bodyLang)
  const markdown = blog.format === 'markdown'
  const minutes = readingMinutes(text)
  const toc = markdown && minutes * 200 > TOC_WORDS ? tableOfContents(text) : []
  const updated = blog.modified_at && !sameDay(blog.modified_at, blog.published_at)
  const headingIds = new Map()
  const segments = markdown ? splitBody(text, blog.blocks || {}, bodyLang) : []
  const bar = ar ? 'ar' : 'en'
  const title = pick(blog.title, bodyLang)
  const hero = heroOf(blog, bodyLang)
  const sources = pictureSources(hero?.srcset)

  return (
    <main className="wz-article wz-mag" dir={bodyLang === 'ar' ? 'rtl' : 'ltr'} lang={bodyLang}>
      <header className="wz-mag__head">
        <nav className="wz-article__crumbs" aria-label={ar ? 'مسار التنقل' : 'Breadcrumb'}>
          <Link href="/">{ar ? 'الرئيسية' : 'Home'}</Link>
          <span aria-hidden="true">›</span>
          <Link href="/blogs">{ar ? 'المقالات' : 'Articles'}</Link>
        </nav>
        <h1>{title}</h1>
        <p className="wz-article__meta">
          <span>
            {bar === 'ar' ? 'بقلم ' : 'By '}
            <Link href="/team">{team}</Link>
          </span>
          <span>
            {ar ? 'نُشر في ' : 'Published '}
            <time dateTime={blog.published_at}>{cairoDate(blog.published_at, ar)}</time>
          </span>
          {updated ? (
            <span>
              {ar ? 'آخر تحديث ' : 'Last updated '}
              <time dateTime={blog.modified_at}>{cairoDate(blog.modified_at, ar)}</time>
            </span>
          ) : null}
          {markdown ? <span>{ar ? `${minutes} دقائق قراءة` : `${minutes} min read`}</span> : null}
        </p>
      </header>
      {hero ? (
        <figure className="wz-article__hero">
          <picture className="wz-article__hero-frame">
            {sources.avif ? <source type="image/avif" srcSet={sources.avif} sizes={HERO_SIZES} /> : null}
            {sources.webp ? <source type="image/webp" srcSet={sources.webp} sizes={HERO_SIZES} /> : null}
            {/* the page's largest picture: asked for first, never lazily (LCP) */}
            <img
              className="wz-article__cover"
              src={hero.src}
              alt={hero.alt}
              width={hero.width || undefined}
              height={hero.height || undefined}
              fetchPriority="high"
              decoding="async"
            />
          </picture>
          {hero.product ? (
            <figcaption className="wz-article__credit">
              {ar ? 'في الصورة: ' : 'Pictured: '}
              <Link href={`/product/${hero.product.slug}`}>{hero.product.title}</Link>
            </figcaption>
          ) : null}
        </figure>
      ) : null}
      {toc.length > 2 ? (
        <nav className="wz-toc" aria-label={ar ? 'محتويات المقال' : 'Contents'}>
          <p className="wz-toc__title">{ar ? 'محتويات المقال' : 'Contents'}</p>
          <ol>
            {toc.map((h) => (
              <li key={h.id}>
                <a href={`#${h.id}`}>{h.title}</a>
              </li>
            ))}
          </ol>
        </nav>
      ) : null}
      <div className="wz-mag__body">
        {markdown ? (
          segments.map((s, i) =>
            s.type === 'md' ? (
              <MarkdownBody key={i} text={s.text} assetBase={ASSET_BASE} internalHref={(h) => localizeHref(h, language)} headingIds={headingIds} />
            ) : (
              <DataBlock key={i} block={s.block} lang={bodyLang} />
            ),
          )
        ) : (
          <ArticleBody text={text} />
        )}
      </div>
      {blog.related?.length ? (
        <section className="wz-related" aria-label={ar ? 'مقالات ذات صلة' : 'Related articles'}>
          <h2>{ar ? 'مقالات ذات صلة' : 'Related articles'}</h2>
          <ul>
            {blog.related.map((r) => (
              <li key={r.slug}>
                <Link href={blogPath(r, language)}>{pick(r.title, language)}</Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
      <p className="wz-article__policy">
        {ar ? 'كيف نكتب مقالاتنا ونتحقق منها: ' : 'How we write and check our articles: '}
        <Link href="/editorial-policy">{ar ? 'السياسة التحريرية' : 'our editorial policy'}</Link>
      </p>
    </main>
  )
}
