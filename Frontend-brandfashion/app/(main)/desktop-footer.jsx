import SiteFooter from '@/src/Components/Footer/SiteFooter'
import '@/src/Components/Footer/Footer.css'

// The site's footer area. A TWIN.
//
// Since 2026-11-19 (Michael, part 2 of the three fixes) ONE footer on every width: SiteFooter, a server component,
// so every link is in the server HTML. The categories with their sub-categories and the brands (the directory of
// 2026-11-14) are part of it; on a phone its columns are closed sections that open on a tap. Never gated by
// useMediaQuery (2026-09-30: that hook is false on the server, so a gated footer popped in after hydration — CLS 0.50).
// Each shop's colours are its own src/Components/Footer/Footer.css (split).
export default function DesktopFooter() {
  return (
    <div className="wz-site-foot">
      <SiteFooter />
    </div>
  )
}
