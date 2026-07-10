// Central image-URL helper.
//
// The catalog mixes image formats:
//  - Full external URLs (https://cdn-images.farfetch-contents.com/...) → use as-is
//  - Bare filenames (watch.jpg) uploaded to the backend → ASSET_BASE + /Uploads_Images/<folder>/
//  - Root-relative backend paths (/storage/...) → ASSET_BASE prepended
//
// getImageUrl never throws on null/undefined so callers can use it inline.

const ASSET_BASE = import.meta.env.VITE_ASSET_BASE || ''

// Simple inline gray placeholder (no external dependency, no binary asset needed).
export const PLACEHOLDER_IMG =
  'data:image/svg+xml;charset=UTF-8,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400">' +
      '<rect width="100%" height="100%" fill="#ececec"/>' +
      '<text x="50%" y="50%" font-family="Arial, sans-serif" font-size="22" fill="#a0a0a0" ' +
      'text-anchor="middle" dominant-baseline="middle">No image</text></svg>',
  )

// onError handler factory — swaps a broken image for the placeholder once.
export const handleImgError = (e) => {
  e.target.onerror = null
  e.target.src = PLACEHOLDER_IMG
}

/**
 * Build a usable <img src> from a stored image value.
 * @param {string|null|undefined} path  stored value (full URL, filename, or /path)
 * @param {string} folder  sub-folder under /Uploads_Images for bare filenames
 * @returns {string|null}
 */
export function getImageUrl(path, folder = 'Product') {
  if (!path || typeof path !== 'string') return path ?? null

  const trimmed = path.trim()
  if (trimmed === '') return null

  // Absolute URL (http/https/protocol-relative) or data URI → use verbatim.
  if (/^(https?:)?\/\//i.test(trimmed) || trimmed.startsWith('data:')) return trimmed

  // Root-relative backend path (e.g. /storage/foo.jpg, /Uploads_Images/…) →
  // prepend the base as-is (never double the /Uploads_Images segment).
  if (trimmed.startsWith('/')) return `${ASSET_BASE}${trimmed}`

  // Idempotent: a value that already carries a folder segment (e.g.
  // "Product/x.webp", from older seeder runs) is treated as Uploads_Images-
  // relative — the default folder is NOT prepended again, so we never emit the
  // doubled "Product_image/Product/…" path. Bare filenames get the folder.
  if (trimmed.includes('/')) return `${ASSET_BASE}/Uploads_Images/${trimmed}`

  return `${ASSET_BASE}/Uploads_Images/${folder}/${trimmed}`
}

// ── Responsive image URLs (opt-in via a CDN image resizer) ────────────────
// When VITE_IMAGE_CDN_BASE points at an image CDN/resizer origin, this returns
// { src, srcSet, sizes } so the browser can pick the right width. When it's
// empty (the default), it falls back to the plain resolved URL with no srcSet —
// zero behaviour change until a CDN is wired up. Absolute/external URLs and data
// URIs are never rewritten through the resizer.
//
// NOTE: catalog images arrive here already resolved to absolute URLs (the
// transform runs getImageUrl up front), so today this passes through. To
// actually emit resizer widths, either the backend must expose the raw filename
// OR the CDN must accept a full source URL (…/?url=<encoded>&w=…). Wired now so
// the switch is a one-line env change once the CDN is chosen.
export const IMAGE_CDN_BASE = import.meta.env.VITE_IMAGE_CDN_BASE || ''

export const getResponsiveImageUrl = (path, folder = 'Product') => {
  const base = getImageUrl(path, folder)
  const isAbsolute =
    typeof path === 'string' &&
    (/^(https?:)?\/\//i.test(path.trim()) || path.trim().startsWith('data:'))

  // No CDN, no path, or an already-absolute/data URL → passthrough (no srcSet).
  if (!base || !IMAGE_CDN_BASE || isAbsolute) {
    return { src: base, srcSet: null, sizes: null }
  }

  const widths = [320, 640, 960, 1200]
  const cdnUrl = (w) => `${IMAGE_CDN_BASE}/${folder}/${path}?w=${w}&format=auto`
  return {
    src: cdnUrl(640),
    srcSet: widths.map((w) => `${cdnUrl(w)} ${w}w`).join(', '),
    sizes:
      '(max-width: 400px) 320px, (max-width: 768px) 640px, (max-width: 1200px) 960px, 1200px',
  }
}

export default getImageUrl
