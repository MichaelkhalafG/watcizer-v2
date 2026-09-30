import Footer from '@/src/Components/Footer/Footer'

// Desktop-only footer — mirrors Frontend App.jsx (`isDesktop ? <Footer/> : null`).
//
// Hidden on phones by CSS (.wz-desktop-footer in Footer.css), no longer by useMediaQuery (2026-09-30):
// that hook is false on the server and on the first browser render, so the footer was never in the
// HTML and popped in only after the page's JavaScript ran — right under the header whenever the
// content had not arrived yet, then shoved down (CLS 0.50 on desktop, measured). Phones still never
// see it; desktops get it in the first paint, and its links are in the HTML crawlers read.
export default function DesktopFooter() {
  return (
    <div className="wz-desktop-footer">
      <Footer />
    </div>
  )
}
