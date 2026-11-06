import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { heldLookup, LOOKUP_HOLD, HOLD, MISS, CoreUnavailableError, __resetCoreRead } from '../src/lib/coreRead'

/*
 * THE TWO SMALL LOOKUPS NEVER MAKE A REQUEST WAIT (2026-11-06, Michael's decision). A TWIN.
 *
 * Every page's server render reads the category names (`catalog/meta`) and the menu's facts (`catalog/nav`). They
 * were kept five minutes and then read again while a request waited — once every five minutes one visitor's page
 * waited for core before its first byte. `heldLookup` (coreRead.js) is the rule both reads go through now
 * (serverCatalog.js `getServerTables` and `getServerNav`); these tests hold it, on a clock the test moves:
 *
 *   • for five minutes the copy is the answer and core is not asked, as before;
 *   • after that the copy is STILL the answer, at once, and core is asked behind the request — once, however many
 *     pages are opened meanwhile — so the next visitor has the new names;
 *   • a request waits only when nothing is held (a new server process) or after a whole day of silence;
 *   • a core that cannot be asked never empties the copy, and a "no such thing" is never held.
 */

const MIN = 60_000
const HOUR = 60 * MIN
const names = (label) => ({ categoryTypes: [{ id: 1, name: label }] })
const status = (code) => Object.assign(new Error(String(code)), { response: { status: code } })
/** a core whose next answer comes only when the test lets it go */
const pending = () => {
  let release
  const answer = new Promise((resolve) => (release = resolve))
  return { answer, release }
}

describe('the held lookups (category names, menu facts)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-11-06T12:00:00Z'))
    __resetCoreRead()
  })
  afterEach(() => vi.useRealTimers())

  it('waits for core once, when nothing is held, then answers five minutes from the copy', async () => {
    const core = vi.fn().mockResolvedValue(names('Watches'))
    const read = heldLookup('tables', core)
    expect(await read()).toEqual(names('Watches'))
    vi.advanceTimersByTime(5 * MIN - 1_000)
    expect(await read()).toEqual(names('Watches'))
    expect(core).toHaveBeenCalledTimes(1)
  })

  it('after five minutes still answers at once, asks core behind the request, and the next visitor has the new names', async () => {
    const slow = pending()
    const core = vi.fn().mockResolvedValueOnce(names('Watches')).mockImplementationOnce(() => slow.answer)
    const read = heldLookup('tables', core)
    await read()
    vi.advanceTimersByTime(5 * MIN + 1_000)
    // core has been asked and has NOT answered — the request has its answer all the same
    expect(await read()).toEqual(names('Watches'))
    expect(core).toHaveBeenCalledTimes(2)
    slow.release(names('Timepieces'))
    await vi.runAllTimersAsync()
    expect(await read()).toEqual(names('Timepieces'))
    expect(core).toHaveBeenCalledTimes(2)
  })

  it('asks core once while that refresh is running, however many pages are opened', async () => {
    const slow = pending()
    const core = vi.fn().mockResolvedValueOnce('old').mockImplementationOnce(() => slow.answer)
    const read = heldLookup('nav', core)
    await read()
    vi.advanceTimersByTime(6 * MIN)
    expect(await Promise.all([read(), read(), read(), read()])).toEqual(['old', 'old', 'old', 'old'])
    expect(core).toHaveBeenCalledTimes(2)
    slow.release('new')
    await vi.runAllTimersAsync()
    expect(await read()).toBe('new')
  })

  it('does not make the first visitor after hours of silence wait: the copy answers, the second visitor has the new one', async () => {
    const slow = pending()
    const core = vi.fn().mockResolvedValueOnce('evening').mockImplementationOnce(() => slow.answer)
    const read = heldLookup('tables', core)
    await read()
    vi.advanceTimersByTime(23 * HOUR)
    expect(await read()).toBe('evening')
    slow.release('morning')
    await vi.runAllTimersAsync()
    expect(await read()).toBe('morning')
  })

  it('waits for core after a whole day in which nobody asked', async () => {
    const core = vi.fn().mockResolvedValueOnce('yesterday').mockResolvedValueOnce('today')
    const read = heldLookup('tables', core)
    await read()
    vi.advanceTimersByTime(24 * HOUR + 1_000)
    expect(await read()).toBe('today')
    expect(core).toHaveBeenCalledTimes(2)
  })

  it('keeps the copy when core cannot be asked, and asks again five minutes later', async () => {
    const core = vi.fn().mockResolvedValueOnce('held').mockRejectedValue(status(503))
    const read = heldLookup('tables', core)
    await read()
    vi.advanceTimersByTime(6 * MIN)
    expect(await read()).toBe('held')
    await vi.runAllTimersAsync()
    // the failed refresh (two tries) left the copy where it was
    expect(core).toHaveBeenCalledTimes(3)
    expect(await read()).toBe('held')
    expect(core).toHaveBeenCalledTimes(3)
    vi.advanceTimersByTime(6 * MIN)
    expect(await read()).toBe('held')
    await vi.runAllTimersAsync()
    expect(core).toHaveBeenCalledTimes(5)
  })

  it('never holds a "no such thing": that request hears it and the next one asks core again', async () => {
    const core = vi.fn().mockRejectedValueOnce(status(404)).mockResolvedValueOnce('there now')
    const read = heldLookup('nav', core)
    expect(await read()).toBe(MISS)
    expect(await read()).toBe('there now')
    expect(core).toHaveBeenCalledTimes(2)
  })

  it('drops the copy when a refresh hears "no such thing", so nobody is given names core has taken away', async () => {
    const core = vi.fn().mockResolvedValueOnce('held').mockRejectedValueOnce(status(404)).mockResolvedValueOnce('back')
    const read = heldLookup('tables', core)
    await read()
    vi.advanceTimersByTime(6 * MIN)
    expect(await read()).toBe('held')
    await vi.runAllTimersAsync()
    // the next request does not get the old copy: it waits for core
    expect(await read()).toBe('back')
    expect(core).toHaveBeenCalledTimes(3)
  })

  it('fails as before when core cannot answer and nothing is held, and the next request tries again', async () => {
    const core = vi.fn().mockRejectedValueOnce(status(503)).mockRejectedValueOnce(status(503)).mockResolvedValueOnce('up')
    const read = heldLookup('tables', core)
    const first = read().catch((e) => e)
    await vi.runAllTimersAsync()
    expect(await first).toBeInstanceOf(CoreUnavailableError)
    vi.advanceTimersByTime(6_000)
    expect(await read()).toBe('up')
  })

  it('is five minutes as the answer and a day as the copy — and is not one of the price-and-stock windows', () => {
    expect(LOOKUP_HOLD).toEqual({ fresh: 5 * MIN, stale: 24 * HOUR })
    expect(Object.values(HOLD)).not.toContain(LOOKUP_HOLD)
    expect(Math.max(...Object.values(HOLD).map((w) => w.stale))).toBeLessThanOrEqual(2 * MIN)
  })
})
