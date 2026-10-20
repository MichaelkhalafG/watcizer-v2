'use client'
import { memo, useState, useMemo } from 'react'
import { useTables } from '../../Hooks/queries/useTables'
import { useUIStore } from '../../Store/uiStore'
import './SideBar.css'

/*
 * NO-UPPER-CAP: what the filter state carries when the shopper has not capped the price. A sentinel, not
 * a price — the listing request omits `price_max` when it sees it.
 */
const NO_CAP = 99999999
/*
 * The slider's ceiling when the listing serves NO bounds at all.
 *
 * v2 serves `facets.price` on every listing, so this is only reached if the facet block is missing
 * entirely — `facets=0`, or a payload that changed shape. It used to be 10,000,000, which put the whole
 * catalogue inside the first tenth of a percent of the track and made the control useless. 1,000,000 EGP
 * is the defensive replacement: far above anything this catalogue has held (the highest price v2 reports
 * today is 12,500), so it cannot put a real product out of the slider's reach, and small enough that the
 * track stays usable if the bounds ever go missing.
 */
const PRICE_MAX = 1000000
const STEP = 1000
// With the result's bounds served (v2 `facets.price`, 2026-10-18) the slider spans 0 … the result's highest price,
// rounded up to FINE_STEP, and moves in FINE_STEP: a 0 … 10,000,000 track put a 9,400 EGP shelf in its first 0.1 %.
const FINE_STEP = 100

// localized name: current language → English fallback
const trName = (item, key, language) =>
  item?.translations?.find((t) => t.locale === language)?.[key] ??
  item?.translations?.find((t) => t.locale === 'en')?.[key] ??
  ''

function FilterSection({ title, items, selectedIds, onToggle, onClear, searchable }) {
  const [expanded, setExpanded] = useState(true)
  const [showAll, setShowAll] = useState(false)
  const [search, setSearch] = useState('')
  const isRTL = useUIStore((s) => s.language) === 'ar'

  /*
   * A section is drawn only when it can NARROW something (2026-10-22, Michael's rule).
   *
   * One option is not a filter: ticking it returns the same products, so it costs a heading, a
   * row and a decision for nothing. Two is the first count at which a choice exists. The rule is
   * general, not an exception for a particular field — a section whose second value appears later
   * starts being drawn on its own, with no code change.
   *
   * On Watchizer today this hides Display Type on the whole-catalogue listing (production carries
   * exactly one display type). On both shops it also hides any section a narrow result has
   * collapsed to a single option, which is the larger half of what it is for.
   */
  if (items.length < 2) return null

  const filtered = items.filter((i) => i.name.toLowerCase().includes(search.toLowerCase()))
  const visible = showAll ? filtered : filtered.slice(0, 8)

  return (
    <div className="wz-fs">
      <button className="wz-fs-title" onClick={() => setExpanded(!expanded)}>
        <span className="wz-fs-title-text">{title}</span>
        {selectedIds.length > 0 && (
          <span
            className="wz-fs-clear"
            role="button"
            tabIndex={0}
            onClick={(e) => {
              e.stopPropagation()
              onClear()
            }}
          >
            {isRTL ? 'مسح' : 'Clear'}
          </span>
        )}
        <span className="wz-fs-toggle">{expanded ? '−' : '+'}</span>
      </button>

      {expanded && (
        <div className="wz-fs-body">
          {searchable && items.length > 10 && (
            <input
              className="wz-fs-search"
              placeholder={isRTL ? 'ابحث…' : 'Search...'}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          )}

          <ul className="wz-fs-list">
            {visible.map((item) => (
              <li key={item.id} className="wz-fs-item">
                <label>
                  <input
                    type="checkbox"
                    checked={selectedIds.includes(item.id)}
                    onChange={() => onToggle(item.id)}
                  />
                  <span className="wz-fs-item-name">{item.name}</span>
                  <span className="wz-fs-item-count">({item.count})</span>
                </label>
              </li>
            ))}
          </ul>

          {filtered.length > 8 && (
            <button className="wz-fs-show-more" onClick={() => setShowAll(!showAll)}>
              {showAll ? (isRTL ? 'عرض أقل ↑' : 'Show less ↑') : isRTL ? `عرض الكل (${filtered.length}) ↓` : `Show all ${filtered.length} ↓`}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

// `facets`: the option counts from core's listing (C-1 stage 3; v2 since the move) — for each section, the
// products matching that option AND every OTHER active filter. The sidebar used to count them
// itself over the whole catalogue, once per option.
function SideBar({ facets }) {
  const { data: tables = {} } = useTables()
  const { language, filters, setFilters, setCurrentPage } = useUIStore()
  const isRTL = language === 'ar'

  const toggle = (key, id) => {
    setCurrentPage(1)
    setFilters((prev) => {
      const arr = prev[key] || []
      return { ...prev, [key]: arr.includes(id) ? arr.filter((x) => x !== id) : [...arr, id] }
    })
  }
  const clearKey = (key) => {
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, [key]: [] }))
  }
  const clearAll = () => {
    setCurrentPage(1)
    setFilters({
      categories: [],
      brands: [],
      subTypes: [],
      genders: [],
      offers: false,
      price: [0, NO_CAP],
      dialColors: [],
      bandColors: [],
      materials: [],
      movements: [],
      shapes: [],
      displayTypes: [],
      grades: [],
    })
  }

  // Gender is filtered by language-stable English NAME (genders_en), not a table
  // id — so its items come from the gender counts, with Arabic display labels.
  const genderItems = useMemo(() => {
    const AR = { Men: 'رجالي', Women: 'نسائي', Unisex: 'للجنسين', Kids: 'أطفال', Boys: 'أولاد', Girls: 'بنات' }
    const ORDER = ['Men', 'Women', 'Unisex', 'Kids', 'Boys', 'Girls']
    const counts = facets?.genders || {}
    return Object.keys(counts)
      .sort((a, b) => {
        const ia = ORDER.indexOf(a)
        const ib = ORDER.indexOf(b)
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
      })
      .map((name) => ({
        id: name, // English name doubles as the toggle key
        name: isRTL ? AR[name] || name : name,
        count: counts[name] || 0,
      }))
      .filter((g) => g.count > 0)
  }, [facets, isRTL])

  // ── Option counts: for each section, products matching this option AND every
  // OTHER active filter (faceted search) — counted by core, looked up here. ──
  const sections = useMemo(() => {
    const build = (source, key, nameKey) =>
      (source || [])
        .map((item) => ({
          id: item.id,
          name: trName(item, nameKey, language) || `#${item.id}`,
          count: facets?.[key]?.[String(item.id)] || 0,
        }))
        .filter((x) => x.count > 0)
        .sort((a, b) => b.count - a.count)

    return [
      {
        title: isRTL ? 'العلامة التجارية' : 'Brand',
        key: 'brands',
        searchable: true,
        items: build(tables?.brands, 'brands', 'brand_name'),
      },
      {
        title: isRTL ? 'النوع الفرعي' : 'Sub Type',
        key: 'subTypes',
        searchable: true,
        items: build(tables?.subTypes, 'subTypes', 'sub_type_name'),
      },
      {
        title: isRTL ? 'النوع' : 'Gender',
        key: 'genders',
        items: genderItems,
      },
      {
        /*
         * ONE colour section since the listing moved to v2 (2026-10-22).
         *
         * v2 counts and filters a colour in ANY role — dial, band or the product's own — so there is one set
         * of counts, not two, and the second section would always be empty. It is NAMED "Colour" rather than
         * left reading "Dial colour", because a section headed "Dial colour" that also matches a navy strap
         * is a label that lies. The state key stays `dialColors` so no bookmarked listing URL changes meaning.
         */
        title: isRTL ? 'اللون' : 'Colour',
        key: 'dialColors',
        searchable: true,
        items: build(tables?.colors, 'dialColors', 'color_name'),
      },
      {
        // kept so an existing `bandColors` selection in a bookmarked URL still clears; v2 serves it no
        // counts, so `build` returns nothing and the section is never drawn
        title: isRTL ? 'لون السوار' : 'Band Color',
        key: 'bandColors',
        searchable: true,
        items: build(tables?.colors, 'bandColors', 'color_name'),
      },
      {
        title: isRTL ? 'الخامة' : 'Material',
        key: 'materials',
        items: build(tables?.materials, 'materials', 'material_name'),
      },
      {
        title: isRTL ? 'شكل العلبة' : 'Watch Shape',
        key: 'shapes',
        items: build(tables?.shapes, 'shapes', 'shape_name'),
      },
      {
        title: isRTL ? 'نوع العرض' : 'Display Type',
        key: 'displayTypes',
        items: build(tables?.displayTypes, 'displayTypes', 'display_type_name'),
      },
      {
        title: isRTL ? 'نوع الحركة' : 'Movement',
        key: 'movements',
        items: build(tables?.movementTypes, 'movements', 'movement_type_name'),
      },
    ]
  }, [tables, facets, language, isRTL, genderItems])

  // ── Price ──
  // The track's end: the result's highest price when the listing serves it (v2, without the price filter itself, so
  // the end does not move while the shopper drags), else PRICE_MAX as before. Dragged to the end = no upper cap.
  const servedMax = Number(facets?.price?.max)
  const bounded = Number.isFinite(servedMax) && servedMax > 0
  const step = bounded ? FINE_STEP : STEP
  const ceiling = bounded ? Math.max(step, Math.ceil(servedMax / step) * step) : PRICE_MAX
  const priceMin = Math.min(filters.price?.[0] ?? 0, ceiling - step)
  const priceMaxRaw = filters.price?.[1] ?? NO_CAP
  const priceMaxView = priceMaxRaw >= NO_CAP ? ceiling : Math.min(priceMaxRaw, ceiling)
  const onMinChange = (e) => {
    const v = Math.min(Number(e.target.value), priceMaxView - step)
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, price: [Math.max(0, v), prev.price?.[1] ?? NO_CAP] }))
  }
  const onMaxChange = (e) => {
    const v = Math.max(Number(e.target.value), priceMin + step)
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, price: [prev.price?.[0] ?? 0, v >= ceiling ? NO_CAP : v] }))
  }

  const activeCount =
    (filters.brands?.length || 0) +
    (filters.categories?.length || 0) +
    (filters.subTypes?.length || 0) +
    (filters.dialColors?.length || 0) +
    (filters.bandColors?.length || 0) +
    (filters.materials?.length || 0) +
    (filters.movements?.length || 0) +
    (filters.shapes?.length || 0) +
    (filters.displayTypes?.length || 0) +
    (filters.genders?.length || 0) +
    (filters.offers ? 1 : 0) +
    (filters.sale ? 1 : 0) + // Brand Fashion's Sale key (round 5); Watchizer never sets it
    (priceMin > 0 || priceMaxRaw < NO_CAP ? 1 : 0)

  return (
    <div className="wz-sidebar" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-sidebar-active">
        <span className="wz-sidebar-active-label">
          {isRTL ? `فلاتر (${activeCount})` : `Filters (${activeCount})`}
        </span>
        {activeCount > 0 && (
          <button className="wz-sidebar-clear-all" onClick={clearAll}>
            {isRTL ? 'مسح الكل' : 'Clear all'}
          </button>
        )}
      </div>

      {/* Brand first */}
      {sections.slice(0, 1).map((s) => (
        <FilterSection
          key={s.key}
          title={s.title}
          items={s.items}
          selectedIds={filters[s.key] || []}
          onToggle={(id) => toggle(s.key, id)}
          onClear={() => clearKey(s.key)}
          searchable={s.searchable}
        />
      ))}

      {/* Price */}
      <div className="wz-fs">
        <div className="wz-fs-title" style={{ cursor: 'default' }}>
          <span className="wz-fs-title-text">{isRTL ? 'السعر' : 'Price'}</span>
        </div>
        <div className="wz-fs-body">
          <div className="wz-price-wrap">
            <div className="wz-price-track">
              <div
                className="wz-price-fill"
                style={{
                  left: `${(priceMin / ceiling) * 100}%`,
                  right: `${100 - (priceMaxView / ceiling) * 100}%`,
                }}
              />
            </div>
            <input
              type="range"
              className="wz-price-input"
              min={0}
              max={ceiling}
              step={step}
              value={priceMin}
              onChange={onMinChange}
            />
            <input
              type="range"
              className="wz-price-input"
              min={0}
              max={ceiling}
              step={step}
              value={priceMaxView}
              onChange={onMaxChange}
            />
          </div>
          <div className="wz-price-vals">
            <span>
              {priceMin.toLocaleString()} {isRTL ? 'ج.م' : 'EGP'}
            </span>
            <span>
              {priceMaxView.toLocaleString()}
              {priceMaxRaw >= NO_CAP && !bounded ? '+' : ''} {isRTL ? 'ج.م' : 'EGP'}
            </span>
          </div>
        </div>
      </div>

      {/* Remaining sections */}
      {sections.slice(1).map((s) => (
        <FilterSection
          key={s.key}
          title={s.title}
          items={s.items}
          selectedIds={filters[s.key] || []}
          onToggle={(id) => toggle(s.key, id)}
          onClear={() => clearKey(s.key)}
          searchable={s.searchable}
        />
      ))}
    </div>
  )
}

export default memo(SideBar)
