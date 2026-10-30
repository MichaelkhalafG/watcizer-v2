import { describe, expect, it } from 'vitest'
import { storesJsonLd } from '../src/lib/storesJsonLd'
import { STORES_LD, TRUST_PAGES } from '../src/content/trustPages'

/*
 * The contact and about pages' Store + Organization JSON-LD (Part 3 task 5): valid in shape for Google's LocalBusiness
 * reading (name, address with a street and a country, telephone in E.164, opening hours as HH:MM), one Store per branch
 * the contact page lists, and nothing the page does not state (hours only where the page shows hours; no geo).
 */
describe('storesJsonLd', () => {
  for (const lang of ['en', 'ar']) {
    it(`is a valid Organization + Stores graph (${lang})`, () => {
      const ld = storesJsonLd(lang)
      expect(ld['@context']).toBe('https://schema.org')
      const org = ld['@graph'].find((n) => n['@type'] === 'Organization')
      expect(org.name).toBeTruthy()
      expect(org.contactPoint[0].telephone).toMatch(/^\+20\d{10}$/)
      expect(org.contactPoint[0].url).toMatch(/^https:\/\/wa\.me\/20\d{10}$/)
      const stores = ld['@graph'].filter((n) => n['@type'] === 'Store')
      expect(stores.length).toBe(TRUST_PAGES.contact.en.stores.length)
      for (const s of stores) {
        expect(s.name).toBeTruthy()
        expect(s.address['@type']).toBe('PostalAddress')
        expect(s.address.streetAddress).toBeTruthy()
        expect(s.address.addressCountry).toBe('EG')
        expect(s.telephone).toMatch(/^\+20\d{10}$/)
        expect(s.parentOrganization['@id']).toBe(org['@id'])
        expect(s.geo).toBeUndefined()
        for (const h of s.openingHoursSpecification || []) {
          expect(h.opens).toMatch(/^\d{2}:\d{2}$/)
          expect(h.closes).toMatch(/^\d{2}:\d{2}$/)
          expect(h.dayOfWeek.length).toBeGreaterThan(0)
        }
      }
      expect(JSON.parse(JSON.stringify(ld))).toEqual(ld)
    })
  }

  it('states hours only for a branch the contact page shows hours for, and the same number the page shows', () => {
    const page = TRUST_PAGES.contact.en
    STORES_LD.stores.forEach((s, i) => expect(Boolean(s.hours)).toBe(Boolean(page.stores[i]?.hours && (page.storeHours || page.hours))))
    // the client's hours (2026-10-29): both branches, every day, 10:00 to midnight (midnight = 23:59)
    for (const s of STORES_LD.stores) expect([s.hours?.days.length, s.hours?.opens, s.hours?.closes]).toEqual([7, '10:00', '23:59'])
    const shown = page.cards.find((c) => c.icon === 'phone').value
    expect(STORES_LD.phone).toBe('+2' + shown)
  })
})
