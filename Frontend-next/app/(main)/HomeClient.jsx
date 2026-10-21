'use client'
import { useMemo, useCallback } from 'react'
import Image from 'next/image'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import WatchHero from '@/src/Components/Hero/WatchHero'
import '@/src/Components/Home/home.css'
import ProductSlider from '@/src/Components/Product/ProductSlider'
import OfferSlider from '@/src/Components/Product/OfferSlider'
import FeaturedBanner from '@/src/Components/Home/FeaturedBanner'
import BannerHero from '@/src/Components/Home/BannerHero'
import { useHome, useCardsOf } from '@/src/Hooks/queries/useListing'
import { useTables } from '@/src/Hooks/queries/useTables'
import { useUIStore } from '@/src/Store/uiStore'
import CategoryTiles from '@/src/Components/Merchandising/CategoryTiles'
import { getImageUrl } from '@/src/utils/imageUrl'
import { buildListingParams } from '@/src/utils/listingParams'

// A home rail is drawn only with at least this many products (item 14, 2026-10-23). Four is one full row of
// cards at desktop width: below it the band is mostly empty under its own heading. See the rails memo.
export const MIN_RAIL_PRODUCTS = 4

// The home page's product rails come from core (C-1 stage 4 slice C, `catalog/home`): the rails the
// dashboard's "Home rails" screen lists, in its order, each with its cards already chosen. The page
// used to download the whole catalogue and derive offers, featured and one rail per grade here.
// The hero, the category tiles and the brand strip stay fixed; the brand strip sits after the first
// rail, where it sat after the offers rail before.

// Module-level handler (stable identity, never recreated): hide a broken brand
// logo and reveal its text fallback.
const onBrandImgError = (e) => {
  e.target.style.display = 'none'
  if (e.target.nextSibling) e.target.nextSibling.style.display = 'block'
}

// Inline styles for the section-level fetch-error + retry affordance (kept inline
// so no shared CSS/App.css file is touched for this change).
const sectionErrorStyle = {
  display: 'flex',
  flexDirection: 'column',
  alignItems: 'center',
  gap: '14px',
  padding: '48px 24px',
  textAlign: 'center',
  color: 'rgba(0,0,0,0.6)',
}
const sectionRetryStyle = {
  padding: '10px 28px',
  background: '#262626',
  color: '#fff',
  border: 'none',
  borderRadius: '4px',
  fontSize: '13px',
  letterSpacing: '0.08em',
  textTransform: 'uppercase',
  cursor: 'pointer',
}

// A table row's name in `language`, English next, then the flat field.
const nameIn = (row, key, language) =>
  row?.translations?.find((t) => t.locale === language)?.[key] ||
  row?.translations?.find((t) => t.locale === 'en')?.[key] ||
  row?.[key] ||
  ''

// The fixed titles of the kinds that have no target to be named after.
const KIND_TITLES = {
  offers: { en: 'Season Offers', ar: 'عروض الموسم' },
  newest: { en: 'New Arrivals', ar: 'وصل حديثًا' },
}

export default function HomeClient({ initialHome = null }) {
  const { data: home, isFetching, isError, refetch } = useHome(initialHome)
  const { data: tables } = useTables()
  const { language } = useUIStore()
  const router = useRouter()
  const isRTL = language === 'ar'

  // Every rail's cards, transformed once in the shopper's language.
  const cards = useCardsOf(home, language)
  const cardById = useMemo(() => new Map(cards.map((c) => [c.id, c])), [cards])

  const loading = !home && isFetching
  const homeError = !home && isError

  /*
   * WHICH HERO THIS SHOP DRAWS (the developer's addendum, 2026-10-20).
   *
   * `home.hero` is the storefront's own setting — `three_d` or `banner` — flipped on the dashboard's
   * Banners screen, not decided by which app is deployed. ANYTHING ELSE, INCLUDING A MISSING VALUE,
   * IS THE 3D HERO: Watchizer is live, this page is its home page, and a payload that arrives without
   * the key (an old cached answer, a core deployed without its migration) must render exactly what it
   * renders today. A shop set to `banner` with no banners falls back to the 3D hero too — an empty
   * hero slot is worse than the one that was already there.
   */
  const banners = home?.banners?.length ? home.banners : home?.banner ? [home.banner] : []
  const bannerHero = home?.hero === 'banner' && banners.length > 0

  // Each rail with its cards, its title and where "View all" goes. A rail whose target is gone from
  // the tables still shows, under its custom title if it has one.
  const rails = useMemo(() => {
    if (!home?.rails) return []
    return home.rails
      .map((rail) => {
        const products = rail.products.map((id) => cardById.get(id)).filter(Boolean)
        const custom = { en: rail.title?.en || '', ar: rail.title?.ar || '' }
        let title = KIND_TITLES[rail.kind] || { en: '', ar: '' }
        let description = { en: '', ar: '' }
        let href
        let moreid
        if (rail.kind === 'grade') {
          const grade = tables?.grades?.find((g) => g.id === rail.target)
          const name = grade ? nameIn(grade, 'grade_name', language) : ''
          title = { en: name, ar: name }
          /*
           * The grade's `description` is NOT passed as the rail's eyebrow any more (item 15a).
           * It was rendered as a tracked-out line above every heading — "HIGH-QUALITY WATCHES WITH
           * EXCELLENT CRAFTSMANSHIP", "PREMIUM LUXURY TIMEPIECES FROM ICONIC MAISONS" — eight of
           * them down one page, saying nearly the same thing. The column is untouched and still
           * editable on the dashboard; it is simply not this page's subheading.
           */
          moreid = rail.target
        } else if (rail.kind === 'brand') {
          const brand = tables?.brands?.find((b) => b.id === rail.target)
          const name = brand ? nameIn(brand, 'brand_name', language) : ''
          title = { en: name, ar: name }
          href = `/listing?${buildListingParams({ brands: [rail.target] }, {}, tables).toString()}`
        } else if (rail.kind === 'category_type') {
          const category = tables?.categoryTypes?.find((c) => c.id === rail.target)
          const name = category ? nameIn(category, 'category_type_name', language) : ''
          title = { en: name, ar: name }
          href = `/listing?${buildListingParams({ categories: [rail.target] }, {}, tables).toString()}`
        } else if (rail.kind === 'newest') {
          href = '/listing?sort=newest'
        }
        return {
          ...rail,
          products,
          text: {
            title: { en: custom.en || title.en, ar: custom.ar || title.ar },
            description,
          },
          href,
          moreid,
        }
      })
      /*
       * A rail needs FOUR products to be drawn (item 14, the owner's threshold, 2026-10-23).
       *
       * The grid is four cards to a row at desktop width, so a rail below four is a full-width band, with a
       * heading and its own vertical weight, that cannot fill one row: the live home page had one with a
       * SINGLE product and about three quarters of the band empty. Two or three is the same defect, smaller,
       * which is why the line is four rather than two.
       *
       * This is the REAL guard, not the dashboard's. A rail's product count moves with stock and visibility
       * without anyone editing it, so the only count that can be trusted is the one at the moment the page is
       * drawn — which is this one. The dashboard warns at save (and lists the count) so the mistake is not
       * made knowingly; it cannot promise the rail stays above the line afterwards.
       */
      /*
       * The minimum applies to rails that render as a ROW OF CARDS. `featured` is exempt because it is not
       * one: it draws as FeaturedBanner, with its own layout and no heading of the kind this rule is about.
       * The owner's reason — "a rail that cannot fill one row of cards should not be a full-width band with
       * a heading" — describes the card rails and not the banner, so the banner keeps its own behaviour.
       */
      .filter((rail) => rail.kind === 'featured' || rail.products.length >= MIN_RAIL_PRODUCTS)
  }, [home, cardById, tables, language])

  const brands = tables?.brands || []
  const brandName = (b) => nameIn(b, 'brand_name', language)

  const handleBrandClick = useCallback(
    (brandId) => {
      router.push(`/listing?${buildListingParams({ brands: [brandId] }, {}, tables).toString()}`)
    },
    [router, tables],
  )

  const brandStrip =
    brands.length > 0 ? (
      <div className="wz-brand-strip">
        <div className="wz-brand-strip-track">
          {[...brands, ...brands].map((b, i) => {
            const img = getImageUrl(b.image, 'Brand')
            const name = brandName(b)
            return (
              <button
                key={`${b.id}-${i}`}
                className="wz-brand-strip-item"
                onClick={() => handleBrandClick(b.id)}
                title={name}
                type="button"
              >
                {img ? (
                  <Image
                    src={img}
                    alt={name}
                    className="wz-brand-strip-img"
                    width={90}
                    height={32}
                    quality={70}
                    sizes="90px"
                    onError={onBrandImgError}
                  />
                ) : null}
                <span className="wz-brand-strip-name" style={{ display: img ? 'none' : 'block' }}>
                  {name}
                </span>
              </button>
            )
          })}
        </div>
      </div>
    ) : null

  // `repeat` is every rail after the first: the opening rail keeps the page's full heading weight
  // and the ones below it step down, which is what gives the column a top-to-bottom taper (item 16).
  const renderRail = (rail, repeat = false) => {
    const section = `wz-home-section${repeat ? ' wz-home-section--repeat' : ''}`
    if (rail.kind === 'featured') {
      return <FeaturedBanner key={rail.id} products={rail.products} />
    }
    if (rail.kind === 'offers') {
      return (
        <section className={section} key={rail.id}>
          <div className="wz-container">
            <OfferSlider text={{ title: rail.text.title, description: rail.text.title }} products={rail.products} />
          </div>
        </section>
      )
    }
    return (
      <section className={section} key={rail.id}>
        <div className="wz-container">
          <ProductSlider text={rail.text} gradeproducts={rail.products} moreid={rail.moreid} href={rail.href} viewAll={rail.kind !== 'custom'} />
        </div>
      </section>
    )
  }

  return (
    <div className="wz-home" dir={isRTL ? 'rtl' : 'ltr'}>
      {bannerHero ? (
        <section className="wz-home-section">
          <div className="wz-container">
            <BannerHero banners={banners} lang={language} dir={isRTL ? 'rtl' : 'ltr'} />
          </div>
        </section>
      ) : (
        <WatchHero />
      )}

      <section className="wz-home-section">
        <div className="wz-container">
          <CategoryTiles />
        </div>
      </section>

      {/* The rails failed to load → inline error + retry (not a blank page). */}
      {homeError && (
        <section className="wz-home-section">
          <div className="wz-container">
            <div style={sectionErrorStyle} role="alert">
              <p style={{ margin: 0 }}>
                {isRTL
                  ? 'تعذّر تحميل المنتجات. تحقّق من اتصالك وحاول مرة أخرى.'
                  : "Couldn't load products. Check your connection and try again."}
              </p>
              <button type="button" style={sectionRetryStyle} onClick={refetch}>
                {isRTL ? 'إعادة المحاولة' : 'Retry'}
              </button>
            </div>
          </div>
        </section>
      )}

      {loading &&
        [0, 1].map((i) => (
          <section className="wz-home-section" key={`rail-skel-${i}`}>
            <div className="wz-container">
              <ProductSlider loading />
            </div>
          </section>
        ))}

      {rails.length > 0 && renderRail(rails[0])}
      {brandStrip}
      {rails.slice(1).map((rail) => renderRail(rail, true))}
    </div>
  )
}
