import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The analytics events (2026-10-22): what each helper hands GA4 (gtag), the Meta pixel (fbq) and the TikTok
// pixel (ttq), that a purchase is reported ONCE per order even across a reload, that the card hand-off round-trips,
// and that a storefront with no id configured sends NOTHING. The three vendor globals are spies on a stub
// `window`; localStorage is a Map. The module is re-imported per test because it reads the ids at import.

const store = new Map()
const localStorageStub = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
}
Object.defineProperty(globalThis, 'localStorage', { value: localStorageStub, configurable: true, writable: true })

const ids = { NEXT_PUBLIC_GA4_MEASUREMENT_ID: 'G-TEST12345', NEXT_PUBLIC_META_PIXEL_ID: '1614877760150035', NEXT_PUBLIC_TIKTOK_PIXEL_ID: 'CTEST000ABC' }

async function load(env = ids) {
  vi.resetModules()
  for (const k of Object.keys(ids)) vi.stubEnv(k, env[k] ?? '')
  const gtag = vi.fn()
  const fbq = vi.fn()
  const track = vi.fn()
  globalThis.window = { gtag, fbq, ttq: { track } }
  const px = await import('../src/scripts/pixels')
  return { px, gtag, fbq, track }
}

beforeEach(() => store.clear())
afterEach(() => {
  vi.unstubAllEnvs()
  vi.useRealTimers()
  delete globalThis.window
})

describe('each helper hands every platform its native event, in EGP', () => {
  it('view_item · ViewContent', async () => {
    const { px, gtag, fbq, track } = await load()
    px.trackViewContent({ id: 440, name: 'Tommy Hilfiger 1791631', value: 3090 })
    expect(gtag).toHaveBeenCalledWith('event', 'view_item', {
      currency: 'EGP',
      value: 3090,
      items: [{ item_id: '440', item_name: 'Tommy Hilfiger 1791631', price: 3090, quantity: 1 }],
    })
    expect(fbq).toHaveBeenCalledWith('track', 'ViewContent', expect.objectContaining({ content_ids: ['440'], value: 3090, currency: 'EGP' }))
    expect(track).toHaveBeenCalledWith('ViewContent', expect.objectContaining({ value: 3090, currency: 'EGP' }))
  })

  it('add_to_cart · AddToCart carries the value of THIS action and the unit price', async () => {
    const { px, gtag, fbq } = await load()
    px.trackAddToCart({ id: 440, name: 'Tommy', value: 6180, quantity: 2 })
    expect(gtag).toHaveBeenCalledWith('event', 'add_to_cart', { currency: 'EGP', value: 6180, items: [{ item_id: '440', item_name: 'Tommy', price: 3090, quantity: 2 }] })
    expect(fbq).toHaveBeenCalledWith('track', 'AddToCart', expect.objectContaining({ contents: [{ id: '440', quantity: 2 }], value: 6180 }))
  })

  it('remove_from_cart is GA4 only', async () => {
    const { px, gtag, fbq, track } = await load()
    px.trackRemoveFromCart({ id: 440, value: 3090, quantity: 1 })
    expect(gtag).toHaveBeenCalledWith('event', 'remove_from_cart', { currency: 'EGP', value: 3090, items: [{ item_id: '440', price: 3090, quantity: 1 }] })
    expect(fbq).not.toHaveBeenCalled()
    expect(track).not.toHaveBeenCalled()
  })

  it('view_cart is GA4 only', async () => {
    const { px, gtag, fbq } = await load()
    px.trackViewCart({ value: 4000, contents: [{ id: 1, name: 'A', quantity: 1, price: 1000 }, { id: 2, name: 'B', quantity: 3, price: 1000 }] })
    expect(gtag).toHaveBeenCalledWith('event', 'view_cart', expect.objectContaining({ value: 4000, items: [expect.objectContaining({ item_id: '1' }), expect.objectContaining({ item_id: '2', quantity: 3 })] }))
    expect(fbq).not.toHaveBeenCalled()
  })

  it('begin_checkout · InitiateCheckout', async () => {
    const { px, gtag, fbq, track } = await load()
    px.trackInitiateCheckout({ value: 4000, contents: [{ id: 1, name: 'A', quantity: 1, price: 1000 }, { id: 2, name: 'B', quantity: 3, price: 1000 }] })
    expect(gtag).toHaveBeenCalledWith('event', 'begin_checkout', expect.objectContaining({ currency: 'EGP', value: 4000 }))
    expect(fbq).toHaveBeenCalledWith('track', 'InitiateCheckout', expect.objectContaining({ num_items: 4, value: 4000 }))
    expect(track).toHaveBeenCalledWith('InitiateCheckout', expect.objectContaining({ value: 4000 }))
  })

  it('view_item_list: the first 50, in order, with their index', async () => {
    const { px, gtag } = await load()
    const contents = Array.from({ length: 60 }, (_, i) => ({ id: i + 1, name: `P${i + 1}`, price: 100 + i }))
    px.trackViewItemList({ listId: '/listing?brand=rolex', listName: 'Rolex', contents })
    const [, name, params] = gtag.mock.calls[0]
    expect(name).toBe('view_item_list')
    expect(params.item_list_id).toBe('/listing?brand=rolex')
    expect(params.item_list_name).toBe('Rolex')
    expect(params.items).toHaveLength(50)
    expect(params.items[0]).toEqual({ item_id: '1', item_name: 'P1', price: 100, quantity: 1, index: 0 })
    expect(params.items[49].index).toBe(49)
  })
})

describe('purchase: once per order, the same id as the server event', () => {
  const order = { orderNumber: 'WZ-2026-000123', value: 3090, shipping: 60, contents: [{ id: 440, name: 'Tommy', quantity: 1, price: 3090 }] }

  it('GA4 gets transaction_id, value, shipping and the items; Meta and TikTok get the event id', async () => {
    const { px, gtag, fbq, track } = await load()
    expect(px.trackPurchase(order)).toBe(true)
    expect(gtag).toHaveBeenCalledWith('event', 'purchase', {
      transaction_id: 'WZ-2026-000123',
      currency: 'EGP',
      value: 3090,
      shipping: 60,
      items: [{ item_id: '440', item_name: 'Tommy', price: 3090, quantity: 1 }],
    })
    expect(fbq).toHaveBeenCalledWith('track', 'Purchase', expect.objectContaining({ value: 3090, currency: 'EGP', num_items: 1 }), { eventID: 'purchase-WZ-2026-000123' })
    expect(track).toHaveBeenCalledWith('CompletePayment', expect.objectContaining({ value: 3090 }), { event_id: 'purchase-WZ-2026-000123' })
    expect(px.purchaseEventId('WZ-2026-000123')).toBe('purchase-WZ-2026-000123')
  })

  it('a second call for the same order sends nothing', async () => {
    const { px, gtag, fbq, track } = await load()
    px.trackPurchase(order)
    expect(px.trackPurchase(order)).toBe(false)
    expect(gtag).toHaveBeenCalledTimes(1)
    expect(fbq).toHaveBeenCalledTimes(1)
    expect(track).toHaveBeenCalledTimes(1)
  })

  it('a reload (a fresh module, the same storage) still sends nothing', async () => {
    const first = await load()
    first.px.trackPurchase(order)
    expect(JSON.parse(store.get('wz_fb_purchased'))).toContain('WZ-2026-000123')
    const again = await load() // the in-memory Set is gone; localStorage is not
    expect(again.px.trackPurchase(order)).toBe(false)
    expect(again.gtag).not.toHaveBeenCalled()
    expect(again.fbq).not.toHaveBeenCalled()
  })
})

describe('the card hand-off', () => {
  it('what Checkout saves, the landing takes — once', async () => {
    const { px } = await load()
    px.savePendingPurchase({ orderNumber: 'WZ-2026-000124', value: 3090.004, shipping: 60, contents: [{ id: 440, name: 'Tommy', quantity: 1, price: 3090 }] })
    const taken = px.takePendingPurchase()
    expect(taken).toMatchObject({ orderNumber: 'WZ-2026-000124', value: 3090, shipping: 60 })
    expect(taken.contents).toHaveLength(1)
    expect(px.takePendingPurchase()).toBeNull()
  })

  it('nothing saved, nothing taken; a broken record is nothing', async () => {
    const { px } = await load()
    expect(px.takePendingPurchase()).toBeNull()
    store.set('wz_pending_purchase', '{not json')
    expect(px.takePendingPurchase()).toBeNull()
    expect(store.has('wz_pending_purchase')).toBe(false)
  })
})

describe('a vendor global that is not there yet', () => {
  it('is waited for, then called; a platform with no id is never waited for', async () => {
    vi.useFakeTimers()
    const { px, fbq, track } = await load()
    const gtag = vi.fn()
    delete globalThis.window.gtag
    px.trackViewContent({ id: 1, name: 'A', value: 10 })
    expect(fbq).toHaveBeenCalledTimes(1)
    expect(track).toHaveBeenCalledTimes(1)
    expect(gtag).not.toHaveBeenCalled()
    vi.advanceTimersByTime(600)
    globalThis.window.gtag = gtag
    vi.advanceTimersByTime(300)
    expect(gtag).toHaveBeenCalledWith('event', 'view_item', expect.objectContaining({ value: 10 }))
  })
})

describe('a storefront with NO id configured (Brand Fashion today)', () => {
  it('sends nothing to anyone, and never queues a retry', async () => {
    vi.useFakeTimers()
    const { px, gtag, fbq, track } = await load({})
    px.trackViewContent({ id: 1, name: 'A', value: 10 })
    px.trackAddToCart({ id: 1, name: 'A', value: 10 })
    px.trackViewCart({ value: 10, contents: [{ id: 1, quantity: 1, price: 10 }] })
    px.trackInitiateCheckout({ value: 10, contents: [{ id: 1, quantity: 1, price: 10 }] })
    px.trackViewItemList({ listId: 'x', contents: [{ id: 1, name: 'A', price: 10 }] })
    px.trackPurchase({ orderNumber: 'BF-1', value: 10, contents: [{ id: 1, quantity: 1, price: 10 }] })
    vi.advanceTimersByTime(20000)
    expect(gtag).not.toHaveBeenCalled()
    expect(fbq).not.toHaveBeenCalled()
    expect(track).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
  })
})
