// NOT from ./requestLang (2026-11-14): that imports next/headers, and this module reaches the client bundle (detailSeo →
// the hero product page on Watchizer) — the production build refuses it
import { localePath } from '../utils/localePath'
import { SITE } from './siteAddress'
import { STORES_LD } from '../content/trustPages'

/*
 * THE SHOP AS STRUCTURED DATA (2026-11-14, SEO Phase 1 item 2). A TWIN; the data is each shop's own (STORES_LD in its
 * trustPages.js, the fees from its dashboard). Every value here is one the shop's pages state:
 *   • founded 2013 (BUSINESS FACTS 1); the customer-service number, e-mail and social profiles of the contact page;
 *   • returns (BUSINESS FACTS 6, the terms page): 14 days, by post, the shop pays the return shipping, a full refund;
 *   • delivery within 24 hours (BUSINESS FACTS 5 — Michael, 2026-11-14: the structured data says 24 hours), at the
 *     dashboard's fee for the governorate. Google takes regional rates only for the US, Australia and Japan, so Egypt is
 *     ONE condition with the highest governorate fee as the upper bound (`maxValue`). No free-shipping threshold.
 * Google: the organization-level policies once on the site (the home page), the offer-level subsets on each product.
 */

const RETURN_DAYS = 14
const S = (t) => `https://schema.org/${t}`
const day = (min, max) => ({ '@type': 'QuantitativeValue', minValue: min, maxValue: max, unitCode: 'DAY' })

export const orgId = () => `${SITE}/#organization`

// The organization-level return policy (the full set Google reads there).
/**
 * The warranty an offer carries, as schema.org's WarrantyPromise (2026-11-14): the years core sends (the product's own,
 * else a watch's brand default, else a watch's one year). null for none — 0, empty or not a number states nothing.
 */
export function offerWarranty(years, provider = null) {
  const n = Number(years)
  if (!Number.isFinite(n) || n <= 0) return null
  // who gives it (2026-11-14): named in words only, never an agent company
  const name = provider === 'agent' ? "Brand agent's warranty" : provider === 'store' ? "Store's warranty" : null
  return { '@type': 'WarrantyPromise', ...(name ? { name } : {}), durationOfWarranty: { '@type': 'QuantitativeValue', value: n, unitCode: 'ANN' } }
}

export function returnPolicy() {
  return {
    '@type': 'MerchantReturnPolicy',
    '@id': `${SITE}/#return-policy`,
    applicableCountry: 'EG',
    returnPolicyCountry: 'EG',
    returnPolicyCategory: S('MerchantReturnFiniteReturnWindow'),
    merchantReturnDays: RETURN_DAYS,
    returnMethod: S('ReturnByMail'),
    returnFees: S('FreeReturn'),
    refundType: S('FullRefund'),
    itemCondition: S('NewCondition'),
  }
}

// The offer-level subset (merchant listings: applicableCountry, returnPolicyCategory, merchantReturnDays, returnFees,
// returnMethod) — the same policy, as each product offer carries it.
export function offerReturnPolicy() {
  return {
    '@type': 'MerchantReturnPolicy',
    applicableCountry: 'EG',
    returnPolicyCategory: S('MerchantReturnFiniteReturnWindow'),
    merchantReturnDays: RETURN_DAYS,
    returnMethod: S('ReturnByMail'),
    returnFees: S('FreeReturn'),
  }
}

// `fees`: { min, max } of the dashboard's per-governorate fees (EGP), or null when core could not say — then there is no
// shipping markup at all, never an invented one.
export function shippingService(fees) {
  if (!fees?.max) return null
  return {
    '@type': 'ShippingService',
    '@id': `${SITE}/#shipping`,
    name: 'Delivery across Egypt',
    fulfillmentType: S('FulfillmentTypeDelivery'),
    handlingTime: { '@type': 'ServicePeriod', duration: day(0, 0) },
    shippingConditions: [
      {
        '@type': 'ShippingConditions',
        shippingDestination: { '@type': 'DefinedRegion', addressCountry: 'EG' },
        shippingRate: { '@type': 'MonetaryAmount', maxValue: fees.max, currency: 'EGP' },
        transitTime: { '@type': 'ServicePeriod', duration: day(0, 1) },
      },
    ],
  }
}

export function offerShippingDetails(fees) {
  if (!fees?.max) return null
  return {
    '@type': 'OfferShippingDetails',
    shippingDestination: { '@type': 'DefinedRegion', addressCountry: 'EG' },
    shippingRate: { '@type': 'MonetaryAmount', maxValue: fees.max, currency: 'EGP' },
    deliveryTime: { '@type': 'ShippingDeliveryTime', handlingTime: day(0, 0), transitTime: day(0, 1) },
  }
}

/**
 * THE OWNER AND THE SISTER SHOP (Michael, 2026-11-17; the client approves saying it): both shops' Organization names
 * Elegance as its parentOrganization, and Elegance lists both shops as its subOrganization — the sister shop by the
 * same @id its own site gives its Organization (`{site}/#organization`), so the two graphs join. Elegance has a NAME
 * ONLY: its domain serves the API, not a public page. Nothing when trustPages.js names no parent.
 */
export function familyLd(org) {
  if (!STORES_LD.parent) return {}
  const sister = STORES_LD.sister?.url
    ? [{ '@type': 'Organization', '@id': `${STORES_LD.sister.url}/#organization`, name: STORES_LD.sister.name, url: STORES_LD.sister.url }]
    : []
  return {
    parentOrganization: { '@type': 'Organization', name: STORES_LD.parent, subOrganization: [{ '@id': org }, ...sister] },
  }
}

/**
 * The home page's graph: the shop (Organization + OnlineStore) with its policies, its two stores as physical places of
 * it, and the WebSite (name and url only — no SearchAction: Google retired the sitelinks search box, and /search stays
 * unindexed).
 */
export function shopGraph(lang = 'en', fees = null) {
  const org = orgId()
  const shipping = shippingService(fees)
  const contactUrl = `${SITE}${localePath('/contact-us', lang)}`
  return {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': ['Organization', 'OnlineStore'],
        '@id': org,
        name: STORES_LD.name,
        url: SITE,
        logo: `${SITE}${STORES_LD.logo}`,
        foundingDate: '2013',
        email: STORES_LD.email,
        telephone: STORES_LD.phone,
        sameAs: STORES_LD.sameAs,
        areaServed: { '@type': 'Country', name: 'Egypt' },
        contactPoint: [
          { '@type': 'ContactPoint', telephone: STORES_LD.phone, contactType: 'customer service', areaServed: 'EG', availableLanguage: ['Arabic', 'English'], url: STORES_LD.whatsapp },
        ],
        hasMerchantReturnPolicy: returnPolicy(),
        ...(shipping ? { hasShippingService: shipping } : {}),
        department: STORES_LD.stores.map((s) => ({ '@id': `${SITE}/#store-${s.key}` })),
        ...familyLd(org),
      },
      ...STORES_LD.stores.map((s) => ({
        '@type': 'Store',
        '@id': `${SITE}/#store-${s.key}`,
        name: s.name,
        url: contactUrl,
        image: `${SITE}${STORES_LD.logo}`,
        telephone: STORES_LD.phone,
        parentOrganization: { '@id': org },
        address: { '@type': 'PostalAddress', ...s.address },
        ...(s.map ? { hasMap: s.map } : {}),   // the store's Google Maps link (2026-11-14)
        ...(s.hours ? { openingHoursSpecification: [{ '@type': 'OpeningHoursSpecification', dayOfWeek: s.hours.days, opens: s.hours.opens, closes: s.hours.closes }] } : {}),
      })),
      { '@type': 'WebSite', '@id': `${SITE}/#website`, name: STORES_LD.name, url: SITE, inLanguage: ['ar', 'en'], publisher: { '@id': org } },
    ],
  }
}

// What a page sends from the dashboard's shipping cities: the lowest and highest fee above 0 (EGP), or null.
export function feesOf(cities) {
  const fees = (Array.isArray(cities) ? cities : []).map((c) => Number(c?.shipping_cost)).filter((n) => Number.isFinite(n) && n > 0)
  return fees.length ? { min: Math.min(...fees), max: Math.max(...fees) } : null
}
