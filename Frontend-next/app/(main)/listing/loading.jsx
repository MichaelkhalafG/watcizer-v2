import './Listing.css'
import '@/src/Components/Listing/SmartSuggestions.css'
import { requestLang } from '@/src/lib/requestLang'

// Shown while /listing's server render waits for core (2026-09-30). It used to be a hard-coded
// DESKTOP grid (a 250 px sidebar and 4 columns, no mobile rules): on a phone it drew two landscape
// boxes cut off at the right edge, and was then replaced by a page of a completely different shape.
// Now it is the listing's own frame — the same containers and classes as ListingClient, with its
// own skeleton cards — so the title, chip row, toolbar and grid appear where they will stay, in the
// shopper's language and direction, on every screen size.
export default async function ListingLoading() {
  const { lang } = await requestLang()
  return (
    <div className="wz-listing" dir={lang === 'ar' ? 'rtl' : 'ltr'} aria-busy="true">
      <div className="wz-listing-inner">
        <nav className="wz-bc" aria-hidden="true">
          <span className="wz-skel-line wz-skel-crumb" />
        </nav>
        <div className="wz-listing-h1" aria-hidden="true">
          <span className="wz-skel-line wz-skel-title" />
        </div>
        <div className="wz-sg" aria-hidden="true">
          <span className="wz-skel-line wz-skel-chips" />
        </div>
        <div className="wz-listing-body">
          <aside className="wz-listing-aside" aria-hidden="true" />
          <div className="wz-listing-main">
            <div className="wz-toolbar" aria-hidden="true">
              <span className="wz-skel-line wz-skel-toolbar" />
            </div>
            <div className="wz-listing-grid">
              {Array.from({ length: 8 }).map((_, i) => (
                <div className="wz-skel-card" key={i}>
                  <div className="wz-skel-img" />
                  <div className="wz-skel-line wz-skel-brand" />
                  <div className="wz-skel-line wz-skel-name" />
                  <div className="wz-skel-line wz-skel-price" />
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
