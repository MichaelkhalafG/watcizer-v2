'use client'
import { memo, useMemo } from 'react'
import Image from 'next/image'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useUIStore } from '../../Store/uiStore'
import { passesFilters } from '../../utils/filterPredicate'
import { getImageUrl } from '../../utils/imageUrl'
import { Carousel, CarouselSlide } from '../UI/Carousel'
import './SmartSuggestions.css'

const MAX_BRANDS = 15

// English gender name (genders_en) → Arabic label + display order.
const GENDER_AR = {
  Men: 'رجالي',
  Women: 'نسائي',
  Unisex: 'للجنسين',
  Kids: 'أطفال',
  Boys: 'أولاد',
  Girls: 'بنات',
}
const GENDER_ORDER = ['Men', 'Women', 'Unisex', 'Kids', 'Boys', 'Girls']

// localized table name: current language → English fallback
const trName = (item, key, language) =>
  item?.translations?.find((t) => t.locale === language)?.[key] ??
  item?.translations?.find((t) => t.locale === 'en')?.[key] ??
  ''

// Stable comparator that floats the currently-selected chips to the front of
// their section. Because Array.prototype.sort is stable, items keep their prior
// order (count descending / gender order) within the active and inactive groups.
const activeFirst = (selected, valueOf) => (a, b) =>
  (selected.includes(valueOf(a)) ? 0 : 1) - (selected.includes(valueOf(b)) ? 0 : 1)

function Chip({ active, label, count, logo, onClick }) {
  return (
    <button
      type="button"
      className={`wz-sg-chip${active ? ' is-active' : ''}`}
      onClick={onClick}
      title={label}
      aria-pressed={active}
    >
      {logo ? (
        <Image
          className="wz-sg-chip-logo"
          src={logo}
          alt=""
          width={20}
          height={20}
          quality={75}
          sizes="20px"
          onError={(e) => {
            e.target.style.display = 'none'
          }}
        />
      ) : null}
      <span className="wz-sg-chip-label">{label}</span>
      <span className="wz-sg-chip-count">{count}</span>
    </button>
  )
}

// Smart suggestions chip bar: contextual gender / category / brand chips with
// faceted counts. Toggles filters through the store (which the Listing page
// mirrors to the URL), so chips read AND write the active filter state.
function SmartSuggestions() {
  const { products, tables } = useCatalog()
  const { language, filters, setFilters, setCurrentPage } = useUIStore()
  const isRTL = language === 'ar'
  const list = products || []

  const toggleArr = (key, value) => {
    setCurrentPage(1)
    setFilters((prev) => {
      const arr = prev[key] || []
      return {
        ...prev,
        [key]: arr.includes(value) ? arr.filter((x) => x !== value) : [...arr, value],
      }
    })
  }

  // A) GENDER — derived from product genders_en (language-safe English names).
  const genders = useMemo(() => {
    const seen = new Set()
    list.forEach((p) => (p.genders_en || []).forEach((g) => g && seen.add(g)))
    return [...seen]
      .sort((a, b) => {
        const ia = GENDER_ORDER.indexOf(a)
        const ib = GENDER_ORDER.indexOf(b)
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
      })
      .map((name) => ({
        name,
        label: isRTL ? GENDER_AR[name] || name : name,
        count: list.filter(
          (p) => passesFilters(p, filters, 'genders') && (p.genders_en || []).includes(name),
        ).length,
      }))
      .filter((g) => g.count > 0)
      .sort(activeFirst(filters.genders || [], (g) => g.name))
  }, [list, filters, isRTL])

  // B) CATEGORY — all category types with faceted counts.
  const categories = useMemo(
    () =>
      (tables?.categoryTypes || [])
        .map((cat) => ({
          id: cat.id,
          label: trName(cat, 'category_type_name', language) || `#${cat.id}`,
          count: list.filter(
            (p) => passesFilters(p, filters, 'categories') && p.category_type_id === cat.id,
          ).length,
        }))
        .filter((c) => c.count > 0)
        .sort((a, b) => b.count - a.count)
        .sort(activeFirst(filters.categories || [], (c) => c.id)),
    [tables, list, filters, language],
  )

  // C) BRANDS — sorted by count, top 15, with logo.
  const brands = useMemo(
    () =>
      (tables?.brands || [])
        .map((b) => ({
          id: b.id,
          label: trName(b, 'brand_name', language) || `#${b.id}`,
          logo: getImageUrl(b.image, 'Brand'),
          count: list.filter(
            (p) => passesFilters(p, filters, 'brands') && p.brand_id === b.id,
          ).length,
        }))
        .filter((b) => b.count > 0)
        .sort((a, b) => b.count - a.count)
        // active-first BEFORE the cap, so a selected brand is never hidden below
        // the top-15 slice
        .sort(activeFirst(filters.brands || [], (b) => b.id))
        .slice(0, MAX_BRANDS),
    [tables, list, filters, language],
  )

  if (!list.length) return null

  // Build each group as a FLAT array of chips (no wrapper div). Group wrappers
  // create extra flex containers in the overflow chain, so the chips go directly
  // into the scroll track as siblings instead.
  const genderChips = genders.map((g) => (
    <Chip
      key={`g-${g.name}`}
      active={(filters.genders || []).includes(g.name)}
      label={g.label}
      count={g.count}
      onClick={() => toggleArr('genders', g.name)}
    />
  ))
  const categoryChips = categories.map((c) => (
    <Chip
      key={`c-${c.id}`}
      active={(filters.categories || []).includes(c.id)}
      label={c.label}
      count={c.count}
      onClick={() => toggleArr('categories', c.id)}
    />
  ))
  const brandChips = brands.map((b) => (
    <Chip
      key={`b-${b.id}`}
      active={(filters.brands || []).includes(b.id)}
      label={b.label}
      count={b.count}
      logo={b.logo}
      onClick={() => toggleArr('brands', b.id)}
    />
  ))

  const parts = [genderChips, categoryChips, brandChips].filter((arr) => arr.length)
  if (!parts.length) return null

  // Flat children: chips with a divider between each non-empty group.
  const track = []
  parts.forEach((chips, i) => {
    if (i > 0) track.push(<span className="wz-sg-divider" key={`div-${i}`} aria-hidden="true" />)
    chips.forEach((chip) => track.push(chip))
  })

  return (
    <div className="wz-sg" dir={isRTL ? 'rtl' : 'ltr'}>
      <span className="wz-sg-label">{isRTL ? 'تصفية:' : 'Filter:'}</span>
      <Carousel gap={8} showArrows={false} showDots={false} className="wz-sg-carousel">
        {track.map((el) => (
          <CarouselSlide key={el.key} className="wz-sg-slide">
            {el}
          </CarouselSlide>
        ))}
      </Carousel>
    </div>
  )
}

export default memo(SmartSuggestions)
