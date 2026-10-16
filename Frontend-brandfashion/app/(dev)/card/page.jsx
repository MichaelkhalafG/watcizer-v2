import { notFound } from 'next/navigation'
import { devPageEnabled } from '@/src/lib/devPages'
import { readTokenSheet } from '@/src/lib/tokenSheet'
import { contrast, lightness, r2, REQUIREMENT } from '@/src/lib/contrast'
import { multiply } from '@/src/lib/blend'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import { txt, cardColours } from '@/src/lib/cardData'
import './card.css'

/*
 * /card — build step B3 (BRAND_FASHION_UI_SPEC v2.6 §20): "The product card, all states — in stock,
 * sold out, sale, new, with and without a photo, at EGP 110 and EGP 214,380, en and ar — done when
 * the card survives the real catalogue at both price extremes."
 *
 * Development only (src/lib/devPages.js). Every card below is the real BfProductCard on real v2
 * rows from the dev API; the blend table is computed from the token sheet at request time.
 */

export const dynamic = 'force-dynamic'
export const metadata = { title: 'Card — Brand Fashion (dev gate)', robots: { index: false, follow: false } }

const API = process.env.TOKENS_API_BASE || 'http://127.0.0.1:8000/api'
const DEMO = [
  { id: 25649, slug: 'hugo-boss-mens-green-dial-chronograph-watch-1514227', why: 'the dead colour: Black is out in every size; +150 on 44 mm' },
  { id: 25648, slug: 'hugo-boss-mens-grand-prix-chronograph-watch-with-green-dial-and-gunmetal-gray-stainless-steel-bracelet-1514228', why: 'the hole: one colour × size has no row' },
  { id: 25644, slug: 'hugo-boss-mens-grand-prix-chronograph-watch-with-black-dial-and-silver-stainless-steel-bracelet-1514251', why: 'sizes only: no colour on the rows' },
  { id: 7120, slug: 'joyroom-jr-bp560-excellent-series-portable-passive-stylus-pen', why: 'electronics: 0 attribute colours — the swatch row is reserved and empty; sold out' },
]
// §5 / §20 stress prices — the spec's figures, from the 7,759-product catalogue of 2026-10-07.
const SPEC_PRICE_LOW = 110
const SPEC_PRICE_HIGH = 214380
// photo backgrounds the multiply guard in §8.3 names: white studio, the tile, grey and beige sweeps
const PHOTO_BACKGROUNDS = [
  { k: 'white studio', hex: '#FFFFFF' },
  { k: 'tile grey', hex: '#F4F4F4' },
  { k: 'light grey sweep', hex: '#CCCCCC' },
  { k: 'beige sweep', hex: '#E8DCC8' },
  { k: 'mid grey sweep', hex: '#808080' },
]
const TINTS = ['tint-watch', 'tint-fashion', 'tint-leather', 'tint-tech']

async function get(path) {
  try {
    const res = await fetch(`${API}/v2/brandfashion/${path}`, { cache: 'no-store', headers: { Accept: 'application/json', 'Accept-Language': 'en' } })
    if (!res.ok) return { error: `HTTP ${res.status}` }
    return await res.json()
  } catch (e) {
    return { error: e?.message || String(e) }
  }
}
export default async function CardPage() {
  if (!devPageEnabled()) notFound()
  const sheet = readTokenSheet()
  const tok = (name) => {
    const v = sheet.byName[`--wz-${name}`]
    if (!v) throw new Error(`card page: --wz-${name} is not in ${sheet.file}`)
    return v
  }

  // ── 1. post-blend pairs ─────────────────────────────────────────────────────────────────────────
  // The badge is OUTSIDE the blend (only the <img> multiplies) and since R20 carries a 1px `page` ring:
  // its inner boundary is fill-vs-ring — a flat pair, the same on every surface — and the ring's own
  // boundary against the blended surround is what reads where the fill alone did not.
  const ringSoldOut = contrast(tok('page'), tok('ink-3'))
  const ringNew = contrast(tok('page'), tok('new'))
  const blend = []
  for (const t of TINTS) {
    for (const bg of PHOTO_BACKGROUNDS) {
      const surface = multiply(bg.hex, tok(t))
      const ringVsSurface = contrast(tok('page'), surface)
      const soldOut = contrast(tok('ink-3'), surface)
      const isNew = contrast(tok('new'), surface)
      blend.push({
        tint: t, bg: bg.k, bgHex: bg.hex, surface, lstar: lightness(surface),
        soldOut, isNew, ringVsSurface,
        // what a shopper can see of the badge's edge: its fill against the surround, or its ring against it
        perceivableSoldOut: Math.max(soldOut, ringVsSurface), perceivableNew: Math.max(isNew, ringVsSurface),
        ink: contrast(tok('ink'), surface), ink3: contrast(tok('ink-3'), surface),
      })
    }
  }
  const boundaryFails = blend.filter((b) => b.soldOut < REQUIREMENT.ui || b.isNew < REQUIREMENT.ui)
  const perceivableFails = blend.filter((b) => b.perceivableSoldOut < REQUIREMENT.ui || b.perceivableNew < REQUIREMENT.ui)
  const innerConstant = new Set(blend.map(() => `${r2(ringSoldOut)}/${r2(ringNew)}`)).size === 1
  // the control for the blend: a mid-grey square (a data-URI image, no network) multiplied over each tint,
  // beside the flat grey and the computed blend — the tile must match the computed swatch, not the flat one
  const GREY = '#808080'
  const greySvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="400" height="400" fill="${GREY}"/></svg>`)}`

  // ── 2–5. real data ──────────────────────────────────────────────────────────────────────────────
  const [details, newest, electronics, cheapest, dearest] = await Promise.all([
    Promise.all(DEMO.map(async (d) => ({ ...d, product: (await get(`products/${d.slug}`)).product ?? null }))),
    get('products?sort=newest&per_page=12'),
    get('products?category=electronics&sort=position&per_page=24'),
    get('products?sort=price_asc&per_page=1'),
    get('products?sort=price_desc&per_page=1'),
  ])
  const shelf = newest.data ?? []
  const elec = electronics.data ?? []
  const low = cheapest.data?.[0] ?? null
  const high = dearest.data?.[0] ?? null
  const apiDown = !shelf.length && !low
  const withPrice = (p, amount, sale) => p && { ...p, price: { ...p.price, amount, sale_amount: sale, has_sale: sale != null, discount_pct: sale != null ? Math.round((1 - sale / amount) * 100) : null } }
  const stressLow = withPrice(low ?? shelf[0], SPEC_PRICE_LOW, 89)
  const stressHigh = withPrice(high ?? shelf[0], SPEC_PRICE_HIGH, 171504)
  const soldOutReal = elec[0] ?? shelf.find((p) => !p.stock?.in_stock) ?? null
  const inStockReal = shelf.find((p) => p.stock?.in_stock) ?? null
  const noPhoto = inStockReal && { ...inStockReal, image: null }
  const outOfStockShare = shelf.length ? shelf.filter((p) => !p.stock?.in_stock).length : 0

  return (
    <main className="cd" id="main-content">
      <h1>The product card — the B3 gate</h1>
      <p className="meta">Every card is <code>BfProductCard</code> on real rows from <code>{API}/v2/brandfashion/…</code> (dev database, demo on); the blend table is computed from <code>{sheet.file}</code>.</p>
      <div className="gate">
        <strong>What §20 requires of B3:</strong> &ldquo;The product card, all states — in stock, sold out, sale, new, with and without a photo, at EGP 110 and EGP 214,380,
        en and ar — done when the card survives the real catalogue at both price extremes.&rdquo;{' '}
        <strong>The catalogue&apos;s extremes today</strong> (dev API, sort price_asc / price_desc): {low ? `EGP ${low.price.amount.toLocaleString('en-EG')} (${txt(low.title)})` : '—'} to{' '}
        {high ? `EGP ${high.price.amount.toLocaleString('en-EG')} (${txt(high.title)})` : '—'} — §5 and §20 still print 110 and 214,380, the extremes of the 7,759-product catalogue of 2026-10-07;
        both the spec&apos;s stress values and today&apos;s real ends are rendered below.
        <br /><strong>Post-blend result:</strong> {blend.length} tint × photo-background surfaces measured; badge FILL against the blended surround under 3:1: <strong>{boundaryFails.length}</strong>
        {boundaryFails.length ? ` (${boundaryFails.map((b) => `${b.tint} over ${b.bg}: sold-out ${r2(b.soldOut)}, new ${r2(b.isNew)}`).join('; ')})` : ''}.{' '}
        <strong>With the 1px page ring (R20):</strong> the badge&apos;s inner boundary is {innerConstant ? 'a CONSTANT' : 'NOT constant'} across all {blend.length} surfaces —
        sold-out <strong>{r2(ringSoldOut)}</strong>, new <strong>{r2(ringNew)}</strong> (the tokens page&apos;s flat pairs page-on-ink-3 and page-on-new) — and the badge&apos;s
        perceivable edge (fill or ring against the surround, whichever is stronger) is under 3:1 on <strong>{perceivableFails.length}</strong> surface{perceivableFails.length === 1 ? '' : 's'};
        worst case {r2(Math.min(...blend.map((b) => Math.min(b.perceivableSoldOut, b.perceivableNew))))}.
      </div>
      {apiDown ? <div className="notice">The dev API did not answer ({newest.error ?? cheapest.error}); start <code>php artisan serve</code> in core/ or set <code>TOKENS_API_BASE</code>.</div> : null}

      <h2>1. The blend: what `mix-blend-mode: multiply` makes of the tint, and what sits on it</h2>
      <p className="meta">
        §8.3 multiplies the photo into the aisle tint. Multiply is per channel (photo × tint ÷ 255), so under a white studio background the surface IS the tint, and under a
        grey or beige sweep it is darker than any token. Text never sits on a photo (§7.1) and the card body is on <code>page</code>, so the 78 flat ratios cover every text pair;
        the one thing laid over the blended surface is the <strong>badge pill</strong>, which carries its own fill (white on ink-3 5.74, white on new 6.35 — flat, measured on /tokens).
        Measured here: the pill FILL against the blended surface — a graphic boundary, WCAG 1.4.11, {REQUIREMENT.ui}:1 — and, for reference, ink and ink-3 text on the surface, which the card never does.
      </p>
      <table>
        <thead><tr><th>tint</th><th>photo background</th><th>blended surface</th><th className="num">L*</th><th className="num">sold-out fill vs surround</th><th className="num">new fill vs surround</th><th className="num">ring (page) vs surround</th><th className="num">inner: fill vs ring</th><th className="num">perceivable edge (worst of the two pills)</th></tr></thead>
        <tbody>
          {blend.map((b) => {
            const perceivable = Math.min(b.perceivableSoldOut, b.perceivableNew)
            return (
              <tr key={`${b.tint}/${b.bg}`}>
                <td><code>{b.tint}</code></td>
                <td><span className="swatch" style={{ background: b.bgHex }} />{b.bg} <small>{b.bgHex}</small></td>
                <td><span className="swatch" style={{ background: b.surface }} /><code>{b.surface}</code> <span className="pillx" style={{ background: tok('ink-3'), marginInlineStart: 8, outline: `6px solid ${b.surface}`, boxShadow: `0 0 0 1px ${tok('page')}` }}>Sold out</span></td>
                <td className="num">{r2(b.lstar)}</td>
                <td className={`num ${b.soldOut >= REQUIREMENT.ui ? 'pass' : 'fail'}`}>{r2(b.soldOut)}</td>
                <td className={`num ${b.isNew >= REQUIREMENT.ui ? 'pass' : 'fail'}`}>{r2(b.isNew)}</td>
                <td className="num">{r2(b.ringVsSurface)}</td>
                <td className="num">{r2(ringSoldOut)} / {r2(ringNew)}</td>
                <td className={`num ${perceivable >= REQUIREMENT.ui ? 'pass' : 'fail'}`}>{r2(perceivable)} {perceivable >= REQUIREMENT.ui ? 'pass' : 'FAIL'}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      <h3>The control: the card still multiplies — a mid-grey square over each tint, beside the flat grey and the computed blend</h3>
      <p className="meta">The image is a data-URI {GREY} square (no network). If the tile reads as the right-hand swatch (the computed multiply) and not as the middle one (flat grey), the photo layer still blends; the badge sits on it unblended, with its ring.</p>
      <div className="grid" style={{ '--cols': 4 }}>
        {TINTS.map((t) => (
          <div key={t}>
            <BfProductCard product={{ id: `ctl-${t}`, slug: 'control', family: t === 'tint-watch' ? 'watch' : t === 'tint-fashion' ? 'fashion' : t === 'tint-tech' ? 'electronics' : 'bag', title: { en: `control — ${t}` }, brand: { name: { en: 'control' } }, image: { src: greySvg }, price: { amount: 1000, sale_amount: 800, has_sale: true, discount_pct: 20, currency: 'EGP' }, stock: { in_stock: false }, colors: [] }} lang="en" />
            <div className="cap">
              <span className="swatch" style={{ background: GREY }} /> flat {GREY} ·{' '}
              <span className="swatch" style={{ background: multiply(GREY, tok(t)) }} /> computed multiply {multiply(GREY, tok(t))}
            </div>
          </div>
        ))}
      </div>

      <h2>2. The four real shapes from the demo data</h2>
      <div className="grid" style={{ '--cols': 4 }}>
        {details.map(({ id, why, product }) => (
          <div key={id}>
            {product ? <BfProductCard product={product} lang="en" /> : <div className="notice">product {id}: not served</div>}
            <div className="cap">{id} — {why}{product ? ` · swatch row: ${cardColours(product).length ? cardColours(product).map((c) => txt(c.name)).join(', ') : 'empty, reserved'}` : ''}</div>
          </div>
        ))}
      </div>

      <h2>3. Both price extremes — the spec&apos;s stress values and today&apos;s real ends, en and ar, comfortable and compact</h2>
      {stressLow && stressHigh ? (
        <>
          <div className="grid" style={{ '--cols': 4 }}>
            <div><BfProductCard product={stressLow} lang="en" /><div className="cap">EGP {SPEC_PRICE_LOW} — synthetic (the spec&apos;s low), on a real card</div></div>
            <div><BfProductCard product={stressHigh} lang="en" /><div className="cap">EGP {SPEC_PRICE_HIGH.toLocaleString('en-EG')} with a struck EGP 214,380 and −20% — synthetic (the spec&apos;s high); the price row must not wrap</div></div>
            <div><BfProductCard product={stressLow} lang="ar" /><div className="cap">the same low price, Arabic</div></div>
            <div><BfProductCard product={stressHigh} lang="ar" /><div className="cap">the same high price, Arabic</div></div>
          </div>
          <h3>Today&apos;s real extremes</h3>
          <div className="grid" style={{ '--cols': 4 }}>
            {low ? <div><BfProductCard product={low} lang="en" /><div className="cap">cheapest visible product today ({low.id})</div></div> : null}
            {high ? <div><BfProductCard product={high} lang="en" /><div className="cap">dearest visible product today ({high.id})</div></div> : null}
            {high ? <div><BfProductCard product={high} lang="en" density="compact" /><div className="cap">compact density (rails)</div></div> : null}
            {high ? <div><BfProductCard product={high} lang="ar" density="compact" /><div className="cap">compact, Arabic</div></div> : null}
          </div>
          <h3>Phone width (390): 2 across, 8px gap, the price block at the stress values</h3>
          <div className="phone"><div className="grid"><BfProductCard product={stressHigh} lang="en" /><BfProductCard product={stressHigh} lang="ar" /></div></div>
          <h3>The breaking point: the same 169px card at ever larger prices (R19), en and ar — measured by scrollWidth in the browser</h3>
          <p className="meta">Each ladder card is <code>data-ladder</code>-tagged; the browser measurement reads whether either price line overflows its card.</p>
          <div className="phone"><div className="grid">
            {[99999, 999999, 9999999, 99999999, 999999999].flatMap((amount) => ['en', 'ar'].map((lang) => (
              <div key={`${amount}-${lang}`} data-ladder={amount} data-lang={lang}>
                <BfProductCard product={withPrice(high ?? shelf[0], amount, Math.round(amount * 0.8))} lang={lang} />
                <div className="cap">{amount.toLocaleString('en-EG')} · {lang}</div>
              </div>
            )))}
          </div></div>
        </>
      ) : null}

      <h2>4. Every state the spec names</h2>
      <div className="grid" style={{ '--cols': 4 }}>
        {inStockReal ? <div><BfProductCard product={inStockReal} lang="en" /><div className="cap">in stock, on sale (as 100% of the catalogue is): struck price + red −%, no pill</div></div> : null}
        {soldOutReal ? <div><BfProductCard product={soldOutReal} lang="en" /><div className="cap">sold out: ink-3 pill, photo NOT dimmed, secondary &ldquo;Notify me&rdquo;</div></div> : null}
        {inStockReal ? <div><BfProductCard product={inStockReal} lang="en" isNew /><div className="cap">new (forced — what &ldquo;new&rdquo; means is §19-13, open)</div></div> : null}
        {noPhoto ? <div><BfProductCard product={noPhoto} lang="en" /><div className="cap">no photo: the tile stays, the inline SVG placeholder, the name carries the card</div></div> : null}
        {inStockReal ? <div><BfProductCard product={inStockReal} lang="ar" /><div className="cap">Arabic: RTL, no letter-spacing, Western digits, ج.م after the number</div></div> : null}
        {soldOutReal ? <div><BfProductCard product={soldOutReal} lang="ar" isNew /><div className="cap">sold out AND new: one badge only — sold out wins</div></div> : null}
        {inStockReal ? <div className="tinted" style={{ background: `var(--wz-${inStockReal.family === 'watch' ? 'tint-watch' : 'tint-leather'})` }}><BfProductCard product={inStockReal} lang="en" ground="tint" /><div className="cap">on a tinted ground: the card drops its tint for `tile` (the tint rule)</div></div> : null}
        {inStockReal ? <div><BfProductCard product={inStockReal} lang="en" tintMode="never" /><div className="cap">tintMode=never (the multiply guard for a discoloured family)</div></div> : null}
      </div>

      <h2>5. The catalogue as it is: every card on sale, and electronics&apos; first page</h2>
      <p className="meta">The 12 newest visible products ({shelf.length} served; {outOfStockShare} of them sold out): every one carries a struck price and a red −%. Does it still read as a shelf when every card shouts? The judgement is the reader&apos;s; the measurement is below it.</p>
      <div className="shelf">{shelf.map((p) => <BfProductCard key={p.id} product={p} lang="en" />)}</div>
      <h3>Electronics, page 1 as `position` orders it: {elec.length} cards, {elec.filter((p) => !p.stock?.in_stock).length} sold out</h3>
      <div className="shelf">{elec.map((p) => <BfProductCard key={p.id} product={p} lang="en" />)}</div>
    </main>
  )
}
