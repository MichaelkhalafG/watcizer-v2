import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { fromV2Listing, packListing, unpackListing, withLegacyCategoryFacets } from '../src/lib/v2Listing'
import { transformProductData } from '../src/utils/transformProduct'

/*
 * A LISTING PAGE'S HTML CARRIES CORE'S PAGE ONCE, AND DRAWS THE SAME (the follow-up of 2026-11-06). A TWIN.
 *
 * The server page used to put the whole adapted payload in its HTML: every card twice (the legacy row, which keeps
 * every v2 field, and the raw card under `v2`) and every facet block twice. It now puts the raw page alone
 * (`packListing`), and the hook that reads it rebuilds the payload with the same adapter (`unpackListing`). The claim
 * that matters is that NOTHING THE PAGE READS CHANGES: these tests take core's real answer for three cards
 * (recorded from the scratch copy on 2026-11-06), send it through the HTML's JSON as the page does, and compare
 * the result with the adapter's own, field for field — then through the real card transform.
 */

const here = dirname(fileURLToPath(import.meta.url))
const page = JSON.parse(readFileSync(join(here, 'fixtures', 'v2-listing-3.json'), 'utf8'))
const throughHtml = (value) => JSON.parse(JSON.stringify(value))

describe('the listing in a page, packed', () => {
  const whole = fromV2Listing(page)

  it('the fixture is a real page: three cards, their facets and the total', () => {
    expect(page.data).toHaveLength(3)
    expect(whole.products.map((p) => p.id)).toEqual(page.data.map((c) => c.id))
    expect(whole.total).toBe(page.meta.total)
    expect(Object.keys(whole.facets.brands).length).toBeGreaterThan(0)
  })

  it('carries each card once: the packed copy is the raw page and nothing else', () => {
    const packed = packListing(whole)
    expect(Object.keys(packed)).toEqual(['v2'])
    expect(packed.v2).toBe(whole.v2)
    const before = JSON.stringify(whole)
    const after = JSON.stringify(packed)
    // the slug of a card appears once per copy of the card
    const copies = (text, slug) => text.split(`"slug":"${slug}"`).length - 1
    for (const card of page.data) {
      expect(copies(before, card.slug)).toBe(2)
      expect(copies(after, card.slug)).toBe(1)
    }
    expect(after.length).toBeLessThan(before.length * 0.6)
  })

  it('unpacked after the trip through the HTML, it is the adapter\'s own payload', () => {
    const arrived = unpackListing(throughHtml(packListing(whole)))
    expect(arrived).toEqual(throughHtml(whole))
  })

  it('a payload that is already whole passes through both untouched (the browser\'s own refetch)', () => {
    expect(unpackListing(whole)).toBe(whole)
    expect(packListing(packListing(whole))).toEqual({ v2: whole.v2 })
    expect(unpackListing(undefined)).toBe(undefined)
    expect(packListing(null)).toBe(null)
  })

  it('the cards drawn from it are the same cards', () => {
    const draw = (payload) => transformProductData(payload.products, {}, payload.ratings, payload.images, 'en')
    const arrived = unpackListing(throughHtml(packListing(whole)))
    expect(draw(arrived)).toEqual(draw(throughHtml(whole)))
    expect(draw(arrived)).toHaveLength(3)
  })

  it('the category counts are folded onto it as before', () => {
    const tables = { categoryTypes: [], subTypes: [] }
    const arrived = unpackListing(throughHtml(packListing(whole)))
    expect(withLegacyCategoryFacets(arrived, tables)).toEqual(withLegacyCategoryFacets(throughHtml(whole), tables))
  })
})
