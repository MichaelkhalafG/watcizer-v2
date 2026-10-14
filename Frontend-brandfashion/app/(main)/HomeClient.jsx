'use client'
import { useMemo, useCallback } from 'react'
import Image from 'next/image'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import WatchHero from '@/src/Components/Hero/WatchHero'
import '@/src/Components/Home/home.css'
import ProductSlider from '@/src/Components/Product/ProductSlider'
import OfferSlider from '@/src/Components/Product/OfferSlider'
import FeaturedBanner from '@/src/Components/Home/FeaturedBanner'
import { useHome, useCardsOf } from '@/src/Hooks/queries/useListing'
import { useTables } from '@/src/Hooks/queries/useTables'
import { useUIStore } from '@/src/Store/uiStore'
import CategoryTiles from '@/src/Components/Merchandising/CategoryTiles'
import { getImageUrl } from '@/src/utils/imageUrl'
import { buildListingParams } from '@/src/utils/listingParams'

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
          const desc = grade ? nameIn(grade, 'description', language) : ''
          description = { en: desc, ar: desc }
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
      .filter((rail) => rail.products.length > 0)
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

  const renderRail = (rail) => {
    if (rail.kind === 'featured') {
      return <FeaturedBanner key={rail.id} products={rail.products} />
    }
    if (rail.kind === 'offers') {
      return (
        <section className="wz-home-section" key={rail.id}>
          <div className="wz-container">
            <OfferSlider text={{ title: rail.text.title, description: rail.text.title }} products={rail.products} />
          </div>
        </section>
      )
    }
    return (
      <section className="wz-home-section" key={rail.id}>
        <div className="wz-container">
          <ProductSlider text={rail.text} gradeproducts={rail.products} moreid={rail.moreid} href={rail.href} viewAll={rail.kind !== 'custom'} />
        </div>
      </section>
    )
  }

  return (
    <div className="wz-home" dir={isRTL ? 'rtl' : 'ltr'}>
      <WatchHero />

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
      {rails.slice(1).map(renderRail)}
    </div>
  )
}
