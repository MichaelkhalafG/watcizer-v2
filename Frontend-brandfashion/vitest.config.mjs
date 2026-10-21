import { defineConfig } from 'vitest/config'

// The storefront's own tests: the adapters that map core's v2 answers onto the shapes the pages read.
// Node, not a browser: these are pure functions over recorded answers. `pnpm test` runs them.
export default defineConfig({
  test: { environment: 'node', include: ['tests/**/*.test.mjs'] },
})
