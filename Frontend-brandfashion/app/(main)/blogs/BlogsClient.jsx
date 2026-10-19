'use client'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import '@/src/Components/Blog/blog.css'

const pick = (pair, lang) => (pair?.[lang] || pair?.[lang === 'ar' ? 'en' : 'ar'] || '').trim()

// The articles list (2026-10-01): this storefront's published articles from core, newest first, in
// the shopper's language (the other one when an article has only one).
// Brand Fashion (DIVERGED 2026-10-19, B8 / spec §10.8): a NEUTRAL heading — the shop sells more than watches, and
// Watchizer's watch articles will be shared here (decision C-7) — an article with no cover gets its aisle-tint card
// with the title set large instead of no picture, and the empty list points somewhere.
export default function BlogsClient({ blogs = [] }) {
  const { language } = useUIStore()
  const ar = language === 'ar'
  const date = (iso) =>
    iso ? new Date(iso.replace(' ', 'T')).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : ''

  return (
    <main className="wz-blogs" dir={ar ? 'rtl' : 'ltr'}>
      <h1 className="wz-blogs__title">{ar ? 'أدلة ومقالات' : 'Guides & articles'}</h1>
      <p className="wz-blogs__lead">
        {ar ? 'كيف تختار ما تشتريه، وكيف تعتني به بعد ذلك.' : 'How to choose what you buy, and how to look after it.'}
      </p>
      {blogs.length === 0 ? (
        <p className="wz-blogs__empty">
          {ar ? 'لا توجد مقالات بعد. ' : 'No articles yet. '}
          <Link href="/listing?sort=newest" className="bf-blogs__empty-link">
            {ar ? 'تسوّق أحدث المنتجات' : 'Shop the newest products'}
          </Link>
        </p>
      ) : (
        <div className="wz-blogs__grid">
          {blogs.map((b) => (
            <Link key={b.slug} href={`/blog/${b.slug}`} className={`wz-blogs__card${b.cover ? '' : ' bf-blogs__card--nocover'}`}>
              {b.cover ? (
                // eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL
                <img className="wz-blogs__cover" src={b.cover} alt="" loading="lazy" />
              ) : (
                // no cover: the tint card with the title set large (§10.8) — never an empty 16:9 box
                <span className="bf-blogs__nocover" aria-hidden="true">{pick(b.title, language)}</span>
              )}
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
