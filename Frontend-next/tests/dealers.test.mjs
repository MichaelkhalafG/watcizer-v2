import { describe, expect, it } from 'vitest'
import { dealerBrands, dealerSentence, isDealerFor } from '../src/lib/dealers.js'

/*
 * AUTHORIZED DEALER, PER BRAND (Michael, 2026-11-14) — a twin. The claim exists only for the brands the shop switched
 * on (v2 meta settings.authorized_brands); none switched on = no sentence anywhere.
 */
const meta = {
  storefront: { settings: { authorized_brands: ['hugo-boss', 'tommy-hilfiger', 'unknown-brand'] } },
  brands: [
    { slug: 'hugo-boss', name: { en: 'Hugo Boss', ar: 'هوغو بوس' } },
    { slug: 'tommy-hilfiger', name: { en: 'Tommy Hilfiger', ar: 'تومي هيلفيغر' } },
    { slug: 'guess', name: { en: 'Guess', ar: 'جيس' } },
  ],
}

describe('authorized dealer', () => {
  it('names only the brands switched on that the shop knows', () => {
    expect(dealerBrands(meta).map((b) => b.slug)).toEqual(['hugo-boss', 'tommy-hilfiger'])
    expect(isDealerFor(meta, 'guess')).toBe(false)
    expect(isDealerFor(meta, 'hugo-boss')).toBe(true)
  })
  it('says it in each language, and says nothing when none is switched on', () => {
    expect(dealerSentence(dealerBrands(meta), 'en', 'Watchizer')).toBe('Watchizer is an authorized dealer for Hugo Boss and Tommy Hilfiger.')
    expect(dealerSentence(dealerBrands(meta), 'ar', 'واتشايزر')).toBe('واتشايزر وكيل معتمد لـهوغو بوس وتومي هيلفيغر.')
    expect(dealerSentence(dealerBrands({ storefront: { settings: {} }, brands: meta.brands }), 'en', 'X')).toBe('')
    expect(dealerBrands(null)).toEqual([])
  })
})
