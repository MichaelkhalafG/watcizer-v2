import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { coreRead, HOLD, MISS, __resetCoreRead } from '../src/lib/coreRead'

/*
 * HELD READS (the performance pass, 2026-11-05). A TWIN.
 *
 * A page's server render used to ask core again for every visitor and wait. With a `hold`, the copy this process
 * already keeps answers at once while it is young, answers AND is refreshed behind the request while it is a little
 * older, and is not used at all once it is old — and a product core no longer has is never served from it.
 */

const hold = { fresh: 10_000, stale: 60_000 }

describe('coreRead with a hold', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-05T12:00:00Z'))
    __resetCoreRead()
  })
  afterEach(() => vi.useRealTimers())

  it('answers a young copy without asking core', async () => {
    const load = vi.fn().mockResolvedValue({ price: 100 })
    expect(await coreRead('k', load, hold)).toEqual({ price: 100 })
    vi.advanceTimersByTime(9_000)
    expect(await coreRead('k', load, hold)).toEqual({ price: 100 })
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('answers an older copy at once and refreshes it behind the request', async () => {
    const load = vi.fn().mockResolvedValueOnce({ price: 100 }).mockResolvedValueOnce({ price: 90 })
    await coreRead('k', load, hold)
    vi.advanceTimersByTime(30_000)
    // the old copy is the answer, now — core is asked in the background
    expect(await coreRead('k', load, hold)).toEqual({ price: 100 })
    expect(load).toHaveBeenCalledTimes(2)
    await vi.runAllTimersAsync()
    // the next visitor has the new one, without another request
    expect(await coreRead('k', load, hold)).toEqual({ price: 90 })
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('asks core once, not once per visitor, while a refresh is running', async () => {
    let release
    const load = vi
      .fn()
      .mockResolvedValueOnce('old')
      .mockImplementationOnce(() => new Promise((r) => (release = () => r('new'))))
    await coreRead('k', load, hold)
    vi.advanceTimersByTime(30_000)
    expect(await coreRead('k', load, hold)).toBe('old')
    expect(await coreRead('k', load, hold)).toBe('old')
    expect(load).toHaveBeenCalledTimes(2)
    release()
    await vi.runAllTimersAsync()
    expect(await coreRead('k', load, hold)).toBe('new')
  })

  it('waits for core when the copy is older than the window', async () => {
    const load = vi.fn().mockResolvedValueOnce('old').mockResolvedValueOnce('new')
    await coreRead('k', load, hold)
    vi.advanceTimersByTime(61_000)
    expect(await coreRead('k', load, hold)).toBe('new')
  })

  it('keeps the copy when the refresh fails', async () => {
    const down = Object.assign(new Error('down'), { response: { status: 503 } })
    const load = vi.fn().mockResolvedValueOnce('old').mockRejectedValue(down)
    await coreRead('k', load, hold)
    vi.advanceTimersByTime(30_000)
    expect(await coreRead('k', load, hold)).toBe('old')
    await vi.runAllTimersAsync()
    expect(await coreRead('k', load, hold)).toBe('old')
  })

  it('never serves a product core no longer has', async () => {
    const gone = Object.assign(new Error('gone'), { response: { status: 404 } })
    const load = vi.fn().mockResolvedValueOnce({ id: 1 }).mockRejectedValue(gone)
    await coreRead('k', load, hold)
    vi.advanceTimersByTime(30_000)
    // this visitor still gets the copy (the refresh has not answered yet)…
    expect(await coreRead('k', load, hold)).toEqual({ id: 1 })
    await vi.runAllTimersAsync()
    // …and from the moment core says it is gone, nobody does
    expect(await coreRead('k', load, hold)).toBe(MISS)
  })

  it('holds a "no such thing" for the young window, then asks again', async () => {
    const gone = Object.assign(new Error('gone'), { response: { status: 404 } })
    const load = vi.fn().mockRejectedValueOnce(gone).mockResolvedValueOnce({ id: 2 })
    expect(await coreRead('k', load, hold)).toBe(MISS)
    vi.advanceTimersByTime(9_000)
    expect(await coreRead('k', load, hold)).toBe(MISS)
    expect(load).toHaveBeenCalledTimes(1)
    // a product created since is found once the window has passed
    vi.advanceTimersByTime(2_000)
    expect(await coreRead('k', load, hold)).toEqual({ id: 2 })
    // without a hold a miss is asked again at once, as before
    const again = vi.fn().mockRejectedValue(gone)
    await coreRead('other', again)
    await coreRead('other', again)
    expect(again).toHaveBeenCalledTimes(2)
  })

  it('asks core every time when no hold is given, as before', async () => {
    const load = vi.fn().mockResolvedValue('v')
    await coreRead('k', load)
    await coreRead('k', load)
    expect(load).toHaveBeenCalledTimes(2)
    // and a window of zero switches a hold off
    await coreRead('k', load, { fresh: 0, stale: 0 })
    expect(load).toHaveBeenCalledTimes(3)
  })

  it('holds a product page for less time than a listing, and the home for no longer than a listing', () => {
    expect(HOLD.product.stale).toBeLessThan(HOLD.listing.stale)
    expect(HOLD.listing.stale).toBeLessThanOrEqual(HOLD.home.stale)
    for (const w of Object.values(HOLD)) expect(w.fresh).toBeLessThan(w.stale)
    // the windows Michael set on 2026-11-06: the home two minutes (it was five), a listing two, a product page one
    expect([HOLD.home.stale, HOLD.listing.stale, HOLD.product.stale]).toEqual([120_000, 120_000, 60_000])
    // nothing a shopper acts on is ever more than two minutes old
    expect(Math.max(...Object.values(HOLD).map((w) => w.stale))).toBeLessThanOrEqual(120_000)
  })
})
