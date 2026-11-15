import { reportedNotFound } from '@/src/lib/notFoundReport'
import { getServerBlogs } from '@/src/lib/serverBlogs'
import { requestLang, localePath, SITE } from '@/src/lib/requestLang'
import { SHOP_NAME } from '@/src/lib/siteAddress'
import { TOPICS, MIN_INDEXABLE, archiveOf, archiveName, pageOf } from '@/src/lib/blogTopics'
import BlogsClient from '../../BlogsClient'
import '@/src/Components/Blog/article.css'

// /blogs/brand/<brand> and /blogs/topic/<topic> (+ /ar) — the article archives (SEO Phase 3, 2026-11-14). A TWIN. The
// shop's published articles about one brand, or of one kind, newest first, 24 a page (?page=N, each page its own
// canonical). Indexable from three articles (src/lib/blogTopics.js); an unknown brand or topic, or a page past the end,
// is a real 404.
export const revalidate = 600

async function resolve(params, searchParams) {
  const { kind, value } = await params
  const { page } = (await searchParams) || {}
  if (kind !== 'brand' && kind !== 'topic') await reportedNotFound()
  if (kind === 'topic' && !TOPICS[value]) await reportedNotFound()
  const all = archiveOf(await getServerBlogs(), kind, value)
  if (all.length === 0) await reportedNotFound()
  const paged = pageOf(all, page)
  if (paged.items.length === 0) await reportedNotFound()
  return { kind, value, all, paged }
}

const pathOf = (kind, value, page) => `/blogs/${kind}/${value}${page > 1 ? `?page=${page}` : ''}`

export async function generateMetadata({ params, searchParams }) {
  const { urlLang } = await requestLang()
  const { kind, value, all, paged } = await resolve(params, searchParams)
  const name = archiveName(kind, value, all, urlLang)
  const ar = urlLang === 'ar'
  const base = kind === 'brand' ? (ar ? `مقالات ${name}` : `${name} articles and guides`) : name
  const title = `${base}${paged.page > 1 ? (ar ? ` — صفحة ${paged.page}` : ` — page ${paged.page}`) : ''} | ${SHOP_NAME}`
  const description =
    kind === 'brand'
      ? ar
        ? `كل مقالاتنا عن ${name}: أدلة الشراء والمقارنات والعناية — ${all.length} مقال.`
        : `All our articles about ${name}: buying guides, comparisons and care — ${all.length} articles.`
      : ar
        ? `${name} من ${SHOP_NAME} — ${all.length} مقال.`
        : `${name} from ${SHOP_NAME} — ${all.length} articles.`
  const path = pathOf(kind, value, paged.page)
  return {
    title,
    description,
    alternates: {
      canonical: `${SITE}${localePath(path, urlLang)}`,
      languages: { en: `${SITE}${path}`, ar: `${SITE}${localePath(path, 'ar')}`, 'x-default': `${SITE}${path}` },
    },
    ...(all.length < MIN_INDEXABLE ? { robots: { index: false, follow: true } } : {}),
  }
}

export default async function ArchivePage({ params, searchParams }) {
  const { urlLang } = await requestLang()
  const { kind, value, all, paged } = await resolve(params, searchParams)
  const ar = urlLang === 'ar'
  const name = archiveName(kind, value, all, urlLang)
  return (
    <>
      <BlogsClient
        blogs={paged.items}
        heading={kind === 'brand' ? (ar ? `مقالات ${name}` : `${name}: articles and guides`) : name}
        lead={
          kind === 'brand'
            ? ar
              ? `أدلة الشراء والمقارنات ونصائح العناية الخاصة بـ ${name}.`
              : `Buying guides, comparisons and care advice for ${name}.`
            : ''
        }
      />
      {paged.pages > 1 ? (
        <nav className="wz-blogs wz-blogs__pager" aria-label={ar ? 'الصفحات' : 'Pages'} dir={ar ? 'rtl' : 'ltr'}>
          {Array.from({ length: paged.pages }, (_, i) => i + 1).map((p) => (
            <a key={p} href={localePath(pathOf(kind, value, p), urlLang)} aria-current={p === paged.page ? 'page' : undefined}>
              {p}
            </a>
          ))}
        </nav>
      ) : null}
    </>
  )
}
