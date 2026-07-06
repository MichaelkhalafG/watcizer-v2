import { notFound } from 'next/navigation'
import { getServerBlogs, findBlogByName, blogTitleEn } from '@/src/lib/serverBlogs'
import { getImageUrl } from '@/src/utils/imageUrl'
import BlogClient from './BlogClient'

// ISR: server-render the post (content + metadata in the initial HTML for SEO),
// cache, revalidate hourly — matching the /blogs list.
export const revalidate = 3600

const SEO_DOMAIN = 'https://watchizereg.com'

// plain-text, collapsed, ≤160 chars for the meta description
const stripText = (raw) =>
  (raw || '')
    .toString()
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 160)

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

  return <BlogClient blog={blog} />
}
