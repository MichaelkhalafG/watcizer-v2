import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /editorial-policy and /ar/editorial-policy (SEO Phase 3, 2026-11-14): how articles are written (with the help of AI
// tools), checked (automatically, against the live catalogue) and kept up to date — linked from every article. It never
// claims human review or editing (BUSINESS FACTS 10). A TWIN: the text is each shop's own, in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('editorial')

export default function Page() {
  return <TrustPage page="editorial" />
}
