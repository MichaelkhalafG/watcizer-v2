'use client'
import { useMemo, useCallback } from 'react'
import dynamic from 'next/dynamic'
import Image from 'next/image'
import { useRouter } from '@/src/Hooks/useLocaleRouter'
// THE HERO ZONE HAS THREE MODES since 2026-11-05 (the twelve updates, Part 3.1), chosen on the dashboard's Banners
// screen: `campaign` — the Hugo Boss Grand Prix 1513477 banner (WATCHIZER ONLY, which is why this file DIVERGES from
// its Brand Fashion twin; Frontend-brandfashion/TWINS.txt records it) — `three_d`, the original 3D watch, and
// `banner`, the large hero with two side slots. Until that day the campaign banner replaced the 3D hero IN CODE and
// ending the campaign meant editing this file; now it is the dashboard's switch, and both heroes are here.
import GrandPrixBanner from '@/src/Components/Home/GrandPrixBanner'
import '@/src/Components/Home/home.css'
import ProductSlider from '@/src/Components/Product/ProductSlider'
import TrustSignals from '@/src/Components/Merchandising/TrustSignals'
import OfferSlider from '@/src/Components/Product/OfferSlider'
import HeroZone from '@/src/Components/Home/HeroZone'
import BrandBanner, { onColor } from '@/src/Components/Home/BrandBanner'
import CampaignRail from '@/src/Components/Promo/CampaignRail'
import { useHotDealsMode, useLiveCampaign } from '@/src/Hooks/queries/usePromo'
import { money } from '@/src/lib/campaign'
import { brandSlug } from '@/src/utils/slugs'
import { useHome, useCardsOf } from '@/src/Hooks/queries/useListing'
import { useTables } from '@/src/Hooks/queries/useTables'
import { useUIStore } from '@/src/Store/uiStore'
import CategoryTiles from '@/src/Components/Merchandising/CategoryTiles'
import { getImageUrl } from '@/src/utils/imageUrl'
import { productUrl } from '@/src/utils/productUrl'
import { buildListingParams } from '@/src/utils/listingParams'

// The 3D hero is drawn only in `three_d` mode, so its code (three.js) is not in the home page's first bundle while
// the shop is on the campaign banner.
const WatchHero = dynamic(() => import('@/src/Components/Hero/WatchHero'))
// Hot Deals mode replaces the home with the campaign's page; loaded only when the switch is on.
const CampaignPage = dynamic(() => import('@/src/Components/Promo/CampaignPage'))

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
  best_value: { en: 'Best Value', ar: 'أفضل قيمة' },
}

// The kinds that are a SECTION of the page rather than a slider of products (core: HomeRails::SECTIONS).
const SECTION_KINDS = ['banners', 'hot_deals', 'flash_sale', 'categories', 'brands']

export default function HomeClient({ initialHome = null, campaign = null }) {
  const { data: home, isFetching, isError, refetch } = useHome(initialHome)
  const { data: tables } = useTables()
  const { language } = useUIStore()
  const router = useRouter()
  const isRTL = language === 'ar'
  const hotDeals = useHotDealsMode()
  const flashSale = useLiveCampaign('flash_sale')

  // Every rail's cards, transformed once in the shopper's language.
  const cards = useCardsOf(home, language)
  const cardById = useMemo(() => new Map(cards.map((c) => [c.id, c])), [cards])

  const loading = !home && isFetching
  const homeError = !home && isError

  /*
   * WHICH HERO THIS SHOP DRAWS (the developer's addendum, 2026-10-20).
   *
   * `home.hero` is the storefront's own setting — `campaign`, `three_d` or `banner` (Part 3.1, 2026-11-05) —
   * flipped on the dashboard's Banners screen, not decided by which app is deployed. ANYTHING ELSE, INCLUDING
   * A MISSING VALUE, IS THE CAMPAIGN BANNER: Watchizer is live, this page is its home page, and a payload that
   * arrives without the key (an old cached answer, a core deployed without its migration) must render exactly
   * what it renders today — and today that is the campaign banner. A shop set to `banner` with no banners
   * falls back to it too — an empty hero slot is worse than the one that was already there.
   */
  const banners = home?.banners?.length ? home.banners : home?.banner ? [home.banner] : []
  const bannerHero = home?.hero === 'banner' && banners.length > 0
  const threeD = home?.hero === 'three_d'
  // the two side slots of the hero zone: [top, bottom], each a banner or null; both null = the hero alone
  const sideBanners = Array.isArray(home?.side_banners) ? home.side_banners : []
  // does the Banners block have anything to draw (a slide of the large banner, or a filled side slot)?
  const blockBanners = banners.length > 0
  /*
   * EVERY HOME SECTION IS A ROW OF THE DASHBOARD (the developer's review, 2026-11-05). `managed_sections` names the
   * sections the Home sliders screen has a row for, on or off: such a section is drawn ONLY where its row is (it
   * arrives as a rail of its kind, in the rails' order) and not at all when the row is off. A section with no row
   * keeps the fixed place it has always had in this file, so nothing moves until the team adds the row.
   */
  const managed = useMemo(() => new Set(home?.managed_sections || []), [home])

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
        } else if (rail.kind === 'best_value') {
          // the biggest real discounts in stock: "view all" opens every discounted product
          href = `/listing?${buildListingParams({ offers: true }, {}, tables).toString()}`
        } else if (rail.kind === 'price') {
          // under a price: the rail's `target` is its cap
          const cap = Number(rail.target) || 0
          title = { en: `Under ${money(cap, 'en')}`, ar: `أقل من ${money(cap, 'ar')}` }
          href = `/listing?${buildListingParams({ price: [0, cap] }, {}, tables).toString()}`
        }
        // THE BRAND BANNER (Part 3.3): the brand's logo and page, the team's title, description and three
        // colours, and up to three of the brand's products — as the plain model BrandBanner draws.
        let banner = null
        if (rail.kind === 'brand_banner') {
          const brand = tables?.brands?.find((b) => b.id === rail.target)
          if (brand) {
            banner = {
              href: `/brand/${brandSlug(brand)}`,
              brand: nameIn(brand, 'brand_name', 'en'),
              logo: getImageUrl(brand.image, 'Brand'),
              title: custom[language] || custom.en,
              description: rail.description?.[language] || rail.description?.en || '',
              colors: rail.colors || [],
              products: products.slice(0, 3).map((p) => {
                // the shop's one price rule: a sale counts only when 0 < sale < selling
                const selling = Number(p.selling_price) || 0
                const sale = Number(p.sale_price_after_discount) || 0
                const onSale = sale > 0 && sale < selling
                return {
                  id: p.id,
                  // each card of the banner opens ITS OWN product
                  href: productUrl(p),
                  image: p.image,
                  title: p.product_title || p.name,
                  price: money(onSale ? sale : selling, language),
                  was: onSale ? money(selling, language) : null,
                  // the product's real discount, rounded as its card prints it
                  off: onSale ? Math.round(((selling - sale) / selling) * 100) : null,
                }
              }),
            }
          }
        }
        return {
          ...rail,
          banner,
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
      // The brand banner is exempt for the same reason (Part 3.3): it is a banner with three products by
      // design, not a row of cards — it needs its brand, nothing else.
      // THE BANNERS BLOCK AS A SECTION (the design pass): core sends its row at its place among the sliders only
      // while a hero leads the page; it is drawn there when the shop has a banner to show.
      .filter((rail) => {
        // THE ROTATING "FEATURED" BANNER IS GONE (the review of 2026-11-05): a `featured` row is not drawn — the
        // Hot Deals section took its place. The Hugo Boss Grand Prix hero above is untouched.
        if (rail.kind === 'featured') return false
        if (rail.kind === 'banners') return blockBanners
        // a campaign's section exists only while its campaign is live
        if (rail.kind === 'hot_deals') return !!hotDeals.campaign
        if (rail.kind === 'flash_sale') return !!flashSale.campaign
        if (rail.kind === 'categories') return true
        if (rail.kind === 'brands') return (tables?.brands || []).length > 0
        if (rail.kind === 'brand_banner') return !!rail.banner
        return rail.products.length >= MIN_RAIL_PRODUCTS
      })
  }, [home, cardById, tables, language, blockBanners, hotDeals.campaign, flashSale.campaign])

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
                  /* NO `sizes` (the performance pass, 2026-11-05): with it next/image writes every width it knows into
                     each logo's srcset — thirteen URLs a logo, 160 logos (the strip is drawn twice for the loop): 303 KB
                     of this page's 619 KB of markup. A fixed 90 x 32 logo needs two: 1x and 2x. The same two files are
                     chosen as before (96 px and 256 px). */
                  <Image
                    src={img}
                    alt={name}
                    className="wz-brand-strip-img"
                    width={90}
                    height={32}
                    quality={70}
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
  /*
   * A SLIDER'S OWN LOOK (Part 3.2, 2026-11-05): a background colour for its section and, separately, the
   * blend that dissolves the white studio background of its product photos into that colour. Both are per
   * slider and come from the dashboard's Home sliders screen. A slider with neither is drawn EXACTLY as
   * before — no wrapper at all.
   */
  // ONLY the section's ground and, with the blend, the photo boxes: `--hl-ink` (ink or white, whichever reads on
  // the colour) is for the slider's own heading, which stands on the section — a card keeps everything of its own.
  const look = (rail, children) =>
    rail.background ? (
      <div className={`hl${rail.blend ? ' hl--blend' : ''}`} style={{ '--hl-ground': rail.background, '--hl-ink': onColor(rail.background) }}>
        {children}
      </div>
    ) : (
      children
    )

  // THE BLEND NEVER TINTS THE PRODUCT (2026-11-05): with the blend on, core sends the mask of each product's studio
  // background (`rail.masks`, product id => a data: URL); each product goes to its card with its own. A product
  // that has none is drawn as it is.
  const cut = (rail) => (rail.blend && rail.masks ? rail.products.map((p) => (rail.masks[p.id] ? { ...p, cut_mask: rail.masks[p.id] } : p)) : rail.products)

  const renderRail = (rail, repeat = false) => {
    const section = `wz-home-section${repeat ? ' wz-home-section--repeat' : ''}`
    if (rail.kind === 'hot_deals' || rail.kind === 'flash_sale') {
      return (
        <section className={`${section} wz-home-section--bleed`} key={rail.id}>
          <CampaignRail presentation={rail.kind} lang={language} bleed />
        </section>
      )
    }
    if (rail.kind === 'categories') {
      return (
        <section className={section} key={rail.id}>
          <div className="wz-container">
            <CategoryTiles />
          </div>
        </section>
      )
    }
    if (rail.kind === 'brands') {
      return <div key={rail.id}>{brandStrip}</div>
    }
    if (rail.kind === 'banners') {
      return (
        <section className={section} key={rail.id}>
          <div className="wz-container">
            <HeroZone banners={banners} side={sideBanners} lang={language} dir={isRTL ? 'rtl' : 'ltr'} />
          </div>
        </section>
      )
    }
    if (rail.kind === 'brand_banner') {
      return (
        <section className={`${section} wz-home-section--bleed`} key={rail.id}>
          <BrandBanner model={rail.banner} lang={language} dir={isRTL ? 'rtl' : 'ltr'} bleed />
        </section>
      )
    }
    // A SLIDER WITH ITS OWN COLOUR IS A FULL-WIDTH BAND (the review of 2026-11-05): the colour runs edge to edge and
    // the cards stay on the page's normal column inside it. Only these — every other slider keeps the normal width.
    const band = rail.background ? { className: `${section} wz-home-section--band`, style: { '--hl-ground': rail.background } } : { className: section }
    if (rail.kind === 'offers') {
      return (
        <section {...band} key={rail.id}>
          <div className="wz-container">
            {look(rail, <OfferSlider text={{ title: rail.text.title, description: rail.text.title }} products={cut(rail)} />)}
          </div>
        </section>
      )
    }
    return (
      <section {...band} key={rail.id}>
        <div className="wz-container">
          {look(
            rail,
            <ProductSlider text={rail.text} gradeproducts={cut(rail)} moreid={rail.moreid} href={rail.href} viewAll={rail.kind !== 'custom'} />,
          )}
        </div>
      </section>
    )
  }

  // HOT DEALS MODE (Part 2.4): the switch in the header is on, so the shop shows only the live Hot Deals
  // campaign — and its home IS that campaign's page. Off (or the campaign over), the home below returns.
  if (hotDeals.on) return <CampaignPage presentation="hot_deals" />

  return (
    <div className="wz-home" dir={isRTL ? 'rtl' : 'ltr'}>
      {bannerHero ? (
        <section className="wz-home-section">
          <div className="wz-container">
            <HeroZone banners={banners} side={sideBanners} lang={language} dir={isRTL ? 'rtl' : 'ltr'} />
          </div>
        </section>
      ) : (
        <>
          {/*
           * THE HERO LEADS; THE BANNERS BLOCK IS A SECTION (the design pass, 2026-11-05). From 2026-10-27 the
           * banners were drawn straight under the hero, always. They are now a home section whose place the
           * dashboard sets among the sliders (the "Banners block" row of the Home sliders screen): core sends that
           * row as a rail of kind `banners`, and it is drawn there, below. With the hero set to `banner` — no
           * hero — the block is the first thing on the page, above, automatically.
           */}
          {threeD ? <WatchHero /> : <GrandPrixBanner data={campaign} />}
        </>
      )}

      {/*
       * THE LIVE CAMPAIGNS (Part 2.2, 2026-11-05): a Flash Sale and a Hot Deals slider, each in its own design,
       * each drawn only while a campaign of its kind is live and gone at its end — section and all, so a day
       * without a campaign leaves no gap here.
       */}
      {!managed.has('flash_sale') && flashSale.campaign ? (
        <section className="wz-home-section wz-home-section--bleed">
          <CampaignRail presentation="flash_sale" lang={language} bleed />
        </section>
      ) : null}
      {!managed.has('hot_deals') && hotDeals.campaign ? (
        <section className="wz-home-section wz-home-section--bleed">
          <CampaignRail presentation="hot_deals" lang={language} bleed />
        </section>
      ) : null}

      {managed.has('categories') ? null : (
        <section className="wz-home-section">
          <div className="wz-container">
            <CategoryTiles />
          </div>
        </section>
      )}

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

      {rails.length > 0 && renderRail(rails[0], SECTION_KINDS.includes(rails[0].kind))}
      {managed.has('brands') ? null : brandStrip}
      {rails.slice(1).map((rail) => renderRail(rail, true))}

      {/* THE TRUST STRIP CLOSES THE PAGE (the developer's order for this home, 2026-11-05): the component and the
          wording the cart already shows — nothing new. It is the page's last line, not a row of the sliders screen. */}
      {rails.length > 0 ? (
        <section className="wz-home-section wz-home-section--trust">
          <div className="wz-container">
            <TrustSignals variant="cart" isRTL={language === 'ar'} />
          </div>
        </section>
      ) : null}
    </div>
  )
}
