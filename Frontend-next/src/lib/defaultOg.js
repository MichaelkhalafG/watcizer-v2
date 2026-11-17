import { SITE, SHOP_NAME } from './siteAddress'

// THE DEFAULT SHARE PICTURE (2026-11-17, the 2026-11-16 comparison's item 3). A TWIN.
//
// A page that sets its own `openGraph` REPLACES the root layout's (Next merges metadata one key deep), so the
// information pages — about, contact, FAQ, the policies, hot deals, flash sale, Original watches — went out with no
// og:image at all and a shared link showed no picture. They take the shop's own 1200×630 card (public/og-image.jpg,
// the root layout's) unless the page names a picture of its own.
export const DEFAULT_OG_IMAGE = { url: `${SITE}/og-image.jpg`, width: 1200, height: 630, alt: SHOP_NAME }

export function withDefaultOg(metadata) {
  const og = metadata?.openGraph || {}
  const images = og.images && (!Array.isArray(og.images) || og.images.length) ? og.images : [DEFAULT_OG_IMAGE]
  const first = Array.isArray(images) ? images[0] : images
  return {
    ...metadata,
    openGraph: { ...og, images },
    twitter: { card: 'summary_large_image', ...(metadata?.twitter || {}), images: [typeof first === 'string' ? first : first.url] },
  }
}
