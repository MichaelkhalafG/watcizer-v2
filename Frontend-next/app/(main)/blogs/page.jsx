import { getServerBlogs } from '@/src/lib/serverBlogs'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import BlogsClient from './BlogsClient'

// ISR: the article list in the initial HTML for SEO, revalidated every 10 minutes — a newly
// published article shows within that (2026-10-01: articles come from core, per storefront).
export const revalidate = 600

const TEXT = {
  en: {
    title: 'Watch guides & articles | Watchizer',
    description:
      'Guides for choosing, wearing and caring for a watch — movements, sizes, straps and more — from Watchizer.',
  },
  ar: {
    title: 'مقالات ودليل الساعات | Watchizer',
    description: 'أدلة لاختيار الساعة وارتدائها والعناية بها — الحركة والمقاس والأسوار وأكثر — من Watchizer.',
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
