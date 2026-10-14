import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'

// /contact-us and /ar/contact-us — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('contact')

export default function Page() {
  return <TrustPage page="contact" />
}
