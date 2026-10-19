import { notFound } from 'next/navigation'
import { devPageEnabled } from '@/src/lib/devPages'
import { readTokenSheet } from '@/src/lib/tokenSheet'
import { contrast, lightness, r2, verdict, REQUIREMENT } from '@/src/lib/contrast'
import { aisleTint, aisleTintIsDefault, FAMILIES, TINT_BY_FAMILY, DEFAULT_TINT } from '@/src/lib/aisleTint'
import './tokens.css'

/*
 * /tokens — build step B2 (BRAND_FASHION_UI_SPEC v2.5 §20): "a tokens page renders every token and
 * every contrast pairing". That is the gate, and this page is built to BE the gate rather than to
 * illustrate it: every ratio below is computed at request time from the values in app/globals.css
 * (src/lib/tokenSheet.js → src/lib/contrast.js); nothing numeric is typed into this file except the
 * spec's OWN printed claims, kept in SPEC_SAYS so the page can measure the spec and print the delta.
 *
 * Development only: src/lib/devPages.js answers false on a production build unless
 * NEXT_PUBLIC_DEV_PAGES=1 was set at build time, and then notFound() makes the route a 404.
 */

export const dynamic = 'force-dynamic'
export const metadata = { title: 'Tokens — Brand Fashion (dev gate)', robots: { index: false, follow: false } }

// §4 — the spec's colour tokens and the token in the sheet that carries each. The NAMES and the usage
// rules are the spec's words; the VALUES come from the sheet at request time.
const COLOUR_TOKENS = [
  { spec: 'ink', css: '--wz-ink', group: '4.1', use: 'body text, titles, prices, primary fill, header, footer' },
  { spec: 'ink-2', css: '--wz-ink-2', group: '4.1', use: 'secondary text, labels, counts' },
  { spec: 'ink-3', css: '--wz-ink-3', group: '4.1', use: 'meta, old price, placeholders, sold-out pill — the floor: nothing a shopper must read is lighter, or smaller than 12px' },
  { spec: 'page', css: '--wz-page', group: '4.1', use: 'page ground, cards' },
  { spec: 'line', css: '--wz-line', group: '4.1', use: 'hairlines; decorative only — fails the 3:1 UI minimum by design, so it never carries meaning' },
  { spec: 'edge', css: '--wz-edge', group: '4.1', use: 'every border a user must see — inputs, unselected chips, swatches' },
  { spec: 'tile', css: '--wz-tile', group: '4.1', use: 'behind a product photo on a tinted ground; skeletons' },
  { spec: 'sale', css: '--wz-sale', group: '4.2', use: 'the −% beside a price, the "Sale" nav link' },
  { spec: 'new', css: '--wz-new', group: '4.2', use: 'the New pill, the new-arrivals dot' },
  { spec: 'ok', css: '--wz-ok', group: '4.2', use: 'in stock, delivered, success' },
  { spec: 'alert', css: '--wz-alert', group: '4.2', use: 'low stock, price changed, processing' },
  { spec: 'error', css: '--wz-danger', group: '4.2', use: 'form errors, failed payment, error pages (sheet name: --wz-danger)' },
  { spec: 'brand', css: '--wz-brand', group: '4.2', use: 'GROUND only; ink text on it; never text, never a border' },
  { spec: 'tint-watch', css: '--wz-tint-watch', group: '4.3', use: 'aisle ground — watch' },
  { spec: 'tint-fashion', css: '--wz-tint-fashion', group: '4.3', use: 'aisle ground — fashion' },
  { spec: 'tint-leather', css: '--wz-tint-leather', group: '4.3', use: 'aisle ground — bag · wallet · perfume · the default' },
  { spec: 'tint-tech', css: '--wz-tint-tech', group: '4.3', use: 'aisle ground — electronics' },
  { spec: 'disabled-fg', css: '--wz-disabled-fg', group: '7.1', use: 'disabled button label' },
  { spec: 'disabled-bg', css: '--wz-disabled-bg', group: '7.1', use: 'disabled button fill' },
  { spec: 'focus-ring', css: '--wz-focus-ring', group: '7.2', use: 'the one focus ring, 2px, offset 2' },
  { spec: 'focus-ring-on-ink', css: '--wz-focus-ring-on-ink', group: '7.2', use: 'the ring on ink surfaces, plus an inner 2px ink shadow' },
]

const TEXT_TOKENS = ['ink', 'ink-2', 'ink-3', 'sale', 'new', 'ok', 'alert', 'error']
const TINTS = ['tint-watch', 'tint-fashion', 'tint-leather', 'tint-tech']
const GROUNDS = ['page', 'tile', ...TINTS]

// The spec's printed numbers (§4.1, §4.2, §4.3, §7.1), quoted so they can be CHECKED. They are the claims
// under test — the only numbers in this file — and every one is printed beside the measured value.
const SPEC_SAYS = {
  'ink/page': 18.88, 'ink-2/page': 7.46, 'ink-3/page': 5.74, 'edge/page': 4.54,
  'sale/page': 5.45, 'new/page': 6.35, 'ok/page': 6.07, 'alert/page': 5.43, 'error/page': 6.54,
  'sale/tints': 4.71, 'new/tints': 5.49, 'ok/tints': 5.25, 'alert/tints': 4.69, 'error/tints': 5.65, 'edge/tints': 3.93,
  'page/sale': 5.45, 'page/new': 6.35, 'page/ok': 6.07, 'page/alert': 5.43, 'page/error': 6.54,
  'ink/brand': 10.13, 'ink-2/brand': 4.0, 'ink-3/brand': 3.08,
  'disabled-fg/disabled-bg': 4.2,
}
const SPEC_LSTAR = { 'tint-watch': 95.5, 'tint-fashion': 95.4, 'tint-leather': 94.2, 'tint-tech': 94.6 }

// §5 — the steps, and the sheet token each reads its size from. Line-height: §5 fixes it only for display
// (32 / 40) and adds +0.15 for Arabic at every step; the other steps are shown at 1.4 and say so.
const TYPE_STEPS = [
  { step: 'display', token: '--wz-fs-2xl', weight: 800, lh: 1.25, lhNote: '40px, from §5', use: 'hero copy' },
  { step: 'h1', token: '--wz-fs-xl', weight: 800, lh: 1.4, use: 'page titles, product name, current price on the PDP' },
  { step: 'h2', token: '--wz-fs-lg', weight: 800, lh: 1.4, use: 'rail titles, section headings, cart total' },
  { step: 'h3', token: '--wz-fs-md', weight: 600, lh: 1.4, use: 'card headings, "Order summary"' },
  { step: 'price', token: '--wz-fs-base', weight: 600, lh: 1.4, use: 'card price' },
  { step: 'body', token: '--wz-fs-base', weight: 400, lh: 1.4, use: 'running text' },
  { step: 'small', token: '--wz-fs-sm', weight: 400, lh: 1.4, use: 'product subtitle, meta, counts' },
  { step: 'caption', token: '--wz-fs-xs', weight: 600, lh: 1.4, use: 'brand line (caps), pills' },
]
const SPACE_SCALE = [4, 8, 12, 16, 24, 32, 48] // §6, in px — the scale the sheet is checked against

// The demo seeder's products (dev database, 2026-10-16) — the shapes §17.5 must survive. Slugs are
// identifiers, read from storefront_product for storefront 2.
const API = process.env.TOKENS_API_BASE || 'http://127.0.0.1:8000/api'
const DEMO = [
  { id: 25649, slug: 'hugo-boss-mens-green-dial-chronograph-watch-1514227', why: 'watch — 3 colours × 3 sizes; Black sold out in every size (the dead colour); +150.00 on every 44 mm row' },
  { id: 25648, slug: 'hugo-boss-mens-grand-prix-chronograph-watch-with-green-dial-and-gunmetal-gray-stainless-steel-bracelet-1514228', why: 'watch — the hole: one colour × size has no row' },
  { id: 25644, slug: 'hugo-boss-mens-grand-prix-chronograph-watch-with-black-dial-and-silver-stainless-steel-bracelet-1514251', why: 'watch — sizes only: no colour on the rows, so no colour picker; the size picker alone' },
  { id: 7120, slug: 'joyroom-jr-bp560-excellent-series-portable-passive-stylus-pen', why: 'electronics — no colour data: the swatch row is reserved and empty; every variant at 0' },
]

async function fetchProduct(slug) {
  try {
    const res = await fetch(`${API}/v2/brandfashion/products/${slug}`, {
      cache: 'no-store',
      headers: { Accept: 'application/json', 'Accept-Language': 'en' },
    })
    if (!res.ok) return { error: `HTTP ${res.status}` }
    const json = await res.json()
    return json.product ?? { error: 'no product in the payload' }
  } catch (e) {
    return { error: e?.message || String(e) }
  }
}

const money = (n) => `EGP ${Number(n).toLocaleString('en-EG')}`
const txt = (v) => (v && typeof v === 'object' ? v.en ?? Object.values(v)[0] : v) ?? ''

export default async function TokensPage() {
  if (!devPageEnabled()) notFound()

  const sheet = readTokenSheet()
  const v = (spec) => {
    const t = COLOUR_TOKENS.find((c) => c.spec === spec)
    const value = t ? sheet.byName[t.css] : undefined
    if (!value) throw new Error(`tokens page: ${spec} (${t?.css}) is not in ${sheet.file}`)
    return value
  }

  // ── every permitted pair, measured ───────────────────────────────────────────────────────────────
  const pairs = []
  const add = (fg, bg, kind, note, claimKey) => {
    const ratio = contrast(v(fg), v(bg))
    pairs.push({ fg, bg, kind, note, ratio, verdict: verdict(ratio, kind), claim: claimKey ? SPEC_SAYS[claimKey] : undefined })
  }
  for (const g of GROUNDS) {
    const isTint = TINTS.includes(g)
    for (const t of TEXT_TOKENS) add(t, g, 'text', isTint ? 'text on an aisle tint' : g === 'tile' ? 'text on the photo tile' : 'text on the page', g === 'page' ? `${t}/page` : undefined)
    add('edge', g, 'ui', 'a border a user must see (3:1, WCAG 1.4.11)', g === 'page' ? 'edge/page' : undefined)
    add('focus-ring', g, 'ui', 'the focus ring (2px) on this ground')
    add('line', g, 'decorative', 'hairline — decorative by rule (§4.1), measured so the rule is visible')
  }
  add('page', 'ink', 'text', 'white on ink: header, footer, primary button label')
  add('focus-ring-on-ink', 'ink', 'ui', 'the white ring on an ink surface')
  add('page', 'ink-3', 'text', 'white on the sold-out pill (§8.3)')
  for (const m of ['sale', 'new', 'ok', 'alert', 'error']) add('page', m, 'text', 'white on a pill or one-line notice ("White on it", §4.2)', `page/${m}`)
  add('ink', 'brand', 'text', 'the only text allowed on the brass ground (§4.2)', 'ink/brand')
  add('ink-2', 'brand', 'forbidden', 'NOT permitted by §4.2 — measured to show why', 'ink-2/brand')
  add('ink-3', 'brand', 'forbidden', 'NOT permitted by §4.2 — measured to show why', 'ink-3/brand')
  add('disabled-fg', 'disabled-bg', 'disabled', 'disabled button label — an inactive control is exempt from 1.4.3 / 1.4.11; the spec keeps it legible by choice', 'disabled-fg/disabled-bg')

  // the spec's "on tints" column: one figure per colour — the WORST of the four tints is what it must mean
  const onTints = [...TEXT_TOKENS.filter((t) => t !== 'ink' && t !== 'ink-2' && t !== 'ink-3'), 'edge'].map((t) => {
    const per = TINTS.map((g) => ({ g, ratio: contrast(v(t), v(g)) }))
    const worst = per.reduce((a, b) => (b.ratio < a.ratio ? b : a))
    return { t, per, worst, claim: SPEC_SAYS[`${t}/tints`], kind: t === 'edge' ? 'ui' : 'text' }
  })

  // a forbidden pair failing is the spec being right about it; a PERMITTED pair failing is the gate tripping
  const failures = pairs.filter((p) => p.verdict === 'fail' && p.kind !== 'forbidden')
  const forbiddenConfirmed = pairs.filter((p) => p.kind === 'forbidden' && p.verdict === 'fail')
  const audit = pairs.filter((p) => p.claim != null).map((p) => ({ ...p, delta: p.ratio - p.claim }))
  const auditOff = audit.filter((p) => Math.abs(p.delta) >= 0.05)

  // ── tints and the six families ───────────────────────────────────────────────────────────────────
  const tintRows = TINTS.map((t) => ({
    t, value: v(t), lstar: lightness(v(t)), claim: SPEC_LSTAR[t],
    families: Object.entries(TINT_BY_FAMILY).filter(([, tint]) => tint === t).map(([f]) => f),
  }))
  const familyRows = [...FAMILIES, null, 'something-new'].map((f) => ({ f, tint: aisleTint(f), isDefault: aisleTintIsDefault(f) }))

  // ── the sheet inventory ──────────────────────────────────────────────────────────────────────────
  const specCss = new Set(COLOUR_TOKENS.map((c) => c.css))
  const spaceTokens = sheet.tokens.filter((t) => t.name.startsWith('--wz-sp-'))
  const radiusTokens = sheet.tokens.filter((t) => t.name.startsWith('--wz-r-'))
  const typeTokens = sheet.tokens.filter((t) => t.name.startsWith('--wz-fs-'))

  // ── real variant data from the dev database ──────────────────────────────────────────────────────
  const products = await Promise.all(DEMO.map(async (d) => ({ ...d, product: await fetchProduct(d.slug) })))

  return (
    <main className="tk" id="main-content">
      <h1>Tokens — the B2 gate</h1>
      <p className="tk-meta">
        Source of every value: <code>{sheet.file}</code> ({sheet.tokens.length} <code>--wz-*</code> tokens read at request time). Every ratio and L*
        on this page is computed by <code>src/lib/contrast.js</code>; the only typed numbers are the spec&apos;s own claims, printed beside the measurement.
        Variant data: <code>{API}/v2/brandfashion/products/…</code> (the dev database with DemoVariantsSeeder on).
      </p>
      <div className="tk-gate">
        <strong>What §20 requires of B2:</strong> &ldquo;Tokens: §4 colours, §5 type, §6 space, §7 focus, the family→tint helper — done when a tokens page renders
        every token and every contrast pairing.&rdquo; <strong>Ground:</strong> §4.1 names <code>page</code> #FFFFFF as the page ground and measures every
        &ldquo;on white&rdquo; ratio on it; §3&apos;s &ldquo;black base&rdquo; is the ink SURFACES (header, footer, primary buttons) — those pairs are measured on <code>ink</code> below.
        <br />
        <strong>Result:</strong> {pairs.length} pairs measured · permitted pairs failing their requirement: <strong>{failures.length}</strong>
        {failures.length ? ` (${failures.map((p) => `${p.fg} on ${p.bg}`).join(', ')})` : ''} · forbidden pairs confirmed below their requirement: {forbiddenConfirmed.length} of {pairs.filter((p) => p.kind === 'forbidden').length}
        {forbiddenConfirmed.length ? ` (${forbiddenConfirmed.map((p) => `${p.fg} on ${p.bg} ${r2(p.ratio)}`).join(', ')})` : ''} · spec audit: {audit.length} claims checked,{' '}
        {auditOff.length === 0 ? 'all within 0.05' : `${auditOff.length} off by ≥ 0.05: ${auditOff.map((p) => `${p.fg} on ${p.bg} (spec ${p.claim}, measured ${r2(p.ratio)})`).join('; ')}`}.
      </div>

      {/* (a) every colour token on its real ground */}
      <h2>(a) §4 colour tokens — each on the ground the spec gives it</h2>
      <table>
        <thead><tr><th>Token</th><th>Sheet name</th><th>Value</th><th>Shown on its ground</th><th>Use (the spec&apos;s rule)</th></tr></thead>
        <tbody>
          {COLOUR_TOKENS.map((c) => {
            const value = v(c.spec)
            const isGround = ['page', 'tile', 'brand', 'disabled-bg', 'ink'].includes(c.spec) || c.spec.startsWith('tint')
            const ground = c.spec === 'focus-ring-on-ink' || c.spec === 'page' ? v('ink') : c.spec === 'disabled-fg' ? v('disabled-bg') : v('page')
            return (
              <tr key={c.spec}>
                <td><code>{c.spec}</code> <small>§{c.group}</small></td>
                <td><code>{c.css}</code></td>
                <td><code>{value}</code></td>
                <td>
                  {isGround ? (
                    <span className="pair" style={{ background: value, color: c.spec === 'ink' ? v('page') : v('ink') }}>{c.spec === 'brand' ? 'ink on brand' : c.spec}</span>
                  ) : c.spec === 'line' || c.spec === 'edge' || c.spec.startsWith('focus') ? (
                    <span className="pair ui" style={{ background: ground, color: value }}><span className="ring" /> {c.spec}</span>
                  ) : (
                    <span className="pair" style={{ background: ground, color: value }}>{c.spec} text</span>
                  )}
                </td>
                <td>{c.use}</td>
              </tr>
            )
          })}
        </tbody>
      </table>

      {/* (b) every permitted pair, computed */}
      <h2>(b) every permitted pair — contrast computed on the page, AA pass / fail</h2>
      <p className="tk-meta">
        Requirement by what the colour does: text {REQUIREMENT.text}:1 (WCAG 1.4.3 AA; the spec allows no text under 12px, so no large-text discount), UI {REQUIREMENT.ui}:1
        (1.4.11: borders a user must see, focus rings), decorative and disabled exempt, forbidden pairs measured to show the reason. &ldquo;Spec says&rdquo; is the
        figure printed in §4 / §7; Δ is measured − spec.
      </p>
      <table>
        <thead><tr><th>Sample</th><th>Foreground</th><th>Ground</th><th>Kind</th><th className="num">Measured</th><th className="num">Spec says</th><th className="num">Δ</th><th>Verdict</th><th>Note</th></tr></thead>
        <tbody>
          {pairs.map((p) => (
            <tr key={`${p.fg}/${p.bg}`}>
              <td>
                <span className={`pair ${p.kind === 'ui' || p.kind === 'decorative' ? 'ui' : ''}`} style={{ background: v(p.bg), color: v(p.fg), borderColor: p.kind === 'ui' || p.kind === 'decorative' ? v(p.fg) : undefined }}>
                  {p.kind === 'ui' || p.kind === 'decorative' ? <><span className="ring" /> {p.fg}</> : `${p.fg} Aa 3,900`}
                </span>
              </td>
              <td><code>{p.fg}</code> <small>{v(p.fg)}</small></td>
              <td><code>{p.bg}</code> <small>{v(p.bg)}</small></td>
              <td>{p.kind}</td>
              <td className="num"><strong>{r2(p.ratio)}</strong></td>
              <td className="num">{p.claim != null ? p.claim.toFixed(2) : '—'}</td>
              <td className="num">{p.claim != null ? (p.ratio - p.claim >= 0 ? '+' : '') + r2(p.ratio - p.claim) : '—'}</td>
              <td className={p.verdict === 'pass' ? 'pass' : p.verdict === 'fail' ? 'fail' : 'exempt'}>{p.verdict}{p.kind === 'forbidden' ? ' (forbidden by spec)' : ''}</td>
              <td>{p.note}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3>§4.2&apos;s &ldquo;on tints&rdquo; column, resolved: the worst of the four tints</h3>
      <table>
        <thead><tr><th>Colour</th>{TINTS.map((t) => <th key={t} className="num">{t}</th>)}<th className="num">worst</th><th className="num">spec says</th><th>verdict (worst)</th></tr></thead>
        <tbody>
          {onTints.map((row) => (
            <tr key={row.t}>
              <td><code>{row.t}</code></td>
              {row.per.map((x) => <td key={x.g} className="num">{r2(x.ratio)}</td>)}
              <td className="num"><strong>{r2(row.worst.ratio)}</strong> <small>({row.worst.g})</small></td>
              <td className="num">{row.claim != null ? row.claim.toFixed(2) : '—'}</td>
              <td className={verdict(row.worst.ratio, row.kind) === 'pass' ? 'pass' : 'fail'}>{verdict(row.worst.ratio, row.kind)} ({row.kind} {REQUIREMENT[row.kind]}:1)</td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* (c) the aisle tints and the six families */}
      <h2>(c) §4.3 aisle tints — against the families they key on</h2>
      <div className="tints">
        {tintRows.map((row) => (
          <div key={row.t} className="tint" style={{ background: row.value }}>
            <div><code>{row.t}</code> <small>{row.value}</small></div>
            <div className="tk-meta">L* measured <strong>{r2(row.lstar)}</strong> · spec says {row.claim.toFixed(1)} · Δ {r2(row.lstar - row.claim)}</div>
            <div className="tile">tile — the photo ground on this tint</div>
            <div><strong>families:</strong> {row.families.join(' · ')}{row.t === DEFAULT_TINT ? ' · and the default' : ''}</div>
            <div style={{ marginTop: 8 }}>
              <span style={{ color: v('ink') }}>ink {r2(contrast(v('ink'), row.value))}</span> · <span style={{ color: v('ink-2') }}>ink-2 {r2(contrast(v('ink-2'), row.value))}</span> ·{' '}
              <span style={{ color: v('ink-3') }}>ink-3 {r2(contrast(v('ink-3'), row.value))}</span> · <span style={{ color: v('sale') }}>sale {r2(contrast(v('sale'), row.value))}</span>
            </div>
          </div>
        ))}
      </div>
      <h3>Six families in §11, four tints in §4.3 — the spec&apos;s own answer, applied by <code>src/lib/aisleTint.js</code></h3>
      <p>
        §4.3, quoted: &ldquo;The map is a six-to-four switch on <code>family</code>, in code, with a default: watch → tint-watch · fashion → tint-fashion ·
        bag | wallet | perfume → tint-leather · electronics → tint-tech · anything else / null → tint-leather. That is the whole mapping.&rdquo;
        So three families SHARE <code>tint-leather</code>, none gets no tint, and an unmatched or missing family renders as <code>tint-leather</code>. The spec answers it; nothing is proposed here.
      </p>
      <table>
        <thead><tr><th>family</th><th>resolves to</th><th>value</th><th>how</th><th>sample</th></tr></thead>
        <tbody>
          {familyRows.map((row) => (
            <tr key={String(row.f)}>
              <td><code>{row.f === null ? 'null' : row.f}</code>{row.f === 'something-new' ? <small> (an unknown family)</small> : null}</td>
              <td><code>{row.tint}</code></td>
              <td><code>{v(row.tint)}</code></td>
              <td>{row.isDefault ? 'the default (anything else / null)' : 'explicit in the switch'}</td>
              <td><span className="pair" style={{ background: v(row.tint), color: v('ink') }}>{row.f ?? 'null'}</span></td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* (d) the disabled pair */}
      <h2>(d) the disabled pair — §7.1 says &ldquo;#777 on #E5E5E5 (4.2)&rdquo;</h2>
      {(() => {
        const p = pairs.find((x) => x.fg === 'disabled-fg')
        return (
          <p>
            Measured: <strong>{r2(p.ratio)}</strong> ({v('disabled-fg')} on {v('disabled-bg')}); the spec printed {p.claim}. The pair is not changed and no
            requirement is lowered: a disabled control is <strong>exempt</strong> from WCAG 1.4.3 and 1.4.11 (inactive user-interface components), so the
            number has nothing to pass — the spec&apos;s intent, &ldquo;so the label still reads&rdquo;, is a choice, and {r2(p.ratio)} reads. The spec line is
            corrected to the measured value in the same pass (v2.5 → v2.6 changelog).
            <span className="btn is-disabled" style={{ marginInlineStart: 12 }}>Add to cart</span>
          </p>
        )
      })()}

      {/* (e) type, space, states */}
      <h2>(e) §5 type scale — Watchizer’s face (Georgia en / El Messiri ar), rendered, en and ar</h2>
      <p className="tk-meta">Sizes read from the sheet&apos;s <code>--wz-fs-*</code>; weight from §5. Arabic gets +0.15 line-height at every step (§5), no letter-spacing. Digits stay Western in both.</p>
      {TYPE_STEPS.map((s) => {
        const size = sheet.byName[s.token]
        return (
          <div key={s.step} className="type-row" style={{ '--lh': s.lh }}>
            <div className="step"><code>{s.step}</code> {size} / {s.weight}<br /><small>{s.token} · lh {s.lhNote ?? `${s.lh} (not in §5)`} · {s.use}</small></div>
            <div className="sample" lang="en" style={{ fontSize: size, fontWeight: s.weight, letterSpacing: s.step === 'caption' ? '0.04em' : 0, textTransform: s.step === 'caption' ? 'uppercase' : 'none' }}>Guess satchel EGP 3,900</div>
            <div className="sample" lang="ar" dir="rtl" style={{ fontSize: size, fontWeight: s.weight }}>حقيبة جيس 3,900 ج.م</div>
          </div>
        )
      })}
      <h3>The price field at both ends of the real data (§5)</h3>
      <div className="type-row" style={{ '--lh': 1.4 }}>
        <div className="step"><code>price</code> 14 / 600</div>
        <div className="sample" lang="en" style={{ fontSize: sheet.byName['--wz-fs-base'], fontWeight: 600 }}>EGP 110</div>
        <div className="sample" lang="en" style={{ fontSize: sheet.byName['--wz-fs-base'], fontWeight: 600 }}>EGP 214,380 <s style={{ fontSize: 11, fontWeight: 400, color: v('ink-3') }}>EGP 260,000</s> <span style={{ fontSize: 11, color: v('sale') }}>−18%</span></div>
      </div>

      <h2>§6 space scale, radius, hit targets</h2>
      {spaceTokens.map((t) => {
        const px = parseInt(t.value, 10)
        const inSpec = SPACE_SCALE.includes(px)
        return (
          <div key={t.name} className="space-row">
            <code style={{ width: 110 }}>{t.name}</code>
            <span className="bar" style={{ width: t.value }} />
            <span>{t.value}</span>
            <small>{inSpec ? 'in the §6 scale' : 'not in the §6 scale (4 · 8 · 12 · 16 · 24 · 32 · 48) — the sheet carries it from Watchizer'}</small>
          </div>
        )
      })}
      <div className="radii" style={{ marginTop: 12 }}>
        {radiusTokens.map((t) => (
          <div key={t.name} className="r" style={{ borderRadius: t.value }}>{t.name.replace('--wz-r-', '')} {t.value}</div>
        ))}
      </div>
      <p className="tk-meta" style={{ marginTop: 8 }}>Hit targets: 44 on phone, 36 on desktop (§6). Hairline 1px; a 2px line is only ever the focus ring.</p>

      <h2>§7 states — default, hover, focus, disabled (hover and focus are forced classes on this static sheet)</h2>
      <div className="states">
        <div className="state">default<button type="button" className="btn btn-primary">Add to cart</button></div>
        <div className="state">hover (forced)<button type="button" className="btn btn-primary is-hover">Add to cart</button></div>
        <div className="state">focus-visible (forced)<button type="button" className="btn btn-primary is-focus">Add to cart</button></div>
        <div className="state">disabled<button type="button" className="btn btn-primary" disabled>Add to cart</button></div>
        <div className="state">secondary<button type="button" className="btn btn-secondary">Notify me</button></div>
        <div className="state">secondary hover (forced)<button type="button" className="btn btn-secondary is-hover">Notify me</button></div>
        <div className="state">secondary focus (forced)<button type="button" className="btn btn-secondary is-focus">Notify me</button></div>
        <div className="state">on an ink surface: white ring + inner ink shadow<span className="on-ink"><button type="button" className="btn btn-onink">Shop</button><button type="button" className="btn btn-onink is-focus">Shop</button></span></div>
        <div className="state">chip unselected (edge border)<span className="chipf">Boss</span></div>
        <div className="state">chip selected (fill, not a ring)<span className="chipf is-selected">Boss</span></div>
        <div className="state">chip selected + focused: both<span className="chipf is-selected is-focus">Boss</span></div>
        <div className="state">input<input className="input" defaultValue="Cairo" readOnly aria-label="City" /></div>
        <div className="state">input focus (forced)<input className="input is-focus" defaultValue="Cairo" readOnly aria-label="City, focused" /></div>
        <div className="state">input error<input className="input is-error" defaultValue="01x" readOnly aria-label="Phone, invalid" aria-describedby="tk-err" /><span id="tk-err" className="err">Enter an Egyptian mobile number</span></div>
        <div className="state">pills: new · sold out (ink-3 fill) · ok<span><span className="pill" style={{ background: v('new') }}>New</span> <span className="pill" style={{ background: v('ink-3') }}>Sold out</span> <span className="pill" style={{ background: v('ok') }}>In stock</span></span></div>
      </div>

      {/* real variant data */}
      <h2>§8.2 the card&apos;s swatch row and §17.5 the pickers — on real variant data (DemoVariantsSeeder, dev database)</h2>
      {products.map(({ id, why, product }) => {
        if (!product || product.error) {
          return <div key={id} className="notice" style={{ marginBottom: 12 }}>Product {id}: the dev API did not answer ({product?.error ?? 'no payload'}) — start <code>php artisan serve</code> in core/ or set <code>TOKENS_API_BASE</code>.</div>
        }
        const colors = product.attributes?.colors ?? { dial: [], band: [], main: [] }
        const attr = [...(colors.dial ?? []), ...(colors.band ?? []), ...(colors.main ?? [])].filter((c, i, a) => a.findIndex((x) => x.id === c.id) === i)
        const shown = attr.slice(0, 4)
        const variants = product.variants ?? []
        const vColours = variants.map((x) => x.color).filter(Boolean).filter((c, i, a) => a.findIndex((x) => x.id === c.id) === i)
        // v2 emits `size: null` on every row today (core finding V2-SIZE-NULL) — the size is read from the label until that is fixed
        // a colour-only row has no size at all: one '—' column, not a column per colour name
        const sizeOf = (x) => x.size?.name ? txt(x.size.name) : x.label.includes(' / ') ? x.label.split(' / ')[1] : x.color ? '—' : x.label
        const vSizes = [...new Set(variants.map(sizeOf))]
        const cell = (c, s) => variants.find((x) => (x.color?.id ?? null) === (c?.id ?? null) && sizeOf(x) === s)
        const chosen = vColours.find((c) => variants.some((x) => x.color?.id === c.id && x.stock.in_stock)) ?? vColours[0]
        const price = product.price
        return (
          <section key={id} style={{ marginBottom: 32 }}>
            <h3>{txt(product.title)} <small>— product {id}, {product.family}: {why}</small></h3>
            <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap', alignItems: 'flex-start' }}>
              <div className="card">
                <div className="photo" style={{ background: `var(--wz-${aisleTint(product.family)})` }}>
                  {product.image?.src ? <img src={product.image.src} alt="" loading="lazy" /> : null}
                </div>
                <div className="body">
                  <div className="brand">{txt(product.brand?.name)}</div>
                  <p className="title">{txt(product.title)}</p>
                  <div className="swatch-row" aria-label={attr.length ? `Colours: ${attr.map((c) => txt(c.name)).join(', ')}` : undefined}>
                    {shown.map((c) => <span key={c.id} className="dot" style={{ background: c.hex }} />)}
                    {attr.length > 4 ? <span className="more">+{attr.length - 4}</span> : null}
                  </div>
                  <div className="price">
                    <span>{money(price.has_sale ? price.sale_amount : price.amount)}</span>
                    {price.has_sale ? <><s>{money(price.amount)}</s><span className="pct">−{price.discount_pct}%</span></> : null}
                  </div>
                </div>
              </div>
              <div style={{ flex: 1, minWidth: 320 }}>
                <p className="tk-meta">
                  §8.2 row above: {attr.length} attribute colour{attr.length === 1 ? '' : 's'} from <code>attributes.colors</code> (dial, then band) — {attr.length ? attr.map((c) => `${txt(c.name)} ${c.hex}`).join(', ') : 'none: the 14px row is reserved and empty'}; no role, no state, the whole card is the target.
                </p>
                {variants.length ? (
                  <>
                    <p className="tk-meta">§17.5 pickers below: {variants.length} variant rows — {vColours.length} colour{vColours.length === 1 ? '' : 's'} × {vSizes.length} size{vSizes.length === 1 ? '' : 's'} from <code>variants[]</code> (colour picker reads the ROWS&apos; colours, never the attribute row). The matrix decides what is enabled.</p>
                    <div className="picker" role="radiogroup" aria-label="Colour">
                      {vColours.map((c) => {
                        const rows = variants.filter((x) => x.color?.id === c.id)
                        const dead = rows.every((x) => !x.stock.in_stock)
                        return <span key={c.id} role="radio" aria-checked={c.id === chosen?.id} aria-label={`${txt(c.name)}${dead ? ', out of stock in every size' : ''}`} className={`sw ${c.id === chosen?.id ? 'is-checked' : ''} ${dead ? 'is-dead' : ''}`} style={{ background: c.hex }} />
                      })}
                      {vColours.length ? <small style={{ marginInlineStart: 8 }}>{txt(chosen?.name)}</small> : <small>no colour on the rows — colour picker not rendered</small>}
                    </div>
                    {vSizes.length > 1 || (vSizes.length === 1 && vColours.length === 0) ? (
                      <div className="picker" role="radiogroup" aria-label="Size" style={{ marginTop: 8 }}>
                        {vSizes.map((s) => {
                          const row = cell(chosen, s)
                          if (!row) return <span key={s} className="sz is-hole" aria-hidden="true">{s}</span>
                          const out = !row.stock.in_stock
                          return <span key={s} role="radio" aria-checked="false" aria-label={`${s}${out ? ', out of stock, notify me' : ''}`} className={`sz ${out ? 'is-out' : ''}`}>{s}</span>
                        })}
                      </div>
                    ) : null}
                    <table className="matrix">
                      <thead><tr><th>colour \ size</th>{vSizes.map((s) => <th key={s}>{s}</th>)}</tr></thead>
                      <tbody>
                        {(vColours.length ? vColours : [null]).map((c) => (
                          <tr key={c?.id ?? 'none'}>
                            <th>{c ? txt(c.name) : '—'}</th>
                            {vSizes.map((s) => {
                              const row = cell(c, s)
                              if (!row) return <td key={s} className="hole">no row (the hole)</td>
                              const units = row.stock.express + row.stock.market
                              const delta = row.price.amount - price.amount
                              return (
                                <td key={s} className={units === 0 ? 'dead' : delta !== 0 ? 'delta' : ''}>
                                  {units === 0 ? 'out' : `${row.stock.express} ex · ${row.stock.market} mk`}
                                  {delta !== 0 ? <><br />{money(row.price.has_sale ? row.price.sale_amount : row.price.amount)} ({delta > 0 ? '+' : ''}{delta})</> : null}
                                </td>
                              )
                            })}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </>
                ) : (
                  <p className="tk-meta">no variants on this product: no picker, no reserved space (§11.5).</p>
                )}
              </div>
            </div>
          </section>
        )
      })}

      <h2>The sheet, every <code>--wz-*</code> token, and whether the spec names it</h2>
      <table>
        <thead><tr><th>Token</th><th>Value</th><th>Spec</th><th>Comment in the sheet</th></tr></thead>
        <tbody>
          {sheet.tokens.map((t) => (
            <tr key={t.name}>
              <td><code>{t.name}</code></td>
              <td><code>{t.value}</code></td>
              <td>{specCss.has(t.name) ? `§${COLOUR_TOKENS.find((c) => c.css === t.name).group}` : typeTokens.includes(t) ? '§5' : spaceTokens.includes(t) ? '§6' : radiusTokens.includes(t) ? '§6' : <span className="tk-meta">no spec value</span>}</td>
              <td className="tk-meta">{t.comment}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </main>
  )
}
