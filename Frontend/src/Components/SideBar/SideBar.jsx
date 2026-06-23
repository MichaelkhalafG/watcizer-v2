import { useContext, useState, useMemo } from 'react'
import { MyContext } from '../../Context/Context'
import { useUIStore } from '../../Store/uiStore'
import { passesFilters } from '../../utils/filterPredicate'
import './SideBar.css'

const PRICE_MAX = 10000000 // slider ceiling (EGP); maps to "no upper cap" in store
const STEP = 1000

// localized name: current language → English fallback
const trName = (item, key, language) =>
  item?.translations?.find((t) => t.locale === language)?.[key] ??
  item?.translations?.find((t) => t.locale === 'en')?.[key] ??
  ''

function FilterSection({ title, items, selectedIds, onToggle, onClear, searchable }) {
  const [expanded, setExpanded] = useState(true)
  const [showAll, setShowAll] = useState(false)
  const [search, setSearch] = useState('')

  if (!items.length) return null

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
            Clear
          </span>
        )}
        <span className="wz-fs-toggle">{expanded ? '−' : '+'}</span>
      </button>

      {expanded && (
        <div className="wz-fs-body">
          {searchable && items.length > 10 && (
            <input
              className="wz-fs-search"
              placeholder="Search..."
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
              {showAll ? 'Show less ↑' : `Show all ${filtered.length} ↓`}
            </button>
          )}
        </div>
      )}
    </div>
  )
}

function SideBar() {
  const { tables, products } = useContext(MyContext)
  const { language, filters, setFilters, setCurrentPage } = useUIStore()
  const isRTL = language === 'ar'
  const list = products || []

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
      price: [0, 99999999],
      dialColors: [],
      bandColors: [],
      materials: [],
      movements: [],
    })
  }

  // ── Dynamic option counts: for each section, count products that match this
  // option AND every OTHER active filter (faceted search). ──
  const sections = useMemo(() => {
    const count = (key, matcher) => (item) =>
      list.filter((p) => passesFilters(p, filters, key) && matcher(p, item.id)).length

    const build = (source, key, nameKey, matcher) =>
      (source || [])
        .map((item) => ({
          id: item.id,
          name: trName(item, nameKey, language) || `#${item.id}`,
          count: count(key, matcher)(item),
        }))
        .filter((x) => x.count > 0)
        .sort((a, b) => b.count - a.count)

    return [
      {
        title: isRTL ? 'العلامة التجارية' : 'Brand',
        key: 'brands',
        searchable: true,
        items: build(tables?.brands, 'brands', 'brand_name', (p, id) => p.brand_id === id),
      },
      {
        title: isRTL ? 'الفئة' : 'Category',
        key: 'categories',
        items: build(
          tables?.categoryTypes,
          'categories',
          'category_type_name',
          (p, id) => p.category_type_id === id,
        ),
      },
      {
        title: isRTL ? 'النوع الفرعي' : 'Sub Type',
        key: 'subTypes',
        searchable: true,
        items: build(tables?.subTypes, 'subTypes', 'sub_type_name', (p, id) => p.sub_type_id === id),
      },
      {
        title: isRTL ? 'لون الميناء' : 'Dial Color',
        key: 'dialColors',
        searchable: true,
        items: build(tables?.colors, 'dialColors', 'color_name', (p, id) =>
          (p.dial_colors || []).some((c) => c.color_id === id),
        ),
      },
      {
        title: isRTL ? 'لون السوار' : 'Strap Color',
        key: 'bandColors',
        searchable: true,
        items: build(tables?.colors, 'bandColors', 'color_name', (p, id) =>
          (p.band_colors || []).some((c) => c.color_id === id),
        ),
      },
      {
        title: isRTL ? 'خامة السوار' : 'Strap Material',
        key: 'materials',
        items: build(
          tables?.materials,
          'materials',
          'material_name',
          (p, id) => p.band_material_id === id,
        ),
      },
      {
        title: isRTL ? 'نوع الحركة' : 'Movement',
        key: 'movements',
        items: build(
          tables?.movementTypes,
          'movements',
          'movement_type_name',
          (p, id) => p.watch_movement_id === id,
        ),
      },
    ]
  }, [tables, list, filters, language, isRTL])

  // ── Price ──
  const priceMin = filters.price?.[0] ?? 0
  const priceMaxRaw = filters.price?.[1] ?? 99999999
  const priceMaxView = priceMaxRaw >= 99999999 ? PRICE_MAX : priceMaxRaw
  const onMinChange = (e) => {
    const v = Math.min(Number(e.target.value), priceMaxView - STEP)
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, price: [Math.max(0, v), prev.price?.[1] ?? 99999999] }))
  }
  const onMaxChange = (e) => {
    const v = Math.max(Number(e.target.value), priceMin + STEP)
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, price: [prev.price?.[0] ?? 0, v >= PRICE_MAX ? 99999999 : v] }))
  }

  const activeCount =
    (filters.brands?.length || 0) +
    (filters.categories?.length || 0) +
    (filters.subTypes?.length || 0) +
    (filters.dialColors?.length || 0) +
    (filters.bandColors?.length || 0) +
    (filters.materials?.length || 0) +
    (filters.movements?.length || 0) +
    (filters.genders?.length || 0) +
    (filters.offers ? 1 : 0) +
    (priceMin > 0 || priceMaxRaw < 99999999 ? 1 : 0)

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
                  left: `${(priceMin / PRICE_MAX) * 100}%`,
                  right: `${100 - (priceMaxView / PRICE_MAX) * 100}%`,
                }}
              />
            </div>
            <input
              type="range"
              className="wz-price-input"
              min={0}
              max={PRICE_MAX}
              step={STEP}
              value={priceMin}
              onChange={onMinChange}
            />
            <input
              type="range"
              className="wz-price-input"
              min={0}
              max={PRICE_MAX}
              step={STEP}
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
              {priceMaxRaw >= 99999999 ? '+' : ''} {isRTL ? 'ج.م' : 'EGP'}
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

export default SideBar
