import { readFileSync } from 'node:fs'
import { expect, it } from 'vitest'

// THE PRODUCT PAGE'S LARGEST PAINT (2026-11-26, Search Console Core Web Vitals): the gallery's first photo is fetched at high
// priority and eagerly; every other photo of the gallery stays lazy and at the browser's own priority, so the one
// image the shopper sees first does not wait behind the rest.
const src = readFileSync(new URL('../src/Components/Product/BfProductPage.jsx', import.meta.url), 'utf8')

it('fetches the first product photo at high priority, and only the first', () => {
  expect(src).toMatch(/loading=\{i === 0 \? 'eager' : 'lazy'\}[\s\S]{0,260}fetchPriority=\{i === 0 \? 'high' : undefined\}/)
  // no gallery image is high priority for every index
  expect(src).not.toMatch(/fetchPriority[=:]\s*\{?\s*['"]high['"]\s*\}?[^?]*wz-pd-slide-img/)
})
