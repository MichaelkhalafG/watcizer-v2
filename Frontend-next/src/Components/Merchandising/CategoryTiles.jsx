'use client'
import { memo, useState } from 'react'
import Image from 'next/image'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
import { useTables } from '../../Hooks/queries/useTables'
import { useUIStore } from '../../Store/uiStore'
import { getImageUrl } from '../../utils/imageUrl'
import { subTypeSlug } from '../../utils/slugs'
import { Carousel, CarouselSlide } from '../UI/Carousel'
import { useMenu } from '../../Hooks/queries/usePromo'
import './CategoryTiles.css'

/*
 * A category's picture (the review of 2026-11-05, item 35): its OWN picture; when it has none — or its file does not
 * load — the picture core's menu gives that category (the rule used everywhere: a product photo from it); a letter
 * only when there is neither, which no category with a product in it can reach.
 */
function ChipPicture({ own, fallback, name }) {
  const [ownFailed, setOwnFailed] = useState(false)
  const [fallbackFailed, setFallbackFailed] = useState(false)
  if (own && !ownFailed) {
    // alt="" (Lighthouse: image-redundant-alt): the name is printed under the picture, inside the same button —
    // a screen reader read it twice
    // no `sizes`: a fixed 80 px picture needs a 1x and a 2x file, not next/image's whole ladder in every chip
    return <Image src={own} alt="" width={80} height={80} quality={75} className="wz-cat-img" onError={() => setOwnFailed(true)} />
  }
  if (fallback && !fallbackFailed) {
    // eslint-disable-next-line @next/next/no-img-element -- a small recorded rendition, an absolute asset-host URL
    return <img src={fallback} alt="" width="80" height="80" loading="lazy" decoding="async" className="wz-cat-img wz-cat-img--product" onError={() => setFallbackFailed(true)} />
  }
  return <span className="wz-cat-chip-initial">{name.charAt(0).toUpperCase()}</span>
}

const CategoryTiles = ({ menu: menuFromPage = null }) => {
  // The lookup tables only — not the catalogue (C-1 stage 2).
  const { data: tables, isFetching } = useTables()
  const { language } = useUIStore()
  const router = useRouter()
  // THE MENU COMES FROM THE PAGE'S OWN SERVER RENDER (`menu`, 2026-11-07; before, from the query cache). The header's
  // menu component creates the menu query — empty — before this page's HydrationBoundary could fill it, so the
  // server render of this row never saw the menu (every category without a picture of its own was a letter) while
  // the browser's first render, on a repeat visit, had the sessionStorage copy: a different tree than the HTML,
  // React #418 on the live home. A prop is the same on both sides by construction. The hook stays a reader of the
  // cache for a caller that passes nothing.
  const { data: fetched } = useMenu(!menuFromPage)
  const menu = menuFromPage || fetched
  const fallbackOf = {}
  for (const top of menu?.categories || []) {
    for (const child of top.children || []) {
      if (child.image?.src) fallbackOf[child.slug] = child.image.src
    }
  }

  // A category is in the row when it has a picture to show: its own, or — through the menu — a product of it. One
  // that has neither has no product in this shop either: it was a grey circle with a letter that led to an empty page.
  const subTypes = (tables?.subTypes || []).filter((sub) => sub.image_url || sub.image || !menu || fallbackOf[subTypeSlug(sub)])

  // While the catalog tables are still loading, show placeholder chips so the
  // section fills in progressively instead of popping in. Once loaded, an empty
  // list genuinely means "no categories" → render nothing.
  if (!subTypes.length) {
    if (!isFetching) return null
    return (
      <div className="wz-cats" dir={language === 'ar' ? 'rtl' : 'ltr'}>
        <div className="wz-cats-row wz-cats-skeleton">
          {Array.from({ length: 8 }).map((_, i) => (
            <div className="wz-skel-chip" key={i}>
              <div className="wz-skel-chip-circle" />
              <div className="wz-skel-chip-line" />
            </div>
          ))}
        </div>
      </div>
    )
  }

  // Names live in a translations[] array (current language → English fallback).
  const getName = (sub) => {
    const tr = (loc) => sub.translations?.find((t) => t.locale === loc)?.sub_type_name
    return (language === 'ar' ? tr('ar') || tr('en') : tr('en') || tr('ar')) || ''
  }

  // The sub-type's clean route (2026-10-08) — the same filter (sub type alone) as the
  // /listing?subType= URL it replaced, on the page that is indexable.
  const handleClick = (sub) => {
    router.push(`/subtypes/${subTypeSlug(sub)}`)
  }

  return (
    <div className="wz-cats" dir={language === 'ar' ? 'rtl' : 'ltr'}>
      {/* Header */}
      <div className="wz-cats-header">
        <div className="wz-cats-title-wrap">
          <span className="wz-section-label">{language === 'ar' ? 'تصفح حسب' : 'Shop by'}</span>
          <h2 className="wz-cats-title">{language === 'ar' ? 'الفئة' : 'Category'}</h2>
        </div>
        <button className="wz-section-action" onClick={() => router.push('/listing')}>
          {language === 'ar' ? 'عرض الكل ←' : 'View all →'}
        </button>
      </div>

      {/* Scrollable row (Embla) */}
      <Carousel gap={10} showArrows showDots={false} snap>
        {subTypes.map((sub) => {
          const name = getName(sub)
          // Prefer the absolute image_url the API now appends; fall back to the
          // bare filename resolved with the correct Sub_type folder (the old code
          // used the default Product folder here → every sub-type image 404'd).
          const img = sub.image_url || getImageUrl(sub.image, 'Sub_type')

          return (
            <CarouselSlide key={sub.id} className="wz-cat-slide">
              <button className="wz-cat-chip" onClick={() => handleClick(sub)} title={name}>
                <div className="wz-cat-chip-img">
                  <ChipPicture own={img} fallback={fallbackOf[subTypeSlug(sub)]} name={name} />
                </div>

                <span className="wz-cat-chip-name">{name}</span>
              </button>
            </CarouselSlide>
          )
        })}
      </Carousel>
    </div>
  )
}

export default memo(CategoryTiles)