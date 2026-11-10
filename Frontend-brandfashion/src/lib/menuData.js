// The menu's data as the page carries it (the developer's review, 2026-11-05, item 36). A TWIN.
//
// WHY IT IS IN THE PAGE: the menu was asked of core on the FIRST HOVER, so the first panel a shopper opened read
// "Loading the menu…" for as long as that request took (measured: 0.3–0.4 s on the local core, 95 KB of JSON).
// The menu is the same for every visitor and core caches it, so the (main) layout now puts it in the server-rendered
// page and it is there before any hover or tap — on a cold first visit and on every later page.
//
// WHY IT IS SLIMMED: core's payload carries, for each of ~340 pictures, the whole ladder of renditions (every
// width, AVIF and WebP). The panel draws one small picture each. `slimMenu` keeps ONE URL per picture, at the
// size the panel draws it, and drops the ladder — measured, 95 KB → about a third of that before compression —
// and the picture it keeps is a small rendition instead of the 1200 px master the panel used to load.
//
// The browser's own request for the menu (only if the server could not seed it, or after its ten minutes) goes
// through the same function, so the two shapes cannot differ.

// ONE URL per picture, AT THE SIZE THE PANEL DRAWS IT (the performance pass, 2026-11-05). The first cut kept a
// recorded rendition when the photo had one and the MASTER when it had none — and a category's own picture has none:
// measured on a category page, the idle warm-up pulled 270 KB of 600 px pictures for 107 px tiles, and a phone
// loaded two 1200 px product photos (214 KB) for 44 px rows of its closed drawer. Every menu picture now goes
// through the shop's own image optimiser (the same one the product cards use): `w` is one of the sizes next.config
// allows, AVIF or WebP by what the browser takes, cached by the optimiser for an hour and by the browser after.
const SIZE = { row: 128, tile: 256, feature: 420 }
const pick = (image, w) => (image?.src ? { src: `/_next/image?url=${encodeURIComponent(image.src)}&w=${w}&q=70` } : null)

const product = (p, want) => (p ? { id: p.id, slug: p.slug, title: p.title, brand: p.brand ? { name: p.brand.name } : null, image: pick(p.image, want), price: p.price } : null)

const node = (n) => ({
  id: n.id,
  slug: n.slug,
  legacy_id: n.legacy_id,
  name: n.name,
  count: n.count,
  image: pick(n.image, SIZE.row),
  children: (n.children || []).map((c) => ({ id: c.id, slug: c.slug, legacy_id: c.legacy_id, name: c.name, count: c.count, image: pick(c.image, SIZE.tile) })),
  brands: (n.brands || []).map((b) => ({ id: b.id, slug: b.slug, name: b.name, count: b.count })),
  featured: n.featured?.product ? { ...n.featured, product: product(n.featured.product, SIZE.feature) } : null,
  picks: (n.picks || []).map((p) => product(p, SIZE.row)),
})

/**
 * The menu's TOP LEVEL alone — a slug, a path and a name per category — for a page whose "nothing running" state
 * lists the shop's categories (the campaign pages, 2026-11-07). A few hundred bytes in the page instead of the whole
 * slimmed menu, and the same list on the server and in the browser's first render.
 */
export function idleMenu(menu) {
  if (!menu) return null
  return { slim: true, idle: true, categories: (menu.categories || []).map((c) => ({ id: c.id, slug: c.slug, path: c.path, name: c.name })) }
}

export function slimMenu(menu) {
  if (!menu || menu.slim) return menu || null
  return {
    slim: true,
    categories: (menu.categories || []).map(node),
    genders: (menu.genders || []).map(node),
    brands: (menu.brands || []).map((b) => ({ id: b.id, slug: b.slug, name: b.name, count: b.count, logo: pick(b.logo, SIZE.row) })),
    sale: menu.sale
      ? {
          count: menu.sale.count,
          max_discount: menu.sale.max_discount,
          categories: (menu.sale.categories || []).map((c) => ({ ...c, image: pick(c.image, SIZE.tile) })),
          brands: menu.sale.brands || [],
        }
      : null,
  }
}

/** The pictures the panels draw, once each: `first` — the first section's alone; otherwise every section's. */
export function menuImageUrls(menu, first = false) {
  const out = new Set()
  const add = (image) => image?.src && out.add(image.src)
  const sections = [...(menu?.categories || []), ...(menu?.genders || [])]
  for (const n of first ? sections.slice(0, 1) : sections) {
    for (const c of n.children || []) add(c.image)
    add(n.featured?.product?.image)
    for (const p of n.picks || []) add(p?.image)
  }
  if (!first) {
    for (const b of menu?.brands || []) add(b.logo)
    for (const c of menu?.sale?.categories || []) add(c.image)
  }
  return [...out]
}

const warmed = { first: false, all: false }
/**
 * Load the panels' pictures ahead of their use, so a panel does not open on empty tiles — GENTLY: four at a time,
 * one after another (a hundred requests at once is what a bot looks like to the image host, and a burst a visitor's
 * connection does not need).
 *
 *   'first'  the first section's pictures — asked for when the pointer first moves near the menu (MegaMenu.jsx
 *            `nearMenu`, 2026-11-06; until then on a clock, 2.5 s after every page load), six at a time: by
 *            then the visitor is on the way to an entry
 *   'all'    every section's — asked for the first time the panel opens (or the pointer reaches the header), so
 *            moving from one entry to the next finds its pictures there
 *
 * Once per page view each; only where there is a pointer that can hover (a phone opens its drawer by a tap and
 * loads that drawer's few pictures then), and never for a visitor who asked the browser to save data.
 */
export function warmMenuImages(menu, scope = 'first') {
  if (typeof window === 'undefined' || !menu || warmed[scope]) return
  if (window.matchMedia && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return
  if (navigator.connection?.saveData) return
  warmed[scope] = true
  const urls = menuImageUrls(menu, scope === 'first')
  let i = 0
  const next = () => {
    if (i >= urls.length) return
    const img = new Image()
    img.decoding = 'async'
    img.onload = next
    img.onerror = next
    img.src = urls[i++]
  }
  for (let k = 0; k < (scope === 'first' ? 6 : 4); k++) next()
}
