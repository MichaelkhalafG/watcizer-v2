import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { heldValue, __resetCoreRead } from '../src/lib/coreRead'
import { campaignHoldRules, nextChangeAt, withoutEnded, slimCampaigns } from '../src/lib/campaign'

/*
 * AN OFFER'S START AND END VOID THE HELD CAMPAIGNS AT THAT SECOND (the follow-up of 2026-11-06). A TWIN.
 *
 * The storefront's server holds core's campaigns answer (30 s as the answer, refreshed behind the request after
 * that) so that no page waits for it. Everything a page draws of an offer comes from that one answer — the header's
 * switch, the home's two sliders, the campaign pages, the strip on a card and the note on a product page — so a
 * copy that outlives a campaign's end, or does not know a campaign has started, is a page with an ended offer or
 * without a new one. These tests hold the rule that prevents it, on a clock the test moves:
 *
 *   • one second before an end or a start the held copy is the answer and core is not asked;
 *   • AT that second it is not: the request waits for core, and what it renders is core's new answer;
 *   • the instant is read on CORE's clock (the answer's own `now`), never this machine's;
 *   • when core cannot be asked at that second, the last good copy goes out WITHOUT the offer that ended.
 */

const T0 = Date.parse('2026-11-06T10:00:00+02:00')
const iso = (ms) => new Date(ms).toISOString()
const campaign = (id, endsAt) => ({ id, presentation: 'x', name: 'Sale', starts_at: iso(T0 - 3_600_000), ends_at: iso(endsAt), count: 2, cards: [], progress: {}, product_ids: [11, 12] })
/** core's whole answer at `now` (its own clock) */
const answer = (now, { flash = null, hot = null, next = null } = {}) => ({ data: { now: iso(now), hot_deals: hot, flash_sale: flash, next_start_at: next == null ? null : iso(next) }, locale: 'en' })
const held = (fn) => heldValue('v2:campaigns', fn, { ttl: 30_000, stale: 300_000, ...campaignHoldRules })

describe('the held campaigns answer', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(T0))
    __resetCoreRead()
  })
  afterEach(() => vi.useRealTimers())

  it('is the answer until a campaign ENDS — and at that second core is asked and the offer is gone', async () => {
    const end = T0 + 10_000
    const core = vi
      .fn()
      .mockResolvedValueOnce(answer(T0, { flash: campaign(7, end), hot: campaign(8, T0 + 86_400_000) }))
      .mockImplementation(async () => answer(Date.now(), { hot: campaign(8, T0 + 86_400_000) }))
    const read = held(core)

    expect((await read()).data.flash_sale.id).toBe(7)
    vi.setSystemTime(new Date(end - 1_000))
    // one second before the end: the copy, and no request
    expect((await read()).data.flash_sale.id).toBe(7)
    expect(core).toHaveBeenCalledTimes(1)

    vi.setSystemTime(new Date(end))
    const after = await read()
    // AT the end: core was asked before anything was rendered, and the page gets its answer — no Flash Sale
    expect(core).toHaveBeenCalledTimes(2)
    expect(after.data.flash_sale).toBeNull()
    expect(after.data.hot_deals.id).toBe(8)
  })

  it('is the answer until a campaign STARTS — and at that second core is asked and the offer is there', async () => {
    const start = T0 + 12_000
    const core = vi
      .fn()
      .mockResolvedValueOnce(answer(T0, { next: start }))
      .mockImplementation(async () => answer(Date.now(), { hot: campaign(9, start + 3_600_000) }))
    const read = held(core)

    expect((await read()).data.hot_deals).toBeNull()
    vi.setSystemTime(new Date(start - 1_000))
    expect((await read()).data.hot_deals).toBeNull()
    expect(core).toHaveBeenCalledTimes(1)

    vi.setSystemTime(new Date(start))
    const after = await read()
    expect(core).toHaveBeenCalledTimes(2)
    expect(after.data.hot_deals.id).toBe(9)
    // …and that answer is held in its turn, until ITS end
    vi.setSystemTime(new Date(start + 20_000))
    expect((await read()).data.hot_deals.id).toBe(9)
    expect(core).toHaveBeenCalledTimes(2)
  })

  it('reads the instant on core\'s clock, whatever this machine\'s says', async () => {
    // core's clock is an hour ahead of this machine's; the sale ends ten seconds after core's "now"
    const ahead = 3_600_000
    const core = vi
      .fn()
      .mockResolvedValueOnce(answer(T0 + ahead, { flash: campaign(7, T0 + ahead + 10_000) }))
      .mockImplementation(async () => answer(Date.now() + ahead))
    const read = held(core)
    await read()
    vi.setSystemTime(new Date(T0 + 9_000))
    expect((await read()).data.flash_sale.id).toBe(7)
    expect(core).toHaveBeenCalledTimes(1)
    vi.setSystemTime(new Date(T0 + 10_000))
    expect((await read()).data.flash_sale).toBeNull()
    expect(core).toHaveBeenCalledTimes(2)
  })

  it('never hands out an ended offer, even when core cannot be asked at its end', async () => {
    const end = T0 + 10_000
    const core = vi
      .fn()
      .mockResolvedValueOnce(answer(T0, { flash: campaign(7, end), hot: campaign(8, T0 + 86_400_000) }))
      .mockRejectedValue(Object.assign(new Error('core is down'), { code: 'ECONNREFUSED' }))
    const read = held(core)
    await read()
    vi.spyOn(console, 'warn').mockImplementation(() => {})

    vi.setSystemTime(new Date(end))
    const pending = read()
    await vi.advanceTimersByTimeAsync(400) // coreRead's one retry
    const out = await pending
    // core was asked (twice: the retry) and could not answer: the last good copy, without what has ended
    expect(core).toHaveBeenCalledTimes(3)
    expect(out.data.flash_sale).toBeNull()
    expect(out.data.hot_deals.id).toBe(8)
    // every request after it goes on asking (not more than once in five seconds) and goes on leaving it out
    vi.setSystemTime(new Date(Date.now() + 6_000))
    const again = read()
    await vi.advanceTimersByTimeAsync(400)
    expect((await again).data.flash_sale).toBeNull()
    expect(core.mock.calls.length).toBeGreaterThan(3)
    console.warn.mockRestore()
  })

  it('without a start or an end in sight, holds as before: the answer for 30 s, refreshed behind after that', async () => {
    const core = vi.fn().mockImplementation(async () => answer(Date.now(), { hot: campaign(8, T0 + 86_400_000) }))
    const read = held(core)
    await read()
    vi.setSystemTime(new Date(T0 + 29_000))
    await read()
    expect(core).toHaveBeenCalledTimes(1)
    vi.setSystemTime(new Date(T0 + 31_000))
    // answered from the copy, core asked behind the request
    expect((await read()).data.now).toBe(iso(T0))
    expect(core).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(0)
    expect((await read()).data.now).toBe(iso(T0 + 31_000))
    expect(core).toHaveBeenCalledTimes(2)
  })
})

describe('the instants a campaigns answer names', () => {
  it('the next change is the earliest end or start after the answer\'s own clock', () => {
    const body = answer(T0, { flash: campaign(7, T0 + 50_000), hot: campaign(8, T0 + 20_000), next: T0 + 35_000 }).data
    expect(nextChangeAt(body)).toBe(T0 + 20_000)
    expect(nextChangeAt(answer(T0, { next: T0 + 35_000 }).data)).toBe(T0 + 35_000)
    expect(nextChangeAt(answer(T0).data)).toBeNull()
    // an instant that is not after the clock is not a change to wait for
    expect(nextChangeAt(answer(T0, { next: T0 - 5 }).data)).toBeNull()
    // an older core sends no next_start_at: the ends still count
    const old = answer(T0, { hot: campaign(8, T0 + 20_000) }).data
    delete old.next_start_at
    expect(nextChangeAt(old)).toBe(T0 + 20_000)
  })

  it('takes out what has ended and nothing else — the same object when nothing has', () => {
    const body = answer(T0, { flash: campaign(7, T0 + 10_000), hot: campaign(8, T0 + 20_000) }).data
    expect(withoutEnded(body, T0 + 9_999)).toBe(body)
    const mid = withoutEnded(body, T0 + 10_000)
    expect(mid.flash_sale).toBeNull()
    expect(mid.hot_deals.id).toBe(8)
    expect(body.flash_sale.id).toBe(7) // the held copy itself is not changed
    expect(withoutEnded(body, T0 + 20_000)).toMatchObject({ flash_sale: null, hot_deals: null })
  })

  it('the browser keeps the next start, so an open page asks again at that second', () => {
    const slim = slimCampaigns(answer(T0, { next: T0 + 35_000 }).data)
    expect(slim.next_start_at).toBe(iso(T0 + 35_000))
    expect(slimCampaigns({}).next_start_at).toBeNull()
  })
})
