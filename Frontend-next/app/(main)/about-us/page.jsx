import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /about-us and /ar/about-us — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('about')

export default function Page() {
  return <TrustPage page="about" />
}
