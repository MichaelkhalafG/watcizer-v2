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
export function getImageUrl(path, folder = '') {
  if (!path || typeof path !== 'string') return path ?? null

  const trimmed = path.trim()
  if (trimmed === '') return null

  // Absolute URL (http/https/protocol-relative) or data URI → use verbatim.
  if (/^(https?:)?\/\//i.test(trimmed) || trimmed.startsWith('data:')) return trimmed

  // Root-relative backend path (e.g. /storage/foo.jpg) → just prepend the base.
  if (trimmed.startsWith('/')) return `${ASSET_BASE}${trimmed}`

  // Bare filename → ASSET_BASE + /Uploads_Images/[folder/]filename
  const dir = folder ? `${folder}/` : ''
  return `${ASSET_BASE}/Uploads_Images/${dir}${trimmed}`
}

export default getImageUrl
