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

/**
 * Group a recorded srcset list by format, smallest width first.
 * @param {Array<{src: string, width: number, format: string}>|null|undefined} list
 * @returns {{ avif: string|null, webp: string|null, widths: number[] }}
 */
export function pictureSources(list) {
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
  for (const f of RENDITION_FORMATS) {
    const rows = byFormat[f].sort((a, b) => a.width - b.width)
    out[f] = rows.length ? rows.map((r) => `${r.src} ${r.width}w`).join(', ') : null
  }
  out.widths = [...widths].sort((a, b) => a - b)
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
