import { defineConfig } from 'vitest/config'

// The storefront's own tests: the adapters that map core's v2 answers onto the shapes the pages read.
// Node, not a browser: these are pure functions over recorded answers. `pnpm test` runs them.
export default defineConfig({
  // the automatic JSX runtime, as Next compiles the components a test renders (Disclosure, 2026-10-22)
  esbuild: { jsx: 'automatic' },
  test: { environment: 'node', include: ['tests/**/*.test.mjs'] },
})
