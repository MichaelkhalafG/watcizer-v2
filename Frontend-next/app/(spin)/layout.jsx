// THE WHEEL'S OWN SHELL (2026-11-08, WATCHIZER ONLY): /spin and /ar/spin render WITHOUT the shop's chrome — no header,
// no navigation, no top strip, no footer, no floating corner (the FloatingHost lives in the (main) layout, so nothing
// floats here either; src/lib/floating.js hides the wheel's own button on /spin for the pages that do have the corner).
// The page carries the brand itself: the Watchizer mark and one small "Home" pill, the pattern of the experience page.
// Server component; the root layout already gives the language, the fonts, the UI store, the toast and the pixels.
export default function SpinLayout({ children }) {
  return (
    <main id="main-content" tabIndex={-1}>
      {children}
    </main>
  )
}
