import Footer from '@/src/Components/Footer/Footer'
import SiteDirectory from '@/src/Components/Footer/SiteDirectory'
import FootContact from '@/src/Components/Footer/FootContact'
import { requestLang } from '@/src/lib/requestLang'
import { STORES_LD } from '@/src/content/trustPages'

// The site's footer area. A TWIN.
//
// The full footer (<Footer/>) is desktop-only, hidden on phones by CSS (.wz-desktop-footer in Footer.css), no longer
// by useMediaQuery (2026-09-30): that hook is false on the server and on the first browser render, so the footer was
// never in the HTML and popped in only after the page's JavaScript ran — right under the header whenever the content
// had not arrived yet, then shoved down (CLS 0.50 on desktop, measured).
//
// Since 2026-11-17 (Michael) the footer area is on every width: the directory of categories and brands sits on the
// footer's ground on every screen (one row that opens on a tap on a phone), and a phone gets the footer's own last
// row — the shop's number as text with its call and WhatsApp links, and the year line. Desktops get the full footer,
// which carries the same number in its first column.
export default async function DesktopFooter() {
  const { urlLang: lang } = await requestLang()
  const year = new Date().getFullYear()
  return (
    <div className="wz-site-foot">
      {/* every category, sub-category and brand as plain links in the server HTML (2026-11-14, SEO Phase 1) */}
      <SiteDirectory />
      <div className="wz-mobile-foot" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
        <FootContact lang={lang} />
        <p className="wz-mobile-foot__copy">© {year} {STORES_LD.name}</p>
      </div>
      <div className="wz-desktop-footer">
        <Footer />
      </div>
    </div>
  )
}
