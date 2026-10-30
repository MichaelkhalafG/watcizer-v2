import { SITE, localePath } from './requestLang'
import { STORES_LD } from '../content/trustPages'

/*
 * The shop and its branches as structured data (Part 3 task 5, 2026-10-29): one Organization (the customer-service
 * number and the WhatsApp link as its contactPoint) and a Store per branch, from STORES_LD in this storefront's
 * trustPages.js — which says only what the contact page shows. Emitted on /contact-us and /about-us, both languages.
 * A TWIN (identical in Frontend-next and Frontend-brandfashion; the data differs, in trustPages.js).
 *
 * @param {'en'|'ar'} lang  the page's language (the url of the page the markup sits on)
 */
export function storesJsonLd(lang = 'en') {
  const org = `${SITE}/#organization`
  const contactUrl = `${SITE}${localePath('/contact-us', lang)}`
  return {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'Organization',
        '@id': org,
        name: STORES_LD.name,
        url: SITE,
        logo: `${SITE}${STORES_LD.logo}`,
        email: STORES_LD.email,
        sameAs: STORES_LD.sameAs,
        contactPoint: [
          {
            '@type': 'ContactPoint',
            telephone: STORES_LD.phone,
            contactType: 'customer service',
            areaServed: 'EG',
            availableLanguage: ['Arabic', 'English'],
            // the same number on WhatsApp
            url: STORES_LD.whatsapp,
          },
        ],
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
        ...(s.hours
          ? { openingHoursSpecification: [{ '@type': 'OpeningHoursSpecification', dayOfWeek: s.hours.days, opens: s.hours.opens, closes: s.hours.closes }] }
          : {}),
      })),
    ],
  }
}
