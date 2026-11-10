// Timed campaigns — Hot Deals and Flash Sale (the twelve updates, Part 2, 2026-11-05). A TWIN.
//
// The rules of the campaign surfaces, as pure functions, so they can be tested without a browser:
//
//   • the countdown counts to the campaign's REAL end, on the SERVER's clock (the payload carries `now`; the
//     difference from this device's clock is kept as an offset), and at zero the campaign is over — nothing
//     here rolls over or restarts;
//   • the progress bar exists only when core measured it: the units a product started the campaign with
//     (a snapshot at activation) and the units the stock ledger says were sold since. No snapshot → no bar.
//     Nothing is derived from a discount, a rating or a guess.

/** Where each presentation's own page lives (English path; Arabic is the same under /ar). */
export const CAMPAIGN_PATH = { flash_sale: '/flash-sale', hot_deals: '/hot-deals' }

/** `{ ar, en } | string` → the text in `lang`, English next. */
export const txt = (v, lang = 'en') => (v && typeof v === 'object' ? v[lang] ?? v.en ?? Object.values(v)[0] : v) ?? ''

/** 3,900 — Western digits in both languages, no decimals unless the amount has them. */
export const amount = (n) => {
  const v = Number(n)
  if (!Number.isFinite(v)) return ''
  return v.toLocaleString('en-US', { maximumFractionDigits: Number.isInteger(v) ? 0 : 2 })
}

/** A price as the shop prints it: `EGP 3,900` in English, `3,900 ج.م` in Arabic. */
export const money = (n, lang = 'en') => (lang === 'ar' ? `${amount(n)} ج.م` : `EGP ${amount(n)}`)

/** How far the server's clock is ahead of this device's, in ms (0 when the payload has no usable `now`). */
export function clockOffset(serverNow, sentAtMs, receivedAtMs) {
  const server = Date.parse(serverNow)
  if (!Number.isFinite(server)) return 0
  return Math.round(server - (sentAtMs + receivedAtMs) / 2)
}

/** Milliseconds until `endsAt` on the server's clock; never negative. */
export function msLeft(endsAt, nowMs, offset = 0) {
  const end = Date.parse(endsAt)
  if (!Number.isFinite(end)) return 0
  return Math.max(0, end - (nowMs + offset))
}

/** True while the campaign has not reached its end. A campaign with no readable end is not live. */
export const isLive = (campaign, nowMs, offset = 0) => !!campaign && msLeft(campaign.ends_at, nowMs, offset) > 0

/** The countdown's parts. Days appear only when there are any; hours are 0-23 then. */
export function countdownParts(ms) {
  const total = Math.max(0, Math.floor(ms / 1000))
  const d = Math.floor(total / 86400)
  const h = Math.floor((total % 86400) / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  return { d, h, m, s, total }
}

export const two = (n) => String(n).padStart(2, '0')

/** `02:14:58`, or `3d 02:14:58` when more than a day is left. */
export function countdownText(ms) {
  const { d, h, m, s } = countdownParts(ms)
  const clock = `${two(h)}:${two(m)}:${two(s)}`
  return d > 0 ? `${d}d ${clock}` : clock
}

/**
 * The progress bar of one card, or null when there is nothing REAL to draw.
 * `campaign` is core's measurement `{ start, sold, left, pct }`; a card without it draws no bar at all.
 */
export function barOf(campaign) {
  if (!campaign || !(Number(campaign.start) > 0)) return null
  const left = Math.max(0, Math.round(Number(campaign.left) || 0))
  const sold = Math.max(0, Math.round(Number(campaign.sold) || 0))
  // ONE RULE, so the bar and the stock label never contradict (core's `state`; the same rule from the three
  // numbers when an older core sends none):
  //   selling      a unit can still be bought → "N sold" (since the last restock; the bar never 100) and "N left"
  //   sold_out     nothing left and the campaign SOLD every unit → a full bar and "Sold out"
  //   unavailable  nothing left, but not by this deal's sales (an adjustment, another channel) → NO bar: the card
  //                says "Sold out" and claims no share
  const state = campaign.state || (left > 0 ? 'selling' : sold >= Number(campaign.start) ? 'sold_out' : 'unavailable')
  if (state === 'unavailable') return { state, left: 0, sold, pct: null }
  if (state === 'sold_out') return { state, left: 0, sold, pct: 100 }
  return { state: 'selling', left, sold, pct: Math.min(99, Math.max(0, Math.round(Number(campaign.pct) || 0))) }
}

/** At or under this many units a deal card says how few are left (Michael, 2026-11-10). ONE place. */
export const LOW_STOCK = 5

/** The low-stock line: "Only 3 left"; Arabic counted — 1 قطعة واحدة, 2 قطعتين, 3–5 قطع. */
export function lowStockText(n, lang) {
  if (lang === 'ar') return n === 1 ? 'باقي قطعة واحدة بس' : n === 2 ? 'باقي قطعتين بس' : `باقي ${n} قطع بس`
  return `Only ${n} left`
}

/**
 * WHAT A DEAL CARD SHOWS UNDER ITS PRICE (Michael, 2026-11-10: no fake numbers, honest urgency), from the bar
 * ({@link barOf}) and the card's own in-stock flag:
 *   gone      not buyable here, or the stock reached zero another way → "Sold out", no bar (as before)
 *   all_sold  every unit since the restock was SOLD → the full bar and "Sold out" (as before)
 *   new       nothing sold since the last restock → a "New deal" badge instead of an empty bar
 *   bar       sold > 0 → the real bar
 *   none      no measurement → nothing (never a made-up value)
 * `low` is the stock line's number when 0 < left <= LOW_STOCK (with `new` and with `bar`), else null.
 */
export function dealLineOf(bar, inStock = true) {
  if (!inStock || bar?.state === 'unavailable') return { kind: 'gone' }
  if (bar?.state === 'sold_out') return { kind: 'all_sold', sold: bar.sold }
  if (!bar || bar.state !== 'selling' || !(bar.left > 0)) return { kind: 'none' }
  const low = bar.left <= LOW_STOCK ? bar.left : null
  if (!(bar.sold > 0)) return { kind: 'new', left: bar.left, low }
  return { kind: 'bar', sold: bar.sold, left: bar.left, pct: bar.pct, low }
}

/**
 * The countdown as a clock of CONSTANT LENGTH — `HH:MM:SS`, the days carried into the hours (`34:05:09`) — so a
 * control that shows it is the same width every second (the header switch changed width as the digits did).
 */
export function clockText(ms) {
  const { d, h, m, s } = countdownParts(ms)
  return `${two(d * 24 + h)}:${two(m)}:${two(s)}`
}

/** What the shopper saves on a card, or 0 when it has no valid sale. */
export function savedOn(price) {
  if (!price?.has_sale) return 0
  const saved = Number(price.amount) - Number(price.sale_amount)
  return saved > 0 ? saved : 0
}

/** What the shopper pays for a card. */
export const payOf = (price) => (price?.has_sale ? Number(price.sale_amount) : Number(price?.amount) || 0)

/** The campaign page's cards with core's measurement attached (cards from the listing carry none). */
export const withProgress = (cards, progress) =>
  (cards || []).map((card) => ({ ...card, campaign: card.campaign ?? progress?.[card.id] ?? null }))

/** The top-level categories present in a list of cards, with their counts — the page's filter chips. */
export function categoryChips(cards) {
  const map = new Map()
  for (const card of cards || []) {
    const top = card.primary_category?.path?.split('/')[0]
    if (!top) continue
    const hit = map.get(top)
    if (hit) hit.count += 1
    else map.set(top, { key: top, name: card.primary_category.depth === 1 ? card.primary_category.name : null, count: 1 })
  }
  return [...map.values()].sort((a, b) => b.count - a.count || a.key.localeCompare(b.key))
}

/** The discount steps worth offering: only the ones at least one card reaches, and not when every card does. */
export function discountSteps(cards, steps = [20, 30, 40, 50]) {
  const list = cards || []
  return steps.filter((step) => {
    const n = list.filter((c) => (c.price?.discount_pct || 0) >= step).length
    return n > 0 && n < list.length
  })
}

export const DEAL_SORTS = ['discount', 'price_asc', 'price_desc', 'ending']

/** Filter by top-level category and minimum discount, then sort. `ending` = the fewest units left first. */
export function arrangeDeals(cards, { category = null, minDiscount = 0, sort = 'discount' } = {}) {
  const out = (cards || []).filter((c) => {
    if (category && c.primary_category?.path?.split('/')[0] !== category) return false
    if (minDiscount > 0 && (c.price?.discount_pct || 0) < minDiscount) return false
    return true
  })
  const stock = (c) => (c.stock?.express || 0) + (c.stock?.market || 0)
  const by = {
    discount: (a, b) => (b.price?.discount_pct || 0) - (a.price?.discount_pct || 0) || a.id - b.id,
    price_asc: (a, b) => payOf(a.price) - payOf(b.price) || a.id - b.id,
    price_desc: (a, b) => payOf(b.price) - payOf(a.price) || a.id - b.id,
    // in stock first, then the scarcest: a sold-out card is not "ending", it has ended
    ending: (a, b) => (stock(a) === 0) - (stock(b) === 0) || stock(a) - stock(b) || a.id - b.id,
  }
  return out.sort(by[sort] || by.discount)
}

// ── the campaigns as a page carries them (the performance pass, 2026-11-05) ──────────────────────────────
// Every page carries the live campaigns (the header switch, the offer strips and the home sliders must not arrive
// late and move the page). Core's answer is 23 KB for two campaigns, most of it each slider card's whole product
// (every colour, every variant, a ten-step picture ladder in two formats). A slider card draws a name, a brand, a
// price, a rating, one picture and its sold bar: `slimCampaigns` keeps exactly that — and the picture ladder only
// up to 640 px, the widest a card is drawn. The browser's own request goes through the same function, so the
// server's copy and the browser's cannot differ in shape.
const slimCard = (c) => ({
  id: c.id,
  slug: c.slug,
  title: c.title,
  brand: c.brand ? { name: c.brand.name } : null,
  image: c.image ? { src: c.image.src, width: c.image.width, height: c.image.height, srcset: (c.image.srcset || []).filter((s) => s.width <= 640) } : null,
  price: c.price,
  // the campaign page shows these twelve until its whole list arrives, and sorts and groups them: it reads these two
  stock: c.stock,
  primary_category: c.primary_category,
  rating: c.rating,
  campaign: c.campaign,
})
const slimCampaign = (c) => (c ? { ...c, cards: (c.cards || []).map(slimCard) } : null)
export const slimCampaigns = (body) => ({
  // the moment of core's answer (its clock): the FIRST render of every countdown and every "is it live" reads this,
  // on the server and in the browser's hydration render alike, so the two cannot disagree (usePromo, 2026-11-07)
  now: body?.now ?? null,
  // the wheel's switch rides with the campaigns (2026-11-07): the floating "Spin & win" button reads it, no request of its own
  wheel: body?.wheel && typeof body.wheel === 'object' ? { enabled: body.wheel.enabled === true, campaign: typeof body.wheel.campaign === 'string' ? body.wheel.campaign : null } : null,
  hot_deals: slimCampaign(body?.hot_deals),
  flash_sale: slimCampaign(body?.flash_sale),
  // the next scheduled start (core, 2026-11-06): the browser asks again at that second (usePromo.useCampaignStart)
  next_start_at: body?.next_start_at ?? null,
  // the live campaign prices (core, 2026-11-10): every price on a page follows them (livePrice below)
  prices: slimPrices(body?.prices),
})

// ── CAMPAIGN PRICES (2026-11-10) ───────────────────────────────────────────────────────────────────────────
// While a Hot Deals or Flash Sale campaign is live, core prices its products at their campaign price everywhere —
// and names those prices in the campaigns answer. The answer is the storefront's clock for campaigns: the server
// drops a held copy at each start and end (nextChangeAt), the browser asks again at the next start
// (useCampaignStart) and drops a campaign at its end by its own timer (useLiveCampaign). So a price on a page FOLLOWS
// the answer: the campaign price while the product's campaign is live, its regular price otherwise — even on a page
// held from before the start, or a card core priced during the campaign and a cache still holds after its end
// (core puts `regular` beside such a price). No clock is read while rendering: the first render reads the answer
// the HTML carried, exactly as the server did.

/** The answer's live campaign prices, slimmed to what a page needs: id → { sale_amount, discount_pct, presentation }. */
export function slimPrices(map) {
  if (!map || typeof map !== 'object') return null
  const out = {}
  for (const [id, p] of Object.entries(map)) {
    const sale = Number(p?.sale_amount)
    if (!(sale > 0)) continue
    out[id] = { sale_amount: sale, discount_pct: Number(p.discount_pct) || 0, presentation: p.presentation === 'flash_sale' ? 'flash_sale' : 'hot_deals' }
  }
  return out
}

/** Only the prices of the campaigns live NOW (`live` = { flash_sale, hot_deals }, each dropped at its end). null = unknown. */
export function livePrices(prices, live) {
  if (prices == null) return null
  const out = {}
  for (const [id, p] of Object.entries(prices)) if (live?.[p.presentation]) out[id] = p
  return out
}

/**
 * A v2 price object as it holds NOW: the live campaign price when the product has one, else the regular price a
 * held card carries beside a campaign price that is over, else the object itself. `prices` null = not known yet.
 */
export function livePrice(price, productId, prices) {
  if (!price || typeof price !== 'object' || prices == null) return price
  const base = price.regular && typeof price.regular === 'object' ? price.regular : price
  const entry = prices[productId] ?? prices[String(productId)]
  if (entry && entry.sale_amount < Number(base.amount)) {
    return { ...base, sale_amount: entry.sale_amount, has_sale: true, discount_pct: entry.discount_pct }
  }
  return base
}

/** The price object of a legacy-shaped row (selling_price / sale_price_after_discount / price_regular). */
export function rowPrice(row) {
  const amount = Number(row?.selling_price) || 0
  const sale = Number(row?.sale_price_after_discount) || 0
  const has = sale > 0 && sale < amount
  return { amount, sale_amount: has ? sale : null, has_sale: has, discount_pct: has ? Number(row?.percentage_discount) || Math.round((1 - sale / amount) * 100) : 0, regular: row?.price_regular || null }
}

/** A legacy-shaped row with its price fields as they hold NOW (the same object when nothing changes). */
export function liveFields(row, prices) {
  if (!row || prices == null) return row
  const price = rowPrice(row)
  const now = livePrice(price, row.id, prices)
  if (now === price) return row
  const has = Boolean(now.has_sale) && Number(now.sale_amount) > 0 && Number(now.sale_amount) < Number(now.amount)
  return { ...row, selling_price: Number(now.amount), sale_price_after_discount: has ? Number(now.sale_amount) : 0, percentage_discount: has ? Number(now.discount_pct) || 0 : 0 }
}

/** A variant's price as it holds NOW: the product's live price, shifted by the variant's own difference. */
export function liveVariantPrice(variantPrice, productPrice, productId, prices) {
  if (!variantPrice || !productPrice || prices == null) return variantPrice
  const now = livePrice(productPrice, productId, prices)
  if (now === productPrice) return variantPrice // nothing differs from what core priced the variant with
  const delta = Number(variantPrice.amount) - Number(productPrice.amount)
  const amount = Number(now.amount) + delta
  const sale = now.has_sale && now.sale_amount != null ? Number(now.sale_amount) + delta : null
  const has = sale !== null && sale > 0 && sale < amount
  return { ...variantPrice, amount, sale_amount: has ? sale : null, has_sale: has, discount_pct: has ? Math.round(((amount - sale) / amount) * 100) : 0 }
}

// ── when a held copy of the campaigns stops being true (the follow-up of 2026-11-06) ─────────────────────
// The storefront's server holds core's campaigns answer for a few seconds so that no page waits for it. Two
// instants make a held copy WRONG, and both are in the answer: a live campaign's `ends_at` and core's
// `next_start_at`. `nextChangeAt` is the earlier of them, on core's clock; the server drops its copy at that
// instant and asks core again (serverV2.js), so no page is rendered with an ended offer or without a new one.
const PRESENTATIONS = ['hot_deals', 'flash_sale']

/** The earliest instant after the answer's own `now` at which a campaign ends or starts — ms, or null. */
export function nextChangeAt(body) {
  const now = Date.parse(body?.now)
  const instants = [...PRESENTATIONS.map((p) => body?.[p]?.ends_at), body?.next_start_at]
    .map((v) => Date.parse(v))
    .filter((t) => Number.isFinite(t) && (!Number.isFinite(now) || t > now))
  return instants.length ? Math.min(...instants) : null
}

/**
 * The same answer with every campaign that has reached its end taken out (`nowMs` on core's clock). The same
 * object when nothing has ended. This is what the server hands out when core cannot be asked at an end: a copy
 * it can still not refresh must never show a sale that is over.
 */
export function withoutEnded(body, nowMs) {
  if (!body) return body
  const ended = PRESENTATIONS.filter((p) => body[p] && !(Date.parse(body[p].ends_at) > nowMs))
  if (!ended.length) return body
  const out = { ...body }
  for (const p of ended) out[p] = null
  return out
}

/**
 * The rules the server's held copy of the campaigns lives by (coreRead.js `heldValue`). `answer` is core's whole
 * answer, `{ data, locale }`; `receivedAt` is this machine's clock when it arrived. Core's clock is the answer's
 * own `now` moved on by the time since — this machine's clock is never compared with core's.
 *
 *   until   the instant, on this machine's clock, from which the copy is not an answer any more
 *   settle  what is handed out of a copy: the answer without whatever has ended since
 */
export const campaignHoldRules = {
  until(answer, receivedAt) {
    const change = nextChangeAt(answer?.data)
    const sent = Date.parse(answer?.data?.now)
    return change == null || !Number.isFinite(sent) ? null : receivedAt + (change - sent)
  },
  settle(answer, now, receivedAt) {
    const clock = Date.parse(answer?.data?.now) + (now - receivedAt)
    if (!answer?.data || !Number.isFinite(clock)) return answer
    const data = withoutEnded(answer.data, clock)
    return data === answer.data ? answer : { ...answer, data }
  },
}

// ── a product's live offer, and the page's ONE clock (items 41 and 42, 2026-11-05) ──────────────────────
// Which live campaign a product is in: the Flash Sale wins over Hot Deals (the developer's choice). `data` is the
// campaigns query's value. Core lists every product of a campaign in `product_ids`; a core that does not send it
// yet is read through `progress` (keyed by product id) and the slider's cards.
const idsOf = (campaign) => {
  if (!campaign) return null
  if (!campaign.__ids) {
    const ids = campaign.product_ids || [...Object.keys(campaign.progress || {}).map(Number), ...(campaign.cards || []).map((c) => c.id)]
    Object.defineProperty(campaign, '__ids', { value: new Set(ids), enumerable: false })
  }
  return campaign.__ids
}
export const offerOf = (productId, data) => {
  const id = Number(productId)
  if (!data || !id) return null
  if (idsOf(data.flash_sale)?.has(id)) return { kind: 'flash', ends_at: data.flash_sale.ends_at }
  if (idsOf(data.hot_deals)?.has(id)) return { kind: 'hot', ends_at: data.hot_deals.ends_at }
  return null
}

// ONE ticking clock for the whole page: every offer strip subscribes to it, none starts an interval of its own —
// forty cards in offers cost one timer, and a page with none costs nothing (the interval runs only while somebody
// listens). 0 = "the browser's time is not known yet" (the server render and the first client render).
let tick = 0
let timer = null
const listeners = new Set()
export const subscribeClock = (fn) => {
  listeners.add(fn)
  if (!timer) {
    tick = Date.now()
    timer = setInterval(() => {
      tick = Date.now()
      listeners.forEach((f) => f())
    }, 1000)
  }
  return () => {
    listeners.delete(fn)
    if (!listeners.size && timer) {
      clearInterval(timer)
      timer = null
    }
  }
}
export const noClock = () => () => {}
export const readClock = () => tick
export const readServerClock = () => 0

// ── Hot Deals mode ───────────────────────────────────────────────────────────────────────────────────────
// The header switch stores the id of the campaign it was turned on FOR. The mode is on only while that very
// campaign is the live one, so it ends with the campaign by itself — nobody is left in an orange shop that
// shows nothing.
export const HOT_MODE_KEY = 'wz-hot-deals'

export const hotModeOn = (storedId, liveHotDeals) =>
  !!liveHotDeals && storedId != null && String(storedId) === String(liveHotDeals.id)
