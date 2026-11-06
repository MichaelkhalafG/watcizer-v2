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
  //   selling      a unit can still be bought → "N% sold" (never 100) and "N left"
  //   sold_out     nothing left and the campaign SOLD every unit → a full bar and "Sold out"
  //   unavailable  nothing left, but not by this deal's sales (an adjustment, another channel) → NO bar: the card
  //                says "Sold out" and claims no share
  const state = campaign.state || (left > 0 ? 'selling' : sold >= Number(campaign.start) ? 'sold_out' : 'unavailable')
  if (state === 'unavailable') return { state, left: 0, sold, pct: null }
  if (state === 'sold_out') return { state, left: 0, sold, pct: 100 }
  return { state: 'selling', left, sold, pct: Math.min(99, Math.max(0, Math.round(Number(campaign.pct) || 0))) }
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
  hot_deals: slimCampaign(body?.hot_deals),
  flash_sale: slimCampaign(body?.flash_sale),
  // the next scheduled start (core, 2026-11-06): the browser asks again at that second (usePromo.useCampaignStart)
  next_start_at: body?.next_start_at ?? null,
})

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
