// THE SHOP'S GOOGLE REVIEW BUTTONS (Michael, 2026-11-17). A TWIN.
//
// One button per branch that has a Google review link (core's `google`: [{key, name: {en, ar}, url}]), shown to
// EVERYONE after a review on the site — whatever the stars, never gated, and no incentive of any kind.
export default function GoogleReviewButtons({ google, lang = 'en' }) {
  if (!Array.isArray(google) || google.length === 0) return null
  const ar = lang === 'ar'
  return (
    <div className="wz-google-reviews">
      <p className="wz-google-reviews__lead">{ar ? 'ويسعدنا أيضًا رأيك على جوجل:' : 'You can also review us on Google:'}</p>
      <div className="wz-google-reviews__list">
        {google.map((g) => (
          <a key={g.url} className="wz-google-reviews__btn" href={g.url} target="_blank" rel="noopener noreferrer">
            {ar ? `فرع ${g.name?.ar || g.name?.en} على جوجل` : `Our ${g.name?.en || g.name?.ar} store on Google`}
          </a>
        ))}
      </div>
    </div>
  )
}
