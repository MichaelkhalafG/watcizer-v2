import { permanentRedirect } from 'next/navigation'
import { reportedNotFound } from '@/src/lib/notFoundReport'
import { getServerBlog, inLang, ownLang } from '@/src/lib/serverBlogs'
import { requestLang, localePath, SITE } from '@/src/lib/requestLang'
import BlogClient from './BlogClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { markdownToPlain } from '@/src/lib/markdown'
import { blogPath, decodedSlug } from '@/src/lib/blogPath'
import { faqOf, listedBlocks } from '@/src/lib/dataBlocks'
import { TRUST_PAGES } from '@/src/content/trustPages'
import { SHOP_NAME } from '@/src/lib/siteAddress'
import { heroShareImage } from '@/src/lib/blogHero'

const SHOP = SHOP_NAME

// ISR: the article in the initial HTML for SEO, revalidated every 10 minutes (2026-10-01: from
// core, per storefront, at /blog/{slug}; Arabic at /ar/blog/{slug}).
export const revalidate = 600

// plain text, collapsed, capped — for descriptions. A 'text' body (plain text with "## " / "- "
// lines) is read exactly as before; a 'markdown' one loses all its marks (links keep their words).
const plain = (raw, max = 160, format = 'text') =>
  (format === 'markdown'
    ? markdownToPlain(raw)
    : (raw || '')
        .replace(/^(##\s+|-\s+)/gm, '')
        .replace(/\s+/g, ' ')
        .trim()
  ).slice(0, max)

// SEO Phase 3 (2026-11-14): the article's two addresses — /blog/<ascii> and /ar/blog/<Arabic slug> (BUSINESS FACTS 9),
// percent-encoded for canonical and hreflang.
const urls = (blog) => {
  const en = `${SITE}${blogPath(blog, 'en', { encoded: true })}`
  const ar = `${SITE}${localePath(blogPath(blog, 'ar', { encoded: true }), 'ar')}`
  return { en, ar }
}

// The address asked for is not the article's own in this language (an Arabic page asked by its English slug, or an
// English page by its Arabic one): one permanent redirect to the right one. Existing Arabic URLs keep working this way.
async function settle(name, urlLang) {
  const blog = await getServerBlog(name)
  if (!blog) await reportedNotFound()
  const own = urlLang === 'ar' && blog.slug_ar ? blog.slug_ar : blog.slug
  if (decodedSlug(name) !== own) permanentRedirect(localePath(blogPath(blog, urlLang, { encoded: true }), urlLang))
  return blog
}

export async function generateMetadata({ params }) {
  const { name } = await params
  const { urlLang } = await requestLang()
  // notFound() here, before the page streams, is what makes the status a real 404.
  const blog = await settle(name, urlLang)
  const title = ownLang(blog.meta_title, urlLang) || `${inLang(blog.title, urlLang)} | ${SHOP}`
  const description = ownLang(blog.meta_description, urlLang) || plain(inLang(blog.body, urlLang), 160, blog.format)
  const u = urls(blog)
  const canonical = urlLang === 'ar' ? u.ar : u.en
  // the article's hero picture (2026-11-17): the same one the page draws and its Article markup names, with its alt
  const share = heroShareImage(blog, urlLang)
  return {
    title,
    description,
    alternates: { canonical, languages: { en: u.en, ar: u.ar, 'x-default': u.en } },
    openGraph: {
      type: 'article',
      title,
      description,
      url: canonical,
      siteName: SHOP,
      publishedTime: blog.published_at,
      modifiedTime: blog.modified_at || blog.published_at,
      ...(share ? { images: [share] } : {}),
    },
    twitter: { card: 'summary_large_image', title, description, ...(share ? { images: [{ url: share.url, alt: share.alt }] } : {}) },
  }
}

export default async function BlogPage({ params }) {
  const { name } = await params
  const { urlLang } = await requestLang()
  const blog = await settle(name, urlLang)
  const u = urls(blog)
  const url = urlLang === 'ar' ? u.ar : u.en
  const team = TRUST_PAGES.team[urlLang].title
  const teamUrl = `${SITE}${localePath('/team', urlLang)}`
  const text = inLang(blog.body, urlLang)
  const headline = inLang(blog.title, urlLang)
  const blocks = blog.format === 'markdown' ? listedBlocks(text, blog.blocks || {}) : []
  const firstImage = blocks.flatMap((b) => b.block.items || []).find((i) => i.image?.src)?.image?.src
  const share = heroShareImage(blog, urlLang)
  const faq = blog.format === 'markdown' ? faqOf(text) : []
  const graph = [
    {
      '@type': 'BlogPosting',
      '@id': `${url}#article`,
      headline,
      description: ownLang(blog.meta_description, urlLang) || plain(text, 200, blog.format),
      inLanguage: urlLang,
      // the hero picture as an ImageObject with its size and caption (2026-11-17), else the first product block's photo
      ...(share
        ? { image: { '@type': 'ImageObject', url: share.url, ...(share.width ? { width: share.width } : {}), ...(share.height ? { height: share.height } : {}), caption: share.alt } }
        : firstImage
          ? { image: firstImage }
          : {}),
      datePublished: blog.published_at,
      dateModified: blog.modified_at || blog.published_at,
      // the shop's team, never a person (BUSINESS FACTS 8): an Organization with its author page
      author: { '@type': 'Organization', name: team, url: teamUrl },
      publisher: { '@type': 'Organization', name: SHOP, url: SITE, logo: { '@type': 'ImageObject', url: `${SITE}/logo.svg` } },
      mainEntityOfPage: { '@type': 'WebPage', '@id': url },
      ...(blog.tags?.length ? { keywords: blog.tags.join(', ') } : {}),
    },
    {
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: urlLang === 'ar' ? 'الرئيسية' : 'Home', item: `${SITE}${localePath('/', urlLang)}` },
        { '@type': 'ListItem', position: 2, name: urlLang === 'ar' ? 'المقالات' : 'Articles', item: `${SITE}${localePath('/blogs', urlLang)}` },
        { '@type': 'ListItem', position: 3, name: headline, item: url },
      ],
    },
    ...(faq.length >= 2
      ? [{ '@type': 'FAQPage', mainEntity: faq.map((q) => ({ '@type': 'Question', name: q.question, acceptedAnswer: { '@type': 'Answer', text: q.answer } })) }]
      : []),
    // the live product lists, as they are rendered (ItemList of the product pages)
    ...blocks.map((b) => ({
      '@type': 'ItemList',
      itemListElement: b.block.items.map((item, i) => ({
        '@type': 'ListItem',
        position: i + 1,
        url: `${SITE}${localePath(`/product/${item.slug}`, urlLang)}`,
        name: inLang(item.title, urlLang),
      })),
    })),
  ]

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd({ '@context': 'https://schema.org', '@graph': graph }) }} />
      <BlogClient blog={blog} team={team} />
    </>
  )
}
