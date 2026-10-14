import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /privacy-policy and /ar/privacy-policy — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('privacy')

export default function Page() {
  return <TrustPage page="privacy" />
}
