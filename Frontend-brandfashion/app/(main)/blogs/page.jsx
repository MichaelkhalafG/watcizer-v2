import { getServerBlogs } from '@/src/lib/serverBlogs'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import BlogsClient from './BlogsClient'

// ISR: the article list in the initial HTML for SEO, revalidated every 10 minutes — a newly
// published article shows within that (2026-10-01: articles come from core, per storefront).
export const revalidate = 600

const TEXT = {
  en: {
    // neutral (2026-10-19): the shop sells more than watches — the list's heading says the same
    title: 'Guides & articles | Brand Fashion',
    description: 'How to choose what you buy, and how to look after it — watches, fashion and accessories — from Brand Fashion.',
  },
  ar: {
    title: 'أدلة ومقالات | Brand Fashion',
    description: 'كيف تختار ما تشتريه وكيف تعتني به — ساعات وأزياء وإكسسوارات — من Brand Fashion.',
  },
}

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const blogs = await getServerBlogs()
  const t = TEXT[urlLang]
  return {
    title: t.title,
    description: t.description,
    alternates: alternatesFor('/blogs', urlLang),
    // An EMPTY articles page is not worth indexing (S9); it becomes indexable with its first article.
    ...(blogs.length === 0 ? { robots: { index: false, follow: true } } : {}),
  }
}

export default async function BlogsPage() {
  return <BlogsClient blogs={await getServerBlogs()} />
}
