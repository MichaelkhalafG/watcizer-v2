// The density rule of BRAND_FASHION_UI_SPEC §3, as a measurement (B3, 2026-10-16; B6 builds against it).
//
//   "On a 390×844 phone, scrolling the home page passes a product card at least once every 0.8
//    viewport heights. No band that is not products — hero, promo, brand strip, category squares —
//    exceeds 0.35 viewport heights, and no two such bands are adjacent."
//
// Run in the browser on the page under test (paste into the console, or call from a dev page):
//   const { measureDensity, measureDensityAt } = await import('/src/lib/density.js')  // or a bundle
//   measureDensity(window)            // the current viewport
//   await measureDensityAt('/', 390, 844)  // a 390×844 iframe of the same page, same origin
//
// Cards are `.bf-card` (the Brand Fashion card) or `.wz-pc` (the Watchizer card the app still ships
// until B4/B6). Bands are the direct children of `.wz-home` (or `main`); a band "is products" when it
// contains a card. Figures are viewport heights, two decimals.

export const DENSITY_RULE = Object.freeze({ maxGapVh: 0.8, maxBandVh: 0.35, adjacentNonProductAllowed: 0 })

export function measureDensity(w = window) {
  const d = w.document
  const H = w.innerHeight
  const cards = [...d.querySelectorAll('.bf-card, .wz-pc')].filter((e) => e.getClientRects().length)
  const tops = cards.map((e) => Math.round(e.getBoundingClientRect().top + w.scrollY)).sort((a, b) => a - b)
  const gaps = []
  let prev = 0
  for (const t of tops) { gaps.push(t - prev); prev = t }
  const root = d.querySelector('.wz-home') || d.querySelector('main') || d.body
  const bands = [...root.children]
    .map((el) => {
      const r = el.getBoundingClientRect()
      return { cls: String(el.className || el.tagName).slice(0, 40), h: Math.round(r.height), vh: +(r.height / H).toFixed(2), hasCard: !!el.querySelector('.bf-card, .wz-pc') }
    })
    .filter((b) => b.h > 0)
  const nonProduct = bands.filter((b) => !b.hasCard)
  let adjacent = 0
  for (let i = 1; i < bands.length; i++) if (!bands[i].hasCard && !bands[i - 1].hasCard) adjacent++
  const maxGapVh = gaps.length ? +(Math.max(...gaps) / H).toFixed(2) : null
  const over = nonProduct.filter((b) => b.vh > DENSITY_RULE.maxBandVh)
  return {
    viewport: { W: w.innerWidth, H },
    cards: cards.length,
    firstCardTopVh: tops.length ? +(tops[0] / H).toFixed(2) : null,
    maxGapVh,
    bandsOver035: over.map((b) => `${b.cls} ${b.vh}vh`),
    adjacentNonProductPairs: adjacent,
    bands: bands.map((b) => `${b.hasCard ? 'cards' : 'band '} ${b.cls} ${b.vh}vh`),
    passes: {
      cardEvery08vh: maxGapVh !== null && maxGapVh <= DENSITY_RULE.maxGapVh,
      noBandOver035vh: over.length === 0,
      noAdjacentBands: adjacent === DENSITY_RULE.adjacentNonProductAllowed,
    },
  }
}

/** The same page in a same-origin iframe of the given size — the phone measurement without resizing the browser. */
export async function measureDensityAt(path = '/', width = 390, height = 844, settleMs = 5000) {
  const f = document.createElement('iframe')
  f.style.cssText = `position:fixed;left:0;top:0;width:${width}px;height:${height}px;border:0;z-index:99999;background:#fff`
  f.src = path
  document.body.appendChild(f)
  await new Promise((resolve) => { f.onload = () => setTimeout(resolve, settleMs) })
  const result = measureDensity(f.contentWindow)
  f.remove()
  return result
}
