'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import '@/src/Components/Blog/blog.css'

const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

// The articles list (2026-10-01): this storefront's published articles from core, newest first, in
// the shopper's language (the other one when an article has only one).
export default function BlogsClient({ blogs = [] }) {
  const { language } = useUIStore()
  const ar = language === 'ar'
  const date = (iso) =>
    iso ? new Date(iso.replace(' ', 'T')).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : ''

  return (
    <main className="wz-blogs" dir={ar ? 'rtl' : 'ltr'}>
      <h1 className="wz-blogs__title">{ar ? 'مقالات ودليل الساعات' : 'Watch guides & articles'}</h1>
      <p className="wz-blogs__lead">
        {ar
          ? 'كل ما تحتاج معرفته قبل أن تختار ساعتك وبعد أن تقتنيها.'
          : 'What to know before you choose a watch, and after you own one.'}
      </p>
      {blogs.length === 0 ? (
        <p className="wz-blogs__empty">{ar ? 'لا توجد مقالات بعد.' : 'No articles yet.'}</p>
      ) : (
        <div className="wz-blogs__grid">
          {blogs.map((b) => (
            <Link key={b.slug} href={`/blog/${b.slug}`} className="wz-blogs__card">
              {b.cover ? <img className="wz-blogs__cover" src={b.cover} alt="" loading="lazy" /> : null}
              <div className="wz-blogs__body">
                <span className="wz-blogs__date">{date(b.published_at)}</span>
                <h2>{pick(b.title, language)}</h2>
                <p>{pick(b.excerpt, language)}</p>
              </div>
            </Link>
          ))}
        </div>
      )}
    </main>
  )
}
