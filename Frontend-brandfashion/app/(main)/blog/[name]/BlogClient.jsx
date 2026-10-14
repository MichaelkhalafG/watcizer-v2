'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import ArticleBody from '@/src/Components/Blog/ArticleBody'
import { MarkdownBody } from '@/src/lib/markdown'
import { ASSET_BASE } from '@/src/lib/env'
import { localizeHref } from '@/src/utils/localePath'
import '@/src/Components/Blog/blog.css'

const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

// One article (2026-10-01), in the shopper's language — the other one when the article has only
// one. Neither renderer ever interprets the body as HTML: a 'markdown' article (written in the
// Markdown editor, 2026-10-02) goes through MarkdownBody, safe by construction; an older 'text'
// article keeps ArticleBody, so it looks exactly as it did.
export default function BlogClient({ blog }) {
  const { language } = useUIStore()
  const ar = language === 'ar'
  // The language the TEXT is actually in (an Arabic-only article reads right-to-left on /en too).
  const bodyLang = blog.body?.[language] ? language : ar ? 'en' : 'ar'
  const date = blog.published_at
    ? new Date(blog.published_at.replace(' ', 'T')).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
    : ''

  return (
    <main className="wz-article" dir={bodyLang === 'ar' ? 'rtl' : 'ltr'}>
      <Link href="/blogs" className="wz-article__back">
        {ar ? '→ كل المقالات' : '← All articles'}
      </Link>
      <h1>{pick(blog.title, bodyLang)}</h1>
      <span className="wz-article__date">{date}</span>
      {blog.cover ? <img className="wz-article__cover" src={blog.cover} alt="" /> : null}
      {blog.format === 'markdown' ? (
        <MarkdownBody text={pick(blog.body, bodyLang)} assetBase={ASSET_BASE} internalHref={(h) => localizeHref(h, language)} />
      ) : (
        <ArticleBody text={pick(blog.body, bodyLang)} />
      )}
    </main>
  )
}
