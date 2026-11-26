import { readFileSync } from 'node:fs'
import { expect, it } from 'vitest'

// THE CRAWL RULES (2026-11-14, SEO Phase 1 item 6): filtered, sorted and searched listings are closed to crawlers in
// public/robots.txt, pagination and every clean page stay open, the private pages stay closed. Google's semantics:
// the longest matching rule wins, Allow wins a tie, * matches anything. A TWIN (the file it reads differs by one line).

const txt = readFileSync(new URL('../public/robots.txt', import.meta.url), 'utf8')
const rules = []
for (const l of txt.split(/\r?\n/)) {
  const m = l.match(/^(Allow|Disallow):\s*(\S*)/i)
  if (m && m[2]) rules.push({ allow: m[1].toLowerCase() === 'allow', p: m[2] })
}
const toRe = (p) => {
  const anchored = p.endsWith('$')
  const body = (anchored ? p.slice(0, -1) : p).split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*')
  return new RegExp('^' + body + (anchored ? '$' : ''))
}
const allowed = (u) => {
  let best = null
  for (const r of rules) {
    if (!toRe(r.p).test(u)) continue
    if (!best || r.p.length > best.p.length || (r.p.length === best.p.length && r.allow)) best = r
  }
  return !best || best.allow
}
const cases = [
  ['/listing', 1], ['/listing?page=2', 1], ['/listing?brand=rolex', 0], ['/ar/listing?gender=Men', 0], ['/brand/rolex?page=2', 1],
  ['/brand/rolex?sort=price_asc', 0], ['/brand/rolex?page=2&sort=newest', 0], ['/product/x', 1], ['/cart', 0], ['/ar/cart', 0],
  ['/listing?utm_source=x', 1], ['/hot-deals?category=watches', 0], ['/listing?q=rolex', 0], ['/faq', 1], ['/subtypes/diver', 1],
  ['/ar/brand/rolex', 1], ['/blog/how-to-tell-if-a-watch-is-original', 1], ['/listing?sale=1', 0], ['/listing?minPrice=500', 0],
]
it('closes filters, sorts and searches, and leaves pagination and the clean pages open', () => {
  const wrong = cases.filter(([u, exp]) => (allowed(u) ? 1 : 0) !== exp).map(([u]) => u)
  expect(wrong).toEqual([])
})

// 2026-11-26: the old shop's action links (WooCommerce's add-to-cart and wishlist) are closed; the clean product
// page, and a product with an ordinary query, stay open
it('closes the old shop\'s cart and wishlist action links, and only them', () => {
  const actions = [
    ['/product/x?add-to-wishlist=62994', 0], ['/product/x/?add-to-wishlist=1', 0], ['/product/x?add-to-cart=5', 0],
    ['/product/x?foo=1&add-to-cart=5', 0], ['/product/x?wishlist_id=1', 0], ['/product/x?remove_item=a', 0],
    ['/?wc-ajax=get_refreshed_fragments', 0], ['/product/x', 1], ['/product/x?utm_source=x', 1], ['/ar/product/x', 1],
  ]
  const wrong = actions.filter(([u, exp]) => (allowed(u) ? 1 : 0) !== exp).map(([u]) => u)
  expect(wrong).toEqual([])
})
