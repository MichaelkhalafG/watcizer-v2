import { getServerBlogs } from '@/src/lib/serverBlogs'
import BlogsClient from './BlogsClient'

// ISR: render the blog list on the server (blogs in the initial HTML for SEO),
// cache, and revalidate hourly — blog content changes infrequently.
export const revalidate = 3600

export const metadata = {
  title: 'Blog | Watchizer',
  description:
    'Read the latest from Watchizer — watch guides, brand stories and style notes on luxury timepieces and accessories.',
  alternates: { canonical: 'https://watchizereg.com/blogs' },
}

export default async function BlogsPage() {
  let blogs = []
  try {
    blogs = await getServerBlogs()
  } catch {
    // blog endpoint unreachable server-side → render an empty list (no crash).
  }

  return <BlogsClient blogs={blogs} />
}
