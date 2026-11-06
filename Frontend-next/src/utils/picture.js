// The <picture> for a catalogue image, from what the API RECORDS and nothing else (2026-10-22).
//
// v2 image objects carry `srcset: [{ src, width, format }]` — the renditions `ImagePipeline` wrote
// beside the master and recorded on the row after checking each file has bytes. Compat rows carry
// the same list as `image_srcset` (the cover) and `srcset` (a gallery row). This helper turns that
// list into the two `<source>` strings a <picture> needs, AVIF first, WebP second, each as
// `url 160w, url 320w, …`; the original stays the <img>'s src as the fallback for a browser that
// takes neither, and for the ~7 % of rows that record no renditions, where the list is empty and the
// picture is exactly the plain <img> it was before.
//
// NEVER build a rendition URL from the naming convention here: a width that is not in the list is
// a file nobody checked exists, and a 404 is a broken photo on a live shop.

export const RENDITION_FORMATS = ['avif', 'webp']

/** The widest rendition a CARD can use: its photo is at most 320 CSS px wide (SIZES below), 640 on a 2× screen. */
export const CARD_MAX = 640

/**
 * Group a recorded srcset list by format, smallest width first.
 * `max` (the performance pass, 2026-11-05): leave out the renditions wider than this — a card passes CARD_MAX, so
 * its markup does not carry a 960 px file no card can choose. A list with nothing at or under `max` is kept whole.
 * @param {Array<{src: string, width: number, format: string}>|null|undefined} list
 * @param {number} [max]
 * @returns {{ avif: string|null, webp: string|null, widths: number[] }}
 */
export function pictureSources(list, max = Infinity) {
  const out = { avif: null, webp: null, widths: [] }
  if (!Array.isArray(list) || list.length === 0) return out
  const byFormat = { avif: [], webp: [] }
  const widths = new Set()
  for (const r of list) {
    if (!r || typeof r.src !== 'string' || !r.src) continue
    const w = Number(r.width)
    if (!Number.isFinite(w) || w <= 0) continue
    if (!RENDITION_FORMATS.includes(r.format)) continue
    byFormat[r.format].push({ src: r.src, width: w })
    widths.add(w)
  }
  const capped = [...widths].some((w) => w <= max)
  for (const f of RENDITION_FORMATS) {
    const all = byFormat[f].sort((a, b) => a.width - b.width)
    const rows = capped ? all.filter((r) => r.width <= max) : all
    out[f] = rows.length ? rows.map((r) => `${r.src} ${r.width}w`).join(', ') : null
  }
  out.widths = [...widths].filter((w) => !capped || w <= max).sort((a, b) => a - b)
  return out
}

/** True when the list records at least one rendition — the only case a <picture> adds anything. */
export const hasRenditions = (list) =>
  Array.isArray(list) && list.some((r) => r && typeof r.src === 'string' && r.src !== '' && Number(r.width) > 0 && RENDITION_FORMATS.includes(r.format))

/*
 * The `sizes` values, MEASURED on 2026-10-22 (the rendered width of the <img>, device-pixel-ratio 1):
 *
 *   Watchizer card    home rail 322 px and listing 262 px at 1440; 301 / 232 at 1024; 358 / 235 at 768;
 *                     171 / 176 px at 390
 *   Brand Fashion card 155–188 px at 1440 (rails and listing); 105–140 at 1024; 186–195 at 768; 134–145 at 390
 *   gallery main image 600 px at 1440 on both shops; 358 px at 390
 *   gallery thumbnail  38 px (Watchizer), 54–56 px (Brand Fashion) at 1440
 *
 * Tablets are 2× devices, so 30vw at 768 (230 CSS px) asks for 460 and takes the 480 rung, which is
 * what a 358 px rail card on such a screen needs; at device-pixel-ratio 1 it takes the 320.
 *
 * The browser takes the smallest rung at or above `sizes` × device-pixel ratio, so a value a few
 * pixels ABOVE a rung costs a whole rung: 330px on a 322 px card chose the 480 file (median 10 KB)
 * where 320px chooses the 320 file (measured 2026-10-22, after2 vs after3). A 322 px card drawing a
 * 320 px photo is a 0.6 % stretch nobody can see; a 3× phone at 171 px asks for 513 and gets 640.
 */
/**
 * A photo that has NO recorded rendition, at the size a card draws it (the performance pass, 2026-11-05). A card
 * offers the API's renditions when a photo has them; when it has none the card used to load the 1200 px master for
 * a 220 px box — measured on the Hot Deals page: 597 KB of pictures, its largest painting at 3.9 s on a phone. Such
 * a photo now goes through the shop's own image optimiser (next.config allows the asset hosts' /Uploads_Images/
 * only, and these widths). Anything that is not an upload on an asset host is returned as it is.
 */
export const optimized = (src, width = 320, quality = 75) =>
  typeof src === 'string' && /^https:\/\/[^/]+\/Uploads_Images\//.test(src) ? `/_next/image?url=${encodeURIComponent(src)}&w=${width}&q=${quality}` : src
/** `srcSet` for the same photo on a dense screen; undefined when the photo is not one the optimiser takes. */
export const optimizedSet = (src) => (optimized(src) !== src ? `${optimized(src, 320)} 1x, ${optimized(src, 640)} 2x` : undefined)

/**
 * A WIDE picture with no recorded rendition — a dashboard banner — as a `srcSet` the browser chooses from by the
 * width it will draw (the performance pass, 2026-11-05): the optimiser's copies at these widths (each one of
 * next.config's sizes), then THE FILE ITSELF as the widest entry, so a large 2× screen still gets the full
 * artwork exactly as before. Measured on both homes: a phone downloaded the 1,600 px banners whole (168 KB and
 * 312 KB) for a 390 px slot. undefined when the picture is not one the optimiser takes (it is then drawn as it was).
 */
export const BANNER_WIDTHS = [640, 768, 1024, 1200]
export const optimizedWide = (src, { widths = BANNER_WIDTHS, quality = 80, full = 1600 } = {}) =>
  optimized(src) !== src ? `${widths.map((w) => `${optimized(src, w, quality)} ${w}w`).join(', ')}, ${src} ${full}w` : undefined

export const SIZES = {
  // two cards a row on a phone, three on a tablet, four (plus a sidebar) on a desktop
  card: '(max-width: 599px) 46vw, (max-width: 1023px) 30vw, 320px',
  // Brand Fashion's denser grid: three a row on a phone's rails, up to six on a desktop
  cardDense: '(max-width: 599px) 38vw, (max-width: 1023px) 24vw, 190px',
  // the gallery's main photo: the full width of a phone, 600 px in its desktop column
  galleryMain: '(max-width: 1023px) 92vw, 600px',
  thumb: '48px',
  thumbLarge: '64px',
}
