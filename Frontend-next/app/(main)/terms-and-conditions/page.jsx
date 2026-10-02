import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /terms-and-conditions and /ar/terms-and-conditions — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('terms')

export default function Page() {
  return <TrustPage page="terms" />
}
