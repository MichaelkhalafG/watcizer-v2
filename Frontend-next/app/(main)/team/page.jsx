import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /team and /ar/team — the articles' author page (SEO Phase 3, 2026-11-14; the brief's BUSINESS FACTS 8): the shop team,
// never a person. A TWIN: the text is each shop's own, in src/content/trustPages.js. Every article's byline links here,
// and its structured data names this page as the author's url.
export const generateMetadata = () => trustMetadata('team')

export default function Page() {
  return <TrustPage page="team" />
}
