import { useState, useRef, useContext, useMemo, useLayoutEffect } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { MdOutlineKeyboardArrowDown, MdOutlineDiamond } from 'react-icons/md'
// Watch / fashion / category icons (verified against react-icons@5.5.0)
import {
  GiPocketWatch,
  GiStopwatch,
  GiRunningShoe,
  GiDivingDagger,
  GiAirplane,
  GiGears,
  GiCrown,
  GiDiamondRing,
  GiBilledCap, // substitute for GiCapBack
  GiBelt,
  GiWallet,
  GiHandBag,
  GiPerfumeBottle, // substitute for PiPerfumeFill
  GiSunglasses,
  GiNecklace,
  GiGemChain, // substitute for GiBracelet
  GiClothes, // substitute for GiScarf
  GiKey,
} from 'react-icons/gi'
import { BsSmartwatch, BsWatch } from 'react-icons/bs'
import { TbShirt, TbShoppingBag, TbCategory, TbBuildingStore } from 'react-icons/tb'
import { RiPercentLine } from 'react-icons/ri'
import { MyContext } from '../../../Context/Context'
import { useUIStore } from '../../../Store/uiStore'
import { getImageUrl } from '../../../utils/imageUrl'
import { buildListingParams } from '../../../utils/listingParams'

// Map a sub-type name (English key) to a React Icon. Falls back to a generic
// category icon. Exact match first, then partial (substring) match.
const getSubTypeIcon = (name, size = 15) => {
  if (!name) return <TbCategory size={size} />
  const key = name.toLowerCase().trim()

  const iconMap = {
    // Watches
    chronograph: <GiStopwatch size={size} />,
    chrono: <GiStopwatch size={size} />,
    classic: <BsWatch size={size} />,
    sport: <GiRunningShoe size={size} />,
    sports: <GiRunningShoe size={size} />,
    dress: <BsWatch size={size} />,
    diver: <GiDivingDagger size={size} />,
    diving: <GiDivingDagger size={size} />,
    pilot: <GiAirplane size={size} />,
    aviation: <GiAirplane size={size} />,
    automatic: <GiGears size={size} />,
    mechanical: <GiGears size={size} />,
    smart: <BsSmartwatch size={size} />,
    digital: <BsSmartwatch size={size} />,
    limited: <GiCrown size={size} />,
    luxury: <GiCrown size={size} />,
    pocket: <GiPocketWatch size={size} />,

    // Fashion
    caps: <GiBilledCap size={size} />,
    cap: <GiBilledCap size={size} />,
    hat: <GiBilledCap size={size} />,
    belts: <GiBelt size={size} />,
    belt: <GiBelt size={size} />,
    wallets: <GiWallet size={size} />,
    wallet: <GiWallet size={size} />,
    bags: <TbShoppingBag size={size} />,
    bag: <TbShoppingBag size={size} />,
    handbag: <GiHandBag size={size} />,
    perfumes: <GiPerfumeBottle size={size} />,
    perfume: <GiPerfumeBottle size={size} />,
    fragrance: <GiPerfumeBottle size={size} />,
    sunglasses: <GiSunglasses size={size} />,
    glasses: <GiSunglasses size={size} />,
    jewelry: <GiDiamondRing size={size} />,
    jewellery: <GiDiamondRing size={size} />,
    diamond: <MdOutlineDiamond size={size} />,
    bracelets: <GiGemChain size={size} />,
    bracelet: <GiGemChain size={size} />,
    scarves: <GiClothes size={size} />,
    scarf: <GiClothes size={size} />,
    keychains: <GiKey size={size} />,
    keychain: <GiKey size={size} />,
    accessories: <TbShirt size={size} />,
    necklace: <GiNecklace size={size} />,
  }

  if (iconMap[key]) return iconMap[key]
  for (const [k, icon] of Object.entries(iconMap)) {
    if (key.includes(k)) return icon
  }
  return <TbCategory size={size} />
}

const getCategoryIcon = (name, size = 16) => {
  if (!name) return <TbCategory size={size} />
  const key = name.toLowerCase()
  if (key.includes('watch')) return <BsWatch size={size} />
  if (key.includes('fashion')) return <TbShirt size={size} />
  if (key.includes('sport')) return <GiRunningShoe size={size} />
  if (key.includes('luxury')) return <GiCrown size={size} />
  return <TbCategory size={size} />
}

function Nav() {
  const { products, tables } = useContext(MyContext)
  const { language, setCurrentPage } = useUIStore()
  const location = useLocation()
  const navigate = useNavigate()

  // Which top-level dropdown is open, and which brand row's nested menu is open.
  const [openKey, setOpenKey] = useState(null)
  const [openBrand, setOpenBrand] = useState(null)
  const closeTimer = useRef(null)
  const brandTimer = useRef(null)
  // Refs to the currently-open first-level dropdown and nested flyout (only one
  // of each is mounted at a time, so a single ref each is enough).
  const dropRef = useRef(null)
  const nestedRef = useRef(null)

  const isRTL = language === 'ar'
  const categoryTypes = tables?.categoryTypes || []
  const allSubTypes = tables?.subTypes || []
  const allBrands = tables?.brands || []
  const list = products || []

  // Translated label: current language → English fallback → flat field.
  const label = (item, key) =>
    item?.translations?.find((t) => t.locale === language)?.[key] ??
    item?.translations?.find((t) => t.locale === 'en')?.[key] ??
    item?.[key] ??
    ''
  // Stable English name (kept for reference / accessibility).
  const enName = (item, key) => item?.translations?.find((t) => t.locale === 'en')?.[key] ?? ''

  // Brand logo URL (full URL as-is, or relative filename → asset base + Brand folder).
  const brandLogo = (b) => getImageUrl(b?.image, 'Brand')

  // Sub-types that actually have products inside a given category type
  // (derived from the product set — no assumption about sub_type schema).
  const subTypesFor = (ct) =>
    allSubTypes.filter((st) =>
      list.some((p) => p.category_type_id === ct.id && p.sub_type_id === st.id),
    )
  // Brands that have at least one product (avoids dead filter links).
  const brands = allBrands.filter((b) => list.some((p) => p.brand_id === b.id))

  // Brands grouped by the category type(s) their products live in (products-based,
  // derived from the live product set — a brand can appear under multiple category
  // types if it has products in several). Empty groups are dropped.
  const brandsByCategory = useMemo(() => {
    const brandCategoryMap = {}
    ;(products || []).forEach((p) => {
      if (p.brand_id && p.category_type_id) {
        ;(brandCategoryMap[p.brand_id] ||= new Set()).add(p.category_type_id)
      }
    })
    const cats = tables?.categoryTypes || []
    const withProducts = (tables?.brands || []).filter((b) =>
      (products || []).some((p) => p.brand_id === b.id),
    )
    return cats
      .map((cat) => ({
        category: cat,
        brands: withProducts.filter((b) => brandCategoryMap[b.id]?.has(cat.id)),
      }))
      .filter((g) => g.brands.length > 0)
  }, [products, tables])

  // Genders are not in `tables`, so derive them from the product set. Each entry
  // keeps the stable English name (`en`, used as the filter value) alongside the
  // localized `label` (used for display) — indexes of genders_en / gender align.
  const genderMap = new Map()
  list.forEach((p) => {
    ;(p.genders_en || []).forEach((en, i) => {
      if (en && !genderMap.has(en)) genderMap.set(en, (p.gender || [])[i] || en)
    })
  })
  const genders = [...genderMap.entries()].map(([en, lbl]) => ({ en, label: lbl }))

  // ── hover open/close with a 300ms grace delay (time to reach the dropdown) ──
  const openMenu = (key) => {
    if (closeTimer.current) clearTimeout(closeTimer.current)
    setOpenKey(key)
  }
  const scheduleClose = () => {
    if (closeTimer.current) clearTimeout(closeTimer.current)
    closeTimer.current = setTimeout(() => {
      setOpenKey(null)
      setOpenBrand(null)
    }, 300)
  }
  // Nested brand→gender menu uses its OWN timer so it doesn't fight the parent.
  const openBrandMenu = (id) => {
    if (brandTimer.current) clearTimeout(brandTimer.current)
    setOpenBrand(id)
  }
  const scheduleBrandClose = () => {
    if (brandTimer.current) clearTimeout(brandTimer.current)
    brandTimer.current = setTimeout(() => setOpenBrand(null), 300)
  }

  // Flip the open first-level dropdown to its trigger's opposite inline edge if
  // it would run off the right (or left, in RTL) edge of the viewport.
  useLayoutEffect(() => {
    const el = dropRef.current
    if (!openKey || !el) return
    el.style.insetInlineStart = ''
    el.style.insetInlineEnd = ''
    const rect = el.getBoundingClientRect()
    const vw = window.innerWidth
    if (rect.right > vw - 16 || rect.left < 16) {
      el.style.insetInlineStart = 'auto'
      el.style.insetInlineEnd = '0'
    }
  }, [openKey])

  // Position the nested brand→gender flyout as fixed (so it escapes the scrollable
  // brands dropdown) and flip it to whichever side has room.
  useLayoutEffect(() => {
    const el = nestedRef.current
    if (!openBrand || !el) return
    const row = el.parentElement
    if (!row) return
    const r = row.getBoundingClientRect()
    const vw = window.innerWidth
    const vh = window.innerHeight
    const w = el.offsetWidth || 220
    const fitsRight = r.right + w <= vw - 16
    const fitsLeft = r.left - w >= 16
    const openRight = isRTL ? !fitsLeft && fitsRight : fitsRight || !fitsLeft
    el.style.top = Math.max(8, r.top - 8) + 'px'
    el.style.maxHeight = Math.min(vh - r.top - 16, 360) + 'px'
    if (openRight) {
      el.style.left = r.right + 'px'
      el.style.right = 'auto'
      el.style.boxShadow = '8px 8px 32px rgba(0, 0, 0, 0.14)'
    } else {
      el.style.left = r.left - w + 'px'
      el.style.right = 'auto'
      el.style.boxShadow = '-8px 8px 32px rgba(0, 0, 0, 0.14)'
    }
  }, [openBrand, isRTL])

  // ── navigation: encode the selected filters into the /listing URL (the
  // Listing page reads them back from the URL — shareable & refresh-safe). ──
  const go = (newFilters) => {
    if (closeTimer.current) clearTimeout(closeTimer.current)
    if (brandTimer.current) clearTimeout(brandTimer.current)
    const params = buildListingParams(
      {
        categories: [],
        brands: [],
        subTypes: [],
        genders: [],
        offers: false,
        price: [0, 99999999],
        ...newFilters,
      },
      {},
      tables,
    )
    setOpenKey(null)
    setOpenBrand(null)
    const qs = params.toString()
    navigate(qs ? `/listing?${qs}` : '/listing')
  }

  const goHome = () => {
    setCurrentPage(1)
    setOpenKey(null)
    setOpenBrand(null)
  }
  const selectCategory = (ct) => go({ categories: [ct.id] })
  const selectCatSub = (ct, st) => go({ categories: [ct.id], subTypes: [st.id] })
  const selectGender = (g) => go({ genders: [g.en] })
  const selectGenderBrand = (g, b) => go({ genders: [g.en], brands: [b.id] })
  const selectBrand = (b) => go({ brands: [b.id] })
  const selectBrandGender = (b, g) => go({ brands: [b.id], genders: [g.en] })
  const selectOffers = () => go({ offers: true })

  const linkClass = (path) => `nav-link-item${location.pathname === path ? ' active' : ''}`

  // Fixed 36×36 logo box: every brand gets the same footprint so text labels
  // start at one x-position. Falls back to 2-letter initials when no image.
  const brandLogoBox = (b) => {
    const logo = brandLogo(b)
    const initials = (enName(b, 'brand_name') || label(b, 'brand_name') || '?')
      .substring(0, 2)
      .toUpperCase()
    return (
      <span className="wz-brand-logo-box">
        {logo ? (
          <img
            src={logo}
            alt={enName(b, 'brand_name')}
            loading="lazy"
            onError={(e) => {
              e.target.onerror = null
              e.target.style.display = 'none'
              e.target.parentElement.innerHTML =
                '<span class="wz-brand-logo-initials">' + initials + '</span>'
            }}
          />
        ) : (
          <span className="wz-brand-logo-initials">{initials}</span>
        )}
      </span>
    )
  }

  // Brand name + (optional) logo cell — logo box + flex-grow text label.
  const brandCell = (b, text) => (
    <>
      {brandLogoBox(b)}
      <span className="wz-brand-text">{text}</span>
    </>
  )

  // "Rolex for Men" with the gender part dimmed and kept inline.
  const forGenderNode = (brandName, g) => (
    <>
      {brandName}
      <span style={{ opacity: 0.45 }}>{isRTL ? ` ${g.label}` : ` for ${g.label}`}</span>
    </>
  )

  return (
    <nav className="Nav">
      <div className="nav-inner">
        <ul className="nav-ul">
          {/* 1 — HOME */}
          <li className="nav-item">
            <Link to="/" className={linkClass('/')} onClick={goHome}>
              {isRTL ? 'الرئيسية' : 'Home'}
            </Link>
          </li>

          {/* 2 — SHOP (all category types + their sub-types, grouped) */}
          <li
            className="nav-item"
            onMouseEnter={() => openMenu('shop')}
            onMouseLeave={scheduleClose}
          >
            <button type="button" className="nav-link-item">
              {isRTL ? 'المتجر' : 'Shop'}
              <MdOutlineKeyboardArrowDown
                style={{
                  fontSize: '15px',
                  transition: 'transform 0.2s ease',
                  transform: openKey === 'shop' ? 'rotate(180deg)' : 'rotate(0deg)',
                }}
              />
            </button>
            {openKey === 'shop' && categoryTypes.length > 0 && (
              <div
                ref={dropRef}
                className="wz-shop-dropdown wz-dropdown-scrollable"
                onMouseEnter={() => openMenu('shop')}
              >
                {categoryTypes.map((ct, idx) => {
                  const subs = subTypesFor(ct)
                  return (
                    <div key={`shop-${ct.id}`}>
                      <button
                        type="button"
                        className="wz-shop-cat-header"
                        onClick={() => selectCategory(ct)}
                      >
                        <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <span
                            style={{ color: '#262626', opacity: 0.7, display: 'flex', alignItems: 'center' }}
                          >
                            {getCategoryIcon(
                              enName(ct, 'category_type_name') || label(ct, 'category_type_name'),
                              16,
                            )}
                          </span>
                          <span>{label(ct, 'category_type_name')}</span>
                        </span>
                        <span className="wz-shop-cat-arrow">{isRTL ? '‹' : '›'}</span>
                      </button>
                      {subs.length > 0 && <div className="wz-shop-cat-divider" />}
                      {subs.slice(0, 8).map((st) => (
                        <button
                          key={`shop-st-${st.id}`}
                          type="button"
                          className="wz-shop-sub-item"
                          onClick={() => selectCatSub(ct, st)}
                        >
                          <span
                            style={{
                              width: 22,
                              height: 22,
                              display: 'inline-flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              flexShrink: 0,
                              marginInlineEnd: 10,
                              color: '#262626',
                              opacity: 0.5,
                            }}
                          >
                            {getSubTypeIcon(enName(st, 'sub_type_name') || label(st, 'sub_type_name'), 14)}
                          </span>
                          <span>{label(st, 'sub_type_name')}</span>
                        </button>
                      ))}
                      {subs.length > 8 && (
                        <button
                          type="button"
                          className="wz-shop-sub-item wz-shop-see-all"
                          onClick={() => selectCategory(ct)}
                        >
                          {isRTL ? `عرض الكل (${subs.length})` : `See all (${subs.length})`}
                        </button>
                      )}
                      {idx < categoryTypes.length - 1 && <div className="wz-shop-section-sep" />}
                    </div>
                  )
                })}
              </div>
            )}
          </li>

          {/* 3 — CATEGORY TYPES (with sub-type dropdown) */}
          {categoryTypes.map((ct) => {
            const subs = subTypesFor(ct)
            return (
              <li
                key={`ct-${ct.id}`}
                className="nav-item"
                onMouseEnter={() => openMenu(`ct-${ct.id}`)}
                onMouseLeave={scheduleClose}
              >
                <button type="button" className="nav-link-item" onClick={() => selectCategory(ct)}>
                  {label(ct, 'category_type_name')}
                  {subs.length > 0 && <MdOutlineKeyboardArrowDown style={{ fontSize: '15px' }} />}
                </button>
                {subs.length > 0 && openKey === `ct-${ct.id}` && (
                  <div
                    ref={dropRef}
                    className="nav-dropdown"
                    onMouseEnter={() => openMenu(`ct-${ct.id}`)}
                  >
                    {subs.map((st) => (
                      <button
                        key={`st-${st.id}`}
                        type="button"
                        className="nav-dropdown-item"
                        onClick={() => selectCatSub(ct, st)}
                      >
                        {label(st, 'sub_type_name')}
                      </button>
                    ))}
                  </div>
                )}
              </li>
            )
          })}

          {/* 3 — GENDERS (with brand dropdown → "Rolex for Men") */}
          {genders.map((g) => (
            <li
              key={`g-${g.en}`}
              className="nav-item"
              onMouseEnter={() => openMenu(`g-${g.en}`)}
              onMouseLeave={scheduleClose}
            >
              <button type="button" className="nav-link-item" onClick={() => selectGender(g)}>
                {g.label}
                {brands.length > 0 && <MdOutlineKeyboardArrowDown style={{ fontSize: '15px' }} />}
              </button>
              {brands.length > 0 && openKey === `g-${g.en}` && (
                <div
                  ref={dropRef}
                  className="nav-dropdown wz-dropdown-scrollable"
                  onMouseEnter={() => openMenu(`g-${g.en}`)}
                >
                  {brands.map((b) => (
                    <button
                      key={`gb-${b.id}`}
                      type="button"
                      className="wz-brand-item"
                      onClick={() => selectGenderBrand(g, b)}
                    >
                      {brandCell(b, forGenderNode(label(b, 'brand_name'), g))}
                    </button>
                  ))}
                </div>
              )}
            </li>
          ))}

          {/* 4 — BRANDS (with nested brand → gender dropdown) */}
          <li
            className="nav-item"
            onMouseEnter={() => openMenu('brands')}
            onMouseLeave={scheduleClose}
          >
            <button type="button" className="nav-link-item">
              <TbBuildingStore size={14} style={{ opacity: 0.7 }} />
              {isRTL ? 'العلامات التجارية' : 'Brands'}
              <MdOutlineKeyboardArrowDown style={{ fontSize: '15px' }} />
            </button>
            {openKey === 'brands' && brandsByCategory.length > 0 && (
              <div
                ref={dropRef}
                className="nav-dropdown nav-dropdown-brands wz-dropdown-scrollable"
                onMouseEnter={() => openMenu('brands')}
              >
                {brandsByCategory.map(({ category, brands: catBrands }) => (
                  <div key={`bcat-${category.id}`}>
                    {/* Category section header (⌚ Watch Brands / 👗 Fashion Brands) */}
                    <div className="nav-brand-group-header">
                      <span className="nav-brand-group-icon">
                        {getCategoryIcon(
                          enName(category, 'category_type_name') ||
                            label(category, 'category_type_name'),
                          13,
                        )}
                      </span>
                      <span>{label(category, 'category_type_name')}</span>
                    </div>
                    {catBrands.map((b) => {
                      const bn = label(b, 'brand_name')
                      return (
                        <div
                          key={`b-${b.id}`}
                          className="nav-dropdown-row"
                          onMouseEnter={() => openBrandMenu(b.id)}
                          onMouseLeave={scheduleBrandClose}
                        >
                          <button
                            type="button"
                            className="wz-brand-item nav-dropdown-parent"
                            onClick={() => selectBrand(b)}
                          >
                            {brandCell(b, bn)}
                            <span className="nav-row-caret">{isRTL ? '‹' : '›'}</span>
                          </button>
                          {openBrand === b.id && (
                            <div
                              ref={nestedRef}
                              className="nav-subdropdown"
                              onMouseEnter={() => openBrandMenu(b.id)}
                            >
                              <button
                                type="button"
                                className="wz-brand-item"
                                onClick={() => selectBrand(b)}
                              >
                                {brandCell(b, isRTL ? `كل ${bn}` : `All ${bn}`)}
                              </button>
                              {genders.map((g) => (
                                <button
                                  key={`bg-${b.id}-${g.en}`}
                                  type="button"
                                  className="wz-brand-item"
                                  onClick={() => selectBrandGender(b, g)}
                                >
                                  {brandCell(b, forGenderNode(bn, g))}
                                </button>
                              ))}
                            </div>
                          )}
                        </div>
                      )
                    })}
                  </div>
                ))}
              </div>
            )}
          </li>

          {/* 5 — OFFERS (discounted products only) */}
          <li className="nav-item">
            <button type="button" className="nav-link-item" onClick={selectOffers}>
              <RiPercentLine size={14} style={{ color: '#ea2b0f' }} />
              {isRTL ? 'العروض' : 'Offers'}
            </button>
          </li>
        </ul>
      </div>
    </nav>
  )
}

export default Nav
