import http from '../Context/api'

// The listing request (C-1 stage 3, 2026-09-27) — pure, so the SERVER pages can import it too.
// ONE builder turns the filter state into the request, and the request string IS the query key.
// The server page prefetches with the same builder, so the first render hits the prefetched data
// instead of fetching again.

const PRICE_MAX = 99999999

export function listingRequest({ filters = {}, q = '', sort = 'default', page = 1, perPage = 24, lang = 'en' }) {
  const p = new URLSearchParams()
  const list = (key, values) => {
    if (values?.length) p.set(key, values.join(','))
  }
  list('brands', filters.brands)
  list('categories', filters.categories)
  list('subTypes', filters.subTypes)
  list('genders', filters.genders)
  list('dialColors', filters.dialColors)
  list('bandColors', filters.bandColors)
  list('materials', filters.materials)
  list('movements', filters.movements)
  list('shapes', filters.shapes)
  list('displayTypes', filters.displayTypes)
  list('grades', filters.grades)
  if (filters.offers) p.set('offers', '1')
  const [min, max] = filters.price || [0, PRICE_MAX]
  if (Number(min) > 0) p.set('minPrice', String(min))
  if (Number(max) < PRICE_MAX) p.set('maxPrice', String(max))
  const term = (q || '').trim()
  if (term) {
    p.set('q', term)
    // Only a search depends on the language (it matches the title in the shopper's language), so
    // only a search carries it — a language switch does not refetch a plain listing.
    p.set('lang', lang === 'ar' ? 'ar' : 'en')
  }
  if (sort && sort !== 'default') p.set('sort', sort)
  if (Number(page) > 1) p.set('page', String(page))
  if (perPage !== 24) p.set('per_page', String(perPage))
  return p.toString()
}

export const listingQueryFn =
  (qs, client = http) =>
  async () => {
    // One template literal (not a ternary): the allow-list test reads storefront calls from source.
    const { data } = await client.get(`catalog/listing?${qs}`)
    return data
  }

