import { describe, expect, it } from 'vitest'
import { heroOf, heroShareImage } from '../src/lib/blogHero'

// AN ARTICLE'S HERO PICTURE (2026-11-17): one reading for the page, og:image and the Article markup
const blog = {
  title: { en: 'How to choose a chronograph', ar: 'كيف تختار ساعة كرونوغراف' },
  cover: 'https://api.example/Uploads_Images/Product_image/a.webp',
  hero: {
    src: 'https://api.example/Uploads_Images/Product_image/a.webp',
    srcset: [
      { src: 'https://api.example/Uploads_Images/Product_image/a-320.webp', width: 320, format: 'webp' },
      { src: 'https://api.example/Uploads_Images/Product_image/a-640.webp', width: 640, format: 'webp' },
      { src: 'https://api.example/Uploads_Images/Product_image/a-320.avif', width: 320, format: 'avif' },
    ],
    width: 732,
    height: 1180,
    alt: { en: 'Michael Kors MK6452 chronograph', ar: 'ساعة مايكل كورس MK6452' },
    product: { slug: 'michael-kors-mk6452', title: { en: 'Michael Kors MK6452', ar: 'مايكل كورس MK6452' } },
  },
}

describe('heroOf', () => {
  it("takes core's stored picture, its alt text and product in the page's language", () => {
    const h = heroOf(blog, 'ar')
    expect(h.alt).toBe('ساعة مايكل كورس MK6452')
    expect(h.product).toEqual({ slug: 'michael-kors-mk6452', title: 'مايكل كورس MK6452' })
    expect(h.width).toBe(732)
    expect(heroOf(blog, 'en').alt).toBe('Michael Kors MK6452 chronograph')
  })
  it('falls back to the cover with the title as alt text, and to nothing', () => {
    expect(heroOf({ ...blog, hero: null }, 'en')).toEqual({ src: blog.cover, srcset: [], width: null, height: null, alt: 'How to choose a chronograph', product: null })
    expect(heroOf({ title: blog.title }, 'en')).toBeNull()
  })
})

describe('heroShareImage', () => {
  it('names the photo with its size and alt text', () => {
    expect(heroShareImage(blog, 'en')).toEqual({ url: blog.hero.src, width: 732, height: 1180, alt: 'Michael Kors MK6452 chronograph' })
  })
  it('takes the widest WebP rendition when the size is unknown', () => {
    expect(heroShareImage({ ...blog, hero: { ...blog.hero, width: null, height: null } }, 'en').url).toMatch(/a-640\.webp$/)
  })
})
