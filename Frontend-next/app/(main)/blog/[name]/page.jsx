import { notFound } from 'next/navigation'
import { getServerBlog, inLang } from '@/src/lib/serverBlogs'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'
import BlogClient from './BlogClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'

// ISR: the article in the initial HTML for SEO, revalidated every 10 minutes (2026-10-01: from
// core, per storefront, at /blog/{slug}; Arabic at /ar/blog/{slug}).
export const revalidate = 600

// plain text, collapsed, capped — for descriptions (the body is plain text with "## " / "- " lines)
const plain = (raw, max = 160) =>
  (raw || '')
    .replace(/^(##\s+|-\s+)/gm, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max)

export async function generateMetadata({ params }) {
  const { name } = await params
  // notFound() here, before the page streams, is what makes the status a real 404.
  const blog = await getServerBlog(name)
  if (!blog) notFound()
  const { urlLang } = await requestLang()
  const title = inLang(blog.meta_title, urlLang) || `${inLang(blog.title, urlLang)} | Watchizer`
  const description = inLang(blog.meta_description, urlLang) || plain(inLang(blog.body, urlLang))
  const alternates = alternatesFor(`/blog/${blog.slug}`, urlLang)
  return {
    title,
    description,
    alternates,
    openGraph: {
      type: 'article',
      title,
      description,
      url: alternates.canonical,
      siteName: 'Watchizer',
      locale: urlLang === 'ar' ? 'ar_EG' : 'en_US',
      publishedTime: blog.published_at,
      ...(blog.cover ? { images: [{ url: blog.cover }] } : {}),
    },
    twitter: { card: 'summary_large_image', title, description, ...(blog.cover ? { images: [blog.cover] } : {}) },
  }
}

export default async function BlogPage({ params }) {
  const { name } = await params
  const blog = await getServerBlog(name)
  if (!blog) notFound()
  const { urlLang } = await requestLang()
  const url = `${SITE}${localePath(`/blog/${blog.slug}`, urlLang)}`
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: inLang(blog.title, urlLang),
    description: inLang(blog.meta_description, urlLang) || plain(inLang(blog.body, urlLang), 200),
    inLanguage: urlLang,
    ...(blog.cover ? { image: blog.cover } : {}),
    datePublished: blog.published_at,
    author: { '@type': 'Organization', name: 'Watchizer', url: SITE },
    publisher: { '@type': 'Organization', name: 'Watchizer', logo: { '@type': 'ImageObject', url: `${SITE}/logo.svg` } },
    mainEntityOfPage: { '@type': 'WebPage', '@id': url },
  }

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(jsonLd) }} />
      <BlogClient blog={blog} />
    </>
  )
}
