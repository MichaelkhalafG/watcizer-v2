// Development-only pages (the tokens page, B2) and how they are kept out of production.
//
// A dev page renders when the server is NOT a production build (`next dev`), or when a production
// build was made with NEXT_PUBLIC_DEV_PAGES=1 on purpose (a local `next build` to inspect it).
// The deploy server sets neither, so there the page answers 404 through `notFound()` — it is
// unreachable, not merely hidden. The decision is a pure function so it can be checked without a
// build: node -e "import('./src/lib/devPages.js').then(m => console.log(m.devPageEnabled({NODE_ENV:'production'})))"

export function devPageEnabled(env = process.env) {
  if (env.NODE_ENV !== 'production') return true
  return env.NEXT_PUBLIC_DEV_PAGES === '1'
}
