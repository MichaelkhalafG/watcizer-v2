import { notFound } from 'next/navigation'
import { getServerBlogs, findBlogByName, blogTitleEn } from '@/src/lib/serverBlogs'
import { getImageUrl } from '@/src/utils/imageUrl'
import BlogClient from './BlogClient'

// ISR: server-render the post (content + metadata in the initial HTML for SEO),
// cache, revalidate hourly — matching the /blogs list.
export const revalidate = 3600

const SEO_DOMAIN = 'https://watchizereg.com'

// plain-text, collapsed, capped for meta/JSON-LD descriptions (HTML stripped)
const stripText = (raw, max = 160) =>
  (raw || '')
    .toString()
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max)

// getServerBlogs is React-cached → generateMetadata + the page share ONE fetch.
async function resolveBlog(name) {
  let blogs = []
  try {
    blogs = await getServerBlogs()
  } catch {
    return null
  }
  return findBlogByName(blogs, name)
}

export async function generateMetadata({ params }) {
  const { name } = await params
  const blog = await resolveBlog(name)
  if (!blog) return { title: 'Blog | Watchizer' }

  const en = blog.translations?.find((t) => t.locale === 'en')
  const title = en?.title || blogTitleEn(blog) || 'Blog'
  const description = stripText(en?.text)
  const canonical = `${SEO_DOMAIN}/blog/${encodeURIComponent(title)}`
  const image = getImageUrl(blog.image, 'Blog')

  return {
    title: `${title} | Watchizer`,
    description,
    alternates: { canonical },
    openGraph: {
      type: 'article',
      title,
      description,
      url: canonical,
      siteName: 'Watchizer',
      authors: ['Watchizer'],
      ...(blog.created_at ? { publishedTime: blog.created_at } : {}),
      ...(blog.updated_at ? { modifiedTime: blog.updated_at } : {}),
      ...(image ? { images: [{ url: image }] } : {}),
    },
    twitter: {
      card: 'summary_large_image',
      title,
      description,
      ...(image ? { images: [image] } : {}),
    },
  }
}

export default async function BlogPage({ params }) {
  const { name } = await params
  const blog = await resolveBlog(name)
  if (!blog) notFound()

  const en = blog.translations?.find((t) => t.locale === 'en')
  const headline = en?.title || blogTitleEn(blog) || 'Blog'
  const image = getImageUrl(blog.image, 'Blog')
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline,
    description: stripText(en?.text, 200),
    ...(image ? { image } : {}),
    ...(blog.created_at ? { datePublished: blog.created_at } : {}),
    ...(blog.updated_at || blog.created_at
      ? { dateModified: blog.updated_at || blog.created_at }
      : {}),
    author: { '@type': 'Organization', name: 'Watchizer', url: SEO_DOMAIN },
    publisher: {
      '@type': 'Organization',
      name: 'Watchizer',
      logo: { '@type': 'ImageObject', url: `${SEO_DOMAIN}/logo.svg` },
    },
    mainEntityOfPage: {
      '@type': 'WebPage',
      '@id': `${SEO_DOMAIN}/blog/${encodeURIComponent(headline)}`,
    },
  }

  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <BlogClient blog={blog} />
    </>
  )
}
